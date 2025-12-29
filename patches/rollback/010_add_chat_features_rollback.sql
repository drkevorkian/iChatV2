-- Rollback: 010_add_chat_features
-- Description: Remove word filter, smileys, ASCII art, and Pinky & Brain bot features
-- WARNING: This will delete all data in these tables!

DROP TABLE IF EXISTS pinky_brain_state;
DROP TABLE IF EXISTS pinky_brain_responses;
DROP TABLE IF EXISTS ascii_art_library;
DROP TABLE IF EXISTS smiley_mappings;
DROP TABLE IF EXISTS word_filter;

