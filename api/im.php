<?php
/**
 * Sentinel Chat Platform - Instant Messaging API Endpoint
 * 
 * Handles instant message operations including sending, receiving,
 * and read receipt management.
 * 
 * Security: All operations use prepared statements and input validation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\ImRepository;
use iChat\Services\SecurityService;
use iChat\Services\AuditService;
use iChat\Services\RBACService;
use iChat\Services\AuthService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$repository = new ImRepository();

try {
    switch ($action) {
        case 'inbox':
            // Get inbox for a user
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for inbox action');
            }
            
            $userHandle = $security->sanitizeInput($_GET['user'] ?? '');
            
            if (empty($userHandle) || !$security->validateHandle($userHandle)) {
                throw new \InvalidArgumentException('Invalid user handle');
            }
            
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $messages = $repository->getInbox($userHandle, $limit);
            
            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'count' => count($messages),
            ]);
            break;
            
        case 'send':
            // Send an IM
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for send action');
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
            
            $fromUser = $security->sanitizeInput($input['from_user'] ?? '');
            $toUser = $security->sanitizeInput($input['to_user'] ?? '');
            $cipherBlob = $input['cipher_blob'] ?? '';
            $encryptionType = $security->sanitizeInput($input['encryption_type'] ?? 'none');
            $nonce = $security->sanitizeInput($input['nonce'] ?? null);
            
            if (empty($fromUser) || empty($toUser) || empty($cipherBlob)) {
                throw new \InvalidArgumentException('Missing required fields');
            }
            
            if (!$security->validateHandle($fromUser) || !$security->validateHandle($toUser)) {
                throw new \InvalidArgumentException('Invalid user handle format');
            }
            
            // Check RBAC permission
            $rbacService = new RBACService();
            $authService = new AuthService();
            $currentUser = $authService->getCurrentUser();
            $userRole = $currentUser['role'] ?? 'guest';
            if (!$rbacService->hasPermission($userRole, 'im.send_im')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied - You do not have permission to send instant messages.']);
                exit;
            }
            
            // SECURITY: Validate cipher_blob (for E2EE, it's JSON, for fallback it's base64)
            if ($encryptionType === 'e2ee') {
                // E2EE messages are JSON strings - validate JSON parsing
                $decoded = json_decode($cipherBlob, true);
                if (json_last_error() !== JSON_ERROR_NONE || $decoded === null || !isset($decoded['encrypted']) || !isset($decoded['nonce'])) {
                    throw new \InvalidArgumentException('Invalid E2EE cipher_blob format: ' . json_last_error_msg());
                }
            } else {
                // Fallback: validate base64
                if (!base64_decode($cipherBlob, true)) {
                    throw new \InvalidArgumentException('Invalid cipher_blob format');
                }
            }
            
            // Prevent sending to self (but allow it for notes/reminders)
            // Actually, let's allow self-IMs - they can be useful for notes
            
            // Create single conversation entry (no folder needed in conversation-based system)
            $imId = $repository->sendIm($fromUser, $toUser, $cipherBlob, $encryptionType, $nonce);
            
            // Log audit event for IM send
            if ($imId) {
                $auditService = new AuditService();
                $authService = new AuthService();
                $fromUserData = $authService->getUserByHandle($fromUser);
                $fromUserId = $fromUserData['id'] ?? null;
                
                $auditService->logMessageSend(
                    $fromUser,
                    $fromUserId,
                    (string)$imId,
                    'im_' . $fromUser . '_' . $toUser, // Use conversation ID as room_id
                    [
                        'to_user' => $toUser,
                        'encryption_type' => $encryptionType,
                        'has_nonce' => !empty($nonce),
                    ]
                );
            }
            
            echo json_encode([
                'success' => true,
                'im_id' => $imId,
            ]);
            break;
            
        case 'login':
            // Promote queued IMs to sent status
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for login action');
            }
            
            // SECURITY: Secure JSON parsing with error checking to prevent injection attacks
            $rawInput = file_get_contents('php://input');
            if ($rawInput === false) {
                throw new \InvalidArgumentException('Failed to read request body');
            }
            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($input) || !isset($input['im_ids']) || !is_array($input['im_ids'])) {
                throw new \InvalidArgumentException('Invalid JSON input: ' . json_last_error_msg());
            }
            
            $imIds = array_map('intval', $input['im_ids']);
            $count = $repository->markAsSent($imIds);
            
            echo json_encode([
                'success' => true,
                'updated_count' => $count,
            ]);
            break;
            
        case 'open':
            // Mark IM as read
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for open action');
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
            
            $userHandle = $security->sanitizeInput($input['user_handle'] ?? '');
            $otherUser = $security->sanitizeInput($input['other_user'] ?? '');
            
            if (empty($userHandle) || empty($otherUser) || 
                !$security->validateHandle($userHandle) || !$security->validateHandle($otherUser)) {
                throw new \InvalidArgumentException('Invalid parameters');
            }
            
            $count = $repository->markConversationAsRead($userHandle, $otherUser);
            
            echo json_encode([
                'success' => true,
                'marked_read' => $count,
            ]);
            break;
            
        case 'mark-read':
            // Mark a specific message as read
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for mark-read action');
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
            $fromUser = $security->sanitizeInput($input['from_user'] ?? '');
            $userHandle = $auth->getCurrentUser()['username'] ?? '';
            
            if ($messageId <= 0 || empty($fromUser)) {
                throw new \InvalidArgumentException('Invalid parameters');
            }
            
            $success = $repository->markMessageAsRead($messageId, $userHandle, $fromUser);
            
            echo json_encode([
                'success' => $success,
            ]);
            break;
            
        case 'badge':
            // Get unread count
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for badge action');
            }
            
            $userHandle = $security->sanitizeInput($_GET['user'] ?? '');
            
            if (empty($userHandle) || !$security->validateHandle($userHandle)) {
                throw new \InvalidArgumentException('Invalid user handle');
            }
            
            $count = $repository->getUnreadConversationsCount($userHandle);
            
            echo json_encode([
                'success' => true,
                'unread_count' => $count,
            ]);
            break;
            
        case 'conversations':
            // Get conversations list
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for conversations action');
            }
            
            $userHandle = $security->sanitizeInput($_GET['user'] ?? '');
            
            if (empty($userHandle) || !$security->validateHandle($userHandle)) {
                throw new \InvalidArgumentException('Invalid user handle');
            }
            
            $conversations = $repository->getConversations($userHandle);
            
            echo json_encode([
                'success' => true,
                'conversations' => $conversations,
                'count' => count($conversations),
            ]);
            break;
            
        case 'conversation':
            // Get messages in a conversation
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for conversation action');
            }
            
            $userHandle = $security->sanitizeInput($_GET['user'] ?? '');
            $otherUser = $security->sanitizeInput($_GET['with'] ?? '');
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            
            if (empty($userHandle) || empty($otherUser) || !$security->validateHandle($userHandle) || !$security->validateHandle($otherUser)) {
                throw new \InvalidArgumentException('Invalid user handles');
            }
            
            $messages = $repository->getConversationMessages($userHandle, $otherUser, $limit);
            
            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'count' => count($messages),
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    error_log('IM API error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'error' => 'Request failed',
        'message' => $e->getMessage(),
    ]);
}

