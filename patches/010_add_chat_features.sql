-- Patch: 010_add_chat_features
-- Description: Add word filter, smileys, ASCII art, and Pinky & Brain bot features
-- Author: Sentinel Chat Platform
-- Date: 2025-12-23
-- Dependencies: 000_init_patch_system
-- Rollback: Yes (see patches/rollback/010_add_chat_features_rollback.sql)

-- Drop tables if they exist (in case of previous failed attempts with wrong structure)
DROP TABLE IF EXISTS pinky_brain_state;
DROP TABLE IF EXISTS pinky_brain_responses;
DROP TABLE IF EXISTS ascii_art_library;
DROP TABLE IF EXISTS smiley_mappings;
DROP TABLE IF EXISTS word_filter;

-- Word filter table - stores words/phrases to filter
CREATE TABLE IF NOT EXISTS word_filter (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    word_pattern VARCHAR(255) NOT NULL COMMENT 'Word or pattern to filter (supports regex)',
    replacement VARCHAR(255) DEFAULT '*' COMMENT 'Replacement text (default: asterisks)',
    severity ENUM('mild', 'moderate', 'severe') NOT NULL DEFAULT 'moderate' COMMENT 'Filter severity level',
    is_regex BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether pattern is a regex',
    is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether filter is active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When filter was added',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'When filter was last updated',
    INDEX idx_word_pattern (word_pattern(100)),
    INDEX idx_is_active (is_active),
    INDEX idx_severity (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Word filter patterns';

-- Smiley/emoticon mappings table
CREATE TABLE IF NOT EXISTS smiley_mappings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    text_pattern VARCHAR(50) NOT NULL UNIQUE COMMENT 'Text pattern (e.g., :), :D, :P)',
    image_url VARCHAR(500) COMMENT 'Image URL for smiley (optional)',
    emoji VARCHAR(10) COMMENT 'Unicode emoji character (optional)',
    description VARCHAR(255) COMMENT 'Description of smiley',
    is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether smiley is active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When smiley was added',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'When smiley was last updated',
    INDEX idx_text_pattern (text_pattern),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Smiley/emoticon text to image/emoji mappings';

-- ASCII art storage (optional - for commonly used ASCII art)
CREATE TABLE IF NOT EXISTS ascii_art_library (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT 'Name/identifier for ASCII art',
    art_text TEXT NOT NULL COMMENT 'ASCII art text',
    description VARCHAR(255) COMMENT 'Description of ASCII art',
    category VARCHAR(50) COMMENT 'Category (animals, faces, objects, etc.)',
    is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether art is active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When art was added',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'When art was last updated',
    INDEX idx_name (name),
    INDEX idx_category (category),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ASCII art library';

-- Pinky and Brain responses table
CREATE TABLE IF NOT EXISTS pinky_brain_responses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bot_character ENUM('brain', 'pinky') NOT NULL COMMENT 'Which character says this',
    response_text TEXT NOT NULL COMMENT 'Response text',
    trigger_type ENUM('brain_toggle', 'pinky_response', 'random') NOT NULL DEFAULT 'pinky_response' COMMENT 'When this response is triggered',
    response_order INT UNSIGNED DEFAULT 0 COMMENT 'Order for sequential responses',
    is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Whether response is active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When response was added',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'When response was last updated',
    INDEX idx_bot_character (bot_character),
    INDEX idx_trigger_type (trigger_type),
    INDEX idx_is_active (is_active),
    INDEX idx_response_order (response_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pinky and Brain bot responses';

-- Pinky and Brain bot state (tracks if bot is active and last interaction)
CREATE TABLE IF NOT EXISTS pinky_brain_state (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(255) NOT NULL COMMENT 'Room where bot is active',
    is_active BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether bot is active in this room',
    last_brain_message_id BIGINT UNSIGNED NULL COMMENT 'ID of last Brain message',
    last_pinky_response_id BIGINT UNSIGNED NULL COMMENT 'ID of last Pinky response',
    last_interaction_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When bot last interacted',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When state was created',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'When state was last updated',
    UNIQUE KEY uk_room_id (room_id),
    INDEX idx_is_active (is_active),
    INDEX idx_last_interaction (last_interaction_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pinky and Brain bot state per room';

-- Insert default smileys (insert without emojis first, then update them to avoid parsing issues)
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES (':)', NULL, 'Happy smile');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES (':D', NULL, 'Big grin');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES (':P', NULL, 'Tongue out');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES (';)', NULL, 'Wink');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES (':(', NULL, 'Sad');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES (':/', NULL, 'Unsure');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES (':|', NULL, 'Neutral');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES (':O', NULL, 'Surprised');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES ('<3', NULL, 'Heart');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES ('</3', NULL, 'Broken heart');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES (':*', NULL, 'Kiss');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES ('XD', NULL, 'Laughing');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES ('-_-', NULL, 'Unimpressed');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES ('o.O', NULL, 'Confused');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES ('^_^', NULL, 'Happy');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES ('T_T', NULL, 'Crying');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES ('>:)', NULL, 'Evil grin');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES (':3', NULL, 'Cute');
INSERT IGNORE INTO smiley_mappings (text_pattern, emoji, description) VALUES ('B)', NULL, 'Cool');

-- Update emojis using hex encoding (separate statements to avoid parsing issues)
UPDATE smiley_mappings SET emoji = UNHEX('F09F988A') WHERE text_pattern = ':)';
UPDATE smiley_mappings SET emoji = UNHEX('F09F9883') WHERE text_pattern = ':D';
UPDATE smiley_mappings SET emoji = UNHEX('F09F989B') WHERE text_pattern = ':P';
UPDATE smiley_mappings SET emoji = UNHEX('F09F9889') WHERE text_pattern = ';)';
UPDATE smiley_mappings SET emoji = UNHEX('F09F98A2') WHERE text_pattern = ':(';
UPDATE smiley_mappings SET emoji = UNHEX('F09F9895') WHERE text_pattern = ':/';
UPDATE smiley_mappings SET emoji = UNHEX('F09F9890') WHERE text_pattern = ':|';
UPDATE smiley_mappings SET emoji = UNHEX('F09F98AE') WHERE text_pattern = ':O';
UPDATE smiley_mappings SET emoji = UNHEX('E29DA4EFB88F') WHERE text_pattern = '<3';
UPDATE smiley_mappings SET emoji = UNHEX('F09F9294') WHERE text_pattern = '</3';
UPDATE smiley_mappings SET emoji = UNHEX('F09F9898') WHERE text_pattern = ':*';
UPDATE smiley_mappings SET emoji = UNHEX('F09F9886') WHERE text_pattern = 'XD';
UPDATE smiley_mappings SET emoji = UNHEX('F09F9891') WHERE text_pattern = '-_-';
UPDATE smiley_mappings SET emoji = UNHEX('F09F9895') WHERE text_pattern = 'o.O';
UPDATE smiley_mappings SET emoji = UNHEX('F09F988A') WHERE text_pattern = '^_^';
UPDATE smiley_mappings SET emoji = UNHEX('F09F98AD') WHERE text_pattern = 'T_T';
UPDATE smiley_mappings SET emoji = UNHEX('F09F9888') WHERE text_pattern = '>:)';
UPDATE smiley_mappings SET emoji = UNHEX('F09F988A') WHERE text_pattern = ':3';
UPDATE smiley_mappings SET emoji = UNHEX('F09F988E') WHERE text_pattern = 'B)';

-- Insert default Pinky and Brain responses (all single-line to avoid parsing issues)
-- Note: Using placeholder APOSTROPHE_PLACEHOLDER which will be replaced after insertion
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('brain', 'Are you thinking what IAPOSTROPHE_PLACEHOLDERm thinking, Pinky?', 'brain_toggle', 1);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but where are we going to find a duck and a hose at this hour?', 'pinky_response', 1);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we wear a disguise?', 'pinky_response', 2);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but where are we going to get rubber pants our size?', 'pinky_response', 3);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but how are we going to get the Spice Girls into a phone booth?', 'pinky_response', 4);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if we get a divorce, who gets the custody of the cheese?', 'pinky_response', 5);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger hamster?', 'pinky_response', 6);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but where are we going to find a pink tutu at this hour?', 'pinky_response', 7);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger ladder?', 'pinky_response', 8);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but how are we going to get the elephants into the phone booth?', 'pinky_response', 9);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger boat?', 'pinky_response', 10);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but where are we going to find a pink flamingo at this hour?', 'pinky_response', 11);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger hat?', 'pinky_response', 12);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but how are we going to get the penguins into the phone booth?', 'pinky_response', 13);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger wheelbarrow?', 'pinky_response', 14);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but where are we going to find a pink elephant at this hour?', 'pinky_response', 15);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger trampoline?', 'pinky_response', 16);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but how are we going to get the giraffes into the phone booth?', 'pinky_response', 17);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger umbrella?', 'pinky_response', 18);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but where are we going to find a pink gorilla at this hour?', 'pinky_response', 19);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger parachute?', 'pinky_response', 20);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but how are we going to get the hippos into the phone booth?', 'pinky_response', 21);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger surfboard?', 'pinky_response', 22);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but where are we going to find a pink rhinoceros at this hour?', 'pinky_response', 23);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger hot air balloon?', 'pinky_response', 24);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but how are we going to get the whales into the phone booth?', 'pinky_response', 25);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger submarine?', 'pinky_response', 26);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but where are we going to find a pink octopus at this hour?', 'pinky_response', 27);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger jetpack?', 'pinky_response', 28);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but how are we going to get the dinosaurs into the phone booth?', 'pinky_response', 29);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger time machine?', 'pinky_response', 30);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but where are we going to find a pink unicorn at this hour?', 'pinky_response', 31);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger magic wand?', 'pinky_response', 32);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but how are we going to get the dragons into the phone booth?', 'pinky_response', 33);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger castle?', 'pinky_response', 34);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but where are we going to find a pink phoenix at this hour?', 'pinky_response', 35);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger volcano?', 'pinky_response', 36);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but how are we going to get the giants into the phone booth?', 'pinky_response', 37);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger beanstalk?', 'pinky_response', 38);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but where are we going to find a pink fairy at this hour?', 'pinky_response', 39);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger wand?', 'pinky_response', 40);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but how are we going to get the wizards into the phone booth?', 'pinky_response', 41);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger spellbook?', 'pinky_response', 42);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but where are we going to find a pink genie at this hour?', 'pinky_response', 43);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger lamp?', 'pinky_response', 44);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but how are we going to get the genies into the phone booth?', 'pinky_response', 45);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger bottle?', 'pinky_response', 46);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but where are we going to find a pink mermaid at this hour?', 'pinky_response', 47);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger ocean?', 'pinky_response', 48);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but how are we going to get the mermaids into the phone booth?', 'pinky_response', 49);
INSERT IGNORE INTO pinky_brain_responses (bot_character, response_text, trigger_type, response_order) VALUES ('pinky', 'I think so, Brain, but if weAPOSTROPHE_PLACEHOLDERre going to do that, shouldnAPOSTROPHE_PLACEHOLDERt we get a bigger aquarium?', 'pinky_response', 50);

-- Replace placeholder with apostrophe using UPDATE statement
-- Using CHAR(39) function call (not in quotes) should work fine in UPDATE context
UPDATE pinky_brain_responses SET response_text = REPLACE(response_text, 'APOSTROPHE_PLACEHOLDER', CHAR(39)) WHERE response_text LIKE '%APOSTROPHE_PLACEHOLDER%';
