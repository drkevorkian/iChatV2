-- Rollback: 011_add_emoji_library
-- Description: Remove emoji library tables

DROP TABLE IF EXISTS emoji_recent;
DROP TABLE IF EXISTS emoji_favorites;
DROP TABLE IF EXISTS emoji_library;

