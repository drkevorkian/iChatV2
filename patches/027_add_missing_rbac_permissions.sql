-- Migration: Add Missing RBAC Permissions
-- This script adds the missing permission keys that were added to patch 027
-- after it was already applied. Run this if you applied patch 027 before the
-- permission keys were updated.
-- 
-- NOTE: This script assumes the RBAC tables already exist. If you get an error
-- that tables don't exist, you need to apply patch 027 first.

-- Check if rbac_permissions table exists, if not, create it
CREATE TABLE IF NOT EXISTS `rbac_permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `permission_key` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Unique permission identifier (e.g., "message.send", "admin.view_users")',
    `category` VARCHAR(50) NOT NULL COMMENT 'Permission category (see, say, do, link, etc.)',
    `action` VARCHAR(50) NOT NULL COMMENT 'Specific action (send, view, edit, delete, etc.)',
    `description` TEXT COMMENT 'Human-readable description of the permission',
    `default_value` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Default permission value (0=deny, 1=allow)',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_category` (`category`),
    INDEX `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RBAC permission definitions';

-- Check if rbac_role_permissions table exists, if not, create it
CREATE TABLE IF NOT EXISTS `rbac_role_permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `role` ENUM('guest', 'user', 'moderator', 'administrator', 'trusted_admin', 'owner') NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    `allowed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=deny, 1=allow',
    `set_by_user_id` INT UNSIGNED NULL COMMENT 'User ID who set this permission',
    `set_by_username` VARCHAR(50) NULL COMMENT 'Username who set this permission',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_role_permission` (`role`, `permission_id`),
    FOREIGN KEY (`permission_id`) REFERENCES `rbac_permissions`(`id`) ON DELETE CASCADE,
    INDEX `idx_role` (`role`),
    INDEX `idx_permission` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Role-based permission assignments';

-- Add missing permission keys (using INSERT IGNORE to avoid duplicates)
INSERT IGNORE INTO `rbac_permissions` (`permission_key`, `category`, `action`, `description`, `default_value`) VALUES
-- Chat permissions (aliases for message permissions)
('chat.send_message', 'say', 'send', 'Send messages in chat rooms', 1),
('chat.edit_own_message', 'say', 'edit', 'Edit own messages', 1),
('chat.delete_own_message', 'say', 'delete', 'Delete own messages', 1),
('chat.upload_media', 'link', 'upload', 'Upload media to chat', 1),

-- Admin permissions
('admin.access_dashboard', 'see', 'view', 'Access admin dashboard', 0),
('admin.manage_users', 'do', 'manage_users', 'Manage user roles and details', 0),
('admin.manage_websocket', 'do', 'manage_websocket', 'Start/stop/restart WebSocket servers', 0),

-- Moderation permissions (aliases for user permissions)
('moderation.kick_user', 'do', 'kick', 'Kick users from rooms', 0),
('moderation.mute_user', 'do', 'mute', 'Mute users', 0),
('moderation.ban_user', 'do', 'ban', 'Ban users', 0),
('moderation.unban_user', 'do', 'unban', 'Unban users', 0),
('moderation.hide_message', 'do', 'hide', 'Hide messages from view', 0),
('moderation.delete_message', 'do', 'delete', 'Permanently delete messages', 0),
('moderation.edit_message', 'do', 'edit', 'Edit any message', 0),
('moderation.view_reports', 'do', 'view_reports', 'View user reports', 0),

-- IM permissions (alias)
('im.send_im', 'say', 'send', 'Send instant messages', 1);

-- Update role permissions for Users
INSERT INTO `rbac_role_permissions` (`role`, `permission_id`, `allowed`, `set_by_username`)
SELECT 'user', `id`, `default_value`, 'system'
FROM `rbac_permissions`
WHERE `permission_key` IN ('chat.send_message', 'chat.edit_own_message', 'chat.delete_own_message', 'chat.upload_media', 'im.send_im')
ON DUPLICATE KEY UPDATE `allowed` = VALUES(`allowed`);

-- Update role permissions for Moderators
INSERT INTO `rbac_role_permissions` (`role`, `permission_id`, `allowed`, `set_by_username`)
SELECT 'moderator', `id`, 1, 'system'
FROM `rbac_permissions`
WHERE `permission_key` IN ('chat.send_message', 'chat.edit_own_message', 'chat.delete_own_message', 'chat.upload_media', 'im.send_im', 'moderation.hide_message', 'moderation.delete_message', 'moderation.edit_message', 'moderation.view_reports', 'moderation.kick_user', 'moderation.mute_user')
ON DUPLICATE KEY UPDATE `allowed` = VALUES(`allowed`);

-- Update role permissions for Administrators
INSERT INTO `rbac_role_permissions` (`role`, `permission_id`, `allowed`, `set_by_username`)
SELECT 'administrator', `id`, 1, 'system'
FROM `rbac_permissions`
WHERE `permission_key` IN ('chat.send_message', 'chat.edit_own_message', 'chat.delete_own_message', 'chat.upload_media', 'im.send_im', 'moderation.hide_message', 'moderation.delete_message', 'moderation.edit_message', 'moderation.view_reports', 'moderation.kick_user', 'moderation.mute_user', 'moderation.ban_user', 'moderation.unban_user', 'admin.access_dashboard', 'admin.manage_users', 'admin.manage_websocket')
ON DUPLICATE KEY UPDATE `allowed` = VALUES(`allowed`);

-- Update role permissions for Trusted Admins
INSERT INTO `rbac_role_permissions` (`role`, `permission_id`, `allowed`, `set_by_username`)
SELECT 'trusted_admin', `id`, 1, 'system'
FROM `rbac_permissions`
WHERE `permission_key` IN ('chat.send_message', 'chat.edit_own_message', 'chat.delete_own_message', 'chat.upload_media', 'im.send_im', 'moderation.hide_message', 'moderation.delete_message', 'moderation.edit_message', 'moderation.view_reports', 'moderation.kick_user', 'moderation.mute_user', 'moderation.ban_user', 'moderation.unban_user', 'admin.access_dashboard', 'admin.manage_users', 'admin.manage_websocket')
ON DUPLICATE KEY UPDATE `allowed` = VALUES(`allowed`);

-- Owners already have all permissions, but ensure new ones are added
INSERT INTO `rbac_role_permissions` (`role`, `permission_id`, `allowed`, `set_by_username`)
SELECT 'owner', `id`, 1, 'system'
FROM `rbac_permissions`
WHERE `permission_key` IN ('chat.send_message', 'chat.edit_own_message', 'chat.delete_own_message', 'chat.upload_media', 'im.send_im', 'moderation.hide_message', 'moderation.delete_message', 'moderation.edit_message', 'moderation.view_reports', 'moderation.kick_user', 'moderation.mute_user', 'moderation.ban_user', 'moderation.unban_user', 'admin.access_dashboard', 'admin.manage_users', 'admin.manage_websocket')
ON DUPLICATE KEY UPDATE `allowed` = 1;

