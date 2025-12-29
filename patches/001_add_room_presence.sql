-- Patch: 001_add_room_presence
-- Description: Add room presence table for tracking online users in chat rooms
-- Author: Sentinel Chat Platform
-- Date: 2025-12-21
-- Dependencies: None
-- Rollback: Yes (see patches/rollback/001_add_room_presence_rollback.sql)

-- Room presence table
-- Tracks which users are currently online in each room
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

