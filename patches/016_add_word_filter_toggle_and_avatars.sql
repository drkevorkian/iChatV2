-- Patch: 016_add_word_filter_toggle_and_avatars
-- Description: Add word filter toggle to user settings and avatar/profile picture system
-- Author: Sentinel Chat Platform
-- Date: 2025-12-29
-- Dependencies: 014_add_user_settings, 005_add_authentication_system
-- Rollback: Yes (see patches/rollback/016_add_word_filter_toggle_and_avatars_rollback.sql)

-- Add word_filter_enabled to user_settings (only if it doesn't exist)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_settings' 
                   AND column_name = 'word_filter_enabled');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE user_settings ADD COLUMN word_filter_enabled BOOLEAN NOT NULL DEFAULT TRUE COMMENT ''Whether word filtering is enabled for this user'' AFTER compact_mode', 
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- User Gallery table - stores images uploaded by users for avatars and other uses
CREATE TABLE IF NOT EXISTS user_gallery (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_handle VARCHAR(100) NOT NULL COMMENT 'User who owns this image',
    user_id BIGINT UNSIGNED NULL COMMENT 'Foreign key to user_registrations if registered',
    filename VARCHAR(255) NOT NULL COMMENT 'Original filename',
    file_path VARCHAR(500) NOT NULL COMMENT 'Path to stored image file',
    file_size BIGINT UNSIGNED NOT NULL COMMENT 'File size in bytes',
    mime_type VARCHAR(100) NOT NULL COMMENT 'MIME type (should be image/*)',
    width INT UNSIGNED NULL COMMENT 'Image width in pixels',
    height INT UNSIGNED NULL COMMENT 'Image height in pixels',
    thumbnail_path VARCHAR(500) NULL COMMENT 'Path to thumbnail image',
    is_avatar BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether this image is set as avatar',
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When image was uploaded',
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    INDEX idx_user_handle (user_handle),
    INDEX idx_user_id (user_id),
    INDEX idx_is_avatar (is_avatar),
    INDEX idx_deleted_at (deleted_at),
    FOREIGN KEY (user_id) REFERENCES user_registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User gallery images for avatars and personal use';

-- Add avatar fields to user_metadata (only if they don't exist)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_metadata' 
                   AND column_name = 'avatar_type');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE user_metadata ADD COLUMN avatar_type ENUM(''default'', ''gravatar'', ''gallery'') NOT NULL DEFAULT ''default'' COMMENT ''Type of avatar (default, gravatar, or gallery image)'' AFTER status_message', 
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_metadata' 
                   AND column_name = 'avatar_path');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE user_metadata ADD COLUMN avatar_path VARCHAR(500) NULL COMMENT ''Path to avatar image if type is gallery'' AFTER avatar_type', 
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_metadata' 
                   AND column_name = 'gravatar_email');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE user_metadata ADD COLUMN gravatar_email VARCHAR(255) NULL COMMENT ''Email for Gravatar (if type is gravatar)'' AFTER avatar_path', 
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create indexes for avatar lookups (only if they don't exist)
SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
                     WHERE table_schema = DATABASE() 
                     AND table_name = 'user_metadata' 
                     AND index_name = 'idx_avatar_type');
SET @sql = IF(@index_exists = 0, 'CREATE INDEX idx_avatar_type ON user_metadata(avatar_type)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
                     WHERE table_schema = DATABASE() 
                     AND table_name = 'user_metadata' 
                     AND index_name = 'idx_avatar_path');
SET @sql = IF(@index_exists = 0, 'CREATE INDEX idx_avatar_path ON user_metadata(avatar_path(255))', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

