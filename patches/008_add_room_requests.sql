-- Patch: 008_add_room_requests
-- Description: Add room request system for members to request private rooms with passwords and invite codes
-- Author: Sentinel Chat Platform
-- Date: 2025-12-22
-- Dependencies: 005_add_authentication_system
-- Rollback: Yes (see patches/rollback/008_add_room_requests_rollback.sql)

-- Room requests table
-- Stores requests from members for private rooms with passwords and invite codes
CREATE TABLE IF NOT EXISTS room_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_name VARCHAR(255) NOT NULL COMMENT 'Requested room name/identifier',
    room_display_name VARCHAR(255) NOT NULL COMMENT 'Display name for the room',
    password_hash VARCHAR(255) NULL COMMENT 'Bcrypt hash of room password (optional)',
    requester_handle VARCHAR(100) NOT NULL COMMENT 'User handle requesting the room',
    requester_user_id BIGINT UNSIGNED NULL COMMENT 'Foreign key to users table if registered user',
    description TEXT NULL COMMENT 'Description/purpose of the room',
    invite_code VARCHAR(32) NULL COMMENT 'Unique invite code for friends to join (generated on approval)',
    status ENUM('pending', 'approved', 'denied', 'active') NOT NULL DEFAULT 'pending' COMMENT 'Request status',
    admin_notes TEXT NULL COMMENT 'Admin notes/reason for approval/denial',
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When request was created',
    reviewed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When request was reviewed by admin',
    reviewed_by VARCHAR(100) NULL COMMENT 'Admin handle who reviewed the request',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation time',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update time',
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    INDEX idx_requester_handle (requester_handle),
    INDEX idx_requester_user_id (requester_user_id),
    INDEX idx_status (status),
    INDEX idx_room_name (room_name),
    INDEX idx_invite_code (invite_code),
    INDEX idx_requested_at (requested_at),
    INDEX idx_deleted (deleted_at),
    FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Room requests from members';

-- Room access control table
-- Tracks which users have access to which rooms (for password-protected and invite-only rooms)
CREATE TABLE IF NOT EXISTS room_access (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_name VARCHAR(255) NOT NULL COMMENT 'Room identifier',
    user_handle VARCHAR(100) NOT NULL COMMENT 'User handle with access',
    user_id BIGINT UNSIGNED NULL COMMENT 'Foreign key to users table if registered user',
    access_type ENUM('owner', 'invited', 'password') NOT NULL DEFAULT 'password' COMMENT 'How access was granted',
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When access was granted',
    expires_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Optional expiration time for access',
    INDEX idx_room_name (room_name),
    INDEX idx_user_handle (user_handle),
    INDEX idx_user_id (user_id),
    INDEX idx_access_type (access_type),
    UNIQUE KEY uk_room_user (room_name, user_handle),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Room access control for private rooms';

