-- Patch 023: Add Audit Logging System
-- Comprehensive audit trail for compliance (SOC2, HIPAA, GDPR, ISO 27001)
-- Logs all significant user actions with full context

-- Create audit_log table for comprehensive action tracking
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the action occurred',
    user_id BIGINT UNSIGNED NULL COMMENT 'User ID if registered user (references users.id)',
    user_handle VARCHAR(100) NOT NULL COMMENT 'User handle (works for both registered and guest users)',
    action_type VARCHAR(50) NOT NULL COMMENT 'Type of action (login, logout, message_send, message_edit, message_delete, file_upload, file_download, room_join, room_leave, admin_change, moderation_action, etc.)',
    action_category ENUM('authentication', 'message', 'file', 'room', 'admin', 'moderation', 'system', 'other') NOT NULL DEFAULT 'other' COMMENT 'Category for filtering',
    resource_type VARCHAR(50) NULL COMMENT 'Type of resource affected (message, file, room, user, etc.)',
    resource_id VARCHAR(255) NULL COMMENT 'ID of the affected resource',
    ip_address VARCHAR(45) NULL COMMENT 'IPv4 or IPv6 address',
    user_agent TEXT NULL COMMENT 'User agent string',
    session_id VARCHAR(255) NULL COMMENT 'Session identifier',
    success BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether the action succeeded',
    error_message TEXT NULL COMMENT 'Error message if action failed',
    before_value TEXT NULL COMMENT 'JSON of state before change (for edits/deletes)',
    after_value TEXT NULL COMMENT 'JSON of state after change (for edits/creates)',
    metadata TEXT NULL COMMENT 'Additional JSON metadata (room_id, file_size, message_length, etc.)',
    INDEX idx_timestamp (timestamp),
    INDEX idx_user_handle (user_handle),
    INDEX idx_user_id (user_id),
    INDEX idx_action_type (action_type),
    INDEX idx_action_category (action_category),
    INDEX idx_resource (resource_type, resource_id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_session_id (session_id),
    INDEX idx_success (success),
    INDEX idx_timestamp_user (timestamp, user_handle),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Comprehensive audit trail for all user actions';

-- Create audit_retention_policy table for configurable retention
CREATE TABLE IF NOT EXISTS audit_retention_policy (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    policy_name VARCHAR(100) NOT NULL UNIQUE COMMENT 'Name of the retention policy',
    action_category ENUM('authentication', 'message', 'file', 'room', 'admin', 'moderation', 'system', 'other', 'all') NOT NULL DEFAULT 'all' COMMENT 'Category this policy applies to',
    action_type VARCHAR(50) NULL COMMENT 'Specific action type (NULL = all in category)',
    retention_days INT UNSIGNED NOT NULL COMMENT 'Number of days to retain logs',
    auto_purge BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether to automatically purge after retention period',
    legal_hold BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'If true, prevents deletion even after retention period',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (action_category),
    INDEX idx_action_type (action_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configurable retention policies for audit logs';

-- Insert default retention policies (7 years for compliance, 90 days for general)
INSERT INTO audit_retention_policy (policy_name, action_category, retention_days, auto_purge, legal_hold) VALUES
('default_all', 'all', 2555, TRUE, FALSE), -- 7 years default
('authentication', 'authentication', 2555, TRUE, FALSE), -- 7 years for auth
('admin_actions', 'admin', 2555, TRUE, FALSE), -- 7 years for admin actions
('moderation', 'moderation', 2555, TRUE, FALSE), -- 7 years for moderation
('messages', 'message', 90, TRUE, FALSE), -- 90 days for messages (can be extended)
('files', 'file', 365, TRUE, FALSE), -- 1 year for file operations
('rooms', 'room', 90, TRUE, FALSE) -- 90 days for room operations
ON DUPLICATE KEY UPDATE retention_days = VALUES(retention_days);

