<?php
/**
 * Sentinel Chat Platform - WebSocket Token Generator
 * 
 * Generates a temporary token for WebSocket authentication.
 * This token is session-based and expires after a short time.
 * 
 * Security: The API secret is never exposed to client-side JavaScript.
 * Instead, authenticated users get a temporary token.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Config;
use iChat\Database;

header('Content-Type: application/json');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get authenticated user
$userHandle = $_SESSION['user_handle'] ?? '';
if (empty($userHandle)) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

try {
    $config = Config::getInstance();
    $conn = Database::getConnection();
    
    // Get API secret from database
    $stmt = $conn->prepare("SELECT credential_value FROM websocket_credentials WHERE credential_type = 'api_secret' LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    if (!$result) {
        // Fallback to config if database doesn't have it yet
        $apiSecret = $config->get('api.shared_secret');
    } else {
        $apiSecret = $result['credential_value'];
    }
    
    // Generate a temporary token (valid for 5 minutes)
    // Token format: user_handle:timestamp:hash
    $timestamp = time();
    $expiresAt = $timestamp + 300; // 5 minutes
    $tokenData = $userHandle . ':' . $expiresAt . ':' . $apiSecret;
    $tokenHash = hash('sha256', $tokenData);
    $token = base64_encode($userHandle . ':' . $expiresAt . ':' . $tokenHash);
    
    echo json_encode([
        'success' => true,
        'token' => $token,
        'expires_at' => $expiresAt
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to generate token',
        'message' => $e->getMessage()
    ]);
}

