-- Patch: 019_add_avatar_thumbnails
-- Description: Add thumbnail_data column to avatar_cache table for serving smaller avatars in chat and online users
-- Author: Sentinel Chat Platform
-- Date: 2025-12-29
-- Dependencies: 017_store_images_in_database
-- Rollback: Yes (see patches/rollback/019_add_avatar_thumbnails_rollback.sql)

-- Add thumbnail_data column to avatar_cache (only if it doesn't exist)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'avatar_cache' 
                   AND column_name = 'thumbnail_data');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE avatar_cache ADD COLUMN thumbnail_data LONGBLOB NULL COMMENT ''Thumbnail version of avatar (typically 36-48px) for chat and online users'' AFTER image_data', 
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add thumbnail_size column
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'avatar_cache' 
                   AND column_name = 'thumbnail_size');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE avatar_cache ADD COLUMN thumbnail_size INT UNSIGNED NULL COMMENT ''Size of thumbnail in bytes'' AFTER thumbnail_data', 
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

