<?php
/**
 * Sentinel Chat Platform - User Gallery Repository
 * 
 * Handles user gallery images for avatars and personal use.
 * 
 * Security: All file operations are validated and sanitized.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;

class UserGalleryRepository
{
    /**
     * Add an image to user gallery (stored as BLOB in database)
     * 
     * @param string $userHandle User handle
     * @param int|null $userId User ID if registered
     * @param string $filename Original filename
     * @param string $imageData Binary image data
     * @param int $fileSize File size in bytes
     * @param string $mimeType MIME type
     * @param int|null $width Image width
     * @param int|null $height Image height
     * @param string|null $thumbnailData Binary thumbnail data
     * @return int|false Gallery image ID or false on failure
     */
    public function addImage(
        string $userHandle,
        ?int $userId,
        string $filename,
        string $imageData,
        int $fileSize,
        string $mimeType,
        ?int $width = null,
        ?int $height = null,
        ?string $thumbnailData = null
    ) {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        try {
            // Calculate MD5 checksum
            $md5Checksum = md5($imageData);
            
            // Check if BLOB columns exist, if not use file_path (backward compatibility)
            $db = Database::getConnection();
            $columnCheck = $db->query("SHOW COLUMNS FROM user_gallery LIKE 'image_data'");
            $hasBlobColumns = $columnCheck->rowCount() > 0;
            
            if ($hasBlobColumns) {
                // Use BLOB storage - for MySQL LONGBLOB, we can insert binary strings directly
                $sql = 'INSERT INTO user_gallery 
                        (user_handle, user_id, filename, file_size, mime_type, width, height, image_data, thumbnail_data, md5_checksum, uploaded_at)
                        VALUES (:user_handle, :user_id, :filename, :file_size, :mime_type, :width, :height, :image_data, :thumbnail_data, :md5_checksum, NOW())';
                
                $stmt = $db->prepare($sql);
                $stmt->bindValue(':user_handle', $userHandle, \PDO::PARAM_STR);
                $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
                $stmt->bindValue(':filename', $filename, \PDO::PARAM_STR);
                $stmt->bindValue(':file_size', $fileSize, \PDO::PARAM_INT);
                $stmt->bindValue(':mime_type', $mimeType, \PDO::PARAM_STR);
                $stmt->bindValue(':width', $width, \PDO::PARAM_INT);
                $stmt->bindValue(':height', $height, \PDO::PARAM_INT);
                
                // For LONGBLOB, bind as string - MySQL PDO handles binary strings correctly
                $stmt->bindValue(':image_data', $imageData, \PDO::PARAM_STR);
                $stmt->bindValue(':thumbnail_data', $thumbnailData !== null ? $thumbnailData : null, $thumbnailData !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
                $stmt->bindValue(':md5_checksum', $md5Checksum, \PDO::PARAM_STR);
            } else {
                // Fallback to file_path storage (if patch not applied yet)
                // Save file to disk temporarily
                $galleryDir = ICHAT_ROOT . '/storage/user_gallery/' . md5($userHandle);
                if (!is_dir($galleryDir)) {
                    mkdir($galleryDir, 0755, true);
                    file_put_contents($galleryDir . '/.htaccess', "Deny from all\n");
                }
                
                $extension = pathinfo($filename, PATHINFO_EXTENSION);
                $uniqueFilename = uniqid('img_', true) . '.' . $extension;
                $filePath = $galleryDir . '/' . $uniqueFilename;
                
                if (file_put_contents($filePath, $imageData) === false) {
                    throw new \RuntimeException('Failed to save image file');
                }
                
                $relativePath = 'storage/user_gallery/' . md5($userHandle) . '/' . $uniqueFilename;
                
                $sql = 'INSERT INTO user_gallery 
                        (user_handle, user_id, filename, file_path, file_size, mime_type, width, height, uploaded_at)
                        VALUES (:user_handle, :user_id, :filename, :file_path, :file_size, :mime_type, :width, :height, NOW())';
                
                $stmt = $db->prepare($sql);
                $stmt->bindValue(':user_handle', $userHandle, \PDO::PARAM_STR);
                $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
                $stmt->bindValue(':filename', $filename, \PDO::PARAM_STR);
                $stmt->bindValue(':file_path', $relativePath, \PDO::PARAM_STR);
                $stmt->bindValue(':file_size', $fileSize, \PDO::PARAM_INT);
                $stmt->bindValue(':mime_type', $mimeType, \PDO::PARAM_STR);
                $stmt->bindValue(':width', $width, \PDO::PARAM_INT);
                $stmt->bindValue(':height', $height, \PDO::PARAM_INT);
            }
            
            if (!$stmt->execute()) {
                $errorInfo = $stmt->errorInfo();
                error_log('UserGalleryRepository::addImage execute failed: ' . print_r($errorInfo, true));
                error_log('SQL: ' . $sql);
                error_log('User handle: ' . $userHandle . ', File size: ' . $fileSize . ', Image data length: ' . strlen($imageData));
                return false;
            }
            
            return $db->lastInsertId();
        } catch (\Exception $e) {
            error_log('UserGalleryRepository::addImage error: ' . $e->getMessage());
            error_log('UserGalleryRepository::addImage stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }
    
    /**
     * Get user's gallery images (metadata only, not image data)
     * 
     * @param string $userHandle User handle
     * @return array Array of gallery images
     */
    public function getUserGallery(string $userHandle): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }
        
        try {
            // Check which columns exist (for backward compatibility)
            $db = Database::getConnection();
            $columnCheck = $db->query("SHOW COLUMNS FROM user_gallery");
            $columns = [];
            $hasImageData = false;
            $hasThumbnailData = false;
            $hasMd5Checksum = false;
            
            while ($row = $columnCheck->fetch()) {
                $colName = $row['Field'];
                if ($colName === 'image_data') {
                    $hasImageData = true;
                } elseif ($colName === 'thumbnail_data') {
                    $hasThumbnailData = true;
                } elseif ($colName === 'md5_checksum') {
                    $hasMd5Checksum = true;
                }
            }
            
            // Check for is_public column
            $hasIsPublic = false;
            $columnCheck2 = $db->query("SHOW COLUMNS FROM user_gallery");
            while ($row = $columnCheck2->fetch()) {
                if ($row['Field'] === 'is_public') {
                    $hasIsPublic = true;
                    break;
                }
            }
            
            // Build SELECT query based on available columns
            $selectCols = ['id', 'filename', 'file_size', 'mime_type', 'width', 'height', 'is_avatar', 'uploaded_at'];
            
            if ($hasIsPublic) {
                $selectCols[] = 'is_public';
            } else {
                $selectCols[] = 'FALSE as is_public';
            }
            
            if ($hasMd5Checksum) {
                $selectCols[] = 'md5_checksum';
            }
            if ($hasImageData) {
                $selectCols[] = 'CASE WHEN image_data IS NOT NULL THEN 1 ELSE 0 END as has_image_data';
            } else {
                $selectCols[] = '0 as has_image_data';
            }
            if ($hasThumbnailData) {
                $selectCols[] = 'CASE WHEN thumbnail_data IS NOT NULL THEN 1 ELSE 0 END as has_thumbnail_data';
            } else {
                $selectCols[] = '0 as has_thumbnail_data';
            }
            
            $sql = 'SELECT ' . implode(', ', $selectCols) . '
                    FROM user_gallery
                    WHERE user_handle = :user_handle AND deleted_at IS NULL
                    ORDER BY uploaded_at DESC';
            
            return Database::query($sql, [':user_handle' => $userHandle]);
        } catch (\Exception $e) {
            error_log('UserGalleryRepository::getUserGallery error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get gallery image data by ID (returns image BLOB or file path)
     * 
     * @param int $imageId Image ID
     * @param string $userHandle User handle (for security)
     * @param bool $thumbnail If true, return thumbnail; if false, return full image
     * @return array|null Image data with BLOB or file_path, or null if not found
     */
    public function getImageData(int $imageId, string $userHandle, bool $thumbnail = false): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }
        
        try {
            // Check which columns exist
            $db = Database::getConnection();
            $columnCheck = $db->query("SHOW COLUMNS FROM user_gallery");
            $hasBlobColumns = false;
            $hasFilePath = false;
            
            while ($row = $columnCheck->fetch()) {
                if ($row['Field'] === 'image_data' || $row['Field'] === 'thumbnail_data') {
                    $hasBlobColumns = true;
                }
                if ($row['Field'] === 'file_path') {
                    $hasFilePath = true;
                }
            }
            
            if ($hasBlobColumns) {
                // Try to get BLOB data first
                $dataColumn = $thumbnail ? 'thumbnail_data' : 'image_data';
                
                // Build SELECT with available columns
                $selectCols = ['id', 'filename', "{$dataColumn} as image_data", 'mime_type', 'file_size'];
                
                // Check if file_path column exists
                $pathColumnCheck = $db->query("SHOW COLUMNS FROM user_gallery LIKE 'file_path'");
                if ($pathColumnCheck->rowCount() > 0) {
                    $selectCols[] = 'file_path';
                }
                
                // Check if thumbnail_path exists (for thumbnails)
                if ($thumbnail) {
                    $thumbPathCheck = $db->query("SHOW COLUMNS FROM user_gallery LIKE 'thumbnail_path'");
                    if ($thumbPathCheck->rowCount() > 0) {
                        $selectCols[] = 'thumbnail_path as file_path';
                    }
                }
                
                $sql = 'SELECT ' . implode(', ', $selectCols) . '
                        FROM user_gallery
                        WHERE id = :id AND user_handle = :user_handle AND deleted_at IS NULL';
                
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':id' => $imageId,
                    ':user_handle' => $userHandle,
                ]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                // Handle BLOB data that might be a resource
                if ($result && isset($result['image_data'])) {
                    if (is_resource($result['image_data'])) {
                        $result['image_data'] = stream_get_contents($result['image_data']);
                    }
                }
                
                if ($result && empty($result['image_data'])) {
                    // BLOB column exists but is empty - try fallback to file_path
                    error_log("getImageData: BLOB column exists but is empty for image_id: {$imageId}, trying file_path fallback");
                    $fallback = $this->getImageById($imageId, $userHandle);
                    if ($fallback && !empty($fallback['file_path'])) {
                        // Try to read from file
                        $filePath = ICHAT_ROOT . '/' . ltrim($fallback['file_path'], '/');
                        if (file_exists($filePath)) {
                            $fileData = file_get_contents($filePath);
                            if ($fileData !== false) {
                                $fallback['image_data'] = $fileData;
                                $fallback['mime_type'] = $fallback['mime_type'] ?? mime_content_type($filePath);
                                return $fallback;
                            }
                        }
                    }
                }
                return $result ?: null;
            } else {
                // Fallback: return metadata with file_path
                error_log("getImageData: No BLOB columns found, using file_path fallback for image_id: {$imageId}");
                $fallback = $this->getImageById($imageId, $userHandle);
                if ($fallback && !empty($fallback['file_path'])) {
                    // Try to read from file
                    $filePath = ICHAT_ROOT . '/' . ltrim($fallback['file_path'], '/');
                    if (file_exists($filePath)) {
                        $fileData = file_get_contents($filePath);
                        if ($fileData !== false) {
                            $fallback['image_data'] = $fileData;
                            $fallback['mime_type'] = $fallback['mime_type'] ?? mime_content_type($filePath);
                            return $fallback;
                        }
                    }
                }
                return $fallback;
            }
        } catch (\Exception $e) {
            error_log('UserGalleryRepository::getImageData error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get gallery image metadata by ID (no BLOB data)
     * 
     * @param int $imageId Image ID
     * @param string $userHandle User handle (for security)
     * @return array|null Image metadata or null if not found/not owned by user
     */
    public function getImageById(int $imageId, string $userHandle): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }
        
        try {
            // Check which columns exist
            $db = Database::getConnection();
            $columnCheck = $db->query("SHOW COLUMNS FROM user_gallery");
            $hasBlobColumns = false;
            $hasFilePath = false;
            
            while ($row = $columnCheck->fetch()) {
                if ($row['Field'] === 'image_data') {
                    $hasBlobColumns = true;
                }
                if ($row['Field'] === 'file_path') {
                    $hasFilePath = true;
                }
            }
            
            // Build SELECT based on available columns
            $selectCols = ['id', 'user_handle', 'filename', 'file_size', 'mime_type', 'width', 'height', 'is_avatar', 'uploaded_at'];
            
            if ($hasBlobColumns) {
                $selectCols[] = 'md5_checksum';
                $selectCols[] = 'CASE WHEN image_data IS NOT NULL THEN 1 ELSE 0 END as has_image_data';
                $selectCols[] = 'CASE WHEN thumbnail_data IS NOT NULL THEN 1 ELSE 0 END as has_thumbnail_data';
            }
            
            if ($hasFilePath) {
                $selectCols[] = 'file_path';
            }
            
            // Check for is_public column
            $hasIsPublic = false;
            $columnCheck2 = $db->query("SHOW COLUMNS FROM user_gallery");
            while ($row = $columnCheck2->fetch()) {
                if ($row['Field'] === 'is_public') {
                    $hasIsPublic = true;
                    break;
                }
            }
            if ($hasIsPublic) {
                $selectCols[] = 'is_public';
            } else {
                $selectCols[] = 'FALSE as is_public';
            }
            
            $sql = 'SELECT ' . implode(', ', $selectCols) . '
                    FROM user_gallery
                    WHERE id = :id AND user_handle = :user_handle AND deleted_at IS NULL';
            
            return Database::queryOne($sql, [
                ':id' => $imageId,
                ':user_handle' => $userHandle,
            ]);
        } catch (\Exception $e) {
            error_log('UserGalleryRepository::getImageById error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Delete gallery image (soft delete)
     * 
     * @param int $imageId Image ID
     * @param string $userHandle User handle (for security)
     * @return bool Success
     */
    public function deleteImage(int $imageId, string $userHandle): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        try {
            $sql = 'UPDATE user_gallery SET deleted_at = NOW() WHERE id = :id AND user_handle = :user_handle AND deleted_at IS NULL';
            return Database::execute($sql, [
                ':id' => $imageId,
                ':user_handle' => $userHandle,
            ]) > 0;
        } catch (\Exception $e) {
            error_log('UserGalleryRepository::deleteImage error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Set image as avatar (unset others)
     * 
     * @param int $imageId Image ID
     * @param string $userHandle User handle
     * @return bool Success
     */
    public function setAsAvatar(int $imageId, string $userHandle): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        try {
            // Unset all avatars for this user
            $sql = 'UPDATE user_gallery SET is_avatar = FALSE WHERE user_handle = :user_handle';
            Database::execute($sql, [':user_handle' => $userHandle]);
            
            // Set this image as avatar
            $sql = 'UPDATE user_gallery SET is_avatar = TRUE WHERE id = :id AND user_handle = :user_handle AND deleted_at IS NULL';
            return Database::execute($sql, [
                ':id' => $imageId,
                ':user_handle' => $userHandle,
            ]) > 0;
        } catch (\Exception $e) {
            error_log('UserGalleryRepository::setAsAvatar error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update gallery image metadata
     * 
     * @param int $imageId Image ID
     * @param string $userHandle User handle
     * @param array $updates Array of fields to update (filename, is_public, is_avatar)
     * @return bool Success
     */
    public function updateImage(int $imageId, string $userHandle, array $updates): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        try {
            $allowedFields = ['filename', 'is_public', 'is_avatar'];
            $updateParts = [];
            $params = [':id' => $imageId, ':user_handle' => $userHandle];
            
            // Check which columns exist
            $db = Database::getConnection();
            $columnCheck = $db->query("SHOW COLUMNS FROM user_gallery");
            $hasIsPublic = false;
            while ($row = $columnCheck->fetch()) {
                if ($row['Field'] === 'is_public') {
                    $hasIsPublic = true;
                    break;
                }
            }
            
            foreach ($updates as $field => $value) {
                if (!in_array($field, $allowedFields)) {
                    continue;
                }
                
                // Skip is_public if column doesn't exist
                if ($field === 'is_public' && !$hasIsPublic) {
                    continue;
                }
                
                $updateParts[] = "{$field} = :{$field}";
                $params[":{$field}"] = $value;
            }
            
            if (empty($updateParts)) {
                return false;
            }
            
            $sql = 'UPDATE user_gallery SET ' . implode(', ', $updateParts) . 
                   ' WHERE id = :id AND user_handle = :user_handle AND deleted_at IS NULL';
            
            $result = Database::execute($sql, $params);
            
            // If setting as avatar, unset others
            if (isset($updates['is_avatar']) && $updates['is_avatar']) {
                $sql = 'UPDATE user_gallery SET is_avatar = FALSE WHERE id != :id AND user_handle = :user_handle';
                Database::execute($sql, [':id' => $imageId, ':user_handle' => $userHandle]);
            }
            
            return $result > 0;
        } catch (\Exception $e) {
            error_log('UserGalleryRepository::updateImage error: ' . $e->getMessage());
            return false;
        }
    }
}

