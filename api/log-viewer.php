<?php
/**
 * Sentinel Chat Platform - Log Viewer API
 * 
 * Provides API endpoints to view current and archived log files.
 * Supports both regular and gzipped log files.
 * 
 * Security: Only accessible to authenticated admin, moderator, trusted admin, and owner users.
 * All authorized roles can read logs and manually compress/rotate them.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Services\LogRotationService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// Check authentication - allow admins, moderators, trusted admins, and owners
$authService = new AuthService();
$user = $authService->getCurrentUser();

if (!$user || !in_array($user['role'], ['administrator', 'admin', 'moderator', 'owner', 'trusted_admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Admin, Moderator, Trusted Admin, or Owner privileges required.']);
    exit;
}

// Get action from query parameter
$action = $_GET['action'] ?? 'list';
$logsDir = ICHAT_ROOT . '/logs';
$logRotation = new LogRotationService($logsDir);

try {
    switch ($action) {
        case 'list':
            // List available log files
            $logsDir = ICHAT_ROOT . '/logs';
            $currentLogs = [];
            $archivedLogs = [];
            
            // Current logs
            $logFiles = ['error.log', 'patches.log', 'rotation.log'];
            foreach ($logFiles as $logName) {
                $logPath = $logsDir . '/' . $logName;
                if (file_exists($logPath)) {
                    $currentLogs[] = $logName;
                }
            }
            
            // Archived logs - get all archived logs
            $archivedLogs = [];
            $archiveDir = $logsDir . '/archived';
            if (is_dir($archiveDir)) {
                $files = @scandir($archiveDir);
                if ($files !== false) {
                    foreach ($files as $file) {
                        if ($file !== '.' && $file !== '..' && str_ends_with($file, '.gz')) {
                            $archivedLogs[] = $file;
                        }
                    }
                    // Sort by filename (newest first typically)
                    rsort($archivedLogs);
                }
            }
            
            echo json_encode([
                'success' => true,
                'current_logs' => $currentLogs,
                'archived_logs' => $archivedLogs
            ]);
            break;
            
        case 'view':
            // View log file content
            $logFile = $_GET['file'] ?? '';
            $limit = (int)($_GET['limit'] ?? 1000);
            $offset = (int)($_GET['offset'] ?? 0);
            
            if (empty($logFile)) {
                throw new \InvalidArgumentException('File parameter required');
            }
            
            // Security: Only allow viewing log files
            if (!preg_match('/^[a-zA-Z0-9_.-]+(\.gz)?$/', $logFile)) {
                throw new \InvalidArgumentException('Invalid file name');
            }
            
            $content = $logRotation->readLog($logFile, $limit, $offset);
            
            // Check if content is an error array
            if (isset($content['error'])) {
                throw new \RuntimeException($content['error']);
            }
            
            echo json_encode([
                'success' => true,
                'file' => $logFile,
                'content' => $content,
                'limit' => $limit,
                'offset' => $offset,
            ]);
            break;
            
        case 'rotate':
            // Manually rotate a log file
            $logFile = $_GET['file'] ?? 'error.log';
            
            if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $logFile)) {
                throw new \InvalidArgumentException('Invalid file name');
            }
            
            $rotated = $logRotation->rotateIfNeeded($logFile);
            
            echo json_encode([
                'success' => true,
                'rotated' => $rotated,
                'message' => $rotated ? 'Log rotated successfully' : 'Log did not need rotation',
            ]);
            break;
            
        case 'cleanup':
            // Clean up old archived logs
            $days = (int)($_GET['days'] ?? 30);
            
            $deleted = $logRotation->cleanupOldArchives($days);
            
            echo json_encode([
                'success' => true,
                'deleted' => $deleted,
                'message' => "Deleted {$deleted} archived log files older than {$days} days",
            ]);
            break;
            
        default:
            throw new \InvalidArgumentException('Invalid action');
    }
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}

