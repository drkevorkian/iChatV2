<?php
/**
 * Sentinel Chat Platform - Bootstrap Patch Application
 * 
 * This script allows applying patch 005_add_authentication_system without authentication.
 * Use this ONLY for initial setup. After applying this patch, use the normal patch system.
 * 
 * SECURITY: This script should be deleted or protected after initial setup.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use iChat\Services\PatchManager;
use iChat\Services\SecurityService;

// Set security headers
$security = new SecurityService();
$security->setSecurityHeaders();

// Simple authentication check - you can remove this after initial setup
$bootstrapKey = $_GET['key'] ?? '';
$expectedKey = 'bootstrap_setup_2024'; // Change this or remove after use

if ($bootstrapKey !== $expectedKey) {
    http_response_code(403);
    die('Access denied. This script requires a bootstrap key.');
}

header('Content-Type: text/html; charset=UTF-8');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bootstrap Patch Application</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #0066cc;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }
        .info {
            background-color: #d1ecf1;
            color: #0c5460;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }
        button {
            background-color: #0066cc;
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
        }
        button:hover {
            background-color: #0052a3;
        }
        pre {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Bootstrap Patch Application</h1>
        <p>This script applies patch 005_add_authentication_system to set up the authentication tables.</p>
        
        <?php
        $patchId = '005_add_authentication_system';
        $patchManager = new PatchManager();
        
        // Check if patch is already applied
        if ($patchManager->isPatchApplied($patchId)) {
            echo '<div class="info">';
            echo '<strong>Patch already applied!</strong><br>';
            echo 'Patch ' . htmlspecialchars($patchId) . ' has already been applied.';
            echo '</div>';
        } else {
            // Apply patch
            if (isset($_POST['apply'])) {
                echo '<h2>Applying Patch...</h2>';
                
                try {
                    $result = $patchManager->applyPatch($patchId);
                    
                    if ($result['success']) {
                        echo '<div class="success">';
                        echo '<strong>Patch applied successfully!</strong><br>';
                        echo 'The authentication system tables have been created.';
                        echo '</div>';
                        echo '<div class="info">';
                        echo '<strong>Next steps:</strong><br>';
                        echo '1. You can now register new users at <a href="/iChat/register.php">/iChat/register.php</a><br>';
                        echo '2. Or login with your existing admin account at <a href="/iChat/login.php">/iChat/login.php</a><br>';
                        echo '3. <strong>Delete or protect this bootstrap_patch.php file for security!</strong>';
                        echo '</div>';
                        
                        if (!empty($result['log'])) {
                            echo '<h3>Patch Log:</h3>';
                            echo '<pre>' . htmlspecialchars($result['log']) . '</pre>';
                        }
                    } else {
                        echo '<div class="error">';
                        echo '<strong>Patch application failed!</strong><br>';
                        echo htmlspecialchars($result['error'] ?? 'Unknown error');
                        echo '</div>';
                        
                        if (!empty($result['log'])) {
                            echo '<h3>Error Log:</h3>';
                            echo '<pre>' . htmlspecialchars($result['log']) . '</pre>';
                        }
                    }
                } catch (\Exception $e) {
                    echo '<div class="error">';
                    echo '<strong>Error:</strong><br>';
                    echo htmlspecialchars($e->getMessage());
                    echo '</div>';
                }
            } else {
                // Show patch info
                $patchInfo = $patchManager->getPatchInfo($patchId);
                
                if ($patchInfo) {
                    echo '<div class="info">';
                    echo '<strong>Patch Information:</strong><br>';
                    echo 'Patch ID: ' . htmlspecialchars($patchInfo['patch_id']) . '<br>';
                    echo 'Version: ' . htmlspecialchars($patchInfo['version'] ?? 'N/A') . '<br>';
                    echo 'Description: ' . htmlspecialchars($patchInfo['description'] ?? 'N/A') . '<br>';
                    echo '</div>';
                    
                    echo '<form method="POST">';
                    echo '<button type="submit" name="apply">Apply Patch ' . htmlspecialchars($patchId) . '</button>';
                    echo '</form>';
                } else {
                    echo '<div class="error">';
                    echo 'Patch file not found: ' . htmlspecialchars($patchId);
                    echo '</div>';
                }
            }
        }
        ?>
        
        <hr>
        <p><small><strong>Security Note:</strong> Delete this file after applying the patch, or change the bootstrap key.</small></p>
    </div>
</body>
</html>

