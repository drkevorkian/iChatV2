<?php
/**
 * Sentinel Chat Platform - Authentication API
 * 
 * Handles user registration, login, logout, and session management.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\AuthService;
use iChat\Services\SecurityService;
use iChat\Services\AuditService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

$authService = new AuthService();
$auditService = new AuditService();

try {
    switch ($action) {
        case 'register':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $username = $security->sanitizeInput($input['username'] ?? '');
            $email = $security->sanitizeInput($input['email'] ?? '');
            $password = $input['password'] ?? '';
            $role = $security->sanitizeInput($input['role'] ?? 'user');
            
            $result = $authService->register($username, $email, $password, $role);
            
            if ($result['success']) {
                echo json_encode($result);
            } else {
                http_response_code(400);
                echo json_encode($result);
            }
            break;
            
        case 'login':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $username = $security->sanitizeInput($input['username'] ?? '');
            $password = $input['password'] ?? '';
            
            $result = $authService->login($username, $password);
            
            // Log audit event
            if ($result['success']) {
                $userId = $result['user']['id'] ?? null;
                $auditService->logLogin($username, $userId, true);
            } else {
                // Log failed login attempt
                $auditService->logFailedLogin($username, $result['error'] ?? 'Invalid credentials');
            }
            
            if ($result['success']) {
                echo json_encode($result);
            } else {
                http_response_code(401);
                echo json_encode($result);
            }
            break;
            
        case 'logout':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
            }
            
            // Get session token from session or input
            $sessionToken = $_SESSION['auth_token'] ?? null;
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($sessionToken) && is_array($input)) {
                $sessionToken = $input['session_token'] ?? null;
            }
            
            if (empty($sessionToken)) {
                http_response_code(400);
                echo json_encode(['error' => 'No session token provided']);
                exit;
            }
            
            // Get user info before logout (for audit logging)
            $user = $authService->getCurrentUser();
            $userHandle = $user['username'] ?? 'unknown';
            $userId = $user['id'] ?? null;
            
            $success = $authService->logout($sessionToken);
            
            // Log audit event
            if ($success && !empty($userHandle)) {
                $auditService->logLogout($userHandle, $userId);
            }
            
            // Clear PHP session
            session_destroy();
            $_SESSION = [];
            
            echo json_encode([
                'success' => $success,
                'message' => 'Logged out successfully',
            ]);
            break;
            
        case 'check':
            // Check authentication status
            $user = $authService->getCurrentUser();
            
            echo json_encode([
                'success' => true,
                'authenticated' => $user !== null,
                'user' => $user,
            ]);
            break;
            
        case 'current':
            // Get current user
            $user = $authService->getCurrentUser();
            
            if ($user === null) {
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'error' => 'Not authenticated',
                ]);
                exit;
            }
            
            echo json_encode([
                'success' => true,
                'user' => $user,
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    error_log('Auth API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Request failed',
        'message' => $e->getMessage(),
    ]);
}

