-- Patch: 011_add_emoji_library
-- Description: Add comprehensive Unicode emoji library support
-- Author: Sentinel Chat Platform
-- Date: 2025-12-26
-- Dependencies: 010_add_chat_features
-- Rollback: Yes (see patches/rollback/011_add_emoji_library_rollback.sql)

-- Emoji library table - stores Unicode emojis with metadata
CREATE TABLE IF NOT EXISTS emoji_library (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code_points VARCHAR(100) NOT NULL COMMENT 'Unicode code points (hex, space-separated)',
    emoji VARCHAR(50) NOT NULL COMMENT 'The actual emoji character(s)',
    short_name VARCHAR(255) NOT NULL COMMENT 'CLDR short name',
    category VARCHAR(100) COMMENT 'Category (Smileys & Emotion, People & Body, Animals & Nature, etc.)',
    subcategory VARCHAR(100) COMMENT 'Subcategory within category',
    keywords TEXT COMMENT 'Comma-separated keywords for search',
    version VARCHAR(10) COMMENT 'Unicode version (e.g., E0.6, E1.0)',
    is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether emoji is active',
    usage_count INT UNSIGNED DEFAULT 0 COMMENT 'How many times emoji has been used',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When emoji was added',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'When emoji was last updated',
    UNIQUE KEY uk_code_points (code_points),
    INDEX idx_category (category),
    INDEX idx_subcategory (subcategory),
    INDEX idx_short_name (short_name(100)),
    INDEX idx_is_active (is_active),
    INDEX idx_usage_count (usage_count DESC),
    FULLTEXT KEY ft_keywords (keywords)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Unicode emoji library';

-- Emoji favorites table - user-specific favorite emojis
CREATE TABLE IF NOT EXISTS emoji_favorites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'User ID',
    emoji_id BIGINT UNSIGNED NOT NULL COMMENT 'Reference to emoji_library.id',
    position INT UNSIGNED DEFAULT 0 COMMENT 'Display order',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When favorited',
    UNIQUE KEY uk_user_emoji (user_id, emoji_id),
    INDEX idx_user_id (user_id),
    INDEX idx_emoji_id (emoji_id),
    FOREIGN KEY (emoji_id) REFERENCES emoji_library(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User favorite emojis';

-- Recent emojis table - tracks recently used emojis per user
CREATE TABLE IF NOT EXISTS emoji_recent (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'User ID',
    emoji_id BIGINT UNSIGNED NOT NULL COMMENT 'Reference to emoji_library.id',
    used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When emoji was last used',
    use_count INT UNSIGNED DEFAULT 1 COMMENT 'How many times user has used this emoji',
    INDEX idx_user_id (user_id),
    INDEX idx_used_at (used_at DESC),
    INDEX idx_emoji_id (emoji_id),
    UNIQUE KEY uk_user_emoji (user_id, emoji_id),
    FOREIGN KEY (emoji_id) REFERENCES emoji_library(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Recently used emojis per user';

