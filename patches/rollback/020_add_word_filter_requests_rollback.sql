-- Rollback Patch: 020_add_word_filter_requests
-- Description: Remove word filter request system
-- Use: Run this to undo patch 020_add_word_filter_requests

-- Drop word filter requests table
DROP TABLE IF EXISTS word_filter_requests;

