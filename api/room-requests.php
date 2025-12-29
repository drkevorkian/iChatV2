<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Repositories\RoomRequestRepository;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

$authService = new AuthService();
$currentUser = $authService->getCurrentUser();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

$roomRequestRepo = new RoomRequestRepository();

try {
    switch ($action) {
        case 'create':
            // Create a new room request
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for create action');
            }
            
            if ($currentUser === null) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized - Login required']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $roomName = $security->sanitizeInput($input['room_name'] ?? '');
            $roomDisplayName = $security->sanitizeInput($input['room_display_name'] ?? '');
            $password = $input['password'] ?? null;
            $description = $security->sanitizeInput($input['description'] ?? '');
            
            // Validate inputs
            if (empty($roomName) || empty($roomDisplayName)) {
                throw new \InvalidArgumentException('Room name and display name are required');
            }
            
            // Validate room name format (alphanumeric, underscore, hyphen)
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $roomName)) {
                throw new \InvalidArgumentException('Room name must contain only letters, numbers, underscores, and hyphens');
            }
            
            // Hash password if provided
            $passwordHash = null;
            if (!empty($password)) {
                if (strlen($password) < 4) {
                    throw new \InvalidArgumentException('Password must be at least 4 characters');
                }
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            }
            
            $requesterHandle = $currentUser['username'];
            $requesterUserId = $currentUser['id'] ?? null;
            
            try {
                $requestId = $roomRequestRepo->createRequest(
                    $roomName,
                    $roomDisplayName,
                    $requesterHandle,
                    $requesterUserId,
                    $passwordHash,
                    $description
                );
                
                echo json_encode([
                    'success' => true,
                    'request_id' => $requestId,
                    'message' => 'Room request submitted successfully'
                ]);
            } catch (\RuntimeException $e) {
                // Re-throw with more context
                throw new \RuntimeException('Failed to create room request: ' . $e->getMessage(), 0, $e);
            }
            break;
            
        case 'list':
            // List all room requests (admin only)
            if ($currentUser === null || $currentUser['role'] !== 'administrator') {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - Admin access required']);
                exit;
            }
            
            $status = $security->sanitizeInput($_GET['status'] ?? '');
            $requests = $roomRequestRepo->getAllRequests($status ?: null);
            
            echo json_encode([
                'success' => true,
                'requests' => $requests
            ]);
            break;
            
        case 'approve':
            // Approve a room request (admin only)
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for approve action');
            }
            
            if ($currentUser === null || $currentUser['role'] !== 'administrator') {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - Admin access required']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $requestId = (int)($input['request_id'] ?? 0);
            $adminNotes = $security->sanitizeInput($input['admin_notes'] ?? '');
            
            if ($requestId <= 0) {
                throw new \InvalidArgumentException('Invalid request ID');
            }
            
            // Get the request to generate invite code
            $request = $roomRequestRepo->getRequestById($requestId);
            if (!$request) {
                throw new \InvalidArgumentException('Request not found');
            }
            
            // Generate invite code
            $inviteCode = $roomRequestRepo->generateInviteCode();
            
            // Update request status
            $success = $roomRequestRepo->updateRequestStatus(
                $requestId,
                'approved',
                $currentUser['username'],
                $adminNotes,
                $inviteCode
            );
            
            if ($success) {
                // Grant access to the requester as owner
                $roomRequestRepo->grantRoomAccess(
                    $request['room_name'],
                    $request['requester_handle'],
                    $request['requester_user_id'],
                    'owner'
                );
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Room request approved',
                    'invite_code' => $inviteCode
                ]);
            } else {
                throw new \RuntimeException('Failed to update request status');
            }
            break;
            
        case 'deny':
            // Deny a room request (admin only)
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for deny action');
            }
            
            if ($currentUser === null || $currentUser['role'] !== 'administrator') {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - Admin access required']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $requestId = (int)($input['request_id'] ?? 0);
            $adminNotes = $security->sanitizeInput($input['admin_notes'] ?? '');
            
            if ($requestId <= 0) {
                throw new \InvalidArgumentException('Invalid request ID');
            }
            
            $success = $roomRequestRepo->updateRequestStatus(
                $requestId,
                'denied',
                $currentUser['username'],
                $adminNotes
            );
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Room request denied'
                ]);
            } else {
                throw new \RuntimeException('Failed to update request status');
            }
            break;
            
        case 'join':
            // Join a room using invite code
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for join action');
            }
            
            if ($currentUser === null) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized - Login required']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $inviteCode = strtoupper($security->sanitizeInput($input['invite_code'] ?? ''));
            $password = $input['password'] ?? null;
            
            if (empty($inviteCode)) {
                throw new \InvalidArgumentException('Invite code is required');
            }
            
            // Get room by invite code
            $room = $roomRequestRepo->getRoomByInviteCode($inviteCode);
            if (!$room) {
                throw new \InvalidArgumentException('Invalid invite code');
            }
            
            // Verify password if room has one
            if (!empty($room['password_hash'])) {
                if (empty($password)) {
                    throw new \InvalidArgumentException('Password is required for this room');
                }
                if (!password_verify($password, $room['password_hash'])) {
                    throw new \InvalidArgumentException('Invalid password');
                }
            }
            
            // Grant access
            $success = $roomRequestRepo->grantRoomAccess(
                $room['room_name'],
                $currentUser['username'],
                $currentUser['id'] ?? null,
                'invited'
            );
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Access granted',
                    'room_name' => $room['room_name'],
                    'room_display_name' => $room['room_display_name']
                ]);
            } else {
                throw new \RuntimeException('Failed to grant access');
            }
            break;
            
        case 'update-password':
            // Update room password (owner only)
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for update-password action');
            }
            
            if ($currentUser === null) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized - Login required']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $roomName = $security->sanitizeInput($input['room_name'] ?? '');
            $password = $input['password'] ?? null; // null to remove password
            
            if (empty($roomName)) {
                throw new \InvalidArgumentException('Room name is required');
            }
            
            // Verify user is owner
            if (!$roomRequestRepo->isRoomOwner($roomName, $currentUser['username'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - You must be the room owner']);
                exit;
            }
            
            // Hash password if provided
            $passwordHash = null;
            if (!empty($password)) {
                if (strlen($password) < 4) {
                    throw new \InvalidArgumentException('Password must be at least 4 characters');
                }
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            }
            
            $success = $roomRequestRepo->updateRoomPassword($roomName, $currentUser['username'], $passwordHash);
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => $passwordHash ? 'Password updated successfully' : 'Password removed successfully'
                ]);
            } else {
                throw new \RuntimeException('Failed to update password');
            }
            break;
            
        case 'room-info':
            // Get room info for owner
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for room-info action');
            }
            
            if ($currentUser === null) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized - Login required']);
                exit;
            }
            
            $roomName = $security->sanitizeInput($_GET['room_name'] ?? '');
            
            if (empty($roomName)) {
                throw new \InvalidArgumentException('Room name is required');
            }
            
            $roomInfo = $roomRequestRepo->getRoomInfoForOwner($roomName, $currentUser['username']);
            
            if ($roomInfo) {
                echo json_encode([
                    'success' => true,
                    'room_info' => $roomInfo
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Room not found or you are not the owner'
                ]);
            }
            break;
            
        default:
            throw new \InvalidArgumentException('Invalid action');
    }
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    http_response_code(500);
    $errorMessage = $e->getMessage();
    // Log full error details for debugging
    error_log('Room requests API RuntimeException: ' . $errorMessage);
    if ($e->getPrevious()) {
        error_log('Previous exception: ' . $e->getPrevious()->getMessage());
    }
    // Provide more helpful error message if table doesn't exist
    if (strpos($errorMessage, 'room_requests') !== false || 
        strpos($errorMessage, "doesn't exist") !== false ||
        (strpos($errorMessage, 'Table') !== false && strpos($errorMessage, 'doesn\'t exist') !== false)) {
        $errorMessage = 'Database table not found. Please apply patch 008_add_room_requests first.';
    }
    echo json_encode(['error' => $errorMessage]);
} catch (\Exception $e) {
    error_log('Room requests API error: ' . $e->getMessage());
    http_response_code(500);
    $errorMessage = 'Internal server error';
    // Provide more helpful error message if table doesn't exist
    if (strpos($e->getMessage(), 'room_requests') !== false || strpos($e->getMessage(), "doesn't exist") !== false) {
        $errorMessage = 'Database table not found. Please apply patch 008_add_room_requests first.';
    }
    echo json_encode(['error' => $errorMessage]);
}

