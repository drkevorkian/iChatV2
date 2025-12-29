-- Patch: 009_add_user_reports
-- Description: Add user reporting system for reporting inappropriate behavior
-- Author: Sentinel Chat Platform
-- Date: 2025-12-24
-- Dependencies: 005_add_authentication_system
-- Rollback: Yes (see patches/rollback/009_add_user_reports_rollback.sql)

-- User reports table
-- Stores reports from users about inappropriate behavior, spam, harassment, etc.
CREATE TABLE IF NOT EXISTS user_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reported_user_handle VARCHAR(100) NOT NULL COMMENT 'Handle of the user being reported',
    reported_user_id BIGINT UNSIGNED NULL COMMENT 'Foreign key to users table if registered user',
    reporter_handle VARCHAR(100) NOT NULL COMMENT 'Handle of the user making the report',
    reporter_user_id BIGINT UNSIGNED NULL COMMENT 'Foreign key to users table if registered user',
    report_type ENUM('spam', 'harassment', 'inappropriate_content', 'impersonation', 'other') NOT NULL DEFAULT 'other' COMMENT 'Type of report',
    report_reason TEXT NOT NULL COMMENT 'Reason for the report',
    room_id VARCHAR(255) NULL COMMENT 'Room where the incident occurred',
    message_id BIGINT UNSIGNED NULL COMMENT 'Message ID if report is about a specific message',
    status ENUM('pending', 'reviewed', 'resolved', 'dismissed') NOT NULL DEFAULT 'pending' COMMENT 'Report status',
    admin_notes TEXT NULL COMMENT 'Admin notes/review comments',
    reviewed_by VARCHAR(100) NULL COMMENT 'Admin handle who reviewed the report',
    reviewed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When report was reviewed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When report was created',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update time',
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    INDEX idx_reported_user_handle (reported_user_handle),
    INDEX idx_reported_user_id (reported_user_id),
    INDEX idx_reporter_handle (reporter_handle),
    INDEX idx_reporter_user_id (reporter_user_id),
    INDEX idx_status (status),
    INDEX idx_report_type (report_type),
    INDEX idx_room_id (room_id),
    INDEX idx_created_at (created_at),
    INDEX idx_deleted (deleted_at),
    FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User reports for moderation';

