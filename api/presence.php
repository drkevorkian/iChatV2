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
use iChat\Services\AuditService;
use iChat\Services\AuthService;
use iChat\Services\RBACService;

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
            
            // Check if this is a first join (no existing presence in last 30 seconds)
            $isFirstJoin = false;
            if (\iChat\Services\DatabaseHealth::isAvailable()) {
                try {
                    $checkSql = 'SELECT COUNT(*) as count FROM room_presence 
                                 WHERE room_id = :room_id AND user_handle = :user_handle 
                                 AND last_seen > DATE_SUB(NOW(), INTERVAL 30 SECOND)';
                    $result = \iChat\Database::queryOne($checkSql, [
                        ':room_id' => $roomId,
                        ':user_handle' => $userHandle,
                    ]);
                    $isFirstJoin = ((int)($result['count'] ?? 0)) === 0;
                } catch (\Exception $e) {
                    // If check fails, assume it's not a first join to avoid spam
                    $isFirstJoin = false;
                }
            }
            
            $success = $repository->updatePresence($roomId, $userHandle, $ipAddress, $sessionId);
            
            // Log room join on first presence update
            if ($success && $isFirstJoin) {
                $auditService = new AuditService();
                $authRepo = new \iChat\Repositories\AuthRepository();
                $userData = $authRepo->getUserByUsernameOrEmail($userHandle);
                $userId = $userData['id'] ?? null;
                
                $auditService->logRoomJoin($userHandle, $userId, $roomId);
            }
            
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
            
            // SECURITY: Check RBAC permission
            $rbacService = new RBACService();
            $authService = new AuthService();
            $currentUser = $authService->getCurrentUser();
            $userRole = $currentUser['role'] ?? 'guest';
            
            if (!$rbacService->hasPermission($userRole, 'presence.view_online')) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - You do not have permission to view online users.']);
                exit;
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
            $userHandle = $security->sanitizeInput($input['user_handle'] ?? '');
            
            if (empty($roomId) || empty($userHandle)) {
                throw new \InvalidArgumentException('Missing required fields');
            }
            
            if (!$security->validateRoomId($roomId) || !$security->validateHandle($userHandle)) {
                throw new \InvalidArgumentException('Invalid input format');
            }
            
            $success = $repository->removePresence($roomId, $userHandle);
            
            // Log room leave
            if ($success) {
                $auditService = new AuditService();
                $authRepo = new \iChat\Repositories\AuthRepository();
                $userData = $authRepo->getUserByUsernameOrEmail($userHandle);
                $userId = $userData['id'] ?? null;
                
                $auditService->logRoomLeave($userHandle, $userId, $roomId);
            }
            
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

