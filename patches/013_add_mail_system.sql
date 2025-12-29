-- Patch: 013_add_mail_system
-- Description: Add comprehensive mail system with folders, subjects, attachments, and threading
-- Author: Sentinel Chat Platform
-- Date: 2025-12-27
-- Dependencies: 000_init_patch_system
-- Rollback: Yes (see patches/rollback/013_add_mail_system_rollback.sql)

-- Drop tables if they exist (for clean re-application during development)
-- Drop in reverse dependency order (drop tables with foreign keys first)
DROP TABLE IF EXISTS mail_message_labels;
DROP TABLE IF EXISTS mail_attachments;
DROP TABLE IF EXISTS mail_labels;
DROP TABLE IF EXISTS mail_messages;

-- Mail Messages table
-- Stores email-like messages with subjects, folders, threading, and flags
CREATE TABLE IF NOT EXISTS mail_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_user VARCHAR(100) NOT NULL COMMENT 'Sender username/handle',
    to_user VARCHAR(100) NOT NULL COMMENT 'Recipient username/handle',
    cc_users TEXT COMMENT 'Comma-separated list of CC recipients',
    bcc_users TEXT COMMENT 'Comma-separated list of BCC recipients',
    subject VARCHAR(500) NOT NULL COMMENT 'Message subject',
    cipher_blob TEXT NOT NULL COMMENT 'Encrypted message body (base64 encoded)',
    folder ENUM('inbox', 'sent', 'drafts', 'trash', 'archive', 'spam') NOT NULL DEFAULT 'inbox' COMMENT 'Message folder',
    status ENUM('draft', 'sent', 'read', 'archived', 'deleted') NOT NULL DEFAULT 'draft' COMMENT 'Message status',
    thread_id BIGINT UNSIGNED NULL COMMENT 'Thread/conversation ID (self-reference for threading)',
    reply_to_id BIGINT UNSIGNED NULL COMMENT 'ID of message this is replying to',
    is_starred BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Starred/important flag',
    is_important BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Important flag',
    has_attachments BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether message has attachments',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When message was created',
    sent_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When message was sent',
    read_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When message was read by recipient',
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    INDEX idx_to_user (to_user),
    INDEX idx_from_user (from_user),
    INDEX idx_folder (folder),
    INDEX idx_status (status),
    INDEX idx_thread_id (thread_id),
    INDEX idx_reply_to_id (reply_to_id),
    INDEX idx_is_starred (is_starred),
    INDEX idx_is_important (is_important),
    INDEX idx_read_at (read_at),
    INDEX idx_deleted (deleted_at),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (thread_id) REFERENCES mail_messages(id) ON DELETE SET NULL,
    FOREIGN KEY (reply_to_id) REFERENCES mail_messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Mail messages';

-- Mail Attachments table
-- Stores file attachments for mail messages
CREATE TABLE IF NOT EXISTS mail_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mail_id BIGINT UNSIGNED NOT NULL COMMENT 'ID of the mail message',
    filename VARCHAR(255) NOT NULL COMMENT 'Original filename',
    file_path VARCHAR(500) NOT NULL COMMENT 'Path to stored file',
    file_size BIGINT UNSIGNED NOT NULL COMMENT 'File size in bytes',
    mime_type VARCHAR(100) COMMENT 'MIME type of the file',
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When file was uploaded',
    INDEX idx_mail_id (mail_id),
    FOREIGN KEY (mail_id) REFERENCES mail_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Mail attachments';

-- Mail Labels table
-- Custom labels/tags for organizing mail messages
CREATE TABLE IF NOT EXISTS mail_labels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_handle VARCHAR(100) NOT NULL COMMENT 'User who owns this label',
    label_name VARCHAR(100) NOT NULL COMMENT 'Label name',
    color VARCHAR(7) COMMENT 'Label color (hex code)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When label was created',
    UNIQUE KEY uk_user_label (user_handle, label_name),
    INDEX idx_user_handle (user_handle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Mail labels';

-- Mail Message Labels junction table
-- Links mail messages to labels
CREATE TABLE IF NOT EXISTS mail_message_labels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mail_id BIGINT UNSIGNED NOT NULL COMMENT 'ID of the mail message',
    label_id BIGINT UNSIGNED NOT NULL COMMENT 'ID of the label',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When label was applied',
    UNIQUE KEY uk_mail_label (mail_id, label_id),
    INDEX idx_mail_id (mail_id),
    INDEX idx_label_id (label_id),
    FOREIGN KEY (mail_id) REFERENCES mail_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (label_id) REFERENCES mail_labels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Mail message labels junction';

-- Create composite indexes for common query patterns
-- These composite indexes improve query performance for common mail operations
-- Since tables are dropped above, these indexes won't exist when created
CREATE INDEX idx_mail_inbox ON mail_messages(to_user, folder, deleted_at, read_at);
CREATE INDEX idx_mail_sent ON mail_messages(from_user, folder, deleted_at, sent_at);
CREATE INDEX idx_mail_unread ON mail_messages(to_user, folder, read_at, deleted_at);

