-- Patch 027: Add Role-Based Access Control (RBAC) System
-- This patch creates the RBAC system allowing Trusted Admins to set permissions
-- for each role (Admin, Moderator, User, Guest), with Owner password protection

-- Permission categories and actions
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

-- Role-based permissions (what each role can do)
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

-- Owner-protected permissions (require password to change)
CREATE TABLE IF NOT EXISTS `rbac_owner_protected` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `permission_id` INT UNSIGNED NOT NULL,
    `role` ENUM('guest', 'user', 'moderator', 'administrator', 'trusted_admin', 'owner') NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL COMMENT 'Bcrypt hash of the password required to change this permission',
    `password_generated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the password was generated',
    `protected_by_user_id` INT UNSIGNED NULL COMMENT 'Owner user ID who protected this permission',
    `protected_by_username` VARCHAR(50) NULL COMMENT 'Owner username who protected this permission',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_protected_permission` (`permission_id`, `role`),
    FOREIGN KEY (`permission_id`) REFERENCES `rbac_permissions`(`id`) ON DELETE CASCADE,
    INDEX `idx_permission` (`permission_id`),
    INDEX `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Owner-protected permissions requiring password to modify';

-- Permission change audit log
CREATE TABLE IF NOT EXISTS `rbac_permission_changes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `permission_id` INT UNSIGNED NOT NULL,
    `role` ENUM('guest', 'user', 'moderator', 'administrator', 'trusted_admin', 'owner') NOT NULL,
    `old_value` TINYINT(1) NULL COMMENT 'Previous permission value',
    `new_value` TINYINT(1) NOT NULL COMMENT 'New permission value',
    `changed_by_user_id` INT UNSIGNED NULL,
    `changed_by_username` VARCHAR(50) NOT NULL,
    `change_reason` TEXT COMMENT 'Reason for the change',
    `password_verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether owner password was verified',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`permission_id`) REFERENCES `rbac_permissions`(`id`) ON DELETE CASCADE,
    INDEX `idx_permission` (`permission_id`),
    INDEX `idx_role` (`role`),
    INDEX `idx_changed_by` (`changed_by_user_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit log for permission changes';

-- Insert default permissions
INSERT INTO `rbac_permissions` (`permission_key`, `category`, `action`, `description`, `default_value`) VALUES
-- Message permissions (say)
('message.send', 'say', 'send', 'Send messages in chat rooms', 1),
('chat.send_message', 'say', 'send', 'Send messages in chat rooms', 1),
('message.edit', 'say', 'edit', 'Edit own messages', 1),
('chat.edit_own_message', 'say', 'edit', 'Edit own messages', 1),
('message.delete', 'say', 'delete', 'Delete own messages', 1),
('chat.delete_own_message', 'say', 'delete', 'Delete own messages', 1),
('message.reply', 'say', 'reply', 'Reply to messages', 1),
('message.mention', 'say', 'mention', 'Mention other users', 1),

-- View permissions (see)
('message.view', 'see', 'view', 'View messages in chat rooms', 1),
('user.view', 'see', 'view', 'View user profiles', 1),
('user.view_online', 'see', 'view_online', 'View online users list', 1),
('admin.view', 'see', 'view', 'View admin dashboard', 0),
('admin.access_dashboard', 'see', 'view', 'Access admin dashboard', 0),
('admin.view_users', 'see', 'view_users', 'View user management', 0),
('admin.view_logs', 'see', 'view_logs', 'View system logs', 0),
('admin.view_audit', 'see', 'view_audit', 'View audit logs', 0),

-- Action permissions (do)
('room.create', 'do', 'create', 'Create new chat rooms', 0),
('room.join', 'do', 'join', 'Join chat rooms', 1),
('room.leave', 'do', 'leave', 'Leave chat rooms', 1),
('room.invite', 'do', 'invite', 'Invite users to rooms', 0),
('room.delete', 'do', 'delete', 'Delete chat rooms', 0),
('user.kick', 'do', 'kick', 'Kick users from rooms', 0),
('moderation.kick_user', 'do', 'kick', 'Kick users from rooms', 0),
('user.mute', 'do', 'mute', 'Mute users', 0),
('moderation.mute_user', 'do', 'mute', 'Mute users', 0),
('user.ban', 'do', 'ban', 'Ban users', 0),
('moderation.ban_user', 'do', 'ban', 'Ban users', 0),
('user.unban', 'do', 'unban', 'Unban users', 0),
('moderation.unban_user', 'do', 'unban', 'Unban users', 0),
('message.moderate', 'do', 'moderate', 'Moderate messages (hide/delete)', 0),
('moderation.hide_message', 'do', 'hide', 'Hide messages from view', 0),
('moderation.delete_message', 'do', 'delete', 'Permanently delete messages', 0),
('moderation.edit_message', 'do', 'edit', 'Edit any message', 0),
('moderation.view_reports', 'do', 'view_reports', 'View user reports', 0),
('message.edit_others', 'do', 'edit_others', 'Edit other users messages', 0),
('user.role_change', 'do', 'role_change', 'Change user roles', 0),
('admin.manage_users', 'do', 'manage_users', 'Manage user roles and details', 0),
('admin.manage_websocket', 'do', 'manage_websocket', 'Start/stop/restart WebSocket servers', 0),

-- Link/Upload permissions
('file.upload', 'link', 'upload', 'Upload files/images', 1),
('chat.upload_media', 'link', 'upload', 'Upload media to chat', 1),
('file.download', 'link', 'download', 'Download files', 1),
('link.share', 'link', 'share', 'Share links in messages', 1),
('image.share', 'link', 'share', 'Share images in messages', 1),
('media.upload', 'link', 'upload', 'Upload media (video/audio)', 0),

-- IM permissions
('im.send', 'say', 'send', 'Send instant messages', 1),
('im.send_im', 'say', 'send', 'Send instant messages', 1),
('im.view', 'see', 'view', 'View instant messages', 1),
('im.view_inbox', 'see', 'view_inbox', 'View IM inbox/conversations', 1),
('im.delete', 'do', 'delete', 'Delete instant messages', 1),

-- Mail permissions
('mail.send', 'say', 'send', 'Send mail messages', 1),
('mail.view', 'see', 'view', 'View mail messages', 1),
('mail.delete', 'do', 'delete', 'Delete mail messages', 1),
('mail.manage_folders', 'do', 'manage_folders', 'Move mail between folders', 1),

-- Presence permissions
('presence.view_online', 'see', 'view_online', 'View online users list', 1),

-- Settings permissions
('settings.change', 'do', 'change', 'Change own settings', 1),
('settings.change_others', 'do', 'change_others', 'Change other users settings', 0),
('profile.edit', 'do', 'edit', 'Edit own profile', 1),
('profile.edit_others', 'do', 'edit_others', 'Edit other users profiles', 0),

-- System permissions
('system.restart_websocket', 'do', 'restart', 'Restart WebSocket servers', 0),
('system.view_status', 'see', 'view', 'View system status', 0),
('rbac.manage', 'do', 'manage', 'Manage RBAC permissions', 0),
('rbac.view', 'see', 'view', 'View RBAC permissions', 0)
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- Set default permissions for each role
-- Guests: minimal permissions
INSERT INTO `rbac_role_permissions` (`role`, `permission_id`, `allowed`, `set_by_username`)
SELECT 'guest', `id`, `default_value`, 'system'
FROM `rbac_permissions`
WHERE `permission_key` IN ('message.view', 'message.send', 'user.view', 'room.join', 'room.leave', 'file.download', 'link.share', 'image.share', 'settings.change', 'profile.edit')
ON DUPLICATE KEY UPDATE `allowed` = VALUES(`allowed`);

-- Users: standard permissions
INSERT INTO `rbac_role_permissions` (`role`, `permission_id`, `allowed`, `set_by_username`)
SELECT 'user', `id`, `default_value`, 'system'
FROM `rbac_permissions`
WHERE `permission_key` IN ('message.send', 'chat.send_message', 'message.edit', 'chat.edit_own_message', 'message.delete', 'chat.delete_own_message', 'message.reply', 'message.mention', 'message.view', 'user.view', 'user.view_online', 'room.join', 'room.leave', 'file.upload', 'chat.upload_media', 'file.download', 'link.share', 'image.share', 'im.send', 'im.send_im', 'im.view', 'im.view_inbox', 'im.delete', 'mail.send', 'mail.view', 'mail.delete', 'mail.manage_folders', 'presence.view_online', 'settings.change', 'profile.edit')
ON DUPLICATE KEY UPDATE `allowed` = VALUES(`allowed`);

-- Moderators: user permissions + moderation
INSERT INTO `rbac_role_permissions` (`role`, `permission_id`, `allowed`, `set_by_username`)
SELECT 'moderator', `id`, 1, 'system'
FROM `rbac_permissions`
WHERE `permission_key` IN ('message.send', 'chat.send_message', 'message.edit', 'chat.edit_own_message', 'message.delete', 'chat.delete_own_message', 'message.reply', 'message.mention', 'message.view', 'user.view', 'user.view_online', 'room.join', 'room.leave', 'file.upload', 'chat.upload_media', 'file.download', 'link.share', 'image.share', 'im.send', 'im.send_im', 'im.view', 'im.view_inbox', 'im.delete', 'mail.send', 'mail.view', 'mail.delete', 'mail.manage_folders', 'presence.view_online', 'settings.change', 'profile.edit', 'message.moderate', 'moderation.hide_message', 'moderation.delete_message', 'moderation.edit_message', 'moderation.view_reports', 'user.kick', 'moderation.kick_user', 'user.mute', 'moderation.mute_user', 'room.invite')
ON DUPLICATE KEY UPDATE `allowed` = VALUES(`allowed`);

-- Administrators: moderator permissions + admin features
INSERT INTO `rbac_role_permissions` (`role`, `permission_id`, `allowed`, `set_by_username`)
SELECT 'administrator', `id`, 1, 'system'
FROM `rbac_permissions`
WHERE `permission_key` IN ('message.send', 'chat.send_message', 'message.edit', 'chat.edit_own_message', 'message.delete', 'chat.delete_own_message', 'message.reply', 'message.mention', 'message.view', 'user.view', 'user.view_online', 'room.join', 'room.leave', 'file.upload', 'chat.upload_media', 'file.download', 'link.share', 'image.share', 'im.send', 'im.send_im', 'im.view', 'im.view_inbox', 'im.delete', 'mail.send', 'mail.view', 'mail.delete', 'mail.manage_folders', 'presence.view_online', 'settings.change', 'profile.edit', 'message.moderate', 'moderation.hide_message', 'moderation.delete_message', 'moderation.edit_message', 'moderation.view_reports', 'user.kick', 'moderation.kick_user', 'user.mute', 'moderation.mute_user', 'user.ban', 'moderation.ban_user', 'user.unban', 'moderation.unban_user', 'room.create', 'room.invite', 'room.delete', 'message.edit_others', 'admin.view', 'admin.access_dashboard', 'admin.view_users', 'admin.view_logs', 'admin.view_audit', 'admin.manage_users', 'admin.manage_websocket', 'system.view_status', 'rbac.view')
ON DUPLICATE KEY UPDATE `allowed` = VALUES(`allowed`);

-- Trusted Admins: administrator permissions + RBAC management
INSERT INTO `rbac_role_permissions` (`role`, `permission_id`, `allowed`, `set_by_username`)
SELECT 'trusted_admin', `id`, 1, 'system'
FROM `rbac_permissions`
WHERE `permission_key` IN ('message.send', 'chat.send_message', 'message.edit', 'chat.edit_own_message', 'message.delete', 'chat.delete_own_message', 'message.reply', 'message.mention', 'message.view', 'user.view', 'user.view_online', 'room.join', 'room.leave', 'file.upload', 'chat.upload_media', 'file.download', 'link.share', 'image.share', 'im.send', 'im.send_im', 'im.view', 'im.view_inbox', 'im.delete', 'mail.send', 'mail.view', 'mail.delete', 'mail.manage_folders', 'presence.view_online', 'settings.change', 'profile.edit', 'message.moderate', 'moderation.hide_message', 'moderation.delete_message', 'moderation.edit_message', 'moderation.view_reports', 'user.kick', 'moderation.kick_user', 'user.mute', 'moderation.mute_user', 'user.ban', 'moderation.ban_user', 'user.unban', 'moderation.unban_user', 'room.create', 'room.invite', 'room.delete', 'message.edit_others', 'admin.view', 'admin.access_dashboard', 'admin.view_users', 'admin.view_logs', 'admin.view_audit', 'admin.manage_users', 'admin.manage_websocket', 'system.view_status', 'rbac.view', 'rbac.manage', 'user.role_change', 'settings.change_others', 'profile.edit_others', 'system.restart_websocket')
ON DUPLICATE KEY UPDATE `allowed` = VALUES(`allowed`);

-- Owners: ALL permissions (set all to 1)
INSERT INTO `rbac_role_permissions` (`role`, `permission_id`, `allowed`, `set_by_username`)
SELECT 'owner', `id`, 1, 'system'
FROM `rbac_permissions`
ON DUPLICATE KEY UPDATE `allowed` = 1;

