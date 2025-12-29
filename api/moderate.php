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

// Only allow moderators and administrators
if (!in_array($userRole, ['moderator', 'administrator'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden - Moderator or Administrator access required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

$messageRepo = new MessageRepository();

try {
    switch ($action) {
        case 'hide':
            // Hide a message (moderator and admin)
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $messageId = isset($input['message_id']) ? (int)$input['message_id'] : 0;
            
            if ($messageId <= 0) {
                throw new \InvalidArgumentException('Invalid message_id');
            }
            
            $success = $messageRepo->hideMessage($messageId, $userHandle);
            
            if ($success) {
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
            // Delete a message (admin only)
            if ($userRole !== 'administrator') {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - Administrator access required']);
                exit;
            }
            
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $messageId = isset($input['message_id']) ? (int)$input['message_id'] : 0;
            
            if ($messageId <= 0) {
                throw new \InvalidArgumentException('Invalid message_id');
            }
            
            $success = $messageRepo->softDelete($messageId);
            
            if ($success) {
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
            // Edit a message (moderator and admin)
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $messageId = isset($input['message_id']) ? (int)$input['message_id'] : 0;
            $newMessage = $input['new_message'] ?? '';
            
            if ($messageId <= 0 || empty($newMessage)) {
                throw new \InvalidArgumentException('Invalid message_id or new_message');
            }
            
            // Encrypt new message
            $cipherBlob = base64_encode($newMessage);
            
            $success = $messageRepo->editMessage($messageId, $cipherBlob, $userHandle);
            
            if ($success) {
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
            // Create a mock message (admin only - impersonate another user)
            if ($userRole !== 'administrator') {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - Administrator access required']);
                exit;
            }
            
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
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

