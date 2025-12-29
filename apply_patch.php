<?php
/**
 * Sentinel Chat Platform - Patch Application Script
 * 
 * Applies database patches with tracking and logging.
 * Can be run from command line or web interface.
 * 
 * Usage:
 *   php apply_patch.php [patch_id]
 *   php apply_patch.php --list
 *   php apply_patch.php --status
 */

declare(strict_types=1);

// Ensure CLI execution only (unless explicitly allowed via web)
$isCli = php_sapi_name() === 'cli';
$allowWeb = isset($_GET['web']) && $_GET['web'] === 'allow';

if (!$isCli && !$allowWeb) {
    die("This script should be run from command line.\nAdd ?web=allow to URL for web access.\n");
}

require_once __DIR__ . '/bootstrap.php';

use iChat\Services\PatchManager;

$patchManager = new PatchManager();

// Handle command line arguments
if ($isCli) {
    $command = $argv[1] ?? '--help';
    
    switch ($command) {
        case '--list':
            echo "Available Patches:\n";
            echo str_repeat('=', 80) . "\n\n";
            
            $patches = $patchManager->getAvailablePatches();
            foreach ($patches as $patch) {
                $status = $patch['applied'] ? '[APPLIED]' : '[PENDING]';
                echo "{$status} {$patch['patch_id']} - {$patch['description']}\n";
                if ($patch['applied']) {
                    echo "  Applied: {$patch['applied_date']}\n";
                    $logUrl = $patchManager->getPatchLogUrl($patch['patch_id']);
                    if ($logUrl) {
                        echo "  Log URL: {$logUrl}\n";
                    }
                }
                echo "\n";
            }
            break;
            
        case '--status':
            echo "Applied Patches:\n";
            echo str_repeat('=', 80) . "\n\n";
            
            $applied = $patchManager->getAppliedPatches();
            if (empty($applied)) {
                echo "No patches have been applied.\n";
            } else {
                foreach ($applied as $patch) {
                    echo "{$patch['patch_id']} (v{$patch['version']})\n";
                    echo "  Description: {$patch['description']}\n";
                    echo "  Applied: {$patch['applied_at']}\n";
                    echo "  Duration: {$patch['duration']}s\n";
                    $logUrl = $patchManager->getPatchLogUrl($patch['patch_id']);
                    if ($logUrl) {
                        echo "  Log URL: {$logUrl}\n";
                    }
                    echo "\n";
                }
            }
            break;
            
        case '--help':
            echo "Sentinel Chat Platform - Patch Manager\n";
            echo str_repeat('=', 80) . "\n\n";
            echo "Usage:\n";
            echo "  php apply_patch.php [patch_id]     Apply a specific patch\n";
            echo "  php apply_patch.php --list         List all available patches\n";
            echo "  php apply_patch.php --status       Show applied patches\n";
            echo "  php apply_patch.php --help         Show this help\n";
            break;
            
        default:
            // Apply specific patch
            $patchId = $command;
            echo "Applying patch: {$patchId}\n";
            echo str_repeat('-', 80) . "\n\n";
            
            $result = $patchManager->applyPatch($patchId);
            
            if ($result['success']) {
                echo "✓ Patch applied successfully!\n";
                echo "  Patch ID: {$result['patch_id']}\n";
                echo "  Duration: {$result['duration']}s\n";
                echo "  Description: {$result['info']['description']}\n";
                echo "\n";
                echo "Log URL: " . $patchManager->getPatchLogUrl($patchId) . "\n";
            } else {
                echo "✗ Patch application failed!\n";
                echo "  Error: {$result['error']}\n";
                if (isset($result['already_applied'])) {
                    echo "\nThis patch has already been applied.\n";
                }
                exit(1);
            }
            break;
    }
} else {
    // Web interface
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? 'list';
    $patchId = $_GET['patch_id'] ?? '';
    
    switch ($action) {
        case 'apply':
            if (empty($patchId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing patch_id parameter']);
                exit;
            }
            
            $result = $patchManager->applyPatch($patchId);
            echo json_encode($result);
            break;
            
        case 'list':
            $patches = $patchManager->getAvailablePatches();
            echo json_encode([
                'success' => true,
                'patches' => $patches,
            ]);
            break;
            
        case 'status':
            $applied = $patchManager->getAppliedPatches();
            echo json_encode([
                'success' => true,
                'applied_patches' => $applied,
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

