-- Patch: 020_add_word_filter_requests
-- Description: Add word filter request system for moderators to request word filter changes
-- Author: Sentinel Chat Platform
-- Date: 2025-12-30
-- Dependencies: 010_add_chat_features
-- Rollback: Yes (see patches/rollback/020_add_word_filter_requests_rollback.sql)

-- Word filter requests table - moderators can request word filter changes
CREATE TABLE IF NOT EXISTS word_filter_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_type ENUM('add', 'edit', 'remove') NOT NULL COMMENT 'Type of request',
    filter_id VARCHAR(100) NULL COMMENT 'Original filter ID (for edit/remove requests)',
    word_pattern VARCHAR(255) NULL COMMENT 'Word pattern to add/edit',
    replacement VARCHAR(255) NULL COMMENT 'Replacement text',
    severity TINYINT UNSIGNED NULL COMMENT 'Severity level (1-4)',
    tags JSON NULL COMMENT 'Tags array',
    exceptions JSON NULL COMMENT 'Exception patterns array',
    is_regex BOOLEAN NULL COMMENT 'Whether pattern is regex',
    justification TEXT NOT NULL COMMENT 'Reason for request',
    requester_handle VARCHAR(100) NOT NULL COMMENT 'Moderator requesting the change',
    requester_user_id BIGINT UNSIGNED NULL COMMENT 'User ID of requester',
    status ENUM('pending', 'approved', 'denied') NOT NULL DEFAULT 'pending' COMMENT 'Request status',
    reviewed_by VARCHAR(100) NULL COMMENT 'Admin who reviewed the request',
    reviewed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When request was reviewed',
    review_notes TEXT NULL COMMENT 'Admin notes on review',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When request was created',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'When request was last updated',
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    INDEX idx_request_type (request_type),
    INDEX idx_status (status),
    INDEX idx_requester_handle (requester_handle),
    INDEX idx_filter_id (filter_id),
    INDEX idx_created_at (created_at),
    INDEX idx_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Word filter change requests from moderators';

