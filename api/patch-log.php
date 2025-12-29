<?php
/**
 * Sentinel Chat Platform - Patch Log Viewer
 * 
 * Displays patch log information without downloading the file.
 * Shows scope and details of applied patches.
 * 
 * Security: Read-only endpoint, no sensitive data exposure.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\PatchManager;

header('Content-Type: application/json');

$patchId = $_GET['patch_id'] ?? '';

if (empty($patchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing patch_id parameter']);
    exit;
}

$patchManager = new PatchManager();

// Get patch logs for this specific patch
$logs = $patchManager->getPatchLogs($patchId);

if (empty($logs)) {
    http_response_code(404);
    echo json_encode(['error' => 'No logs found for this patch']);
    exit;
}

// Get patch info
$patches = $patchManager->getAvailablePatches();
$patchInfo = null;
foreach ($patches as $patch) {
    if ($patch['patch_id'] === $patchId) {
        $patchInfo = $patch;
        break;
    }
}

echo json_encode([
    'success' => true,
    'patch_id' => $patchId,
    'patch_info' => $patchInfo,
    'logs' => $logs,
    'log_count' => count($logs),
]);

