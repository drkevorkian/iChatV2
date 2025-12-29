<?php
/**
 * Sentinel Chat Platform - User Management Repository
 * 
 * Handles user management operations: kick, mute, ban, IM, etc.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;
use iChat\Services\FileStorage;

class UserManagementRepository
{
    private FileStorage $fileStorage;
    
    public function __construct()
    {
        $this->fileStorage = new FileStorage();
    }
    
    /**
     * Get all online users with their details
     * 
     * @return array Online users with IP, room, geolocation, etc.
     */
    public function getOnlineUsers(): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }
        
        $sql = 'SELECT 
                    rp.user_handle,
                    rp.room_id,
                    rp.ip_address,
                    rp.session_id,
                    rp.last_seen,
                    us.user_agent,
                    um.avatar_url,
                    um.avatar_data,
                    um.display_name
                FROM room_presence rp
                LEFT JOIN user_sessions us ON rp.session_id = us.session_id
                LEFT JOIN user_metadata um ON rp.user_handle = um.user_handle
                WHERE rp.last_seen > DATE_SUB(NOW(), INTERVAL 30 SECOND)
                ORDER BY rp.room_id, rp.user_handle';
        
        return Database::query($sql);
    }
    
    /**
     * Get online users in a specific room
     * 
     * @param string $roomId Room ID
     * @return array Online users in room
     */
    public function getOnlineUsersInRoom(string $roomId): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }
        
        $sql = 'SELECT 
                    rp.user_handle,
                    rp.room_id,
                    rp.ip_address,
                    rp.session_id,
                    rp.last_seen,
                    us.user_agent,
                    um.avatar_url,
                    um.avatar_data,
                    um.display_name
                FROM room_presence rp
                LEFT JOIN user_sessions us ON rp.session_id = us.session_id
                LEFT JOIN user_metadata um ON rp.user_handle = um.user_handle
                WHERE rp.room_id = :room_id
                  AND rp.last_seen > DATE_SUB(NOW(), INTERVAL 30 SECOND)
                ORDER BY rp.user_handle';
        
        return Database::query($sql, [':room_id' => $roomId]);
    }
    
    /**
     * Record user session
     * 
     * @param string $userHandle User handle
     * @param string $sessionId Session ID
     * @param string $ipAddress IP address
     * @param string $userAgent User agent
     * @param string|null $currentRoom Current room
     */
    public function recordSession(
        string $userHandle,
        string $sessionId,
        string $ipAddress,
        string $userAgent = '',
        ?string $currentRoom = null
    ): void {
        if (!DatabaseHealth::isAvailable()) {
            return;
        }
        
        $sql = 'INSERT INTO user_sessions 
                (user_handle, session_id, ip_address, user_agent, current_room, last_activity)
                VALUES (:user_handle, :session_id, :ip_address, :user_agent, :current_room, NOW())
                ON DUPLICATE KEY UPDATE 
                    ip_address = VALUES(ip_address),
                    user_agent = VALUES(user_agent),
                    current_room = VALUES(current_room),
                    last_activity = NOW()';
        
        Database::execute($sql, [
            ':user_handle' => $userHandle,
            ':session_id' => $sessionId,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent,
            ':current_room' => $currentRoom,
        ]);
    }
    
    /**
     * Update presence with IP address
     * 
     * @param string $roomId Room ID
     * @param string $userHandle User handle
     * @param string $ipAddress IP address
     * @param string $sessionId Session ID
     */
    public function updatePresenceWithIp(
        string $roomId,
        string $userHandle,
        string $ipAddress,
        string $sessionId
    ): void {
        if (!DatabaseHealth::isAvailable()) {
            return;
        }
        
        $sql = 'INSERT INTO room_presence 
                (room_id, user_handle, ip_address, session_id, last_seen)
                VALUES (:room_id, :user_handle, :ip_address, :session_id, NOW())
                ON DUPLICATE KEY UPDATE 
                    ip_address = VALUES(ip_address),
                    session_id = VALUES(session_id),
                    last_seen = NOW()';
        
        Database::execute($sql, [
            ':room_id' => $roomId,
            ':user_handle' => $userHandle,
            ':ip_address' => $ipAddress,
            ':session_id' => $sessionId,
        ]);
    }
    
    /**
     * Ban a user
     * 
     * @param string $userHandle User to ban
     * @param string $bannedBy Admin/moderator who issued ban
     * @param string $reason Reason for ban
     * @param string|null $ipAddress IP address to ban (optional)
     * @param \DateTime|null $expiresAt Expiration date (null = permanent)
     * @param string|null $email Email address for unban link (optional)
     * @return int|null Ban ID or null if failed
     */
    public function banUser(
        string $userHandle,
        string $bannedBy,
        string $reason,
        ?string $ipAddress = null,
        ?\DateTime $expiresAt = null,
        ?string $email = null
    ): ?int {
        if (!DatabaseHealth::isAvailable()) {
            // Fallback to file storage
            $data = [
                'user_handle' => $userHandle,
                'banned_by' => $bannedBy,
                'reason' => $reason,
                'ip_address' => $ipAddress,
                'expires_at' => $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null,
                'email' => $email,
            ];
            $this->fileStorage->queueMessage('ban', $data);
            return null;
        }
        
        $sql = 'INSERT INTO user_bans 
                (user_handle, ip_address, banned_by, reason, expires_at, unban_email)
                VALUES (:user_handle, :ip_address, :banned_by, :reason, :expires_at, :unban_email)';
        
        Database::execute($sql, [
            ':user_handle' => $userHandle,
            ':ip_address' => $ipAddress,
            ':banned_by' => $bannedBy,
            ':reason' => $reason,
            ':expires_at' => $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null,
            ':unban_email' => $email,
        ]);
        
        return (int)Database::lastInsertId();
    }
    
    /**
     * Generate unban token for a ban
     * 
     * @param int $banId Ban ID
     * @param string $email Email address
     * @return string|null Unban token or null if failed
     */
    public function generateUnbanToken(int $banId, string $email): ?string
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }
        
        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days')); // Token valid for 30 days
        
        // Check if unban_tokens table exists, if not create it
        try {
            $sql = 'INSERT INTO unban_tokens 
                    (ban_id, email, token, expires_at, created_at)
                    VALUES (:ban_id, :email, :token, :expires_at, NOW())
                    ON DUPLICATE KEY UPDATE
                        token = VALUES(token),
                        expires_at = VALUES(expires_at),
                        created_at = NOW()';
            
            Database::execute($sql, [
                ':ban_id' => $banId,
                ':email' => $email,
                ':token' => $token,
                ':expires_at' => $expiresAt,
            ]);
            
            return $token;
        } catch (\Exception $e) {
            // Table might not exist, try to create it
            error_log('Unban token table might not exist: ' . $e->getMessage());
            try {
                $createTableSql = 'CREATE TABLE IF NOT EXISTS unban_tokens (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    ban_id BIGINT UNSIGNED NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    token VARCHAR(64) NOT NULL UNIQUE,
                    expires_at TIMESTAMP NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    used_at TIMESTAMP NULL DEFAULT NULL,
                    INDEX idx_ban_id (ban_id),
                    INDEX idx_token (token),
                    INDEX idx_email (email),
                    FOREIGN KEY (ban_id) REFERENCES user_bans(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
                
                Database::getConnection()->exec($createTableSql);
                
                // Retry insert
                $sql = 'INSERT INTO unban_tokens 
                        (ban_id, email, token, expires_at, created_at)
                        VALUES (:ban_id, :email, :token, :expires_at, NOW())';
                
                Database::execute($sql, [
                    ':ban_id' => $banId,
                    ':email' => $email,
                    ':token' => $token,
                    ':expires_at' => $expiresAt,
                ]);
                
                return $token;
            } catch (\Exception $e2) {
                error_log('Failed to create unban_tokens table: ' . $e2->getMessage());
                return null;
            }
        }
    }
    
    /**
     * Validate and use unban token
     * 
     * @param string $token Unban token
     * @return array Result with success status and ban info
     */
    public function validateUnbanToken(string $token): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return ['success' => false, 'error' => 'Database unavailable'];
        }
        
        try {
            $sql = 'SELECT ut.id, ut.ban_id, ut.email, ut.expires_at, ut.used_at,
                           ub.user_handle, ub.ip_address, ub.reason
                    FROM unban_tokens ut
                    JOIN user_bans ub ON ut.ban_id = ub.id
                    WHERE ut.token = :token
                      AND ut.expires_at > NOW()
                      AND ut.used_at IS NULL';
            
            $result = Database::queryOne($sql, [':token' => $token]);
            
            if (empty($result)) {
                return ['success' => false, 'error' => 'Invalid or expired token'];
            }
            
            // Mark token as used
            $updateSql = 'UPDATE unban_tokens SET used_at = NOW() WHERE id = :id';
            Database::execute($updateSql, [':id' => $result['id']]);
            
            // Unban the user
            $this->unbanUser($result['user_handle'], $result['ip_address']);
            
            return [
                'success' => true,
                'user_handle' => $result['user_handle'],
                'email' => $result['email'],
            ];
        } catch (\Exception $e) {
            error_log('Unban token validation error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Token validation failed'];
        }
    }
    
    /**
     * Mute a user
     * 
     * @param string $userHandle User to mute
     * @param string $mutedBy Admin/moderator who issued mute
     * @param string $reason Reason for mute
     * @param \DateTime|null $expiresAt Expiration date (null = permanent)
     */
    public function muteUser(
        string $userHandle,
        string $mutedBy,
        string $reason,
        ?\DateTime $expiresAt = null
    ): void {
        if (!DatabaseHealth::isAvailable()) {
            // Fallback to file storage
            $data = [
                'user_handle' => $userHandle,
                'muted_by' => $mutedBy,
                'reason' => $reason,
                'expires_at' => $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null,
            ];
            $this->fileStorage->queueMessage('mute', $data);
            return;
        }
        
        $sql = 'INSERT INTO user_mutes 
                (user_handle, muted_by, reason, expires_at)
                VALUES (:user_handle, :muted_by, :reason, :expires_at)';
        
        Database::execute($sql, [
            ':user_handle' => $userHandle,
            ':muted_by' => $mutedBy,
            ':reason' => $reason,
            ':expires_at' => $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null,
        ]);
    }
    
    /**
     * Check if user is banned
     * 
     * @param string $userHandle User handle
     * @param string|null $ipAddress IP address (optional)
     * @return bool True if banned
     */
    public function isBanned(string $userHandle, ?string $ipAddress = null): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        $sql = 'SELECT COUNT(*) FROM user_bans 
                WHERE (user_handle = :user_handle';
        
        $params = [':user_handle' => $userHandle];
        
        if ($ipAddress !== null) {
            $sql .= ' OR ip_address = :ip_address';
            $params[':ip_address'] = $ipAddress;
        }
        
        $sql .= ') AND (expires_at IS NULL OR expires_at > NOW())';
        
        $result = Database::queryOne($sql, $params);
        return (int)($result['COUNT(*)'] ?? 0) > 0;
    }
    
    /**
     * Get ban information for a user
     * 
     * @param string $userHandle User handle
     * @param string|null $ipAddress IP address (optional)
     * @return array Ban information or empty array if not banned
     */
    public function getBanInfo(string $userHandle, ?string $ipAddress = null): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }
        
        $sql = 'SELECT id, user_handle, ip_address, banned_by, reason, expires_at, created_at
                FROM user_bans 
                WHERE (user_handle = :user_handle';
        
        $params = [':user_handle' => $userHandle];
        
        if ($ipAddress !== null) {
            $sql .= ' OR ip_address = :ip_address';
            $params[':ip_address'] = $ipAddress;
        }
        
        $sql .= ') AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY created_at DESC
                LIMIT 1';
        
        $result = Database::queryOne($sql, $params);
        return $result ?: [];
    }
    
    /**
     * Unban a user
     * 
     * @param string $userHandle User handle to unban
     * @param string|null $ipAddress IP address to unban (optional, if null unban by handle only)
     * @return bool True if unbanned successfully
     */
    public function unbanUser(string $userHandle, ?string $ipAddress = null): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        // Set expires_at to NOW() to effectively remove the ban
        $sql = 'UPDATE user_bans 
                SET expires_at = NOW() 
                WHERE user_handle = :user_handle';
        
        $params = [':user_handle' => $userHandle];
        
        if ($ipAddress !== null) {
            $sql .= ' OR ip_address = :ip_address';
            $params[':ip_address'] = $ipAddress;
        }
        
        $sql .= ' AND (expires_at IS NULL OR expires_at > NOW())';
        
        $affected = Database::execute($sql, $params);
        return $affected > 0;
    }
    
    /**
     * Get all active bans for a user
     * 
     * @param string $userHandle User handle
     * @return array List of active bans
     */
    public function getUserBans(string $userHandle): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }
        
        $sql = 'SELECT id, user_handle, ip_address, banned_by, reason, expires_at, created_at
                FROM user_bans 
                WHERE user_handle = :user_handle
                  AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY created_at DESC';
        
        return Database::query($sql, [':user_handle' => $userHandle]);
    }
    
    /**
     * Check if user is muted
     * 
     * @param string $userHandle User handle
     * @return bool True if muted
     */
    public function isMuted(string $userHandle): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        $sql = 'SELECT COUNT(*) FROM user_mutes 
                WHERE user_handle = :user_handle
                  AND (expires_at IS NULL OR expires_at > NOW())';
        
        $result = Database::queryOne($sql, [':user_handle' => $userHandle]);
        return (int)($result['COUNT(*)'] ?? 0) > 0;
    }
    
    /**
     * Kick user from room (remove presence and session)
     * 
     * @param string $userHandle User handle
     * @param string $roomId Room ID
     */
    public function kickUser(string $userHandle, string $roomId): void
    {
        if (!DatabaseHealth::isAvailable()) {
            return;
        }
        
        // Remove from room presence
        $sql = 'DELETE FROM room_presence 
                WHERE user_handle = :user_handle AND room_id = :room_id';
        
        Database::execute($sql, [
            ':user_handle' => $userHandle,
            ':room_id' => $roomId,
        ]);
        
        // Also remove user session to force re-login
        try {
            $sql2 = 'DELETE FROM user_sessions WHERE user_handle = :user_handle';
            Database::execute($sql2, [':user_handle' => $userHandle]);
        } catch (\Exception $e) {
            // Log but don't fail if session removal fails
            error_log('Failed to remove session during kick: ' . $e->getMessage());
        }
    }
    
    /**
     * Get user avatar
     * 
     * @param string $userHandle User handle
     * @return array Avatar data
     */
    public function getUserAvatar(string $userHandle): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return ['avatar_url' => null, 'avatar_data' => null];
        }
        
        $sql = 'SELECT avatar_url, avatar_data FROM user_metadata WHERE user_handle = :user_handle';
        $result = Database::queryOne($sql, [':user_handle' => $userHandle]);
        
        return [
            'avatar_url' => $result['avatar_url'] ?? null,
            'avatar_data' => $result['avatar_data'] ?? null,
        ];
    }

    /**
     * Get all users (online and offline) with comprehensive information
     * 
     * @return array All users with their details
     */
    public function getAllUsers(): array
    {
        if (!DatabaseHealth::isAvailable()) {
            error_log('UserManagementRepository::getAllUsers - Database not available');
            return [];
        }
        
        // Initialize variables
        $registeredUsers = [];
        $guestUsers = [];
        
        try {
            // Simplified query - get registered users first without ban/mute checks
            // Use subquery to get most recent presence per user to avoid duplicates
            try {
                $sql = "SELECT DISTINCT
                            u.id,
                            u.username AS user_handle,
                            u.email,
                            u.role,
                            u.is_active,
                            u.is_verified,
                            u.last_login,
                            u.created_at,
                            um.display_name,
                            um.avatar_url,
                            um.avatar_data,
                            rp.room_id AS current_room,
                            rp.last_seen,
                            rp.ip_address,
                            rp.session_id,
                            us.user_agent,
                            0 AS is_banned,
                            0 AS is_muted
                        FROM users u
                        LEFT JOIN user_metadata um ON u.username = um.user_handle
                        LEFT JOIN (
                            SELECT rp1.user_handle, rp1.room_id, rp1.last_seen, rp1.ip_address, rp1.session_id
                            FROM room_presence rp1
                            INNER JOIN (
                                SELECT user_handle, MAX(last_seen) AS max_last_seen
                                FROM room_presence
                                WHERE last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                                GROUP BY user_handle
                            ) rp2 ON rp1.user_handle = rp2.user_handle AND rp1.last_seen = rp2.max_last_seen
                        ) rp ON u.username = rp.user_handle
                        LEFT JOIN user_sessions us ON rp.session_id = us.session_id
                        ORDER BY u.created_at DESC";
                
                $registeredUsers = Database::query($sql);
                error_log('UserManagementRepository::getAllUsers - Found ' . count($registeredUsers) . ' registered users');
                
                // Try to add ban/mute status if tables exist
                if (!empty($registeredUsers)) {
                    try {
                        $db = Database::getConnection();
                        $bansCheck = $db->query("SHOW TABLES LIKE 'user_bans'");
                        $hasBansTable = $bansCheck->rowCount() > 0;
                        $mutesCheck = $db->query("SHOW TABLES LIKE 'user_mutes'");
                        $hasMutesTable = $mutesCheck->rowCount() > 0;
                        
                        if ($hasBansTable || $hasMutesTable) {
                            foreach ($registeredUsers as &$user) {
                                if ($hasBansTable) {
                                    try {
                                        $banCheck = Database::queryOne(
                                            "SELECT COUNT(*) as count FROM user_bans WHERE user_handle = :handle AND (expires_at IS NULL OR expires_at > NOW())",
                                            [':handle' => $user['user_handle']]
                                        );
                                        $user['is_banned'] = (bool)($banCheck['count'] ?? 0);
                                    } catch (\Exception $e) {
                                        $user['is_banned'] = false;
                                    }
                                }
                                if ($hasMutesTable) {
                                    try {
                                        $muteCheck = Database::queryOne(
                                            "SELECT COUNT(*) as count FROM user_mutes WHERE user_handle = :handle AND (expires_at IS NULL OR expires_at > NOW())",
                                            [':handle' => $user['user_handle']]
                                        );
                                        $user['is_muted'] = (bool)($muteCheck['count'] ?? 0);
                                    } catch (\Exception $e) {
                                        $user['is_muted'] = false;
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        error_log('Error checking ban/mute status: ' . $e->getMessage());
                        // Continue without ban/mute status
                    }
                }
            } catch (\Exception $e) {
                error_log('Error fetching registered users: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                $registeredUsers = [];
            }
            
            // Get guest users from room_presence (users not in users table)
            try {
                $sql = "SELECT DISTINCT
                            rp.user_handle,
                            rp.room_id AS current_room,
                            rp.last_seen,
                            rp.ip_address,
                            rp.session_id,
                            us.user_agent,
                            0 AS is_banned,
                            0 AS is_muted
                        FROM room_presence rp
                        LEFT JOIN users u ON rp.user_handle = u.username
                        LEFT JOIN user_sessions us ON rp.session_id = us.session_id
                        WHERE u.id IS NULL
                          AND rp.last_seen > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                        ORDER BY rp.last_seen DESC";
                
                $guestUsers = Database::query($sql);
                error_log('UserManagementRepository::getAllUsers - Found ' . count($guestUsers) . ' guest users');
                
                // Try to add ban/mute status for guests if tables exist
                if (!empty($guestUsers)) {
                    try {
                        $db = Database::getConnection();
                        $bansCheck = $db->query("SHOW TABLES LIKE 'user_bans'");
                        $hasBansTable = $bansCheck->rowCount() > 0;
                        $mutesCheck = $db->query("SHOW TABLES LIKE 'user_mutes'");
                        $hasMutesTable = $mutesCheck->rowCount() > 0;
                        
                        if ($hasBansTable || $hasMutesTable) {
                            foreach ($guestUsers as &$guest) {
                                if ($hasBansTable) {
                                    try {
                                        $banCheck = Database::queryOne(
                                            "SELECT COUNT(*) as count FROM user_bans WHERE user_handle = :handle AND (expires_at IS NULL OR expires_at > NOW())",
                                            [':handle' => $guest['user_handle']]
                                        );
                                        $guest['is_banned'] = (bool)($banCheck['count'] ?? 0);
                                    } catch (\Exception $e) {
                                        $guest['is_banned'] = false;
                                    }
                                }
                                if ($hasMutesTable) {
                                    try {
                                        $muteCheck = Database::queryOne(
                                            "SELECT COUNT(*) as count FROM user_mutes WHERE user_handle = :handle AND (expires_at IS NULL OR expires_at > NOW())",
                                            [':handle' => $guest['user_handle']]
                                        );
                                        $guest['is_muted'] = (bool)($muteCheck['count'] ?? 0);
                                    } catch (\Exception $e) {
                                        $guest['is_muted'] = false;
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        error_log('Error checking ban/mute status for guests: ' . $e->getMessage());
                        // Continue without ban/mute status
                    }
                }
            } catch (\Exception $e) {
                error_log('Error fetching guest users: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                $guestUsers = [];
            }
        } catch (\Exception $e) {
            error_log('UserManagementRepository::getAllUsers error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            // Return empty array on critical error
            return [];
        }
        
        // Combine and format
        $allUsers = [];
        
        // Add registered users
        foreach ($registeredUsers as $user) {
            $allUsers[] = [
                'id' => $user['id'],
                'user_handle' => $user['user_handle'],
                'email' => $user['email'],
                'role' => $user['role'],
                'is_active' => (bool)($user['is_active'] ?? true),
                'is_verified' => (bool)($user['is_verified'] ?? false),
                'is_online' => !empty($user['current_room']),
                'current_room' => $user['current_room'],
                'last_seen' => $user['last_seen'],
                'last_login' => $user['last_login'],
                'created_at' => $user['created_at'],
                'display_name' => $user['display_name'],
                'avatar_url' => $user['avatar_url'],
                'avatar_data' => $user['avatar_data'],
                'ip_address' => $user['ip_address'],
                'session_id' => $user['session_id'],
                'user_agent' => $user['user_agent'],
                'is_banned' => (bool)($user['is_banned'] ?? false),
                'is_muted' => (bool)($user['is_muted'] ?? false),
                'is_guest' => false,
            ];
        }
        
        // Add guest users
        foreach ($guestUsers as $guest) {
            $allUsers[] = [
                'id' => null,
                'user_handle' => $guest['user_handle'],
                'email' => null,
                'role' => 'guest',
                'is_active' => true,
                'is_verified' => false,
                'is_online' => !empty($guest['current_room']),
                'current_room' => $guest['current_room'],
                'last_seen' => $guest['last_seen'],
                'last_login' => null,
                'created_at' => null,
                'display_name' => null,
                'avatar_url' => null,
                'avatar_data' => null,
                'ip_address' => $guest['ip_address'],
                'session_id' => $guest['session_id'],
                'user_agent' => $guest['user_agent'],
                'is_banned' => (bool)($guest['is_banned'] ?? false),
                'is_muted' => (bool)($guest['is_muted'] ?? false),
                'is_guest' => true,
            ];
        }
        
        return $allUsers;
    }
}

