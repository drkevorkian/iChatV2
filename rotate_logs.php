<?php
/**
 * Sentinel Chat Platform - Log Rotation Utility
 * 
 * Manually rotate log files. Can be run from command line or via web.
 * 
 * Usage:
 *   php rotate_logs.php [log_file]
 * 
 * If no log file specified, rotates error.log
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use iChat\Services\LogRotationService;

$logRotation = new LogRotationService();

// Get log file from command line argument or default to error.log
$logFile = $argv[1] ?? 'error.log';

echo "Rotating log file: {$logFile}\n";

// Check current line count
$lineCount = $logRotation->countLines($logFile);
echo "Current line count: {$lineCount}\n";

// Rotate if needed
$rotated = $logRotation->rotateIfNeeded($logFile);

if ($rotated) {
    echo "Log rotated successfully!\n";
    
    // Show archived logs
    $archives = $logRotation->getArchivedLogs($logFile);
    if (!empty($archives)) {
        echo "\nRecent archives:\n";
        foreach (array_slice($archives, 0, 5) as $archive) {
            $size = number_format($archive['size'] / 1024, 2);
            $date = date('Y-m-d H:i:s', $archive['modified']);
            echo "  - {$archive['filename']} ({$size} KB, {$date})\n";
        }
    }
} else {
    echo "Log does not need rotation (under 5000 lines).\n";
}

echo "\nDone.\n";

