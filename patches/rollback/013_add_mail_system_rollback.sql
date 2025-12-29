-- Rollback Patch: 013_add_mail_system
-- Description: Remove mail system tables
-- Use: Run this to undo patch 013_add_mail_system

-- Drop in reverse dependency order (drop tables with foreign keys first)
DROP TABLE IF EXISTS mail_message_labels;
DROP TABLE IF EXISTS mail_attachments;
DROP TABLE IF EXISTS mail_labels;
DROP TABLE IF EXISTS mail_messages;

