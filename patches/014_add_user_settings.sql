-- Patch: 014_add_user_settings
-- Description: Add user settings table for chat appearance and preferences
-- Author: Sentinel Chat Platform
-- Date: 2025-12-27
-- Dependencies: 000_init_patch_system, 005_add_authentication_system
-- Rollback: Yes (see patches/rollback/014_add_user_settings_rollback.sql)

-- User Settings table
-- Stores user preferences including chat appearance settings
CREATE TABLE IF NOT EXISTS user_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_handle VARCHAR(100) NOT NULL COMMENT 'User handle/username',
    user_id BIGINT UNSIGNED NULL COMMENT 'Foreign key to users table if registered user',
    chat_text_color VARCHAR(7) DEFAULT '#000000' COMMENT 'Hex color for chat text',
    chat_name_color VARCHAR(7) DEFAULT '#0070ff' COMMENT 'Hex color for username in chat',
    font_size VARCHAR(20) DEFAULT 'medium' COMMENT 'Font size preference (small, medium, large)',
    show_timestamps BOOLEAN DEFAULT TRUE COMMENT 'Show message timestamps',
    sound_notifications BOOLEAN DEFAULT TRUE COMMENT 'Enable sound notifications',
    desktop_notifications BOOLEAN DEFAULT FALSE COMMENT 'Enable desktop notifications',
    auto_scroll BOOLEAN DEFAULT TRUE COMMENT 'Auto-scroll to bottom on new messages',
    compact_mode BOOLEAN DEFAULT FALSE COMMENT 'Use compact message display',
    theme VARCHAR(20) DEFAULT 'default' COMMENT 'Theme preference (default, dark, colorful, custom)',
    custom_theme_colors TEXT COMMENT 'JSON string for custom theme colors',
    language VARCHAR(10) DEFAULT 'en' COMMENT 'Language preference',
    timezone VARCHAR(50) DEFAULT 'UTC' COMMENT 'Timezone preference',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When settings were created',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update time',
    UNIQUE KEY uk_user_handle (user_handle),
    INDEX idx_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User settings and preferences';

-- Chat Media table
-- Stores references to media files (images, videos, audio) attached to chat messages
CREATE TABLE IF NOT EXISTS chat_media (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL COMMENT 'Associated message ID',
    media_type ENUM('image', 'video', 'audio', 'voice') NOT NULL COMMENT 'Type of media',
    filename VARCHAR(255) NOT NULL COMMENT 'Original filename',
    file_path VARCHAR(500) NOT NULL COMMENT 'Path to stored file',
    file_size BIGINT UNSIGNED NOT NULL COMMENT 'File size in bytes',
    mime_type VARCHAR(100) COMMENT 'MIME type of the file',
    thumbnail_path VARCHAR(500) COMMENT 'Path to thumbnail (for videos/images)',
    duration INT UNSIGNED NULL COMMENT 'Duration in seconds (for audio/video)',
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When file was uploaded',
    INDEX idx_message_id (message_id),
    INDEX idx_media_type (media_type),
    FOREIGN KEY (message_id) REFERENCES temp_outbox(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Chat media attachments';

