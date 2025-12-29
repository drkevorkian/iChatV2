<?php
/**
 * Sentinel Chat Platform - WebSocket Credentials API
 * 
 * Secure endpoint for retrieving WebSocket server credentials.
 * Only accessible by the Node.js server process (via localhost).
 * 
 * Security: This endpoint should NEVER be exposed to client-side JavaScript.
 * It's only for the Node.js WebSocket server to fetch its credentials.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Database;
use iChat\Config;

header('Content-Type: application/json');

// SECURITY: Only allow requests from localhost
$allowedHosts = ['127.0.0.1', 'localhost', '::1'];
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocalhost = in_array($clientIp, $allowedHosts, true) || 
               (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);

if (!$isLocalhost) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. This endpoint is only accessible from localhost.']);
    exit;
}

// Get action from query parameter
$action = $_GET['action'] ?? 'get';

try {
    $conn = Database::getConnection();
    
    // Check if table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'websocket_credentials'");
    $tableExists = $tableCheck->rowCount() > 0;
    
    switch ($action) {
        case 'get':
            if (!$tableExists) {
                // Table doesn't exist - return defaults from config
                $config = Config::getInstance();
                echo json_encode([
                    'success' => true,
                    'credentials' => [
                        'db_user' => $config->get('db.user'),
                        'db_password' => $config->get('db.password'),
                        'api_secret' => $config->get('api.shared_secret')
                    ],
                    'note' => 'Using default credentials from config (table not created yet)'
                ]);
                break;
            }
            
            // Get all credentials
            $stmt = $conn->prepare("SELECT credential_type, credential_value FROM websocket_credentials ORDER BY credential_type");
            $stmt->execute();
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $credentials = [];
            foreach ($results as $row) {
                $credentials[$row['credential_type']] = $row['credential_value'];
            }
            
            // Ensure all required credentials exist, fallback to config if missing
            $config = Config::getInstance();
            if (empty($credentials['db_user'])) {
                $credentials['db_user'] = $config->get('db.user');
            }
            if (empty($credentials['db_password'])) {
                $credentials['db_password'] = $config->get('db.password');
            }
            if (empty($credentials['api_secret'])) {
                $credentials['api_secret'] = $config->get('api.shared_secret');
            }
            
            echo json_encode([
                'success' => true,
                'credentials' => $credentials
            ]);
            break;
            
        case 'rotate':
            // Rotate API secret (trusted admin or owner only)
            session_start();
            $userRole = $_SESSION['user_role'] ?? '';
            if (!in_array($userRole, ['trusted_admin', 'owner'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Trusted Admin or Owner access required']);
                exit;
            }
            
            // Generate new API secret
            $newSecret = bin2hex(random_bytes(32)); // 64 character hex string
            
            $stmt = $conn->prepare("
                UPDATE websocket_credentials 
                SET credential_value = :value, 
                    rotated_at = CURRENT_TIMESTAMP 
                WHERE credential_type = 'api_secret'
            ");
            $stmt->execute(['value' => $newSecret]);
            
            echo json_encode([
                'success' => true,
                'message' => 'API secret rotated successfully',
                'new_secret' => $newSecret // Only returned to admin
            ]);
            break;
            
        case 'update':
            // Update credentials (trusted admin or owner only)
            session_start();
            $userRole = $_SESSION['user_role'] ?? '';
            if (!in_array($userRole, ['trusted_admin', 'owner'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Trusted Admin or Owner access required']);
                exit;
            }
            
            // Check if table exists
            if (!$tableExists) {
                http_response_code(500);
                echo json_encode(['error' => 'Credentials table does not exist. Please apply patch 021 first.']);
                exit;
            }
            
            $type = $_POST['type'] ?? '';
            $value = $_POST['value'] ?? '';
            
            if (!in_array($type, ['db_user', 'db_password', 'api_secret'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid credential type']);
                exit;
            }
            
            if (empty($value)) {
                http_response_code(400);
                echo json_encode(['error' => 'Value cannot be empty']);
                exit;
            }
            
            $stmt = $conn->prepare("
                INSERT INTO websocket_credentials (credential_type, credential_value)
                VALUES (:type, :value)
                ON DUPLICATE KEY UPDATE 
                    credential_value = :value,
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute(['type' => $type, 'value' => $value]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Credential updated successfully'
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
}

