<?php
/**
 * Sentinel Chat Platform - Profile Repository
 * 
 * Handles user profile operations: viewing, editing, updating profiles.
 * Supports two-way view system: owners see edit mode, others see public view.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;
use iChat\Services\FileStorage;

class ProfileRepository
{
    private FileStorage $fileStorage;
    
    public function __construct()
    {
        $this->fileStorage = new FileStorage();
    }
    
    /**
     * Check if user is registered (not a guest)
     * 
     * @param string $userHandle User handle
     * @return bool True if registered
     */
    public function isRegistered(string $userHandle): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        $sql = 'SELECT COUNT(*) FROM user_registrations WHERE user_handle = :user_handle';
        $result = Database::queryOne($sql, [':user_handle' => $userHandle]);
        return (int)($result['COUNT(*)'] ?? 0) > 0;
    }
    
    /**
     * Get user profile
     * 
     * @param string $userHandle User handle
     * @param string|null $viewerHandle Viewer handle (for access control)
     * @return array|null Profile data or null if not found/not accessible
     */
    public function getProfile(string $userHandle, ?string $viewerHandle = null): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }
        
        $isRegistered = $this->isRegistered($userHandle);
        
        // Build SELECT query dynamically to handle missing columns (if patch not applied)
        $db = Database::getConnection();
        $columns = ['user_handle', 'display_name', 'bio', 'profile_visibility', 'banner_url', 
                    'location', 'website', 'status', 'status_message', 'preferences', 
                    'created_at', 'updated_at'];
        
        // Check which avatar columns exist
        $columnCheck = $db->query("SHOW COLUMNS FROM user_metadata LIKE 'avatar%'");
        $avatarColumns = [];
        while ($row = $columnCheck->fetch()) {
            $avatarColumns[] = $row['Field'];
        }
        
        // Add avatar columns if they exist
        if (in_array('avatar_type', $avatarColumns)) {
            $columns[] = 'avatar_type';
        }
        if (in_array('avatar_data', $avatarColumns)) {
            $columns[] = 'avatar_data';
        }
        if (in_array('avatar_path', $avatarColumns)) {
            $columns[] = 'avatar_path';
        }
        if (in_array('gravatar_email', $avatarColumns)) {
            $columns[] = 'gravatar_email';
        }
        if (in_array('avatar_url', $avatarColumns)) {
            $columns[] = 'avatar_url';
        }
        
        // Check for join_date column
        $joinDateCheck = $db->query("SHOW COLUMNS FROM user_metadata LIKE 'join_date'");
        if ($joinDateCheck->rowCount() > 0) {
            $columns[] = 'join_date';
        }
        
        $sql = 'SELECT ' . implode(', ', $columns) . '
                FROM user_metadata
                WHERE user_handle = :user_handle';
        
        $profile = Database::queryOne($sql, [':user_handle' => $userHandle]);
        
        if (empty($profile)) {
            // Create default profile (for registered users or guests)
            if ($isRegistered) {
                $this->createDefaultProfile($userHandle);
            } else {
                $this->createDefaultGuestProfile($userHandle);
            }
            return $this->getProfile($userHandle, $viewerHandle);
        }
        
        // Check avatar cache first (if table exists)
        $avatarCacheRepo = new \iChat\Repositories\AvatarCacheRepository();
        $cachedAvatar = $avatarCacheRepo->getCachedAvatar($userHandle);
        
        if ($cachedAvatar && !empty($cachedAvatar['image_data'])) {
            // Use cached avatar URL (served from PHP endpoint)
            $profile['avatar_url'] = "/iChat/api/avatar-image.php?user=" . urlencode($userHandle) . "&size=128";
            $profile['avatar_cached'] = true;
        } else {
            // Compute Gravatar URL if avatar_type is gravatar
            $avatarType = $profile['avatar_type'] ?? 'default';
            if ($avatarType === 'gravatar' && !empty($profile['gravatar_email'] ?? '')) {
                $email = strtolower(trim($profile['gravatar_email']));
                // Gravatar uses MD5 hash (standard) - 32 characters
                $hash = md5($email);
                $profile['gravatar_url'] = "https://www.gravatar.com/avatar/{$hash}?s=128&d=identicon";
            } else {
                $profile['gravatar_url'] = null;
            }
            $profile['avatar_cached'] = false;
        }
        
        // Ensure avatar_type exists (default to 'default' if column doesn't exist)
        if (!isset($profile['avatar_type'])) {
            $profile['avatar_type'] = 'default';
        }
        
        // Add is_guest flag to profile data
        $profile['is_guest'] = !$isRegistered;
        
        // Check visibility (guests always have public profiles)
        $isOwner = ($viewerHandle === $userHandle);
        $visibility = $profile['profile_visibility'] ?? 'public';
        
        if (!$isOwner && $visibility === 'private' && $isRegistered) {
            return null; // Private profile, not accessible (only for registered users)
        }
        
        // Record view (if not owner and registered user)
        if (!$isOwner && $isRegistered) {
            $this->recordProfileView($userHandle, $viewerHandle);
        }
        
        return $profile;
    }
    
    /**
     * Update user profile
     * 
     * @param string $userHandle User handle (must be owner)
     * @param array $data Profile data to update
     * @return bool True if successful
     */
    public function updateProfile(string $userHandle, array $data): bool
    {
        // Guests can only update limited fields (display_name, bio, status_message)
        $isGuest = !$this->isRegistered($userHandle);
        
        if ($isGuest) {
            // Guests can only update basic fields
            $allowedGuestFields = ['display_name', 'bio', 'status_message'];
            $data = array_intersect_key($data, array_flip($allowedGuestFields));
            
            if (empty($data)) {
                return false;
            }
        }
        
        // Prevent editing bot profiles (Pinky and Brain)
        if (in_array($userHandle, ['Pinky', 'Brain'], true)) {
            error_log("Attempted to edit bot profile: {$userHandle}");
            return false;
        }
        
        if (!DatabaseHealth::isAvailable()) {
            // Fallback to file storage
            $this->fileStorage->queueMessage('profile_update', [
                'user_handle' => $userHandle,
                'data' => $data,
            ]);
            return true;
        }
        
        // Build update query dynamically
        $allowedFields = [
            'display_name', 'bio', 'avatar_url', 'avatar_data', 'avatar_type', 'avatar_path', 'gravatar_email', 'banner_url',
            'location', 'website', 'status', 'status_message', 'profile_visibility', 'preferences'
        ];
        
        // Handle NULL values for avatar_path (to clear old file paths)
        if (isset($data['avatar_path']) && $data['avatar_path'] === null) {
            $data['avatar_path'] = '';
        }
        
        $updates = [];
        $params = [':user_handle' => $userHandle];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($data[$field] === null && ($field === 'avatar_path' || $field === 'avatar_data' || $field === 'gravatar_email')) {
                    // Allow null values for avatar fields (to clear them)
                    $updates[] = "{$field} = NULL";
                } else {
                    $updates[] = "{$field} = :{$field}";
                    $params[":{$field}"] = $data[$field];
                }
            } elseif (array_key_exists($field, $data) && $data[$field] === null) {
                // Allow null values to be set (for clearing fields)
                $updates[] = "{$field} = NULL";
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        // Check if profile exists
        $exists = Database::queryOne(
            'SELECT COUNT(*) FROM user_metadata WHERE user_handle = :user_handle',
            [':user_handle' => $userHandle]
        );
        
        if ((int)($exists['COUNT(*)'] ?? 0) === 0) {
            // Create profile first (for registered users or guests)
            if ($isGuest) {
                $this->createDefaultGuestProfile($userHandle);
            } else {
                $this->createDefaultProfile($userHandle);
            }
        }
        
        $sql = 'UPDATE user_metadata SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE user_handle = :user_handle';
        Database::execute($sql, $params);
        
        return true;
    }
    
    /**
     * Create default profile for registered user
     * 
     * @param string $userHandle User handle
     */
    private function createDefaultProfile(string $userHandle): void
    {
        if (!DatabaseHealth::isAvailable()) {
            return;
        }
        
        $sql = 'INSERT INTO user_metadata 
                (user_handle, display_name, profile_visibility, status, join_date, created_at)
                VALUES (:user_handle, :display_name, :profile_visibility, :status, NOW(), NOW())
                ON DUPLICATE KEY UPDATE updated_at = NOW()';
        
        Database::execute($sql, [
            ':user_handle' => $userHandle,
            ':display_name' => $userHandle,
            ':profile_visibility' => 'public',
            ':status' => 'offline',
        ]);
    }
    
    /**
     * Create default profile for guest user
     * 
     * Guests get a simple default profile with limited information.
     * They can't edit most fields, but can have a basic profile view.
     * 
     * @param string $userHandle Guest user handle
     */
    private function createDefaultGuestProfile(string $userHandle): void
    {
        if (!DatabaseHealth::isAvailable()) {
            return;
        }
        
        // Get guest's first appearance time from room_presence or user_sessions
        $joinDate = null;
        try {
            $presenceSql = 'SELECT MIN(created_at) as first_seen FROM room_presence WHERE user_handle = :user_handle';
            $presenceResult = Database::queryOne($presenceSql, [':user_handle' => $userHandle]);
            if (!empty($presenceResult['first_seen'])) {
                $joinDate = $presenceResult['first_seen'];
            }
        } catch (\Exception $e) {
            // Ignore errors - table might not exist or column might not exist
            error_log('Failed to get guest join date from room_presence: ' . $e->getMessage());
        }
        
        if ($joinDate === null) {
            try {
                $sessionSql = 'SELECT MIN(created_at) as first_seen FROM user_sessions WHERE user_handle = :user_handle';
                $sessionResult = Database::queryOne($sessionSql, [':user_handle' => $userHandle]);
                if (!empty($sessionResult['first_seen'])) {
                    $joinDate = $sessionResult['first_seen'];
                }
            } catch (\Exception $e) {
                // Ignore errors - table might not exist
                error_log('Failed to get guest join date from user_sessions: ' . $e->getMessage());
            }
        }
        
        // Use current time if no join date found
        if ($joinDate === null) {
            $joinDate = date('Y-m-d H:i:s');
        }
        
        // Default guest profile - use NULL for join_date if column doesn't exist
        try {
            $sql = 'INSERT INTO user_metadata 
                    (user_handle, display_name, bio, profile_visibility, status, join_date, created_at)
                    VALUES (:user_handle, :display_name, :bio, :profile_visibility, :status, :join_date, NOW())
                    ON DUPLICATE KEY UPDATE updated_at = NOW()';
            
            Database::execute($sql, [
                ':user_handle' => $userHandle,
                ':display_name' => $userHandle,
                ':bio' => 'Guest user',
                ':profile_visibility' => 'public',
                ':status' => 'offline',
                ':join_date' => $joinDate,
            ]);
        } catch (\Exception $e) {
            // Try without join_date if column doesn't exist
            error_log('Failed to create guest profile with join_date: ' . $e->getMessage());
            try {
                $sql = 'INSERT INTO user_metadata 
                        (user_handle, display_name, bio, profile_visibility, status, created_at)
                        VALUES (:user_handle, :display_name, :bio, :profile_visibility, :status, NOW())
                        ON DUPLICATE KEY UPDATE updated_at = NOW()';
                
                Database::execute($sql, [
                    ':user_handle' => $userHandle,
                    ':display_name' => $userHandle,
                    ':bio' => 'Guest user',
                    ':profile_visibility' => 'public',
                    ':status' => 'offline',
                ]);
            } catch (\Exception $e2) {
                error_log('Failed to create guest profile: ' . $e2->getMessage());
                throw $e2;
            }
        }
    }
    
    /**
     * Record profile view
     * 
     * @param string $profileOwner Profile owner handle
     * @param string|null $viewerHandle Viewer handle (null for anonymous)
     */
    private function recordProfileView(string $profileOwner, ?string $viewerHandle): void
    {
        if (!DatabaseHealth::isAvailable()) {
            return;
        }
        
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (strpos($ipAddress, ',') !== false) {
            $ipAddress = trim(explode(',', $ipAddress)[0]);
        }
        
        $sql = 'INSERT INTO profile_views 
                (profile_owner, viewer_handle, viewer_ip, viewed_at)
                VALUES (:profile_owner, :viewer_handle, :viewer_ip, NOW())';
        
        try {
            Database::execute($sql, [
                ':profile_owner' => $profileOwner,
                ':viewer_handle' => $viewerHandle,
                ':viewer_ip' => $ipAddress,
            ]);
        } catch (\Exception $e) {
            // Ignore errors (table might not exist yet)
            error_log('Profile view recording failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get profile view count
     * 
     * @param string $userHandle User handle
     * @return int View count
     */
    public function getProfileViewCount(string $userHandle): int
    {
        if (!DatabaseHealth::isAvailable()) {
            return 0;
        }
        
        try {
            $sql = 'SELECT COUNT(*) FROM profile_views WHERE profile_owner = :user_handle';
            $result = Database::queryOne($sql, [':user_handle' => $userHandle]);
            return (int)($result['COUNT(*)'] ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Register a user (create registration record)
     * 
     * @param string $userHandle User handle
     * @param string|null $email Email address (optional)
     * @return bool True if successful
     */
    public function registerUser(string $userHandle, ?string $email = null): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        $sql = 'INSERT INTO user_registrations 
                (user_handle, email, registered_at)
                VALUES (:user_handle, :email, NOW())
                ON DUPLICATE KEY UPDATE email = VALUES(email)';
        
        try {
            Database::execute($sql, [
                ':user_handle' => $userHandle,
                ':email' => $email,
            ]);
            
            // Create default profile
            $this->createDefaultProfile($userHandle);
            
            return true;
        } catch (\Exception $e) {
            error_log('User registration failed: ' . $e->getMessage());
            return false;
        }
    }
}

