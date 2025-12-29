<?php
/**
 * Sentinel Chat Platform - Database Connection Test
 * 
 * CLI-only test script to verify database connectivity.
 * Run from command line: php tests/test_database.php
 * 
 * Security: This script is protected by .htaccess and should
 * only be run from command line.
 */

declare(strict_types=1);

// Ensure CLI execution only
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from command line.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use iChat\Database;
use iChat\Config;

echo "Sentinel Chat - Database Connection Test\n";
echo "=========================================\n\n";

try {
    // Test configuration loading
    echo "1. Testing configuration...\n";
    $config = Config::getInstance();
    echo "   ✓ Configuration loaded\n";
    echo "   - DB Host: " . $config->get('db.host') . "\n";
    echo "   - DB Name: " . $config->get('db.name') . "\n";
    
    // Test database connection
    echo "\n2. Testing database connection...\n";
    $conn = Database::getConnection();
    echo "   ✓ Database connection successful\n";
    
    // Test query
    echo "\n3. Testing database query...\n";
    $result = Database::queryOne("SELECT DATABASE() as db_name");
    echo "   ✓ Query executed successfully\n";
    echo "   - Current database: " . ($result['db_name'] ?? 'unknown') . "\n";
    
    // Test table existence
    echo "\n4. Testing table existence...\n";
    $tables = Database::query("SHOW TABLES");
    echo "   ✓ Tables found: " . count($tables) . "\n";
    foreach ($tables as $table) {
        $tableName = array_values($table)[0];
        echo "   - $tableName\n";
    }
    
    echo "\n✓ All tests passed!\n";
    
} catch (\Exception $e) {
    echo "\n✗ Test failed: " . $e->getMessage() . "\n";
    exit(1);
}

