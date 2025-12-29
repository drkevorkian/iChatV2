-- Patch 025: Add Message Editing & Deletion Support
-- Adds columns to temp_outbox and im_messages for editing/deleting messages
-- Messages older than 24 hours 5 minutes are permanent (cannot be edited/deleted)
-- Message edits are archived (up to 100 edits per message)

-- Create message_edit_archive table to store edit history
CREATE TABLE IF NOT EXISTS message_edit_archive (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL COMMENT 'ID of the message that was edited',
    message_type ENUM('room', 'im') NOT NULL COMMENT 'Type of message (room or im)',
    archive_number INT UNSIGNED NOT NULL COMMENT 'Edit number (1-100)',
    cipher_blob TEXT NOT NULL COMMENT 'Archived message content',
    archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this version was archived',
    archived_by VARCHAR(100) NULL DEFAULT NULL COMMENT 'User who made this edit',
    INDEX idx_message (message_id, message_type),
    INDEX idx_archive_number (archive_number),
    INDEX idx_archived_at (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Archive of message edits (up to 100 per message)';

-- Add edit columns to temp_outbox (room messages)
-- Check if columns exist before adding (MySQL doesn't support IF NOT EXISTS for ALTER TABLE)

-- Add edited_at column to temp_outbox
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'temp_outbox' 
    AND COLUMN_NAME = 'edited_at'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE temp_outbox ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL COMMENT \'When message was last edited\'',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add edited_by column to temp_outbox
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'temp_outbox' 
    AND COLUMN_NAME = 'edited_by'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE temp_outbox ADD COLUMN edited_by VARCHAR(100) NULL DEFAULT NULL COMMENT \'User who edited the message\'',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add edit_count column to temp_outbox
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'temp_outbox' 
    AND COLUMN_NAME = 'edit_count'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE temp_outbox ADD COLUMN edit_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'Number of times this message has been edited (max 100)\'',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add is_permanent column to temp_outbox
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'temp_outbox' 
    AND COLUMN_NAME = 'is_permanent'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE temp_outbox ADD COLUMN is_permanent BOOLEAN NOT NULL DEFAULT FALSE COMMENT \'True if message is older than 24h5m and cannot be edited/deleted\'',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add edit_disabled column to temp_outbox
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'temp_outbox' 
    AND COLUMN_NAME = 'edit_disabled'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE temp_outbox ADD COLUMN edit_disabled BOOLEAN NOT NULL DEFAULT FALSE COMMENT \'True if edit count reached 100 and editing is disabled\'',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add indexes to temp_outbox (check if they exist first)
SET @idx_exists = (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'temp_outbox' 
    AND INDEX_NAME = 'idx_edited_at'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE temp_outbox ADD INDEX idx_edited_at (edited_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'temp_outbox' 
    AND INDEX_NAME = 'idx_is_permanent'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE temp_outbox ADD INDEX idx_is_permanent (is_permanent)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'temp_outbox' 
    AND INDEX_NAME = 'idx_edit_count'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE temp_outbox ADD INDEX idx_edit_count (edit_count)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'temp_outbox' 
    AND INDEX_NAME = 'idx_edit_disabled'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE temp_outbox ADD INDEX idx_edit_disabled (edit_disabled)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add edit columns to im_messages (private messages)
-- Check if columns exist before adding

-- Add edited_at column to im_messages
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'im_messages' 
    AND COLUMN_NAME = 'edited_at'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE im_messages ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL COMMENT \'When message was last edited\'',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add edited_by column to im_messages
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'im_messages' 
    AND COLUMN_NAME = 'edited_by'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE im_messages ADD COLUMN edited_by VARCHAR(100) NULL DEFAULT NULL COMMENT \'User who edited the message\'',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add edit_count column to im_messages
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'im_messages' 
    AND COLUMN_NAME = 'edit_count'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE im_messages ADD COLUMN edit_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'Number of times this message has been edited (max 100)\'',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add is_permanent column to im_messages
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'im_messages' 
    AND COLUMN_NAME = 'is_permanent'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE im_messages ADD COLUMN is_permanent BOOLEAN NOT NULL DEFAULT FALSE COMMENT \'True if message is older than 24h5m and cannot be edited/deleted\'',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add edit_disabled column to im_messages
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'im_messages' 
    AND COLUMN_NAME = 'edit_disabled'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE im_messages ADD COLUMN edit_disabled BOOLEAN NOT NULL DEFAULT FALSE COMMENT \'True if edit count reached 100 and editing is disabled\'',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add indexes to im_messages (check if they exist first)
SET @idx_exists = (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'im_messages' 
    AND INDEX_NAME = 'idx_edited_at'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE im_messages ADD INDEX idx_edited_at (edited_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'im_messages' 
    AND INDEX_NAME = 'idx_is_permanent'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE im_messages ADD INDEX idx_is_permanent (is_permanent)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'im_messages' 
    AND INDEX_NAME = 'idx_edit_count'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE im_messages ADD INDEX idx_edit_count (edit_count)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'im_messages' 
    AND INDEX_NAME = 'idx_edit_disabled'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE im_messages ADD INDEX idx_edit_disabled (edit_disabled)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create a stored procedure to update is_permanent flag
-- This should be called periodically (e.g., via cron) to mark old messages as permanent
-- Drop procedure if it exists (to allow re-application of patch)
DROP PROCEDURE IF EXISTS update_permanent_messages;

-- Create the procedure using a prepared statement to avoid statement splitting issues
-- The entire procedure body is built as a string to prevent the SQL splitter from breaking it
SET @proc_sql = CONCAT(
    'CREATE PROCEDURE update_permanent_messages() ',
    'BEGIN ',
    'UPDATE temp_outbox SET is_permanent = TRUE WHERE is_permanent = FALSE AND queued_at < DATE_SUB(NOW(), INTERVAL 24 HOUR + INTERVAL 5 MINUTE); ',
    'UPDATE im_messages SET is_permanent = TRUE WHERE is_permanent = FALSE AND queued_at < DATE_SUB(NOW(), INTERVAL 24 HOUR + INTERVAL 5 MINUTE); ',
    'END'
);
PREPARE proc_stmt FROM @proc_sql;
EXECUTE proc_stmt;
DEALLOCATE PREPARE proc_stmt;
