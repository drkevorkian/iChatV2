#!/usr/bin/env php
<?php
/**
 * Sentinel Chat Platform - Audit Log Purge Cron Job
 * 
 * This script should be run periodically (e.g., daily via cron) to purge
 * old audit logs based on retention policies.
 * 
 * Usage:
 *   php purge_audit_logs.php
 * 
 * Cron example (daily at 2 AM):
 *   0 2 * * * /usr/bin/php /path/to/iChat/scripts/purge_audit_logs.php >> /var/log/ichat_audit_purge.log 2>&1
 * 
 * Windows Task Scheduler example:
 *   Create a scheduled task that runs:
 *   php.exe C:\wamp64\www\iChat\scripts\purge_audit_logs.php
 */

declare(strict_types=1);

// Change to script directory
chdir(__DIR__);

// Include bootstrap
require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\AuditService;

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Log file
$logFile = ICHAT_ROOT . '/logs/audit_purge.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

/**
 * Log a message
 */
function logMessage(string $message): void
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    echo $logEntry;
}

// Start
logMessage("Starting audit log purge job");

try {
    $auditService = new AuditService();
    
    // Purge old logs
    logMessage("Executing purge based on retention policies...");
    $purgedCount = $auditService->purgeOldLogs();
    
    if ($purgedCount > 0) {
        logMessage("Successfully purged {$purgedCount} old audit log entries");
    } else {
        logMessage("No logs were purged (either no logs matched retention criteria or all are on legal hold)");
    }
    
    logMessage("Audit log purge job completed successfully");
    exit(0);
    
} catch (\Exception $e) {
    logMessage("ERROR: Audit log purge job failed: " . $e->getMessage());
    logMessage("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

