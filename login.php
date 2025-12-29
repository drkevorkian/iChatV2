<?php
/**
 * Sentinel Chat Platform - Login Page
 * 
 * User login page with authentication.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Services\AuthService;

$security = new SecurityService();
$security->setSecurityHeaders();

// Redirect if already logged in
$authService = new AuthService();
if ($authService->isAuthenticated()) {
    header('Location: /iChat/');
    exit;
}

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        try {
            error_log('Login attempt for username: ' . $username);
            $result = $authService->login($username, $password);
            
            error_log('Login result: ' . json_encode($result));
            
            if ($result['success']) {
                error_log('Login successful, redirecting...');
                header('Location: /iChat/');
                exit;
            } else {
                $error = $result['error'] ?? 'Login failed';
                error_log('Login failed: ' . $error);
            }
        } catch (\Exception $e) {
            error_log('Login exception: ' . $e->getMessage());
            $error = 'Login failed: ' . $e->getMessage();
        }
    } else {
        $error = 'Please enter username and password';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sentinel Chat Platform</title>
    <link rel="stylesheet" href="/iChat/css/styles.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <style>
        .auth-container {
            max-width: 400px;
            margin: 5rem auto;
            padding: 2rem;
            background-color: var(--surface-white);
            border-radius: 8px;
            box-shadow: var(--shadow-lg);
        }
        .auth-container h1 {
            text-align: center;
            color: var(--blizzard-blue);
            margin-bottom: 2rem;
        }
        .auth-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .auth-form input {
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 1rem;
        }
        .auth-form input:focus {
            outline: none;
            border-color: var(--blizzard-blue);
            box-shadow: 0 0 0 2px var(--blizzard-blue-light);
        }
        .auth-error {
            background-color: var(--error-color-light);
            color: var(--error-color-dark);
            padding: 0.75rem;
            border-radius: 4px;
            border-left: 4px solid var(--error-color);
        }
        .auth-success {
            background-color: var(--success-color-light);
            color: var(--success-color-dark);
            padding: 0.75rem;
            border-radius: 4px;
            border-left: 4px solid var(--success-color);
        }
        .auth-links {
            text-align: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }
        .auth-links a {
            color: var(--blizzard-blue);
            text-decoration: none;
        }
        .auth-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <h1>Sentinel Chat Platform</h1>
        <h2 style="text-align: center; margin-bottom: 1.5rem;">Login</h2>
        
        <?php if ($error): ?>
            <div class="auth-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="auth-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        
        <form method="POST" class="auth-form">
            <div>
                <label for="username">Username or Email</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>
            
            <div>
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%;">Login</button>
        </form>
        
        <div class="auth-links">
            <a href="/iChat/register.php">Don't have an account? Register</a><br>
            <a href="/iChat/">Back to Chat</a>
        </div>
    </div>
</body>
</html>

