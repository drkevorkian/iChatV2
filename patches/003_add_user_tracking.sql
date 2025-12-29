-- User Tracking and Management System
-- Adds IP tracking, geolocation, avatars, and moderation capabilities

-- User sessions table (tracks active users with IP and metadata)
CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_handle VARCHAR(100) NOT NULL COMMENT 'User handle/username',
    session_id VARCHAR(255) NOT NULL COMMENT 'PHP session ID',
    ip_address VARCHAR(45) NOT NULL COMMENT 'IPv4 or IPv6 address',
    user_agent TEXT COMMENT 'Browser user agent',
    current_room VARCHAR(255) COMMENT 'Current room user is in',
    last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last activity timestamp',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When session started',
    INDEX idx_user_handle (user_handle),
    INDEX idx_session_id (session_id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_current_room (current_room),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User session tracking';

-- User metadata table (avatars, preferences, etc.)
CREATE TABLE IF NOT EXISTS user_metadata (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_handle VARCHAR(100) NOT NULL UNIQUE COMMENT 'User handle/username',
    avatar_url VARCHAR(500) COMMENT 'Avatar image URL',
    avatar_data TEXT COMMENT 'Base64 encoded avatar data',
    display_name VARCHAR(100) COMMENT 'Display name (different from handle)',
    bio TEXT COMMENT 'User bio/description',
    preferences JSON COMMENT 'User preferences (JSON)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When metadata was created',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'When metadata was last updated',
    INDEX idx_user_handle (user_handle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User metadata and preferences';

-- User bans table
CREATE TABLE IF NOT EXISTS user_bans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_handle VARCHAR(100) NOT NULL COMMENT 'Banned user handle',
    ip_address VARCHAR(45) COMMENT 'Banned IP address (optional)',
    banned_by VARCHAR(100) NOT NULL COMMENT 'Admin/moderator who issued ban',
    reason TEXT NOT NULL COMMENT 'Reason for ban',
    expires_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When ban expires (NULL = permanent)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When ban was created',
    INDEX idx_user_handle (user_handle),
    INDEX idx_ip_address (ip_address),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User ban records';

-- User mutes table
CREATE TABLE IF NOT EXISTS user_mutes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_handle VARCHAR(100) NOT NULL COMMENT 'Muted user handle',
    muted_by VARCHAR(100) NOT NULL COMMENT 'Admin/moderator who issued mute',
    reason TEXT COMMENT 'Reason for mute',
    expires_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When mute expires (NULL = permanent)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When mute was created',
    INDEX idx_user_handle (user_handle),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User mute records';

-- Update room_presence to include IP address
-- Note: MySQL doesn't support IF NOT EXISTS for ALTER TABLE ADD COLUMN
-- We check if columns exist first, then add them conditionally

-- Check and add ip_address column
SET @dbname = DATABASE();
SET @tablename = 'room_presence';
SET @columnname = 'ip_address';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(45) COMMENT ''User IP address''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Check and add session_id column
SET @columnname = 'session_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(255) COMMENT ''PHP session ID''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Check and add index if it doesn't exist
SET @indexname = 'idx_ip_address';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (index_name = @indexname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX ', @indexname, ' (ip_address)')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

