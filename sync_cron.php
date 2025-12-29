<?php
/**
 * Sentinel Chat Platform - Sync Cron Job
 * 
 * This script should be run periodically (via cron or scheduled task)
 * to sync file-stored messages to the database when it becomes available.
 * 
 * Usage:
 *   php sync_cron.php
 * 
 * Or add to crontab (Linux/Mac):
 *   */5 * * * * cd /path/to/iChat && php sync_cron.php >> logs/sync.log 2>&1
 * 
 * Or Windows Task Scheduler:
 *   Command: php.exe
 *   Arguments: C:\wamp64\www\iChat\sync_cron.php
 *   Run every 5 minutes
 */

declare(strict_types=1);

// Ensure CLI execution only
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from command line.\n");
}

require_once __DIR__ . '/bootstrap.php';

use iChat\Services\SyncService;

echo "[" . date('Y-m-d H:i:s') . "] Starting sync...\n";

try {
    $syncService = new SyncService();
    
    // Sync all file-stored messages
    $results = $syncService->syncAll(100);
    
    echo "Sync completed:\n";
    echo "  Messages: {$results['messages']['synced']} synced, {$results['messages']['failed']} failed\n";
    echo "  IMs: {$results['im']['synced']} synced, {$results['im']['failed']} failed\n";
    echo "  Escrow: {$results['escrow']['synced']} synced, {$results['escrow']['failed']} failed\n";
    
    // Get stats
    $stats = $syncService->getStats();
    echo "\nPending syncs:\n";
    echo "  Messages: {$stats['messages']}\n";
    echo "  IMs: {$stats['im']}\n";
    echo "  Escrow: {$stats['escrow']}\n";
    echo "  Database available: " . ($stats['database_available'] ? 'YES' : 'NO') . "\n";
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Sync finished.\n";

