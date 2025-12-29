-- Patch 021: Add WebSocket Server Credentials Table
-- Stores secure credentials for WebSocket server (DB credentials, API secret)
-- These are never exposed to client-side JavaScript

-- Check if table exists before creating (using information_schema)
SET @table_exists = (
    SELECT COUNT(*) 
    FROM information_schema.tables 
    WHERE table_schema = DATABASE() 
    AND table_name = 'websocket_credentials'
);

-- Create table if it doesn't exist (using conditional logic)
CREATE TABLE IF NOT EXISTS websocket_credentials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credential_type ENUM('db_user', 'db_password', 'api_secret') NOT NULL UNIQUE,
    credential_value TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    rotated_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_type (credential_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default credentials if they don't exist
-- These will be updated by the admin interface
INSERT IGNORE INTO websocket_credentials (credential_type, credential_value) VALUES
('db_user', 'root'),
('db_password', 'M13@ng3l123'),
('api_secret', 'change-me-now');

