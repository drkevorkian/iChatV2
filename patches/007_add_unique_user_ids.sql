-- Sentinel Chat Platform - Unique User ID System
-- 
-- This patch creates a system to track unique user IDs for all users (guests and registered).
-- Format: Guest_x01445_(datetime)_(IP_ID)
-- Where IP_ID is a unique serial number assigned to each IP address.

-- Create table to track IP addresses and assign unique serial numbers
CREATE TABLE IF NOT EXISTS ip_address_registry (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL UNIQUE COMMENT 'IP address (IPv4 or IPv6)',
    ip_serial_id INT NOT NULL UNIQUE COMMENT 'Unique serial number for this IP (auto-assigned)',
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'First time this IP was seen',
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last time this IP was seen',
    INDEX idx_ip_address (ip_address),
    INDEX idx_ip_serial_id (ip_serial_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registry of IP addresses and their unique serial IDs';

-- Create table to track user login sessions with unique IDs
CREATE TABLE IF NOT EXISTS user_login_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_handle VARCHAR(100) NOT NULL COMMENT 'User handle (username or guest handle)',
    unique_user_id VARCHAR(255) NOT NULL UNIQUE COMMENT 'Unique user ID: Guest_x0123_(datetime)_(IP_ID) where 0123 is random 4-digit number',
    ip_address VARCHAR(45) NOT NULL COMMENT 'IP address at login',
    ip_serial_id INT NOT NULL COMMENT 'Serial ID for this IP address',
    login_datetime DATETIME NOT NULL COMMENT 'Login date and time',
    session_id VARCHAR(255) NULL COMMENT 'PHP session ID',
    user_type ENUM('guest', 'registered') NOT NULL DEFAULT 'guest' COMMENT 'Type of user',
    user_id BIGINT UNSIGNED NULL COMMENT 'Foreign key to users table if registered user',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation time',
    INDEX idx_user_handle (user_handle),
    INDEX idx_unique_user_id (unique_user_id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_ip_serial_id (ip_serial_id),
    INDEX idx_session_id (session_id),
    INDEX idx_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks user login sessions with unique IDs';

-- Note: Stored procedure removed - PHP code handles IP serial ID logic directly
-- The UniqueUserIdService::getOrCreateIpSerialId() method handles this functionality

