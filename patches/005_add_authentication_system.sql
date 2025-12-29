-- Authentication and User Management System
-- Adds user accounts, authentication, roles, and sessions

-- Users table (replaces guest system with registered users)
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE COMMENT 'Unique username/handle',
    email VARCHAR(255) UNIQUE COMMENT 'User email address',
    password_hash VARCHAR(255) NOT NULL COMMENT 'Bcrypt hashed password',
    role ENUM('guest', 'user', 'moderator', 'administrator') NOT NULL DEFAULT 'user' COMMENT 'User role',
    is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Account active status',
    is_verified BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Email verification status',
    verification_token VARCHAR(255) COMMENT 'Email verification token',
    password_reset_token VARCHAR(255) COMMENT 'Password reset token',
    password_reset_expires TIMESTAMP NULL DEFAULT NULL COMMENT 'Password reset expiration',
    last_login TIMESTAMP NULL DEFAULT NULL COMMENT 'Last login timestamp',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Account creation timestamp',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User accounts and authentication';

-- User sessions table (tracks active login sessions)
CREATE TABLE IF NOT EXISTS auth_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'User ID',
    session_token VARCHAR(255) NOT NULL UNIQUE COMMENT 'Session token',
    php_session_id VARCHAR(255) COMMENT 'PHP session ID',
    ip_address VARCHAR(45) NOT NULL COMMENT 'IP address',
    user_agent TEXT COMMENT 'Browser user agent',
    expires_at TIMESTAMP NOT NULL COMMENT 'Session expiration',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Session creation',
    INDEX idx_user_id (user_id),
    INDEX idx_session_token (session_token),
    INDEX idx_php_session_id (php_session_id),
    INDEX idx_expires_at (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User authentication sessions';

-- Update user_registrations to link with users table
-- Add user_id column if it doesn't exist (without foreign key for now)
SET @dbname = DATABASE();
SET @tablename = 'user_registrations';
SET @columnname = 'user_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' BIGINT UNSIGNED COMMENT ''Link to users table'', ADD INDEX idx_user_id (user_id)')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Update user_metadata to link with users table
SET @tablename = 'user_metadata';
SET @columnname = 'user_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' BIGINT UNSIGNED COMMENT ''Link to users table'', ADD INDEX idx_user_id (user_id)')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

