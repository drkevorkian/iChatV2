-- Patch: 017_store_images_in_database
-- Description: Store user gallery images and avatar cache in database as BLOB instead of file system
-- Author: Sentinel Chat Platform
-- Date: 2025-12-30
-- Dependencies: 016_add_word_filter_toggle_and_avatars
-- Rollback: Yes (see patches/rollback/017_store_images_in_database_rollback.sql)

-- Avatar Cache table - stores cached avatar images with metadata
CREATE TABLE IF NOT EXISTS avatar_cache (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_handle VARCHAR(100) NOT NULL UNIQUE COMMENT 'User handle',
    avatar_url VARCHAR(500) NOT NULL COMMENT 'Original avatar URL (Gravatar, etc.)',
    image_data LONGBLOB NOT NULL COMMENT 'Cached image data',
    image_size INT UNSIGNED NOT NULL COMMENT 'Image size in bytes',
    mime_type VARCHAR(100) NOT NULL COMMENT 'MIME type of image',
    md5_checksum VARCHAR(32) NOT NULL COMMENT 'MD5 checksum of image data',
    email VARCHAR(255) NULL COMMENT 'Email associated with avatar (for Gravatar)',
    username VARCHAR(100) NOT NULL COMMENT 'Username of user who stored this',
    cached_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When avatar was cached',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    INDEX idx_user_handle (user_handle),
    INDEX idx_md5_checksum (md5_checksum),
    INDEX idx_cached_at (cached_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cached avatar images';

-- Modify user_gallery table to store images as BLOB instead of file paths
-- First, add new columns for BLOB storage (only if they don't exist)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_gallery' 
                   AND column_name = 'image_data');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE user_gallery ADD COLUMN image_data LONGBLOB NULL COMMENT ''Image data stored in database'' AFTER thumbnail_path', 
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_gallery' 
                   AND column_name = 'thumbnail_data');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE user_gallery ADD COLUMN thumbnail_data LONGBLOB NULL COMMENT ''Thumbnail data stored in database'' AFTER image_data', 
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_gallery' 
                   AND column_name = 'md5_checksum');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE user_gallery ADD COLUMN md5_checksum VARCHAR(32) NULL COMMENT ''MD5 checksum of image data'' AFTER thumbnail_data', 
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create index for MD5 lookups (only if it doesn't exist)
SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
                     WHERE table_schema = DATABASE() 
                     AND table_name = 'user_gallery' 
                     AND index_name = 'idx_md5_checksum');
SET @sql = IF(@index_exists = 0, 'CREATE INDEX idx_md5_checksum ON user_gallery(md5_checksum)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Note: Existing file_path entries will remain for migration purposes
-- New uploads will store data in image_data column

