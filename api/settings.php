<?php
/**
 * Sentinel Chat Platform - Settings API Endpoint
 * 
 * Handles user settings operations including chat appearance preferences.
 * 
 * Security: All operations use prepared statements and input validation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\SettingsRepository;
use iChat\Services\SecurityService;
use iChat\Services\AuthService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// Start session if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get current user
$authService = new AuthService();
$currentUser = $authService->getCurrentUser();
$userHandle = $currentUser['username'] ?? $_SESSION['user_handle'] ?? '';

if (empty($userHandle)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? 'get';
$repository = new SettingsRepository();

try {
    switch ($action) {
        case 'get':
            // Get user settings
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for get action');
            }
            
            $settings = $repository->getSettings($userHandle);
            
            echo json_encode([
                'success' => true,
                'settings' => $settings,
            ]);
            break;
            
        case 'update':
            // Update user settings
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for update action');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $settings = $input['settings'] ?? [];
            if (empty($settings)) {
                throw new \InvalidArgumentException('Settings data required');
            }
            
            $userId = $currentUser['id'] ?? null;
            $success = $repository->updateSettings($userHandle, $userId, $settings);
            
            if ($success) {
                // Return updated settings
                $updatedSettings = $repository->getSettings($userHandle);
                echo json_encode([
                    'success' => true,
                    'settings' => $updatedSettings,
                    'message' => 'Settings updated successfully',
                ]);
            } else {
                throw new \RuntimeException('Failed to update settings');
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
    error_log('Settings API error: ' . $e->getMessage());
    echo json_encode(['error' => 'Failed to process request']);
} catch (\Exception $e) {
    http_response_code(500);
    error_log('Settings API error: ' . $e->getMessage());
    echo json_encode(['error' => 'Internal server error']);
}

