-- Rollback Patch: 014_add_user_settings
-- Description: Remove user settings and chat media tables
-- Use: Run this to undo patch 014_add_user_settings

-- Drop in reverse dependency order (drop tables with foreign keys first)
DROP TABLE IF EXISTS chat_media;
DROP TABLE IF EXISTS user_settings;

