<?php
/**
 * Sentinel Chat Platform - Database Repair API
 * 
 * Provides endpoints for checking database health and repairing issues.
 * Requires administrator access or valid API secret.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\DatabaseRepairService;
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
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'administrator') {
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
$action = $_GET['action'] ?? 'check';

try {
    $repairService = new DatabaseRepairService();
    
    switch ($action) {
        case 'check':
            // Check database health
            $issues = $repairService->checkHealth();
            echo json_encode([
                'success' => true,
                'issues' => $issues,
                'issue_count' => count($issues),
                'healthy' => empty($issues),
            ]);
            break;
            
        case 'repair':
            // Repair all issues
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required for repair']);
                exit;
            }
            
            $results = $repairService->repairAll();
            echo json_encode([
                'success' => true,
                'results' => $results,
                'repaired_count' => count($results['repaired']),
                'failed_count' => count($results['failed']),
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    error_log('Database repair API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Request failed',
        'message' => $e->getMessage(),
    ]);
}

