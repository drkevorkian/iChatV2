<?php
/**
 * Unban User Page
 * 
 * Allows users to unban themselves using a token sent via email.
 * This page validates the token and removes the ban.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use iChat\Repositories\UserManagementRepository;
use iChat\Services\SecurityService;

$security = new SecurityService();
$security->setSecurityHeaders();

$token = $_GET['token'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unban Request - Sentinel Chat</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #0e4c92 0%, #0066cc 100%);
            color: #fff;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
        h1 {
            margin-top: 0;
            text-align: center;
        }
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        .success {
            background: rgba(76, 175, 80, 0.3);
            border: 1px solid rgba(76, 175, 80, 0.5);
        }
        .error {
            background: rgba(244, 67, 54, 0.3);
            border: 1px solid rgba(244, 67, 54, 0.5);
        }
        .info {
            background: rgba(33, 150, 243, 0.3);
            border: 1px solid rgba(33, 150, 243, 0.5);
        }
        a {
            color: #fff;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Unban Request</h1>
        
        <?php
        if (empty($token)) {
            echo '<div class="message error">';
            echo '<strong>Error:</strong> No token provided. Please use the link from your unban email.';
            echo '</div>';
        } else {
            $userRepo = new UserManagementRepository();
            $result = $userRepo->validateUnbanToken($token);
            
            if ($result['success']) {
                echo '<div class="message success">';
                echo '<strong>Success!</strong> Your ban has been removed.';
                echo '<br><br>';
                echo 'You can now <a href="index.php">return to the chat</a>.';
                echo '</div>';
            } else {
                echo '<div class="message error">';
                echo '<strong>Error:</strong> ' . htmlspecialchars($result['error'] ?? 'Invalid or expired token');
                echo '<br><br>';
                echo 'If you believe this is an error, please contact an administrator.';
                echo '</div>';
            }
        }
        ?>
        
        <div class="message info" style="margin-top: 2rem; font-size: 0.9em;">
            <strong>Note:</strong> Unban tokens are valid for 30 days and can only be used once.
        </div>
    </div>
</body>
</html>

