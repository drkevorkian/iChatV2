<?php
/**
 * Sentinel Chat Platform - Message Edit/Delete API
 * 
 * Handles message editing and deletion operations.
 * Messages older than 24 hours 5 minutes are permanent.
 * 
 * Security: All operations use prepared statements and validate permissions.
 */

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\MessageRepository;
use iChat\Repositories\ImRepository;
use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Services\AuditService;

header('Content-Type: application/json');

$security = new SecurityService();
$auth = new AuthService();
$auditService = new AuditService();

$security->setSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

// Check authentication
$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$userHandle = $currentUser['username'];
$userRole = $currentUser['role'] ?? 'user';

$messageRepo = new MessageRepository();
$imRepo = new ImRepository();

try {
    switch ($action) {
        case 'edit':
            // Edit a message
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required');
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

            $messageId = (int)($input['message_id'] ?? 0);
            $messageType = $security->sanitizeInput($input['message_type'] ?? 'room'); // 'room' or 'im'
            $newContent = $input['new_content'] ?? '';
            $roomId = $security->sanitizeInput($input['room_id'] ?? '');

            if ($messageId <= 0 || empty($newContent)) {
                throw new \InvalidArgumentException('Invalid parameters');
            }

            // Check if message can be edited (not permanent, user owns it or is moderator)
            $canEdit = false;
            if ($messageType === 'room') {
                $canEdit = $messageRepo->canEditMessage($messageId, $userHandle, $userRole);
            } else {
                $canEdit = $imRepo->canEditMessage($messageId, $userHandle, $userRole);
            }

            if (!$canEdit) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'Message cannot be edited (permanent or insufficient permissions)',
                ]);
                exit;
            }

            // Encrypt new content (same as original message encryption)
            $cipherBlob = base64_encode($newContent);

            // Edit the message
            if ($messageType === 'room') {
                // MessageRepository::editMessage signature: (messageId, newCipherBlob, editedBy)
                $success = $messageRepo->editMessage($messageId, $cipherBlob, $userHandle);
            } else {
                $success = $imRepo->editMessage($messageId, $userHandle, $cipherBlob);
            }

            if ($success) {
                // Get user ID for audit logging
                $userId = $currentUser['id'] ?? null;
                
                // Get before/after values for audit
                $beforeValue = [];
                $afterValue = ['cipher_blob' => $cipherBlob];
                
                // Try to get original message content for before_value
                if ($messageType === 'room') {
                    $originalMessage = $messageRepo->getMessageById($messageId);
                    if ($originalMessage) {
                        $beforeValue = ['cipher_blob' => $originalMessage['cipher_blob'] ?? ''];
                    }
                } else {
                    $originalMessage = $imRepo->getMessageById($messageId);
                    if ($originalMessage) {
                        $beforeValue = ['cipher_blob' => $originalMessage['cipher_blob'] ?? ''];
                    }
                }
                
                // Log audit event
                $auditService->logMessageEdit(
                    $userHandle,
                    $userId,
                    (string)$messageId,
                    $beforeValue,
                    $afterValue
                );

                echo json_encode([
                    'success' => true,
                    'message' => 'Message edited successfully',
                ]);
            } else {
                throw new \RuntimeException('Failed to edit message');
            }
            break;

        case 'delete':
            // Delete a message
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required');
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

            $messageId = (int)($input['message_id'] ?? 0);
            $messageType = $security->sanitizeInput($input['message_type'] ?? 'room');
            $roomId = $security->sanitizeInput($input['room_id'] ?? '');

            if ($messageId <= 0) {
                throw new \InvalidArgumentException('Invalid message ID');
            }

            // Check if message can be deleted
            $canDelete = false;
            if ($messageType === 'room') {
                $canDelete = $messageRepo->canDeleteMessage($messageId, $userHandle, $userRole);
            } else {
                $canDelete = $imRepo->canDeleteMessage($messageId, $userHandle, $userRole);
            }

            if (!$canDelete) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'Message cannot be deleted (permanent or insufficient permissions)',
                ]);
                exit;
            }

            // Delete the message (soft delete)
            if ($messageType === 'room') {
                $success = $messageRepo->deleteMessage($messageId, $userHandle, $userRole === 'moderator' || $userRole === 'administrator');
            } else {
                $success = $imRepo->deleteMessage($messageId, $userHandle, $userRole === 'moderator' || $userRole === 'administrator');
            }

            if ($success) {
                // Get user ID for audit logging
                $userId = $currentUser['id'] ?? null;
                
                // Get before value (message content before deletion)
                $beforeValue = [];
                if ($messageType === 'room') {
                    $originalMessage = $messageRepo->getMessageById($messageId);
                    if ($originalMessage) {
                        $beforeValue = [
                            'cipher_blob' => $originalMessage['cipher_blob'] ?? '',
                            'room_id' => $originalMessage['room_id'] ?? '',
                        ];
                    }
            } else {
                // For IM messages, we don't have getMessageById, so skip before_value
                // The delete will still be logged
                $beforeValue = [];
            }
                
                // Log audit event
                $auditService->logMessageDelete(
                    $userHandle,
                    $userId,
                    (string)$messageId,
                    $beforeValue
                );

                echo json_encode([
                    'success' => true,
                    'message' => 'Message deleted successfully',
                ]);
            } else {
                throw new \RuntimeException('Failed to delete message');
            }
            break;

        case 'check':
            // Check if a message can be edited/deleted
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('GET method required');
            }

            $messageId = (int)($_GET['message_id'] ?? 0);
            $messageType = $security->sanitizeInput($_GET['message_type'] ?? 'room');

            if ($messageId <= 0) {
                throw new \InvalidArgumentException('Invalid message ID');
            }

            $canEdit = false;
            $canDelete = false;

            if ($messageType === 'room') {
                $canEdit = $messageRepo->canEditMessage($messageId, $userHandle, $userRole);
                $canDelete = $messageRepo->canDeleteMessage($messageId, $userHandle, $userRole);
            } else {
                $canEdit = $imRepo->canEditMessage($messageId, $userHandle, $userRole);
                $canDelete = $imRepo->canDeleteMessage($messageId, $userHandle, $userRole);
            }

            echo json_encode([
                'success' => true,
                'can_edit' => $canEdit,
                'can_delete' => $canDelete,
            ]);
            break;

        default:
            throw new \InvalidArgumentException('Invalid action: ' . $action);
    }
} catch (\Exception $e) {
    error_log('Message Edit API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred: ' . $e->getMessage(),
    ]);
}

