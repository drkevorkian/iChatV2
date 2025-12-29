-- Rollback Patch: 001_add_room_presence
-- Description: Remove room presence table
-- Use: Run this to undo patch 001_add_room_presence

DROP TABLE IF EXISTS room_presence;

