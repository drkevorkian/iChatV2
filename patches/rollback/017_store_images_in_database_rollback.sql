-- Rollback Patch: 017_store_images_in_database
-- Description: Remove database BLOB storage and revert to file-based storage
-- Use: Run this to undo patch 017_store_images_in_database

-- Drop avatar_cache table
DROP TABLE IF EXISTS avatar_cache;

-- Remove BLOB columns from user_gallery (keep file_path for existing data)
-- Note: MySQL doesn't support IF EXISTS for DROP INDEX/COLUMN, so we check first
SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
                     WHERE table_schema = DATABASE() 
                     AND table_name = 'user_gallery' 
                     AND index_name = 'idx_md5_checksum');
SET @sql = IF(@index_exists > 0, 'ALTER TABLE user_gallery DROP INDEX idx_md5_checksum', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop columns if they exist
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_gallery' 
                   AND column_name = 'md5_checksum');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE user_gallery DROP COLUMN md5_checksum', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_gallery' 
                   AND column_name = 'thumbnail_data');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE user_gallery DROP COLUMN thumbnail_data', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_gallery' 
                   AND column_name = 'image_data');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE user_gallery DROP COLUMN image_data', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

