-- Rollback Patch: 007_add_unique_user_ids
-- Description: Remove unique user ID system tables
-- Use: Run this to undo patch 007_add_unique_user_ids

-- Drop user login sessions table
DROP TABLE IF EXISTS user_login_sessions;

-- Drop IP address registry table
DROP TABLE IF EXISTS ip_address_registry;

