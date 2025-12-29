-- Rollback Patch: 012_add_unban_system
-- Description: Remove unban email and token system
-- Use: Run this to undo patch 012_add_unban_system

DROP TABLE IF EXISTS unban_tokens;

-- Remove unban_email column from user_bans
SET @dbname = DATABASE();
SET @tablename = 'user_bans';
SET @columnname = 'unban_email';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  CONCAT('ALTER TABLE ', @tablename, ' DROP COLUMN ', @columnname),
  'SELECT 1'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

