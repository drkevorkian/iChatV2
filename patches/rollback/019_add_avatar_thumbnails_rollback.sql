-- Rollback Patch: 019_add_avatar_thumbnails
-- Description: Remove thumbnail columns from avatar_cache table
-- Use: Run this to undo patch 019_add_avatar_thumbnails

-- Remove thumbnail_size column
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'avatar_cache' 
                   AND column_name = 'thumbnail_size');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE avatar_cache DROP COLUMN thumbnail_size', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Remove thumbnail_data column
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'avatar_cache' 
                   AND column_name = 'thumbnail_data');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE avatar_cache DROP COLUMN thumbnail_data', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

