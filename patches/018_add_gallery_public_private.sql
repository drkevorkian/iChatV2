-- Patch: 018_add_gallery_public_private
-- Description: Add is_public column to user_gallery table for public/private visibility control
-- Author: Sentinel Chat Platform
-- Date: 2025-12-29
-- Dependencies: 016_add_word_filter_toggle_and_avatars
-- Rollback: Yes (see patches/rollback/018_add_gallery_public_private_rollback.sql)

-- Add is_public column to user_gallery (only if it doesn't exist)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_gallery' 
                   AND column_name = 'is_public');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE user_gallery ADD COLUMN is_public BOOLEAN NOT NULL DEFAULT FALSE COMMENT ''Whether this image is public (visible to others) or private'' AFTER is_avatar', 
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create index for public gallery queries
SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
                     WHERE table_schema = DATABASE() 
                     AND table_name = 'user_gallery' 
                     AND index_name = 'idx_is_public');
SET @sql = IF(@index_exists = 0, 'CREATE INDEX idx_is_public ON user_gallery(is_public)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

