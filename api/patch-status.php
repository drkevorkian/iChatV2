<?php
/**
 * Sentinel Chat Platform - Patch Status API
 * 
 * Provides information about available and applied patches.
 * Shows patch information with log URLs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\PatchManager;

header('Content-Type: application/json');

$patchManager = new PatchManager();

// Debug: Log received parameters
error_log('Patch status API - GET params: ' . json_encode($_GET));

$action = $_GET['action'] ?? 'list';
$patchId = $_GET['patch_id'] ?? '';

try {
    switch ($action) {
        case 'list':
            // Get all available patches with status
            $patches = $patchManager->getAvailablePatches();
            
            // Add log URLs and rollback availability
            foreach ($patches as &$patch) {
                $patch['log_url'] = $patchManager->getPatchLogUrl($patch['patch_id']);
                $patch['has_logs'] = !empty($patchManager->getPatchLogs($patch['patch_id']));
                $patch['has_rollback'] = $patchManager->hasRollback($patch['patch_id']);
            }
            
            echo json_encode([
                'success' => true,
                'patches' => $patches,
            ]);
            break;
            
        case 'applied':
            // Get applied patches
            $applied = $patchManager->getAppliedPatches();
            
            echo json_encode([
                'success' => true,
                'applied_patches' => $applied,
            ]);
            break;
            
        case 'info':
            // Get specific patch info
            if (empty($patchId)) {
                throw new \InvalidArgumentException('Missing patch_id parameter');
            }
            
            $info = $patchManager->getPatchInfo($patchId);
            
            if (empty($info)) {
                http_response_code(404);
                echo json_encode(['error' => 'Patch not found']);
                exit;
            }
            
            echo json_encode([
                'success' => true,
                'patch' => $info,
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    error_log('Patch status API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Request failed',
        'message' => $e->getMessage(),
    ]);
}

