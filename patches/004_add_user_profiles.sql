-- User Profiles System
-- Adds comprehensive user profiles with view/edit modes

-- Extend user_metadata table with profile fields
-- Note: These columns may already exist from patch 003, so we check first

-- Add profile-specific columns if they don't exist
SET @dbname = DATABASE();
SET @tablename = 'user_metadata';

-- Add profile visibility setting
SET @columnname = 'profile_visibility';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' ENUM(''public'', ''private'', ''friends'') NOT NULL DEFAULT ''public'' COMMENT ''Profile visibility setting''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add profile header/banner image
SET @columnname = 'banner_url';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(500) COMMENT ''Profile banner image URL''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add location
SET @columnname = 'location';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(255) COMMENT ''User location''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add website URL
SET @columnname = 'website';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(500) COMMENT ''User website URL''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add join date (when user registered)
SET @columnname = 'join_date';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TIMESTAMP NULL DEFAULT NULL COMMENT ''When user joined/registered''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add status (online, away, busy, offline)
SET @columnname = 'status';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' ENUM(''online'', ''away'', ''busy'', ''offline'') NOT NULL DEFAULT ''offline'' COMMENT ''User status''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add status message
SET @columnname = 'status_message';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(255) COMMENT ''User status message''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Create user registration table to track registered users (vs guests)
CREATE TABLE IF NOT EXISTS user_registrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_handle VARCHAR(100) NOT NULL UNIQUE COMMENT 'User handle/username',
    email VARCHAR(255) COMMENT 'User email (optional)',
    password_hash VARCHAR(255) COMMENT 'Hashed password',
    registration_token VARCHAR(255) COMMENT 'Registration/verification token',
    is_verified BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Email verification status',
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Registration timestamp',
    last_login TIMESTAMP NULL DEFAULT NULL COMMENT 'Last login timestamp',
    INDEX idx_user_handle (user_handle),
    INDEX idx_email (email),
    INDEX idx_registered_at (registered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User registration records';

-- Create profile views tracking table (optional - for analytics)
CREATE TABLE IF NOT EXISTS profile_views (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profile_owner VARCHAR(100) NOT NULL COMMENT 'Profile owner handle',
    viewer_handle VARCHAR(100) COMMENT 'Viewer handle (NULL for anonymous)',
    viewer_ip VARCHAR(45) COMMENT 'Viewer IP address',
    viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When profile was viewed',
    INDEX idx_profile_owner (profile_owner),
    INDEX idx_viewer_handle (viewer_handle),
    INDEX idx_viewed_at (viewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Profile view tracking';

