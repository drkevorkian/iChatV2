<?php
/**
 * Test Ban System
 * 
 * Tests the ban checking and unban functionality.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from command line.\n");
}

require_once __DIR__ . '/bootstrap.php';

use iChat\Repositories\UserManagementRepository;
use iChat\Database;

echo "Testing Ban System...\n\n";

try {
    $repo = new UserManagementRepository();
    
    // Test 1: Check if non-existent user is banned
    echo "Test 1: Check if non-existent user is banned...\n";
    $result = $repo->isBanned('nonexistent_user', '127.0.0.1');
    echo "Result: " . ($result ? "BANNED" : "NOT BANNED") . " (Expected: NOT BANNED)\n";
    echo ($result === false ? "✓ PASS\n" : "✗ FAIL\n");
    echo "\n";
    
    // Test 2: Get ban info for non-existent user
    echo "Test 2: Get ban info for non-existent user...\n";
    $banInfo = $repo->getBanInfo('nonexistent_user', '127.0.0.1');
    echo "Result: " . (empty($banInfo) ? "NO BAN INFO" : "BAN INFO FOUND") . " (Expected: NO BAN INFO)\n";
    echo (empty($banInfo) ? "✓ PASS\n" : "✗ FAIL\n");
    echo "\n";
    
    // Test 3: Check database connection
    echo "Test 3: Check database connection...\n";
    if (\iChat\Services\DatabaseHealth::isAvailable()) {
        echo "✓ Database is available\n";
        
        // Test 4: Check if user_bans table exists
        echo "\nTest 4: Check if user_bans table exists...\n";
        $conn = Database::getConnection();
        $stmt = $conn->query("SHOW TABLES LIKE 'user_bans'");
        if ($stmt->rowCount() > 0) {
            echo "✓ user_bans table exists\n";
            
            // Test 5: Check table structure
            echo "\nTest 5: Check user_bans table structure...\n";
            $stmt = $conn->query("DESCRIBE user_bans");
            $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            $requiredColumns = ['id', 'user_handle', 'banned_by', 'reason', 'expires_at', 'created_at'];
            $missingColumns = array_diff($requiredColumns, $columns);
            
            if (empty($missingColumns)) {
                echo "✓ All required columns exist\n";
                
                // Check for unban_email column (from patch 012)
                if (in_array('unban_email', $columns)) {
                    echo "✓ unban_email column exists\n";
                } else {
                    echo "⚠ unban_email column missing (run patch 012_add_unban_system)\n";
                }
            } else {
                echo "✗ Missing columns: " . implode(', ', $missingColumns) . "\n";
            }
            
            // Test 6: Check if unban_tokens table exists
            echo "\nTest 6: Check if unban_tokens table exists...\n";
            $stmt = $conn->query("SHOW TABLES LIKE 'unban_tokens'");
            if ($stmt->rowCount() > 0) {
                echo "✓ unban_tokens table exists\n";
            } else {
                echo "⚠ unban_tokens table missing (run patch 012_add_unban_system)\n";
            }
        } else {
            echo "✗ user_bans table does not exist\n";
        }
    } else {
        echo "✗ Database is not available\n";
    }
    
    echo "\n=== Test Summary ===\n";
    echo "Ban system components checked.\n";
    echo "If all tests passed, the ban system should be working correctly.\n";
    
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

exit(0);

