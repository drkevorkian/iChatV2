-- Rollback script for patch 027: Add RBAC System
-- WARNING: This will remove the entire RBAC system including all permission settings
-- Only use this if you need to completely remove RBAC functionality

-- Check if tables exist before trying to delete from them
-- Drop tables first (in reverse order of creation due to foreign keys)
-- This will automatically remove all data due to CASCADE constraints
DROP TABLE IF EXISTS `rbac_permission_changes`;
DROP TABLE IF EXISTS `rbac_owner_protected`;
DROP TABLE IF EXISTS `rbac_role_permissions`;
DROP TABLE IF EXISTS `rbac_permissions`;

