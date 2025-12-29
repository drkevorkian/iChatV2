-- Rollback Patch: 018_add_gallery_public_private
-- Description: Remove is_public column from user_gallery table
-- Use: Run this to undo patch 018_add_gallery_public_private

-- Drop index for is_public
SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
                     WHERE table_schema = DATABASE() 
                     AND table_name = 'user_gallery' 
                     AND index_name = 'idx_is_public');
SET @sql = IF(@index_exists > 0, 'DROP INDEX idx_is_public ON user_gallery', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Remove is_public column
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_gallery' 
                   AND column_name = 'is_public');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE user_gallery DROP COLUMN is_public', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

