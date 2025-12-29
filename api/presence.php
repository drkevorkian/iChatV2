<?php
/**
 * Sentinel Chat Platform - Presence API Endpoint
 * 
 * Handles user presence (online/offline) tracking for chat rooms.
 * Users send heartbeat updates to remain online.
 * 
 * Security: Uses prepared statements for all database queries.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\PresenceRepository;
use iChat\Repositories\UserManagementRepository;
use iChat\Services\SecurityService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$repository = new PresenceRepository();

try {
    switch ($action) {
        case 'update':
        case 'heartbeat':
            // Update user presence (heartbeat)
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for heartbeat action');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $roomId = $security->sanitizeInput($input['room_id'] ?? '');
            $userHandle = $security->sanitizeInput($input['user_handle'] ?? '');
            
            if (empty($roomId) || empty($userHandle)) {
                throw new \InvalidArgumentException('Missing required fields');
            }
            
            if (!$security->validateRoomId($roomId)) {
                throw new \InvalidArgumentException('Invalid room ID format');
            }
            
            if (!$security->validateHandle($userHandle)) {
                throw new \InvalidArgumentException('Invalid user handle format');
            }
            
            // Get IP address and session ID
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
            if (strpos($ipAddress, ',') !== false) {
                $ipAddress = trim(explode(',', $ipAddress)[0]);
            }
            
            // Check if user is banned
            $userManagementRepo = new UserManagementRepository();
            if ($userManagementRepo->isBanned($userHandle, $ipAddress)) {
                http_response_code(403);
                $banInfo = $userManagementRepo->getBanInfo($userHandle, $ipAddress);
                $banReason = $banInfo['reason'] ?? 'No reason provided';
                echo json_encode([
                    'success' => false,
                    'error' => 'You are banned. Reason: ' . $banReason,
                    'banned' => true,
                ]);
                exit;
            }
            
            // Get session ID
            $sessionId = session_id();
            
            $success = $repository->updatePresence($roomId, $userHandle, $ipAddress, $sessionId);
            
            echo json_encode([
                'success' => $success,
            ]);
            break;
            
        case 'list':
        case 'online':
            // Get online users for a room
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for list action');
            }
            
            $roomId = $security->sanitizeInput($_GET['room_id'] ?? '');
            
            if (empty($roomId) || !$security->validateRoomId($roomId)) {
                throw new \InvalidArgumentException('Invalid room ID');
            }
            
            $users = $repository->getOnlineUsers($roomId);
            
            echo json_encode([
                'success' => true,
                'users' => $users,
                'count' => count($users),
            ]);
            break;
            
        case 'leave':
            // User left the room
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for leave action');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $roomId = $security->sanitizeInput($input['room_id'] ?? '');
            $userHandle = $security->sanitizeInput($input['user_handle'] ?? '');
            
            if (empty($roomId) || empty($userHandle)) {
                throw new \InvalidArgumentException('Missing required fields');
            }
            
            if (!$security->validateRoomId($roomId) || !$security->validateHandle($userHandle)) {
                throw new \InvalidArgumentException('Invalid input format');
            }
            
            $success = $repository->removePresence($roomId, $userHandle);
            
            echo json_encode([
                'success' => $success,
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    error_log('Presence API error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'error' => 'Request failed',
        'message' => $e->getMessage(),
    ]);
}

