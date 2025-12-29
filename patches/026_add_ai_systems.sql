-- Patch 026: Add AI Systems Support
-- Creates tables for AI-powered features: auto-moderation, smart replies, thread summarization, and bot features

-- AI Systems Configuration
CREATE TABLE IF NOT EXISTS ai_systems_config (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    system_name VARCHAR(100) NOT NULL UNIQUE COMMENT 'Name of the AI system (e.g., moderation, smart_replies, summarization, bot)',
    enabled BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether this AI system is enabled',
    provider VARCHAR(50) NULL DEFAULT NULL COMMENT 'AI provider (e.g., openai, anthropic, local, custom)',
    api_key_encrypted TEXT NULL DEFAULT NULL COMMENT 'Encrypted API key for the provider',
    model_name VARCHAR(100) NULL DEFAULT NULL COMMENT 'Model name/identifier',
    config_json JSON NULL DEFAULT NULL COMMENT 'Additional configuration (JSON)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_system_name (system_name),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configuration for AI systems';

-- Auto-Moderation Log
CREATE TABLE IF NOT EXISTS ai_moderation_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NULL COMMENT 'ID of the moderated message (room or IM)',
    message_type ENUM('room', 'im') NOT NULL COMMENT 'Type of message',
    user_handle VARCHAR(100) NOT NULL COMMENT 'User who sent the message',
    message_content TEXT NOT NULL COMMENT 'Content that was moderated',
    flagged_words TEXT NULL DEFAULT NULL COMMENT 'Comma-separated list of flagged words',
    toxicity_score DECIMAL(5,4) NULL DEFAULT NULL COMMENT 'Toxicity score (0.0-1.0)',
    moderation_action ENUM('flag', 'warn', 'hide', 'delete', 'none') NOT NULL DEFAULT 'flag' COMMENT 'Action taken',
    ai_provider VARCHAR(50) NULL DEFAULT NULL COMMENT 'AI provider used',
    ai_model VARCHAR(100) NULL DEFAULT NULL COMMENT 'AI model used',
    ai_response JSON NULL DEFAULT NULL COMMENT 'Full AI response (JSON)',
    reviewed_by VARCHAR(100) NULL DEFAULT NULL COMMENT 'Moderator who reviewed this',
    reviewed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When this was reviewed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message (message_id, message_type),
    INDEX idx_user_handle (user_handle),
    INDEX idx_moderation_action (moderation_action),
    INDEX idx_created_at (created_at),
    INDEX idx_reviewed (reviewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log of AI auto-moderation actions';

-- Smart Replies Cache
CREATE TABLE IF NOT EXISTS ai_smart_replies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id VARCHAR(255) NOT NULL COMMENT 'Conversation identifier (room_id or user_pair)',
    conversation_type ENUM('room', 'im') NOT NULL COMMENT 'Type of conversation',
    context_messages TEXT NOT NULL COMMENT 'Recent messages for context (JSON array)',
    suggested_replies JSON NOT NULL COMMENT 'AI-generated reply suggestions (JSON array)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL COMMENT 'When this cache entry expires',
    INDEX idx_conversation (conversation_id, conversation_type),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cached smart reply suggestions';

-- Thread Summaries
CREATE TABLE IF NOT EXISTS ai_thread_summaries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id VARCHAR(255) NOT NULL COMMENT 'Thread identifier (room_id or conversation_id)',
    thread_type ENUM('room', 'im', 'thread') NOT NULL COMMENT 'Type of thread',
    message_count INT UNSIGNED NOT NULL COMMENT 'Number of messages summarized',
    summary_text TEXT NOT NULL COMMENT 'AI-generated summary',
    key_points JSON NULL DEFAULT NULL COMMENT 'Key points extracted (JSON array)',
    participants JSON NULL DEFAULT NULL COMMENT 'List of participants (JSON array)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_thread (thread_id, thread_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI-generated thread summaries';

-- AI Bot Commands & Reminders
CREATE TABLE IF NOT EXISTS ai_bot_reminders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_handle VARCHAR(100) NOT NULL COMMENT 'User who created the reminder',
    reminder_text TEXT NOT NULL COMMENT 'Reminder message',
    reminder_time TIMESTAMP NOT NULL COMMENT 'When to send the reminder',
    room_id VARCHAR(255) NULL DEFAULT NULL COMMENT 'Room to send reminder in (if applicable)',
    conversation_with VARCHAR(100) NULL DEFAULT NULL COMMENT 'IM conversation partner (if applicable)',
    status ENUM('pending', 'sent', 'cancelled') NOT NULL DEFAULT 'pending',
    sent_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_handle (user_handle),
    INDEX idx_reminder_time (reminder_time),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI bot reminders';

-- AI Bot Polls
CREATE TABLE IF NOT EXISTS ai_bot_polls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_by VARCHAR(100) NOT NULL COMMENT 'User who created the poll',
    room_id VARCHAR(255) NULL DEFAULT NULL COMMENT 'Room where poll is active',
    conversation_with VARCHAR(100) NULL DEFAULT NULL COMMENT 'IM conversation partner (if applicable)',
    poll_question TEXT NOT NULL COMMENT 'Poll question',
    poll_options JSON NOT NULL COMMENT 'Poll options (JSON array)',
    allow_multiple BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Allow multiple selections',
    expires_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When poll expires',
    status ENUM('active', 'closed', 'cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_room (room_id),
    INDEX idx_status (status),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI bot polls';

-- Poll Votes
CREATE TABLE IF NOT EXISTS ai_bot_poll_votes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    poll_id BIGINT UNSIGNED NOT NULL,
    user_handle VARCHAR(100) NOT NULL COMMENT 'User who voted',
    selected_options JSON NOT NULL COMMENT 'Selected option indices (JSON array)',
    voted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_poll_user (poll_id, user_handle),
    INDEX idx_poll_id (poll_id),
    FOREIGN KEY (poll_id) REFERENCES ai_bot_polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Poll votes';

-- Insert default AI system configurations
INSERT IGNORE INTO ai_systems_config (system_name, enabled, provider, model_name, config_json) VALUES
('moderation', FALSE, NULL, NULL, '{"auto_flag": true, "auto_hide": false, "min_toxicity_score": 0.7}'),
('smart_replies', FALSE, NULL, NULL, '{"max_suggestions": 3, "cache_duration": 300}'),
('summarization', FALSE, NULL, NULL, '{"min_messages": 10, "max_summary_length": 500}'),
('bot', FALSE, NULL, NULL, '{"enabled_commands": ["reminder", "poll"], "response_style": "friendly"}');

