<?php
/**
 * Sentinel Chat Platform - Sync API Endpoint
 * 
 * Endpoint to manually trigger sync of file-stored messages to database.
 * Can be called via cron job or manually by administrators.
 * 
 * Security: Requires API secret authentication.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SyncService;
use iChat\Services\SecurityService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// Validate API secret
if (!$security->validateApiSecret()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $syncService = new SyncService();
    
    if ($method === 'POST') {
        // Trigger sync
        $batchSize = isset($_GET['batch_size']) ? (int)$_GET['batch_size'] : 100;
        $batchSize = max(1, min(1000, $batchSize)); // Sanitize
        
        $results = $syncService->syncAll($batchSize);
        
        echo json_encode([
            'success' => true,
            'results' => $results,
        ]);
    } else {
        // Get sync statistics
        $stats = $syncService->getStats();
        
        echo json_encode([
            'success' => true,
            'stats' => $stats,
        ]);
    }
} catch (\Exception $e) {
    error_log('Sync API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Sync failed',
        'message' => $e->getMessage(),
    ]);
}

