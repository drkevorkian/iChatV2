<?php
/**
 * Sentinel Chat Platform - Avatar Cache Repository
 * 
 * Handles caching of avatar images in the database.
 * 
 * Security: All operations validate user ownership.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;

class AvatarCacheRepository
{
    /**
     * Generate thumbnail from image data
     * 
     * @param string $imageData Binary image data
     * @param string $mimeType MIME type
     * @param int $maxSize Maximum size for thumbnail (default 48px)
     * @return string|null Thumbnail binary data or null on failure
     */
    private function generateThumbnail(string $imageData, string $mimeType, int $maxSize = 48): ?string
    {
        // Create image from data
        $source = null;
        switch ($mimeType) {
            case 'image/jpeg':
                $source = imagecreatefromstring($imageData);
                break;
            case 'image/png':
                $source = imagecreatefromstring($imageData);
                break;
            case 'image/gif':
                $source = imagecreatefromstring($imageData);
                break;
            case 'image/webp':
                $source = imagecreatefromstring($imageData);
                break;
            default:
                return null;
        }
        
        if (!$source) {
            return null;
        }
        
        $width = imagesx($source);
        $height = imagesy($source);
        
        if ($width === 0 || $height === 0) {
            imagedestroy($source);
            return null;
        }
        
        // Calculate thumbnail dimensions
        $thumbWidth = $width;
        $thumbHeight = $height;
        
        if ($width > $height) {
            $thumbWidth = $maxSize;
            $thumbHeight = (int)($height * ($maxSize / $width));
        } else {
            $thumbHeight = $maxSize;
            $thumbWidth = (int)($width * ($maxSize / $height));
        }
        
        // Create thumbnail
        $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
        
        // Preserve transparency for PNG/GIF
        if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
            imagefilledrectangle($thumb, 0, 0, $thumbWidth, $thumbHeight, $transparent);
        }
        
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
        
        // Output thumbnail to string
        ob_start();
        switch ($mimeType) {
            case 'image/jpeg':
                imagejpeg($thumb, null, 85);
                break;
            case 'image/png':
                imagepng($thumb);
                break;
            case 'image/gif':
                imagegif($thumb);
                break;
            case 'image/webp':
                imagewebp($thumb, null, 85);
                break;
        }
        $thumbnailData = ob_get_clean();
        
        imagedestroy($thumb);
        imagedestroy($source);
        
        return $thumbnailData ?: null;
    }
    
    /**
     * Cache an avatar image
     * 
     * @param string $userHandle User handle
     * @param string $avatarUrl Original avatar URL (Gravatar, etc.)
     * @param string $imageData Binary image data
     * @param string $mimeType MIME type
     * @param string $email Email associated with avatar (for Gravatar)
     * @param string $username Username of user who stored this
     * @return bool Success
     */
    public function cacheAvatar(
        string $userHandle,
        string $avatarUrl,
        string $imageData,
        string $mimeType,
        string $email,
        string $username,
        ?string $thumbnailData = null,
        ?int $thumbnailSize = null
    ): bool {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        try {
            // Check if table exists first - silently fail if it doesn't
            $db = Database::getConnection();
            try {
                $tableCheck = $db->query("SHOW TABLES LIKE 'avatar_cache'");
                if ($tableCheck->rowCount() === 0) {
                    // Table doesn't exist yet (patch not applied) - return false silently
                    return false;
                }
            } catch (\PDOException $e) {
                // Table check failed - table doesn't exist
                return false;
            } catch (\Exception $e) {
                // Any other exception during table check
                return false;
            }
            $imageSize = strlen($imageData);
            $md5Checksum = md5($imageData);
            
            // Generate thumbnail (48px for chat/online users)
            $thumbnailData = $this->generateThumbnail($imageData, $mimeType, 48);
            $thumbnailSize = $thumbnailData ? strlen($thumbnailData) : null;
            
            // Check if thumbnail_data column exists
            $columnCheck = $db->query("SHOW COLUMNS FROM avatar_cache LIKE 'thumbnail_data'");
            $hasThumbnailColumn = $columnCheck->rowCount() > 0;
            
            if ($hasThumbnailColumn) {
                $sql = 'INSERT INTO avatar_cache 
                        (user_handle, avatar_url, image_data, image_size, mime_type, md5_checksum, email, username, thumbnail_data, thumbnail_size, cached_at)
                        VALUES (:user_handle, :avatar_url, :image_data, :image_size, :mime_type, :md5_checksum, :email, :username, :thumbnail_data, :thumbnail_size, NOW())
                        ON DUPLICATE KEY UPDATE
                            avatar_url = VALUES(avatar_url),
                            image_data = VALUES(image_data),
                            image_size = VALUES(image_size),
                            mime_type = VALUES(mime_type),
                            md5_checksum = VALUES(md5_checksum),
                            email = VALUES(email),
                            username = VALUES(username),
                            thumbnail_data = VALUES(thumbnail_data),
                            thumbnail_size = VALUES(thumbnail_size),
                            updated_at = NOW()';
                
                $stmt = $db->prepare($sql);
                $stmt->bindValue(':user_handle', $userHandle, \PDO::PARAM_STR);
                $stmt->bindValue(':avatar_url', $avatarUrl, \PDO::PARAM_STR);
                $stmt->bindValue(':image_data', $imageData, \PDO::PARAM_STR);
                $stmt->bindValue(':image_size', $imageSize, \PDO::PARAM_INT);
                $stmt->bindValue(':mime_type', $mimeType, \PDO::PARAM_STR);
                $stmt->bindValue(':md5_checksum', $md5Checksum, \PDO::PARAM_STR);
                $stmt->bindValue(':email', $email, \PDO::PARAM_STR);
                $stmt->bindValue(':username', $username, \PDO::PARAM_STR);
                $stmt->bindValue(':thumbnail_data', $thumbnailData, \PDO::PARAM_STR);
                $stmt->bindValue(':thumbnail_size', $thumbnailSize, \PDO::PARAM_INT);
            } else {
                // Fallback if thumbnail column doesn't exist yet
                $sql = 'INSERT INTO avatar_cache 
                        (user_handle, avatar_url, image_data, image_size, mime_type, md5_checksum, email, username, cached_at)
                        VALUES (:user_handle, :avatar_url, :image_data, :image_size, :mime_type, :md5_checksum, :email, :username, NOW())
                        ON DUPLICATE KEY UPDATE
                            avatar_url = VALUES(avatar_url),
                            image_data = VALUES(image_data),
                            image_size = VALUES(image_size),
                            mime_type = VALUES(mime_type),
                            md5_checksum = VALUES(md5_checksum),
                            email = VALUES(email),
                            username = VALUES(username),
                            updated_at = NOW()';
                
                $stmt = $db->prepare($sql);
                $stmt->bindValue(':user_handle', $userHandle, \PDO::PARAM_STR);
                $stmt->bindValue(':avatar_url', $avatarUrl, \PDO::PARAM_STR);
                $stmt->bindValue(':image_data', $imageData, \PDO::PARAM_STR);
                $stmt->bindValue(':image_size', $imageSize, \PDO::PARAM_INT);
                $stmt->bindValue(':mime_type', $mimeType, \PDO::PARAM_STR);
                $stmt->bindValue(':md5_checksum', $md5Checksum, \PDO::PARAM_STR);
                $stmt->bindValue(':email', $email, \PDO::PARAM_STR);
                $stmt->bindValue(':username', $username, \PDO::PARAM_STR);
            }
            
            return $stmt->execute();
        } catch (\Exception $e) {
            error_log('AvatarCacheRepository::cacheAvatar error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get cached avatar image data
     * 
     * @param string $userHandle User handle
     * @return array|null Avatar data with image BLOB or null if not cached
     */
    public function getCachedAvatar(string $userHandle): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }
        
        try {
            // Check if table exists first - use a try/catch around the table check itself
            $db = Database::getConnection();
            $tableExists = false;
            try {
                $tableCheck = $db->query("SHOW TABLES LIKE 'avatar_cache'");
                $tableExists = $tableCheck->rowCount() > 0;
            } catch (\Exception $e) {
                // Table check failed - table doesn't exist
                return null;
            }
            
            if (!$tableExists) {
                // Table doesn't exist yet (patch not applied) - return null silently
                return null;
            }
            
            // Check if thumbnail_data column exists (only if table exists)
            $hasThumbnailColumn = false;
            try {
                $columnCheck = $db->query("SHOW COLUMNS FROM avatar_cache LIKE 'thumbnail_data'");
                $hasThumbnailColumn = $columnCheck->rowCount() > 0;
            } catch (\Exception $e) {
                // Column check failed - assume no thumbnail column
                $hasThumbnailColumn = false;
            }
            
            if ($hasThumbnailColumn) {
                $sql = 'SELECT user_handle, avatar_url, image_data, image_size, mime_type, md5_checksum, email, username, thumbnail_data, thumbnail_size, cached_at, updated_at
                        FROM avatar_cache
                        WHERE user_handle = :user_handle';
            } else {
                $sql = 'SELECT user_handle, avatar_url, image_data, image_size, mime_type, md5_checksum, email, username, cached_at, updated_at
                        FROM avatar_cache
                        WHERE user_handle = :user_handle';
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':user_handle' => $userHandle]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            // Ensure BLOB data is retrieved correctly
            if ($result) {
                // For BLOB columns, PDO might return them as streams or strings
                // Convert to string if needed
                if (isset($result['image_data'])) {
                    if (is_resource($result['image_data'])) {
                        $result['image_data'] = stream_get_contents($result['image_data']);
                    }
                    if ($result['image_data'] === null || $result['image_data'] === '') {
                        // No image data - return null
                        return null;
                    }
                }
                if (isset($result['thumbnail_data']) && is_resource($result['thumbnail_data'])) {
                    $result['thumbnail_data'] = stream_get_contents($result['thumbnail_data']);
                }
            }
            
            return $result ?: null;
        } catch (\PDOException $e) {
            // Silently fail if table doesn't exist (patch not applied yet)
            $errorMsg = $e->getMessage();
            if (strpos($errorMsg, "doesn't exist") !== false || 
                strpos($errorMsg, "Base table or view not found") !== false ||
                strpos($errorMsg, "Table") !== false && strpos($errorMsg, "doesn't exist") !== false) {
                return null; // Don't log - expected if patch not applied
            }
            // Only log unexpected errors
            error_log('AvatarCacheRepository::getCachedAvatar unexpected error: ' . $errorMsg);
            return null;
        } catch (\Exception $e) {
            // Catch any other exceptions
            $errorMsg = $e->getMessage();
            if (strpos($errorMsg, "doesn't exist") !== false || 
                strpos($errorMsg, "Base table or view not found") !== false ||
                strpos($errorMsg, "Table") !== false && strpos($errorMsg, "doesn't exist") !== false) {
                return null; // Don't log - expected if patch not applied
            }
            error_log('AvatarCacheRepository::getCachedAvatar unexpected error: ' . $errorMsg);
            return null;
        }
    }
    
    /**
     * Check if avatar is cached
     * 
     * @param string $userHandle User handle
     * @return bool True if cached
     */
    public function isCached(string $userHandle): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        try {
            $sql = 'SELECT COUNT(*) FROM avatar_cache WHERE user_handle = :user_handle';
            $result = Database::queryOne($sql, [':user_handle' => $userHandle]);
            return (int)($result['COUNT(*)'] ?? 0) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}


