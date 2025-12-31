<?php
/**
 * Sentinel Chat Platform - Patch Application API
 * 
 * Web endpoint for applying patches.
 * Requires API secret authentication for security.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\PatchManager;
use iChat\Services\SecurityService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// Authentication: Allow API secret OR admin session
$isAuthorized = false;

// Check API secret (for proxy calls)
if ($security->validateApiSecret()) {
    $isAuthorized = true;
}

// Check session (for direct web interface access)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Allow if user is administrator (for web interface)
if (isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['administrator', 'trusted_admin', 'owner'], true)) {
    $isAuthorized = true;
}

// Also allow if no role is set (development mode - remove in production)
if (!isset($_SESSION['user_role']) && getenv('APP_ENV') === 'development') {
    $isAuthorized = true;
}

if (!$isAuthorized) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized - Administrator access required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$patchManager = new PatchManager();

try {
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_POST['action'] ?? 'apply';
        $patchId = $input['patch_id'] ?? $_POST['patch_id'] ?? '';
        
        if (empty($patchId)) {
            throw new \InvalidArgumentException('Missing patch_id parameter');
        }
        
        if ($action === 'rollback') {
            // Rollback a patch
            $result = $patchManager->rollbackPatch($patchId);
            echo json_encode($result);
        } else {
            // Apply a patch
            $result = $patchManager->applyPatch($patchId);
            echo json_encode($result);
        }
    } else {
        // Get patch information
        $patchId = $_GET['patch_id'] ?? '';
        
        if (!empty($patchId)) {
            $info = $patchManager->getPatchInfo($patchId);
            echo json_encode([
                'success' => true,
                'patch' => $info,
            ]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Missing patch_id parameter']);
        }
    }
} catch (\Exception $e) {
    error_log('Patch apply API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Request failed',
        'message' => $e->getMessage(),
    ]);
}

