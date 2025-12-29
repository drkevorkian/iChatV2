<?php
/**
 * Sentinel Chat Platform - Typing Indicators API
 * 
 * Handles typing indicator updates and retrieval.
 * Used as fallback when WebSocket is not available.
 */

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Repositories\TypingIndicatorRepository;

// Initialize services
$security = new SecurityService();
$auth = new AuthService();

// Check authentication
$user = $auth->getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

header('Content-Type: application/json');
$security->setSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'update';

$typingRepo = new TypingIndicatorRepository();

try {
    switch ($action) {
        case 'update':
            // Update typing status
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required');
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }

            $conversationWith = $security->sanitizeInput($input['conversation_with'] ?? '');
            $isTyping = isset($input['is_typing']) ? (bool)$input['is_typing'] : true;

            if (empty($conversationWith)) {
                throw new \InvalidArgumentException('conversation_with is required');
            }

            $success = $typingRepo->updateTyping(
                $user['username'],
                $conversationWith,
                $isTyping
            );

            echo json_encode([
                'success' => $success,
            ]);
            break;

        case 'get':
            // Get typing status for a conversation
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('GET method required');
            }

            $conversationWith = $security->sanitizeInput($_GET['conversation_with'] ?? '');

            if (empty($conversationWith)) {
                throw new \InvalidArgumentException('conversation_with is required');
            }

            $typing = $typingRepo->getTypingStatus($user['username'], $conversationWith);

            echo json_encode([
                'success' => true,
                'is_typing' => $typing !== null && $typing['is_typing'],
                'last_activity' => $typing['last_activity'] ?? null,
            ]);
            break;

        default:
            throw new \InvalidArgumentException('Invalid action: ' . $action);
    }
} catch (\Exception $e) {
    error_log('Typing API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred: ' . $e->getMessage(),
    ]);
}

