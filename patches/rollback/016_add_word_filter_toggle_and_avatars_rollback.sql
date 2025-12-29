-- Rollback Patch: 016_add_word_filter_toggle_and_avatars
-- Description: Remove word filter toggle and avatar system
-- Use: Run this to undo patch 016_add_word_filter_toggle_and_avatars

-- Drop user_gallery table
DROP TABLE IF EXISTS user_gallery;

-- Remove avatar fields from user_metadata
-- Note: MySQL doesn't support IF EXISTS for DROP COLUMN, so we check first
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_metadata' 
                   AND column_name = 'gravatar_email');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE user_metadata DROP COLUMN gravatar_email', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_metadata' 
                   AND column_name = 'avatar_data');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE user_metadata DROP COLUMN avatar_data', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_metadata' 
                   AND column_name = 'avatar_url');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE user_metadata DROP COLUMN avatar_url', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_metadata' 
                   AND column_name = 'avatar_path');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE user_metadata DROP COLUMN avatar_path', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_metadata' 
                   AND column_name = 'avatar_type');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE user_metadata DROP COLUMN avatar_type', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop indexes if they exist
SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
                     WHERE table_schema = DATABASE() 
                     AND table_name = 'user_metadata' 
                     AND index_name = 'idx_avatar_type');
SET @sql = IF(@index_exists > 0, 'ALTER TABLE user_metadata DROP INDEX idx_avatar_type', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
                     WHERE table_schema = DATABASE() 
                     AND table_name = 'user_metadata' 
                     AND index_name = 'idx_avatar_path');
SET @sql = IF(@index_exists > 0, 'ALTER TABLE user_metadata DROP INDEX idx_avatar_path', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Remove word_filter_enabled from user_settings
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = DATABASE() 
                   AND table_name = 'user_settings' 
                   AND column_name = 'word_filter_enabled');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE user_settings DROP COLUMN word_filter_enabled', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
