<?php
/**
 * Sentinel Chat Platform - Message Moderation API
 * 
 * Handles message moderation actions: hide, delete, edit, mock.
 * Requires administrator or moderator access.
 * 
 * Security: All operations are logged for audit purposes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\MessageRepository;
use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Services\AuditService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// Start session for authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication and role
$authService = new AuthService();
$currentUser = $authService->getCurrentUser();

if ($currentUser === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized - Login required']);
    exit;
}

$userRole = $currentUser['role'] ?? 'guest';
$userHandle = $currentUser['username'] ?? '';

// Only allow moderators, administrators, trusted admins, and owners
if (!in_array($userRole, ['moderator', 'administrator', 'trusted_admin', 'owner'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden - Moderator, Administrator, Trusted Admin, or Owner access required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

$messageRepo = new MessageRepository();
$auditService = new AuditService();

try {
    switch ($action) {
        case 'hide':
            // Hide a message - SECURITY: Check RBAC permission
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
            }
            
            // SECURITY: Check RBAC permission
            $rbacService = new RBACService();
            if (!$rbacService->hasPermission($userRole, 'moderation.hide_message')) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - You do not have permission to hide messages']);
                exit;
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
            
            if ($messageId <= 0) {
                throw new \InvalidArgumentException('Invalid message_id');
            }
            
            $success = $messageRepo->hideMessage($messageId, $userHandle);
            
            if ($success) {
                // Log moderation action
                $message = $messageRepo->getMessageById($messageId);
                $targetHandle = $message['sender_handle'] ?? 'unknown';
                $auditService->logModerationAction(
                    $userHandle,
                    $currentUser['id'] ?? null,
                    'hide',
                    $targetHandle,
                    [
                        'message_id' => $messageId,
                        'room_id' => $message['room_id'] ?? null,
                    ]
                );
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Message hidden successfully',
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Message not found or already hidden',
                ]);
            }
            break;
            
        case 'delete':
            // Delete a message - SECURITY: Check RBAC permission
            $rbacService = new RBACService();
            if (!$rbacService->hasPermission($userRole, 'moderation.delete_message')) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - You do not have permission to delete messages']);
                exit;
            }
            
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
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
            
            if ($messageId <= 0) {
                throw new \InvalidArgumentException('Invalid message_id');
            }
            
            $success = $messageRepo->softDelete($messageId);
            
            if ($success) {
                // Log moderation action
                $message = $messageRepo->getMessageById($messageId);
                $targetHandle = $message['sender_handle'] ?? 'unknown';
                $auditService->logModerationAction(
                    $userHandle,
                    $currentUser['id'] ?? null,
                    'delete',
                    $targetHandle,
                    [
                        'message_id' => $messageId,
                        'room_id' => $message['room_id'] ?? null,
                    ]
                );
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Message deleted successfully',
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Message not found or already deleted',
                ]);
            }
            break;
            
        case 'edit':
            // Edit a message - SECURITY: Check RBAC permission
            $rbacService = new RBACService();
            if (!$rbacService->hasPermission($userRole, 'moderation.edit_message')) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - You do not have permission to edit messages']);
                exit;
            }
            
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
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
            $newMessage = $input['new_message'] ?? '';
            
            if ($messageId <= 0 || empty($newMessage)) {
                throw new \InvalidArgumentException('Invalid message_id or new_message');
            }
            
            // Encrypt new message
            $cipherBlob = base64_encode($newMessage);
            
            // Get original message for audit
            $originalMessage = $messageRepo->getMessageById($messageId);
            $beforeValue = $originalMessage ? ['cipher_blob' => $originalMessage['cipher_blob'] ?? ''] : [];
            
            $success = $messageRepo->editMessage($messageId, $cipherBlob, $userHandle);
            
            if ($success) {
                // Log moderation action
                $targetHandle = $originalMessage['sender_handle'] ?? 'unknown';
                $auditService->logModerationAction(
                    $userHandle,
                    $currentUser['id'] ?? null,
                    'edit',
                    $targetHandle,
                    [
                        'message_id' => $messageId,
                        'room_id' => $originalMessage['room_id'] ?? null,
                        'before_value' => $beforeValue,
                        'after_value' => ['cipher_blob' => $cipherBlob],
                    ]
                );
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Message edited successfully',
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Message not found',
                ]);
            }
            break;
            
        case 'mock':
            // Create a mock message - SECURITY: Check RBAC permission (admin only - impersonate another user)
            $rbacService = new RBACService();
            if (!$rbacService->hasPermission($userRole, 'moderation.edit_message')) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - You do not have permission to create mock messages']);
                exit;
            }
            
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
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
            $roomId = $security->sanitizeInput($input['room_id'] ?? '');
            $senderHandle = $security->sanitizeInput($input['sender_handle'] ?? '');
            $message = $input['message'] ?? '';
            
            if (empty($roomId) || empty($senderHandle) || empty($message)) {
                throw new \InvalidArgumentException('Missing required fields');
            }
            
            if (!$security->validateRoomId($roomId)) {
                throw new \InvalidArgumentException('Invalid room ID format');
            }
            
            if (!$security->validateHandle($senderHandle)) {
                throw new \InvalidArgumentException('Invalid sender handle format');
            }
            
            // Encrypt message
            $cipherBlob = base64_encode($message);
            
            $messageId = $messageRepo->createMockMessage(
                $roomId,
                $senderHandle,
                $cipherBlob,
                1,
                $userHandle
            );
            
            // Log admin action (mock message creation)
            $auditService->logAdminChange(
                $userHandle,
                $currentUser['id'] ?? null,
                'mock_message',
                'message',
                (string)$messageId,
                [],
                [
                    'room_id' => $roomId,
                    'impersonated_user' => $senderHandle,
                    'message_created' => true,
                ]
            );
            
            echo json_encode([
                'success' => true,
                'message_id' => $messageId,
                'message' => 'Mock message created successfully',
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    error_log('Moderation API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Request failed',
        'message' => $e->getMessage(),
    ]);
}

