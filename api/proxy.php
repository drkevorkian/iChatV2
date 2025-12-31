<?php
/**
 * Sentinel Chat Platform - Secure API Proxy
 * 
 * This proxy handles API requests server-side to prevent exposing
 * API secrets in client-side JavaScript. All authentication is
 * handled on the server.
 * 
 * Security: This is critical for security - API secrets never leave
 * the server. Client-side JavaScript only calls this proxy.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Config;
use iChat\Services\SecurityService;

// Start session if not already started (needed for authentication)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// Get target path from query parameter
$path = $_GET['path'] ?? '';

if (empty($path)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing path parameter']);
    exit;
}

// Preserve all GET parameters for the included file
// The included file will read from $_GET directly, which should still contain
// all original query parameters from the request

// Validate path to prevent SSRF attacks
$allowedPaths = ['messages', 'im', 'admin', 'presence', 'sync', 'patch-log', 'patch-status', 'patch-apply', 'db-repair', 'user-management', 'profile', 'auth', 'moderate', 'room-requests', 'reports', 'admin-notifications', 'pinky-brain', 'log-viewer', 'emojis', 'file-storage', 'mail', 'settings', 'chat-media', 'avatars', 'word-filter', 'websocket-admin', 'audit', 'rbac'];
$pathParts = explode('/', trim($path, '/'));
$basePath = $pathParts[0] ?? '';

// Remove .php extension if present for comparison
$basePathClean = str_replace('.php', '', $basePath);

if (!in_array($basePath, $allowedPaths, true) && !in_array($basePathClean, $allowedPaths, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid path', 'received' => $path, 'basePath' => $basePath, 'basePathClean' => $basePathClean]);
    exit;
}

// Build target file path (more efficient than HTTP request to same server)
// Add .php extension if not present
$targetFile = ICHAT_API . '/' . $path;
if (!str_ends_with($targetFile, '.php')) {
    $targetFile .= '.php';
}

if (!file_exists($targetFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found: ' . basename($targetFile)]);
    exit;
}

// Set API secret in $_SERVER for the target script (SecurityService checks getAllHeaders)
$_SERVER['HTTP_X_API_SECRET'] = Config::getInstance()->get('api.shared_secret');

// Preserve request method
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// For POST requests, handle multipart/form-data (file uploads) specially
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is a multipart/form-data request (file upload)
    // Check all possible Content-Type header locations
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    
    // Also check getAllHeaders() for Content-Type
    $headers = getallheaders();
    if (empty($contentType) && isset($headers['Content-Type'])) {
        $contentType = $headers['Content-Type'];
    }
    
    $hasFiles = !empty($_FILES);
    
    if (strpos($contentType, 'multipart/form-data') !== false || $hasFiles) {
        // For multipart/form-data, $_FILES and $_POST are already populated by PHP
        // No need to read php://input - it's already parsed
        // PHP automatically populates $_FILES for multipart/form-data
        error_log('Proxy: Detected file upload - Content-Type: ' . $contentType . ', Files: ' . count($_FILES));
    } else {
        // For JSON or other POST data, read and store input
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            // Store in a way that the included file can access
            // The included file will read from php://input directly
            // php://input can be read multiple times in the same request
        }
    }
}

// Capture output
ob_start();

// Include the target file (it will handle the request)
try {
    include $targetFile;
    $output = ob_get_clean();
    
    // Output the response
    echo $output;
} catch (\Exception $e) {
    ob_end_clean();
    error_log('Proxy include error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Proxy request failed',
        'message' => $e->getMessage(),
    ]);
}

