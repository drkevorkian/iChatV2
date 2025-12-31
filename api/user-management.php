<?php
/**
 * Sentinel Chat Platform - User Management API
 * 
 * Provides endpoints for managing users: viewing online users,
 * kicking, muting, banning, sending IMs, etc.
 * Requires administrator, trusted_admin, owner, or moderator access.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\UserManagementRepository;
use iChat\Repositories\ImRepository;
use iChat\Repositories\AuthRepository;
use iChat\Repositories\ReportRepository;
use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Services\GeolocationService;
use iChat\Services\AuditService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// Start session if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get current user via AuthService (handles both session and API secret)
$currentUser = null;
$userRole = 'guest';
$isAuthorized = false;
$isAdmin = false;

try {
    $authService = new AuthService();
    $currentUser = $authService->getCurrentUser();
    $userRole = $currentUser['role'] ?? 'guest';
} catch (\Exception $e) {
    // If AuthService fails, try to get role from session directly
    error_log('AuthService::getCurrentUser() failed: ' . $e->getMessage());
    $userRole = $_SESSION['user_role'] ?? 'guest';
    // If we have a role in session, try to construct a minimal user object
    if ($userRole !== 'guest') {
        $currentUser = [
            'id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? '',
            'role' => $userRole
        ];
    }
}

// Check API secret (for proxy calls)
if ($security->validateApiSecret()) {
    $isAuthorized = true;
    // If API secret is valid, check if current user is admin (administrator, trusted_admin, or owner)
    if ($currentUser !== null && in_array($currentUser['role'], ['administrator', 'trusted_admin', 'owner'], true)) {
        $isAdmin = true;
    }
}

// Allow if user is administrator, trusted_admin, owner, or moderator
if (in_array($userRole, ['administrator', 'trusted_admin', 'owner', 'moderator'], true)) {
    $isAuthorized = true;
    if (in_array($userRole, ['administrator', 'trusted_admin', 'owner'], true)) {
        $isAdmin = true;
    }
}

// Also allow if no role is set (development mode - remove in production)
if ($currentUser === null && getenv('APP_ENV') === 'development') {
    $isAuthorized = true;
    $isAdmin = true; // Assume admin in dev mode
}

if (!$isAuthorized) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized - Administrator, Trusted Admin, Owner, or Moderator access required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? 'list';

try {
    $userRepo = new UserManagementRepository();
    $geolocation = new GeolocationService();
    
    switch ($action) {
        case 'list':
            // Get all online users
            $users = $userRepo->getOnlineUsers();
            
            // Add geolocation data
            foreach ($users as &$user) {
                if (!empty($user['ip_address'])) {
                    $user['geolocation'] = $geolocation->getLocation($user['ip_address']);
                } else {
                    $user['geolocation'] = null;
                }
            }
            
            echo json_encode([
                'success' => true,
                'users' => $users,
                'count' => count($users),
            ]);
            break;
            
        case 'all':
            // Get all users (online and offline) - admin only (administrator, trusted_admin, or owner)
            if (!$isAdmin && !in_array($userRole, ['administrator', 'trusted_admin', 'owner'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - Administrator, Trusted Admin, or Owner access required']);
                exit;
            }
            
            try {
                $users = $userRepo->getAllUsers();
                
                // Add geolocation data for users with IP addresses
                foreach ($users as &$user) {
                    if (!empty($user['ip_address'])) {
                        try {
                            $user['geolocation'] = $geolocation->getLocation($user['ip_address']);
                        } catch (\Exception $e) {
                            error_log('Geolocation error for IP ' . $user['ip_address'] . ': ' . $e->getMessage());
                            $user['geolocation'] = null;
                        }
                    } else {
                        $user['geolocation'] = null;
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'users' => $users,
                    'count' => count($users),
                ]);
            } catch (\Exception $e) {
                error_log('User management API getAllUsers error: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to load users: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'room':
            // Get online users in a specific room
            $roomId = $_GET['room_id'] ?? '';
            if (empty($roomId)) {
                throw new \InvalidArgumentException('Missing room_id parameter');
            }
            
            $users = $userRepo->getOnlineUsersInRoom($roomId);
            
            // Add geolocation data
            foreach ($users as &$user) {
                if (!empty($user['ip_address'])) {
                    $user['geolocation'] = $geolocation->getLocation($user['ip_address']);
                } else {
                    $user['geolocation'] = null;
                }
            }
            
            echo json_encode([
                'success' => true,
                'users' => $users,
                'room_id' => $roomId,
                'count' => count($users),
            ]);
            break;
            
        case 'kick':
            // Kick user from room - SECURITY: Check RBAC permission
            $rbacService = new RBACService();
            if (!$rbacService->hasPermission($userRole, 'moderation.kick_user')) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - You do not have permission to kick users']);
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
            $userHandle = $security->sanitizeInput($input['user_handle'] ?? '');
            $roomId = $security->sanitizeInput($input['room_id'] ?? '');
            
            if (empty($userHandle) || empty($roomId)) {
                throw new \InvalidArgumentException('Missing user_handle or room_id');
            }
            
            $userRepo->kickUser($userHandle, $roomId);
            
            // Log moderation action
            $auditService = new AuditService();
            $adminHandle = ($currentUser !== null && isset($currentUser['username'])) ? $currentUser['username'] : 'system';
            $adminUserId = ($currentUser !== null && isset($currentUser['id'])) ? $currentUser['id'] : null;
            $auditService->logModerationAction(
                $adminHandle,
                $adminUserId,
                'kick',
                $userHandle,
                [
                    'room_id' => $roomId,
                ]
            );
            
            echo json_encode([
                'success' => true,
                'message' => "User {$userHandle} kicked from room {$roomId}",
            ]);
            break;
            
        case 'mute':
            // Mute user - SECURITY: Check RBAC permission
            $rbacService = new RBACService();
            if (!$rbacService->hasPermission($userRole, 'moderation.mute_user')) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - You do not have permission to mute users']);
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
            $userHandle = $security->sanitizeInput($input['user_handle'] ?? '');
            $reason = $security->sanitizeInput($input['reason'] ?? 'No reason provided');
            $expiresAt = $input['expires_at'] ?? null;
            
            if (empty($userHandle)) {
                throw new \InvalidArgumentException('Missing user_handle');
            }
            
            $expiresDateTime = null;
            if ($expiresAt) {
                $expiresDateTime = new \DateTime($expiresAt);
            }
            
            $userRepo->muteUser($userHandle, $userRole, $reason, $expiresDateTime);
            
            // Log moderation action
            $auditService = new AuditService();
            $adminHandle = ($currentUser !== null && isset($currentUser['username'])) ? $currentUser['username'] : 'system';
            $adminUserId = ($currentUser !== null && isset($currentUser['id'])) ? $currentUser['id'] : null;
            $auditService->logModerationAction(
                $adminHandle,
                $adminUserId,
                'mute',
                $userHandle,
                [
                    'reason' => $reason,
                    'expires_at' => $expiresAt,
                ]
            );
            
            echo json_encode([
                'success' => true,
                'message' => "User {$userHandle} muted",
            ]);
            break;
            
        case 'ban':
            // Ban user - SECURITY: Check RBAC permission
            $rbacService = new RBACService();
            if (!$rbacService->hasPermission($userRole, 'moderation.ban_user')) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - You do not have permission to ban users']);
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
            $userHandle = $security->sanitizeInput($input['user_handle'] ?? '');
            $reason = $security->sanitizeInput($input['reason'] ?? 'No reason provided');
            $ipAddress = $security->sanitizeInput($input['ip_address'] ?? null);
            $expiresAt = $input['expires_at'] ?? null;
            $email = $security->sanitizeInput($input['email'] ?? null);
            
            if (empty($userHandle)) {
                throw new \InvalidArgumentException('Missing user_handle');
            }
            
            $expiresDateTime = null;
            if ($expiresAt) {
                $expiresDateTime = new \DateTime($expiresAt);
            }
            
            // Get user info before ban for audit
            $targetUser = null;
            try {
                $authRepo = new AuthRepository();
                $targetUser = $authRepo->getUserByUsernameOrEmail($userHandle);
            } catch (\Exception $e) {
                // Continue without target user info
            }
            
            $banId = $userRepo->banUser($userHandle, $userRole, $reason, $ipAddress, $expiresDateTime, $email);
            
            // Log moderation action
            $auditService = new AuditService();
            $adminHandle = ($currentUser !== null && isset($currentUser['username'])) ? $currentUser['username'] : 'system';
            $adminUserId = ($currentUser !== null && isset($currentUser['id'])) ? $currentUser['id'] : null;
            $targetUserId = $targetUser['id'] ?? null;
            
            $auditService->logModerationAction(
                $adminHandle,
                $adminUserId,
                'ban',
                $userHandle,
                [
                    'ban_id' => $banId,
                    'reason' => $reason,
                    'ip_address' => $ipAddress,
                    'expires_at' => $expiresAt,
                    'target_user_id' => $targetUserId,
                ]
            );
            
            // Generate unban URL if email provided
            $unbanUrl = null;
            if ($email && $banId) {
                $unbanToken = $userRepo->generateUnbanToken($banId, $email);
                if ($unbanToken) {
                    $baseUrl = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $unbanUrl = "{$protocol}://{$baseUrl}/iChat/unban.php?token={$unbanToken}";
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => "User {$userHandle} banned",
                'ban_id' => $banId,
                'unban_url' => $unbanUrl,
            ]);
            break;
            
        case 'unban':
            // Unban user - SECURITY: Check RBAC permission
            $rbacService = new RBACService();
            if (!$rbacService->hasPermission($userRole, 'moderation.unban_user')) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - You do not have permission to unban users']);
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
            $userHandle = $security->sanitizeInput($input['user_handle'] ?? '');
            $ipAddress = $security->sanitizeInput($input['ip_address'] ?? null);
            
            if (empty($userHandle)) {
                throw new \InvalidArgumentException('Missing user_handle');
            }
            
            // Get ban info before unban for audit
            $banInfo = $userRepo->getBanInfo($userHandle, $ipAddress);
            
            $success = $userRepo->unbanUser($userHandle, $ipAddress);
            
            if ($success) {
                // Log moderation action
                $auditService = new AuditService();
                $adminHandle = ($currentUser !== null && isset($currentUser['username'])) ? $currentUser['username'] : 'system';
                $adminUserId = ($currentUser !== null && isset($currentUser['id'])) ? $currentUser['id'] : null;
                
                // Safely get ban reason
                $previousBanReason = null;
                if (!empty($banInfo) && is_array($banInfo) && isset($banInfo['reason'])) {
                    $previousBanReason = $banInfo['reason'];
                }
                
                $auditService->logModerationAction(
                    $adminHandle,
                    $adminUserId,
                    'unban',
                    $userHandle,
                    [
                        'ip_address' => $ipAddress,
                        'previous_ban_reason' => $previousBanReason,
                    ]
                );
                
                echo json_encode([
                    'success' => true,
                    'message' => "User {$userHandle} unbanned",
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'User not found or not banned',
                ]);
            }
            break;
            
        case 'ban-info':
            // Get ban information for a user
            $userHandle = $security->sanitizeInput($_GET['user_handle'] ?? '');
            $ipAddress = $security->sanitizeInput($_GET['ip_address'] ?? null);
            
            if (empty($userHandle)) {
                throw new \InvalidArgumentException('Missing user_handle');
            }
            
            $banInfo = $userRepo->getBanInfo($userHandle, $ipAddress);
            $isBanned = !empty($banInfo);
            
            echo json_encode([
                'success' => true,
                'is_banned' => $isBanned,
                'ban_info' => $banInfo,
            ]);
            break;
            
        case 'im':
            // Send IM to user
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
            $toUser = $security->sanitizeInput($input['to_user'] ?? '');
            $message = $security->sanitizeInput($input['message'] ?? '');
            
            if (empty($toUser) || empty($message)) {
                throw new \InvalidArgumentException('Missing to_user or message');
            }
            
            $fromUser = $_SESSION['user_handle'] ?? $userRole;
            $cipherBlob = base64_encode($message); // Simplified encryption
            
            $imRepo = new ImRepository();
            $imRepo->sendIm($fromUser, $toUser, $cipherBlob);
            
            echo json_encode([
                'success' => true,
                'message' => "IM sent to {$toUser}",
            ]);
            break;
            
        case 'update-role':
            // Update user role - SECURITY: Check RBAC permission
            $rbacService = new RBACService();
            if (!$rbacService->hasPermission($userRole, 'admin.manage_users')) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - You do not have permission to change user roles']);
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
            $userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
            $userHandle = $security->sanitizeInput($input['user_handle'] ?? '');
            $newRole = $security->sanitizeInput($input['new_role'] ?? '');
            
            if ($userId <= 0 || empty($userHandle) || empty($newRole)) {
                throw new \InvalidArgumentException('Missing required fields');
            }
            
            // Validate role
            $allowedRoles = ['user', 'moderator', 'administrator', 'trusted_admin', 'owner'];
            if (!in_array($newRole, $allowedRoles, true)) {
                throw new \InvalidArgumentException('Invalid role');
            }
            
            // Get current user info for audit
            $authRepo = new AuthRepository();
            $targetUser = $authRepo->getUserById($userId);
            if (!$targetUser) {
                throw new \InvalidArgumentException('User not found');
            }
            
            $oldRole = $targetUser['role'] ?? 'user';
            
            // Prevent downgrading owner or changing own role
            if ($targetUser['role'] === 'owner' && $newRole !== 'owner') {
                throw new \InvalidArgumentException('Cannot change owner role');
            }
            
            if ($targetUser['username'] === $currentUser['username'] && $newRole !== $oldRole) {
                throw new \InvalidArgumentException('Cannot change your own role');
            }
            
            // Update role
            $success = $authRepo->updateUserRole($userId, $newRole);
            
            if ($success) {
                // Log admin change
                $auditService = new AuditService();
                $adminHandle = ($currentUser !== null && isset($currentUser['username'])) ? $currentUser['username'] : 'system';
                $adminUserId = ($currentUser !== null && isset($currentUser['id'])) ? $currentUser['id'] : null;
                
                $auditService->logAdminChange(
                    $adminHandle,
                    $adminUserId,
                    'user_role_change',
                    'user',
                    (string)$userId,
                    ['role' => $oldRole],
                    ['role' => $newRole]
                );
                
                echo json_encode([
                    'success' => true,
                    'message' => "User {$userHandle} role updated from {$oldRole} to {$newRole}",
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to update role',
                ]);
            }
            break;
            
        case 'report':
            // Report a user
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
            }
            
            // SECURITY: Secure JSON parsing with error checking to prevent injection attacks
            $rawInput = $GLOBALS['HTTP_RAW_POST_DATA'] ?? file_get_contents('php://input');
            if ($rawInput === false) {
                throw new \InvalidArgumentException('Failed to read request body');
            }
            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input: ' . json_last_error_msg());
            }
            
            $reportedUserHandle = $security->sanitizeInput($input['reported_user'] ?? '');
            $messageId = isset($input['message_id']) ? (int)$input['message_id'] : null;
            $reason = $security->sanitizeInput($input['reason'] ?? '');
            $reportType = $security->sanitizeInput($input['report_type'] ?? 'other');
            $roomId = $security->sanitizeInput($input['room_id'] ?? null);
            
            if (empty($reportedUserHandle) || empty($reason)) {
                throw new \InvalidArgumentException('Reported user and reason are required');
            }
            
            if (!$security->validateHandle($reportedUserHandle)) {
                throw new \InvalidArgumentException('Invalid user handle format');
            }
            
            // Validate report type
            $validTypes = ['spam', 'harassment', 'inappropriate_content', 'impersonation', 'other'];
            if (!in_array($reportType, $validTypes, true)) {
                $reportType = 'other';
            }
            
            // Get reporter info
            $reporterHandle = $userHandle;
            $reporterUserId = $_SESSION['user_id'] ?? null;
            
            // Get reported user ID if registered
            $reportedUserId = null;
            if (\iChat\Services\DatabaseHealth::isAvailable()) {
                try {
                    $authRepo = new AuthRepository();
                    $reportedUser = $authRepo->getUserByUsernameOrEmail($reportedUserHandle);
                    if ($reportedUser) {
                        $reportedUserId = $reportedUser['id'];
                    }
                } catch (\Exception $e) {
                    // Continue without user ID
                }
            }
            
            // Store report
            $reportRepo = new ReportRepository();
            $reportId = $reportRepo->createReport(
                $reportedUserHandle,
                $reportedUserId,
                $reporterHandle,
                $reporterUserId,
                $reportType,
                $reason,
                $roomId,
                $messageId
            );
            
            echo json_encode([
                'success' => true,
                'report_id' => $reportId,
                'message' => 'User reported successfully. Moderators will review the report.',
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    error_log('User management API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Request failed',
        'message' => $e->getMessage(),
    ]);
}

