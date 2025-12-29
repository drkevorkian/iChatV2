-- Patch: 012_add_unban_system
-- Description: Add unban email and token system for user bans
-- Author: Sentinel Chat Platform
-- Date: 2025-12-26
-- Dependencies: 003_add_user_tracking
-- Rollback: Yes (see patches/rollback/012_add_unban_system_rollback.sql)

-- Add unban_email column to user_bans table
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
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(255) COMMENT ''Email address for unban link''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Create unban_tokens table
CREATE TABLE IF NOT EXISTS unban_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ban_id BIGINT UNSIGNED NOT NULL COMMENT 'ID of the ban',
    email VARCHAR(255) NOT NULL COMMENT 'Email address for unban',
    token VARCHAR(64) NOT NULL UNIQUE COMMENT 'Unique unban token',
    expires_at TIMESTAMP NOT NULL COMMENT 'Token expiration date',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When token was created',
    used_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When token was used (NULL = unused)',
    INDEX idx_ban_id (ban_id),
    INDEX idx_token (token),
    INDEX idx_email (email),
    INDEX idx_expires_at (expires_at),
    FOREIGN KEY (ban_id) REFERENCES user_bans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Unban tokens for email-based unbanning';

