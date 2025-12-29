<?php
/**
 * Sentinel Chat Platform - File Storage Management API
 * 
 * Provides API endpoints to view, edit, and delete files in storage/queue/.
 * Admin access only.
 * 
 * Security: Only accessible to authenticated administrators.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Services\FileStorage;

/**
 * Extract type from filename (e.g., "message_20251223_123456.json" -> "message")
 */
function extractTypeFromFilename(string $filename): string {
    if (preg_match('/^([a-zA-Z0-9_]+)_/', $filename, $matches)) {
        return $matches[1];
    }
    return 'unknown';
}

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// Check authentication - admin only
$authService = new AuthService();
$user = $authService->getCurrentUser();

if (!$user || $user['role'] !== 'administrator') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Administrator privileges required.']);
    exit;
}

// Get action from query parameter
$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$fileStorage = new FileStorage();
$queueDir = $fileStorage->getQueueDir();

try {
    switch ($action) {
        case 'list':
            // List all files in queue directory
            $typeFilter = $_GET['type'] ?? '';
            $files = [];
            
            if (!is_dir($queueDir)) {
                throw new \RuntimeException('Queue directory does not exist');
            }
            
            $allFiles = scandir($queueDir);
            foreach ($allFiles as $file) {
                if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) {
                    continue;
                }
                
                // Filter by type if specified
                if (!empty($typeFilter)) {
                    if (!str_starts_with($file, $typeFilter . '_')) {
                        continue;
                    }
                }
                
                $filePath = $queueDir . '/' . $file;
                $fileInfo = [
                    'filename' => $file,
                    'size' => filesize($filePath),
                    'modified' => filemtime($filePath),
                    'type' => extractTypeFromFilename($file),
                ];
                
                // Try to read metadata
                $data = $fileStorage->readMessageFile($filePath);
                if ($data !== null && isset($data['_metadata'])) {
                    $fileInfo['queued_at'] = $data['_metadata']['queued_at'] ?? null;
                    $fileInfo['synced'] = $data['_metadata']['synced'] ?? false;
                    $fileInfo['synced_at'] = $data['_metadata']['synced_at'] ?? null;
                }
                
                $files[] = $fileInfo;
            }
            
            // Sort by modified time (newest first)
            usort($files, function($a, $b) {
                return ($b['modified'] ?? 0) - ($a['modified'] ?? 0);
            });
            
            echo json_encode([
                'success' => true,
                'files' => $files,
                'count' => count($files),
            ]);
            break;
            
        case 'view':
            // View content of a specific file
            $filename = $_GET['file'] ?? '';
            
            if (empty($filename)) {
                throw new \InvalidArgumentException('File parameter required');
            }
            
            // Security: Only allow JSON files in queue directory
            if (!preg_match('/^[a-zA-Z0-9_\-]+\.json$/', $filename)) {
                throw new \InvalidArgumentException('Invalid file name');
            }
            
            $filePath = $queueDir . '/' . $filename;
            
            // Ensure file is within queue directory (prevent directory traversal)
            $realPath = realpath($filePath);
            $realQueueDir = realpath($queueDir);
            if ($realPath === false || strpos($realPath, $realQueueDir) !== 0) {
                throw new \RuntimeException('File not found or access denied');
            }
            
            if (!file_exists($filePath)) {
                throw new \RuntimeException('File not found');
            }
            
            $data = $fileStorage->readMessageFile($filePath);
            if ($data === null) {
                throw new \RuntimeException('Failed to read file or invalid JSON');
            }
            
            echo json_encode([
                'success' => true,
                'filename' => $filename,
                'data' => $data,
            ]);
            break;
            
        case 'edit':
            // Edit content of a specific file
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for edit action');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $filename = $input['filename'] ?? '';
            $data = $input['data'] ?? null;
            
            if (empty($filename) || !is_array($data)) {
                throw new \InvalidArgumentException('Filename and data are required');
            }
            
            // Security: Only allow JSON files in queue directory
            if (!preg_match('/^[a-zA-Z0-9_\-]+\.json$/', $filename)) {
                throw new \InvalidArgumentException('Invalid file name');
            }
            
            $filePath = $queueDir . '/' . $filename;
            
            // Ensure file is within queue directory
            $realPath = realpath($filePath);
            $realQueueDir = realpath($queueDir);
            if ($realPath === false || strpos($realPath, $realQueueDir) !== 0) {
                throw new \RuntimeException('File not found or access denied');
            }
            
            if (!file_exists($filePath)) {
                throw new \RuntimeException('File not found');
            }
            
            // Read existing file to preserve metadata
            $existingData = $fileStorage->readMessageFile($filePath);
            if ($existingData === null) {
                throw new \RuntimeException('Failed to read existing file');
            }
            
            // Merge new data with existing metadata
            $mergedData = array_merge($existingData, $data);
            if (isset($existingData['_metadata'])) {
                $mergedData['_metadata'] = $existingData['_metadata'];
                $mergedData['_metadata']['edited_at'] = date('Y-m-d H:i:s');
                $mergedData['_metadata']['edited_by'] = $user['username'] ?? 'admin';
            }
            
            // Write file atomically
            $tempFile = $filePath . '.tmp';
            $jsonContent = json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (file_put_contents($tempFile, $jsonContent) === false) {
                throw new \RuntimeException('Failed to write file');
            }
            
            if (!rename($tempFile, $filePath)) {
                @unlink($tempFile);
                throw new \RuntimeException('Failed to save file');
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'File updated successfully',
            ]);
            break;
            
        case 'delete':
            // Delete a file
            if ($method !== 'POST' && $method !== 'DELETE') {
                throw new \InvalidArgumentException('Invalid method for delete action');
            }
            
            $filename = $_GET['file'] ?? '';
            if (empty($filename)) {
                // Try to get from POST body
                $input = json_decode(file_get_contents('php://input'), true);
                $filename = $input['filename'] ?? $filename;
            }
            
            if (empty($filename)) {
                throw new \InvalidArgumentException('File parameter required');
            }
            
            // Security: Only allow JSON files in queue directory
            if (!preg_match('/^[a-zA-Z0-9_\-]+\.json$/', $filename)) {
                throw new \InvalidArgumentException('Invalid file name');
            }
            
            $filePath = $queueDir . '/' . $filename;
            
            // Ensure file is within queue directory
            $realPath = realpath($filePath);
            $realQueueDir = realpath($queueDir);
            if ($realPath === false || strpos($realPath, $realQueueDir) !== 0) {
                throw new \RuntimeException('File not found or access denied');
            }
            
            if (!file_exists($filePath)) {
                throw new \RuntimeException('File not found');
            }
            
            if (!@unlink($filePath)) {
                throw new \RuntimeException('Failed to delete file');
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'File deleted successfully',
            ]);
            break;
            
        case 'types':
            // Get list of file types
            $types = [];
            $allFiles = scandir($queueDir);
            
            foreach ($allFiles as $file) {
                if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) {
                    continue;
                }
                
                $type = extractTypeFromFilename($file);
                if (!in_array($type, $types, true)) {
                    $types[] = $type;
                }
            }
            
            sort($types);
            
            echo json_encode([
                'success' => true,
                'types' => $types,
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

