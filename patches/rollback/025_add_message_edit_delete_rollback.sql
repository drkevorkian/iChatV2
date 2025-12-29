-- Rollback Patch 025: Remove Message Editing & Deletion Support

ALTER TABLE temp_outbox
DROP COLUMN IF EXISTS edited_at,
DROP COLUMN IF EXISTS edited_by,
DROP COLUMN IF EXISTS edit_count,
DROP COLUMN IF EXISTS is_permanent,
DROP COLUMN IF EXISTS edit_disabled;

ALTER TABLE im_messages
DROP COLUMN IF EXISTS edited_at,
DROP COLUMN IF EXISTS edited_by,
DROP COLUMN IF EXISTS edit_count,
DROP COLUMN IF EXISTS is_permanent,
DROP COLUMN IF EXISTS edit_disabled;

DROP TABLE IF EXISTS message_edit_archive;
DROP PROCEDURE IF EXISTS update_permanent_messages;

