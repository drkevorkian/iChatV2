-- Patch: 000_init_patch_system
-- Description: Initialize patch tracking system (creates patch_history table)
-- Author: Sentinel Chat Platform
-- Date: 2025-12-21
-- Dependencies: None
-- Rollback: No (system table)

-- Patch history table
-- Tracks applied database patches/migrations
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

