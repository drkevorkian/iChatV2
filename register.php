<?php
/**
 * Sentinel Chat Platform - Registration Page
 * 
 * User registration page.
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

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    
    if ($password !== $passwordConfirm) {
        $error = 'Passwords do not match';
    } elseif (!empty($username) && !empty($email) && !empty($password)) {
        try {
            $result = $authService->register($username, $email, $password);
            
            if ($result['success']) {
                $success = 'Registration successful! You can now login.';
                // Auto-login after registration
                try {
                    $loginResult = $authService->login($username, $password);
                    if ($loginResult['success']) {
                        header('Location: /iChat/');
                        exit;
                    }
                } catch (\Exception $e) {
                    error_log('Auto-login after registration failed: ' . $e->getMessage());
                    // Continue to show success message, user can login manually
                }
            } else {
                $error = $result['error'] ?? 'Registration failed';
            }
        } catch (\Exception $e) {
            error_log('Registration error: ' . $e->getMessage());
            $error = 'Registration failed: ' . $e->getMessage();
            // Check if it's a database table missing error
            if (strpos($e->getMessage(), 'Table') !== false || strpos($e->getMessage(), 'doesn\'t exist') !== false) {
                $error = 'Database tables not set up. Please apply patch 005_add_authentication_system first.';
            }
        }
    } else {
        $error = 'Please fill in all fields';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Sentinel Chat Platform</title>
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
        .password-requirements {
            font-size: 0.85rem;
            color: var(--text-color-light);
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <h1>Sentinel Chat Platform</h1>
        <h2 style="text-align: center; margin-bottom: 1.5rem;">Register</h2>
        
        <?php if ($error): ?>
            <div class="auth-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="auth-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        
        <form method="POST" class="auth-form" id="register-form">
            <div>
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus minlength="3" maxlength="100" pattern="[a-zA-Z0-9._-]+">
                <div class="password-requirements">3-100 characters, letters, numbers, dots, underscores, hyphens</div>
            </div>
            
            <div>
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required>
            </div>
            
            <div>
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required minlength="8">
                <div class="password-requirements">Minimum 8 characters</div>
            </div>
            
            <div>
                <label for="password_confirm">Confirm Password</label>
                <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%;">Register</button>
        </form>
        
        <div class="auth-links">
            <a href="/iChat/login.php">Already have an account? Login</a><br>
            <a href="/iChat/">Back to Chat</a>
        </div>
    </div>
    
    <script>
        // Validate password match
        $('#register-form').on('submit', function(e) {
            const password = $('#password').val();
            const passwordConfirm = $('#password_confirm').val();
            
            if (password !== passwordConfirm) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters!');
                return false;
            }
        });
    </script>
</body>
</html>

