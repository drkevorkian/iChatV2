-- Rollback Patch 022: Remove Owner Role and Permission System
-- WARNING: This will remove permission records and backup tracking

DROP TABLE IF EXISTS owner_backup;
DROP TABLE IF EXISTS admin_permissions;

-- Revert role enum (remove owner and trusted_admin)
ALTER TABLE users MODIFY COLUMN role ENUM('guest', 'user', 'moderator', 'administrator') NOT NULL DEFAULT 'user' COMMENT 'User role';

