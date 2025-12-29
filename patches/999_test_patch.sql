-- Test Patch - Can be reset and reapplied
-- This patch creates a test table that can be safely dropped and recreated

-- Create test table if it doesn't exist
CREATE TABLE IF NOT EXISTS test_patch_table (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_value VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_test_value (test_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Test table for patch system';

-- Insert a test record
INSERT INTO test_patch_table (test_value) VALUES ('Patch applied successfully');

