-- Database Repair System Patch
-- This patch ensures all required tables exist with correct structure
-- Run this patch to repair any missing or corrupted database tables

-- Ensure temp_outbox table exists
CREATE TABLE IF NOT EXISTS temp_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(255) NOT NULL COMMENT 'Room identifier',
    sender_handle VARCHAR(100) NOT NULL COMMENT 'Sender username/handle',
    cipher_blob TEXT NOT NULL COMMENT 'Encrypted message data (base64 encoded)',
    filter_version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Word filter version used',
    queued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When message was queued',
    delivered_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When message was delivered to primary server',
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    INDEX idx_room_id (room_id),
    INDEX idx_delivered (delivered_at),
    INDEX idx_deleted (deleted_at),
    INDEX idx_queued (queued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Temporary message outbox';

-- Ensure im_messages table exists
CREATE TABLE IF NOT EXISTS im_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_user VARCHAR(100) NOT NULL COMMENT 'Sender username/handle',
    to_user VARCHAR(100) NOT NULL COMMENT 'Recipient username/handle',
    folder ENUM('inbox', 'sent') NOT NULL DEFAULT 'inbox' COMMENT 'Message folder',
    status ENUM('queued', 'sent', 'read') NOT NULL DEFAULT 'queued' COMMENT 'Message status',
    cipher_blob TEXT NOT NULL COMMENT 'Encrypted message data (base64 encoded)',
    queued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When message was queued',
    sent_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When message was sent to primary server',
    read_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When message was read by recipient',
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    INDEX idx_to_user (to_user),
    INDEX idx_from_user (from_user),
    INDEX idx_folder (folder),
    INDEX idx_status (status),
    INDEX idx_read_at (read_at),
    INDEX idx_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Instant messages';

-- Ensure admin_escrow_requests table exists
CREATE TABLE IF NOT EXISTS admin_escrow_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(255) NOT NULL COMMENT 'Room identifier',
    operator_handle VARCHAR(100) NOT NULL COMMENT 'Operator requesting access',
    justification TEXT NOT NULL COMMENT 'Reason for escrow request',
    status ENUM('pending', 'approved', 'denied') NOT NULL DEFAULT 'pending' COMMENT 'Request status',
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When request was created',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'When request was last updated',
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    INDEX idx_room_id (room_id),
    INDEX idx_status (status),
    INDEX idx_requested_at (requested_at),
    INDEX idx_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Key escrow requests';

-- Ensure room_presence table exists
CREATE TABLE IF NOT EXISTS room_presence (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(255) NOT NULL COMMENT 'Room identifier',
    user_handle VARCHAR(100) NOT NULL COMMENT 'User handle/username',
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last heartbeat timestamp',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When user first joined room',
    UNIQUE KEY uk_room_user (room_id, user_handle),
    INDEX idx_room_id (room_id),
    INDEX idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User presence in rooms';

-- Ensure patch_history table exists
CREATE TABLE IF NOT EXISTS patch_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patch_id VARCHAR(100) NOT NULL COMMENT 'Patch identifier',
    version VARCHAR(20) NOT NULL DEFAULT '1.0.0' COMMENT 'Patch version',
    description TEXT COMMENT 'Patch description',
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When patch was applied',
    duration DECIMAL(10,3) COMMENT 'Execution duration in seconds',
    patch_info TEXT COMMENT 'Full patch information (JSON)',
    UNIQUE KEY uk_patch_id (patch_id),
    INDEX idx_applied_at (applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Applied patch history';

