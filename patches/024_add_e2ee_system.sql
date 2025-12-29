-- Patch 024: Add End-to-End Encryption (E2EE) System
-- Implements libsodium-based E2EE for IM/private messages
-- Users exchange public keys and encrypt messages client-side

-- Create user_keys table to store public keys for E2EE
CREATE TABLE IF NOT EXISTS user_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'References users.id',
    user_handle VARCHAR(100) NOT NULL COMMENT 'User handle for quick lookup',
    public_key VARCHAR(88) NOT NULL COMMENT 'Base64-encoded libsodium public key (32 bytes = 44 chars base64, but we use 88 for box keys)',
    key_type ENUM('box', 'sign') NOT NULL DEFAULT 'box' COMMENT 'Type of key (box for encryption, sign for signatures)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether this key is currently active',
    UNIQUE KEY unique_user_key_type (user_id, key_type),
    INDEX idx_user_handle (user_handle),
    INDEX idx_is_active (is_active),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User public keys for E2EE';

-- Create key_exchanges table to track key exchange requests
CREATE TABLE IF NOT EXISTS key_exchanges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_user_id BIGINT UNSIGNED NOT NULL COMMENT 'User requesting key exchange',
    to_user_id BIGINT UNSIGNED NOT NULL COMMENT 'User being requested',
    from_user_handle VARCHAR(100) NOT NULL,
    to_user_handle VARCHAR(100) NOT NULL,
    public_key VARCHAR(88) NOT NULL COMMENT 'Public key of the requesting user',
    status ENUM('pending', 'accepted', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL COMMENT 'Key exchange expires after 24 hours',
    accepted_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_from_user (from_user_id),
    INDEX idx_to_user (to_user_id),
    INDEX idx_status (status),
    INDEX idx_expires_at (expires_at),
    FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Key exchange requests between users';

-- Add encryption metadata to im_messages table
-- Check if columns exist before adding (MySQL doesn't support IF NOT EXISTS for ALTER TABLE)
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'im_messages' 
    AND COLUMN_NAME = 'encryption_type'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE im_messages ADD COLUMN encryption_type ENUM(\'none\', \'e2ee\') NOT NULL DEFAULT \'none\' COMMENT \'Type of encryption used\'',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'im_messages' 
    AND COLUMN_NAME = 'nonce'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE im_messages ADD COLUMN nonce VARCHAR(32) NULL COMMENT \'Base64-encoded nonce for E2EE (24 bytes = 32 chars base64)\'',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index if it doesn't exist
SET @idx_exists = (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'im_messages' 
    AND INDEX_NAME = 'idx_encryption_type'
);

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE im_messages ADD INDEX idx_encryption_type (encryption_type)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create typing_indicators table for real-time typing status
CREATE TABLE IF NOT EXISTS typing_indicators (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_handle VARCHAR(100) NOT NULL COMMENT 'User who is typing',
    conversation_with VARCHAR(100) NOT NULL COMMENT 'User they are typing to (conversation partner)',
    is_typing BOOLEAN NOT NULL DEFAULT TRUE,
    last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_typing (user_handle, conversation_with),
    INDEX idx_conversation (user_handle, conversation_with),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Real-time typing indicators for IM conversations';

