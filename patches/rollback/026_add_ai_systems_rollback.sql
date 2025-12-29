-- Rollback Patch 026: Remove AI Systems Support

DROP TABLE IF EXISTS ai_bot_poll_votes;
DROP TABLE IF EXISTS ai_bot_polls;
DROP TABLE IF EXISTS ai_bot_reminders;
DROP TABLE IF EXISTS ai_thread_summaries;
DROP TABLE IF EXISTS ai_smart_replies;
DROP TABLE IF EXISTS ai_moderation_log;
DROP TABLE IF EXISTS ai_systems_config;

