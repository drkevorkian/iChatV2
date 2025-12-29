<?php
/**
 * Sentinel Chat Platform - Pinky & Brain Bot API
 * 
 * Handles Pinky & Brain bot interactions and responses.
 * 
 * Security: Bot messages are clearly marked and rate-limited.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\PinkyBrainService;
use iChat\Services\SecurityService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// Validate API secret
if (!$security->validateApiSecret()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? 'get-response';
$pinkyBrainService = new PinkyBrainService();

try {
    switch ($action) {
        case 'get-response':
            // Get Pinky's response for a room
            $roomId = $security->sanitizeInput($_GET['room_id'] ?? '');
            
            if (empty($roomId)) {
                throw new \InvalidArgumentException('Missing room_id parameter');
            }
            
            if (!$security->validateRoomId($roomId)) {
                throw new \InvalidArgumentException('Invalid room ID format');
            }
            
            $pinkyResponse = $pinkyBrainService->getPinkyResponse($roomId);
            
            echo json_encode([
                'success' => true,
                'pinky_response' => $pinkyResponse,
            ]);
            break;
            
        case 'toggle':
            // Toggle bot on/off (handled in messages.php for /p&b command)
            http_response_code(400);
            echo json_encode(['error' => 'Use /p&b command in chat']);
            break;
            
        case 'status':
            // Get bot status for a room
            $roomId = $security->sanitizeInput($_GET['room_id'] ?? '');
            
            if (empty($roomId)) {
                throw new \InvalidArgumentException('Missing room_id parameter');
            }
            
            $isActive = $pinkyBrainService->isBotActive($roomId);
            
            echo json_encode([
                'success' => true,
                'is_active' => $isActive,
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    error_log('Pinky & Brain API error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'error' => 'Request failed',
        'message' => $e->getMessage(),
    ]);
}

