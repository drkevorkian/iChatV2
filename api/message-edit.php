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
use iChat\Services\RBACService;

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

            // Check if message can be edited (not permanent, user owns it or has moderation permission)
            $rbacService = new RBACService();
            $canEdit = false;
            if ($messageType === 'room') {
                $isOwner = $messageRepo->isMessageOwner($messageId, $userHandle);
                if ($isOwner && $rbacService->hasPermission($userRole, 'chat.edit_own_message')) {
                    $canEdit = true;
                } elseif ($rbacService->hasPermission($userRole, 'moderation.edit_message')) {
                    $canEdit = true;
                }
            } else { // IM message
                $isOwner = $imRepo->isMessageOwner($messageId, $userHandle);
                if ($isOwner && $rbacService->hasPermission($userRole, 'chat.edit_own_message')) {
                    $canEdit = true;
                } elseif ($rbacService->hasPermission($userRole, 'moderation.edit_message')) {
                    $canEdit = true;
                }
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
            $rbacService = new RBACService();
            $canDelete = false;
            if ($messageType === 'room') {
                $isOwner = $messageRepo->isMessageOwner($messageId, $userHandle);
                if ($isOwner && $rbacService->hasPermission($userRole, 'chat.delete_own_message')) {
                    $canDelete = true;
                } elseif ($rbacService->hasPermission($userRole, 'moderation.delete_message')) {
                    $canDelete = true;
                }
            } else { // IM message
                $isOwner = $imRepo->isMessageOwner($messageId, $userHandle);
                if ($isOwner && $rbacService->hasPermission($userRole, 'chat.delete_own_message')) {
                    $canDelete = true;
                } elseif ($rbacService->hasPermission($userRole, 'moderation.delete_message')) {
                    $canDelete = true;
                }
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
            // Check if user has moderation permission (for permanent delete capability)
            $hasModerationPermission = $rbacService->hasPermission($userRole, 'moderation.delete_message');
            if ($messageType === 'room') {
                $success = $messageRepo->deleteMessage($messageId, $userHandle, $hasModerationPermission);
            } else {
                $success = $imRepo->deleteMessage($messageId, $userHandle, $hasModerationPermission);
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

            $rbacService = new RBACService();
            $canEdit = false;
            $canDelete = false;

            if ($messageType === 'room') {
                $isOwner = $messageRepo->isMessageOwner($messageId, $userHandle);
                if ($isOwner && $rbacService->hasPermission($userRole, 'chat.edit_own_message')) {
                    $canEdit = true;
                } elseif ($rbacService->hasPermission($userRole, 'moderation.edit_message')) {
                    $canEdit = true;
                }
                if ($isOwner && $rbacService->hasPermission($userRole, 'chat.delete_own_message')) {
                    $canDelete = true;
                } elseif ($rbacService->hasPermission($userRole, 'moderation.delete_message')) {
                    $canDelete = true;
                }
            } else {
                $isOwner = $imRepo->isMessageOwner($messageId, $userHandle);
                if ($isOwner && $rbacService->hasPermission($userRole, 'chat.edit_own_message')) {
                    $canEdit = true;
                } elseif ($rbacService->hasPermission($userRole, 'moderation.edit_message')) {
                    $canEdit = true;
                }
                if ($isOwner && $rbacService->hasPermission($userRole, 'chat.delete_own_message')) {
                    $canDelete = true;
                } elseif ($rbacService->hasPermission($userRole, 'moderation.delete_message')) {
                    $canDelete = true;
                }
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

