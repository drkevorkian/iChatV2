-- Patch 022: Add Owner Role and Permission System
-- Adds Owner role, Trusted Admin role, and permission management system

-- Modify users table to add Owner and Trusted Admin roles
-- Check if column exists and modify it
ALTER TABLE users MODIFY COLUMN role ENUM('guest', 'user', 'moderator', 'administrator', 'trusted_admin', 'owner') NOT NULL DEFAULT 'user' COMMENT 'User role';

-- Create admin_permissions table for Owner to manage Admin/Trusted Admin permissions
CREATE TABLE IF NOT EXISTS admin_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'References users.id',
    permission_type VARCHAR(50) NOT NULL COMMENT 'Type of permission (view_passwords, change_passwords, manage_users, etc.)',
    granted BOOLEAN NOT NULL DEFAULT TRUE,
    granted_by BIGINT UNSIGNED NOT NULL COMMENT 'Owner who granted this permission',
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    revoked_by BIGINT UNSIGNED NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_permission (user_id, permission_type),
    INDEX idx_permission_type (permission_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Permissions granted to Admin and Trusted Admin users by Owner';

-- Create owner_backup table to track backed up files
CREATE TABLE IF NOT EXISTS owner_backup (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_file_id BIGINT UNSIGNED NULL COMMENT 'Reference to user_gallery or chat_media',
    file_type ENUM('image', 'voice', 'video') NOT NULL,
    uploader_handle VARCHAR(100) NOT NULL,
    uploader_email_hash VARCHAR(64) NOT NULL COMMENT 'MD5 hash of uploader email',
    backup_filename VARCHAR(500) NOT NULL COMMENT 'Filename format: username_md5(email)_date-time.ext',
    backup_path TEXT NOT NULL COMMENT 'Full path to backup file',
    original_path TEXT NULL COMMENT 'Original file path if available',
    uploaded_at TIMESTAMP NOT NULL COMMENT 'When original file was uploaded',
    backed_up_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When backup was created',
    file_size BIGINT UNSIGNED NULL COMMENT 'File size in bytes',
    INDEX idx_uploader (uploader_handle),
    INDEX idx_uploaded_at (uploaded_at),
    INDEX idx_file_type (file_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Backup of all user uploads for Owner review';

