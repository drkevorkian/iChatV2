<?php
/**
 * Sentinel Chat Platform - Chat Media API Endpoint
 * 
 * Handles chat media uploads and retrieval (images, videos, audio, voice messages).
 * 
 * Security: All operations use prepared statements and input validation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Services\AuditService;
use iChat\Services\RBACService;
use iChat\Database;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// Start session if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get current user
$authService = new AuthService();
$currentUser = $authService->getCurrentUser();
$userHandle = $currentUser['username'] ?? $_SESSION['user_handle'] ?? '';

if (empty($userHandle)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'upload':
            // Upload media files
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for upload action');
            }
            
            // Check RBAC permission
            $rbacService = new RBACService();
            $userRole = $currentUser['role'] ?? 'guest';
            if (!$rbacService->hasPermission($userRole, 'chat.upload_media')) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - You do not have permission to upload media.']);
                exit;
            }
            
            if (empty($_FILES['files'])) {
                throw new \InvalidArgumentException('No files uploaded');
            }
            
            $files = $_FILES['files'];
            $types = $_POST['types'] ?? [];
            
            // Handle multiple files
            $fileCount = is_array($files['name']) ? count($files['name']) : 1;
            $mediaIds = [];
            
            for ($i = 0; $i < $fileCount; $i++) {
                $file = [
                    'name' => is_array($files['name']) ? $files['name'][$i] : $files['name'],
                    'type' => is_array($files['type']) ? $files['type'][$i] : $files['type'],
                    'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
                    'error' => is_array($files['error']) ? $files['error'][$i] : $files['error'],
                    'size' => is_array($files['size']) ? $files['size'][$i] : $files['size'],
                ];
                
                $mediaType = is_array($types) ? ($types[$i] ?? 'image') : ($types ?: 'image');
                
                // Validate file
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    continue; // Skip failed uploads
                }
                
                // Check file size (max 10MB)
                $maxSize = 10 * 1024 * 1024;
                if ($file['size'] > $maxSize) {
                    continue; // Skip oversized files
                }
                
                // Determine media type from file
                if ($mediaType === 'image' && !str_starts_with($file['type'], 'image/')) {
                    $mediaType = 'image';
                } elseif ($mediaType === 'video' && !str_starts_with($file['type'], 'video/')) {
                    $mediaType = 'video';
                } elseif ($mediaType === 'audio' && !str_starts_with($file['type'], 'audio/')) {
                    $mediaType = 'audio';
                } elseif ($mediaType === 'voice') {
                    $mediaType = 'voice';
                }
                
                // Create media directory
                $mediaDir = ICHAT_ROOT . '/storage/chat_media';
                if (!is_dir($mediaDir)) {
                    @mkdir($mediaDir, 0755, true);
                    file_put_contents($mediaDir . '/.htaccess', "Deny from all\n");
                }
                
                // Generate unique filename
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $uniqueFilename = $userHandle . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
                $filePath = $mediaDir . '/' . $uniqueFilename;
                
                // Move uploaded file
                if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                    continue; // Skip failed moves
                }
                
                // Get MIME type
                $mimeType = $file['type'] ?? mime_content_type($filePath);
                
                // Store in database (temporary - will be linked to message when sent)
                $sql = 'INSERT INTO chat_media (message_id, media_type, filename, file_path, file_size, mime_type)
                        VALUES (0, :media_type, :filename, :file_path, :file_size, :mime_type)';
                
                Database::execute($sql, [
                    ':media_type' => $mediaType,
                    ':filename' => $file['name'],
                    ':file_path' => 'storage/chat_media/' . $uniqueFilename,
                    ':file_size' => $file['size'],
                    ':mime_type' => $mimeType,
                ]);
                
                $mediaId = Database::lastInsertId();
                $mediaIds[] = $mediaId;
                
                // Log audit event for file upload
                $auditService = new AuditService();
                $currentUserData = $authService->getCurrentUser();
                $userId = $currentUserData['id'] ?? null;
                
                $auditService->logFileUpload(
                    $userHandle,
                    $userId,
                    (string)$mediaId,
                    $mediaType,
                    $file['size'],
                    $file['name']
                );
            }
            
            echo json_encode([
                'success' => true,
                'media_ids' => $mediaIds,
            ]);
            break;
            
        case 'link':
            // Link media to a message
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for link action');
            }
            
            // SECURITY: Secure JSON parsing with error checking to prevent injection attacks
            $rawInput = file_get_contents('php://input');
            if ($rawInput === false) {
                throw new \InvalidArgumentException('Failed to read request body');
            }
            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input: ' . json_last_error_msg());
            }
            
            $messageId = isset($input['message_id']) ? (int)$input['message_id'] : 0;
            $mediaIds = isset($input['media_ids']) && is_array($input['media_ids']) ? $input['media_ids'] : [];
            
            if ($messageId <= 0 || empty($mediaIds)) {
                throw new \InvalidArgumentException('Invalid message ID or media IDs');
            }
            
            // Update media records to link to message
            $placeholders = [];
            $params = [':message_id' => $messageId];
            foreach ($mediaIds as $index => $mediaId) {
                $placeholders[] = ':media_id_' . $index;
                $params[':media_id_' . $index] = (int)$mediaId;
            }
            
            $sql = 'UPDATE chat_media SET message_id = :message_id WHERE id IN (' . implode(', ', $placeholders) . ')';
            Database::execute($sql, $params);
            
            echo json_encode([
                'success' => true,
                'linked_count' => count($mediaIds),
            ]);
            break;
            
        case 'view':
            // View/download media file
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for view action');
            }
            
            $mediaId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            
            if ($mediaId <= 0) {
                throw new \InvalidArgumentException('Invalid media ID');
            }
            
            // Get media info
            $sql = 'SELECT * FROM chat_media WHERE id = :id';
            $media = Database::queryOne($sql, [':id' => $mediaId]);
            
            if (!$media) {
                http_response_code(404);
                echo json_encode(['error' => 'Media not found']);
                exit;
            }
            
            $filePath = ICHAT_ROOT . '/' . $media['file_path'];
            
            if (!file_exists($filePath)) {
                http_response_code(404);
                echo json_encode(['error' => 'File not found']);
                exit;
            }
            
            // Log file download
            $auditService = new AuditService();
            $authService = new AuthService();
            $viewer = $authService->getCurrentUser();
            
            // Ensure session is started for fallback
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            $viewerHandle = $viewer['username'] ?? ($_SESSION['user_handle'] ?? 'guest');
            $viewerUserId = $viewer['id'] ?? null;
            
            $auditService->logFileDownload(
                $viewerHandle,
                $viewerUserId,
                (string)$mediaId
            );
            
            // Output file with appropriate headers
            header('Content-Type: ' . ($media['mime_type'] ?? 'application/octet-stream'));
            header('Content-Disposition: inline; filename="' . addslashes($media['filename']) . '"');
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: public, max-age=3600');
            readfile($filePath);
            exit;
            
        case 'get':
            // Get media for a message
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for get action');
            }
            
            $messageId = isset($_GET['message_id']) ? (int)$_GET['message_id'] : 0;
            
            if ($messageId <= 0) {
                throw new \InvalidArgumentException('Invalid message ID');
            }
            
            $sql = 'SELECT id, media_type, filename, file_path, file_size, mime_type, duration
                    FROM chat_media
                    WHERE message_id = :message_id
                    ORDER BY uploaded_at ASC';
            
            $media = Database::query($sql, [':message_id' => $messageId]);
            
            echo json_encode([
                'success' => true,
                'media' => $media ?: [],
            ]);
            break;
            
        default:
            throw new \InvalidArgumentException('Invalid action');
    }
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    http_response_code(500);
    error_log('Chat media API error: ' . $e->getMessage());
    echo json_encode(['error' => 'Failed to process request']);
} catch (\Exception $e) {
    http_response_code(500);
    error_log('Chat media API error: ' . $e->getMessage());
    echo json_encode(['error' => 'Internal server error']);
}

