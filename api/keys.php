<?php
/**
 * Sentinel Chat Platform - E2EE Keys API
 * 
 * Handles public key registration, key exchange requests, and key retrieval.
 * 
 * Security: Only authenticated users can manage their own keys. The server
 * never sees private keys - all encryption/decryption happens client-side.
 */

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\E2EEService;
use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Repositories\AuthRepository;

// Initialize services
$e2eeService = new E2EEService();
$security = new SecurityService();
$auth = new AuthService();

// Check authentication
$user = $auth->getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Check if libsodium is available
if (!$e2eeService->isLibsodiumAvailable()) {
    http_response_code(503);
    echo json_encode([
        'error' => 'E2EE not available',
        'message' => 'libsodium extension is not installed',
    ]);
    exit;
}

header('Content-Type: application/json');
$security->setSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'get';

try {
    switch ($action) {
        case 'register':
            // Register or update user's public key
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required');
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input) || empty($input['public_key'])) {
                throw new \InvalidArgumentException('Invalid JSON input or missing public_key');
            }

            $publicKey = $security->sanitizeInput($input['public_key']);

            $success = $e2eeService->registerPublicKey(
                (int)$user['id'],
                $user['username'],
                $publicKey
            );

            if (!$success) {
                throw new \RuntimeException('Failed to register public key');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Public key registered successfully',
            ]);
            break;

        case 'get':
            // Get a user's public key
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('GET method required');
            }

            $targetHandle = $_GET['user_handle'] ?? $user['username'];
            $targetHandle = $security->sanitizeInput($targetHandle);

            $publicKey = $e2eeService->getPublicKey($targetHandle);

            echo json_encode([
                'success' => true,
                'user_handle' => $targetHandle,
                'public_key' => $publicKey,
                'has_key' => $publicKey !== null,
            ]);
            break;

        case 'exchange':
            // Request or accept a key exchange
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required');
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }

            $exchangeAction = $input['exchange_action'] ?? 'request';
            $exchangeAction = $security->sanitizeInput($exchangeAction);

            if ($exchangeAction === 'request') {
                // Request key exchange
                $toUserHandle = $security->sanitizeInput($input['to_user_handle'] ?? '');
                $publicKey = $security->sanitizeInput($input['public_key'] ?? '');

                if (empty($toUserHandle) || empty($publicKey)) {
                    throw new \InvalidArgumentException('Missing required fields');
                }

                // Get target user ID (use AuthRepository directly)
                $authRepo = new \iChat\Repositories\AuthRepository();
                $targetUser = $authRepo->getUserByUsernameOrEmail($toUserHandle);
                if (!$targetUser) {
                    throw new \InvalidArgumentException('Target user not found');
                }

                $result = $e2eeService->requestKeyExchange(
                    (int)$user['id'],
                    $user['username'],
                    (int)$targetUser['id'],
                    $toUserHandle,
                    $publicKey
                );

                echo json_encode($result);
            } elseif ($exchangeAction === 'accept') {
                // Accept key exchange
                $exchangeId = (int)($input['exchange_id'] ?? 0);
                $publicKey = $security->sanitizeInput($input['public_key'] ?? '');

                if ($exchangeId <= 0 || empty($publicKey)) {
                    throw new \InvalidArgumentException('Missing required fields');
                }

                $success = $e2eeService->acceptKeyExchange(
                    $exchangeId,
                    (int)$user['id'],
                    $publicKey
                );

                if (!$success) {
                    throw new \RuntimeException('Failed to accept key exchange');
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Key exchange accepted',
                ]);
            } else {
                throw new \InvalidArgumentException('Invalid exchange_action');
            }
            break;

        case 'pending':
            // Get pending key exchange requests
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('GET method required');
            }

            $exchanges = $e2eeService->getPendingExchanges((int)$user['id']);

            echo json_encode([
                'success' => true,
                'exchanges' => $exchanges,
            ]);
            break;

        default:
            throw new \InvalidArgumentException('Invalid action: ' . $action);
    }
} catch (\Exception $e) {
    error_log('Keys API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred: ' . $e->getMessage(),
    ]);
}

