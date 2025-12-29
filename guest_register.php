<?php
/**
 * Sentinel Chat Platform - Guest Registration
 * 
 * Allows guests to create a guest name instead of auto-generating one.
 * This enables tracking, kicking, and banning of guests.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Repositories\UserManagementRepository;
use iChat\Services\UniqueUserIdService;

$security = new SecurityService();
$security->setSecurityHeaders();

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in as registered user
try {
    $authService = new \iChat\Services\AuthService();
    if ($authService->isAuthenticated()) {
        header('Location: /iChat/');
        exit;
    }
} catch (\Exception $e) {
    // Continue - user is a guest
}

// If guest already has a name set, redirect to main page
if (isset($_SESSION['user_handle']) && !empty($_SESSION['user_handle']) && $_SESSION['user_handle'] !== 'Guest') {
    header('Location: /iChat/');
    exit;
}

$error = '';
$success = '';

// Handle guest registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $guestName = trim($_POST['guest_name'] ?? '');
    
    if (empty($guestName)) {
        $error = 'Please enter a guest name';
    } elseif (strlen($guestName) < 3) {
        $error = 'Guest name must be at least 3 characters';
    } elseif (strlen($guestName) > 50) {
        $error = 'Guest name must be less than 50 characters';
    } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $guestName)) {
        $error = 'Guest name can only contain letters, numbers, underscores, and hyphens';
    } else {
        // Check if name is already taken by a registered user
        try {
            $authRepo = new \iChat\Repositories\AuthRepository();
            if ($authRepo->usernameExists($guestName)) {
                $error = 'This name is already taken by a registered user';
            } else {
                // Set guest name in session
                $_SESSION['user_handle'] = $guestName;
                $_SESSION['user_role'] = 'guest';
                
                // Record guest session with unique user ID
                try {
                    $uniqueUserIdService = new UniqueUserIdService();
                    $sessionId = session_id();
                    $uniqueUserId = $uniqueUserIdService->recordUserLogin($guestName, $sessionId, null, 'guest');
                    $_SESSION['unique_user_id'] = $uniqueUserId;
                    
                    // Also record in user_sessions table for compatibility
                    $userRepo = new UserManagementRepository();
                    $ipAddress = $security->getClientIp();
                    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $userRepo->recordSession($guestName, $sessionId, $ipAddress, $userAgent);
                    
                    // Create default guest profile
                    try {
                        $profileRepo = new \iChat\Repositories\ProfileRepository();
                        // This will create the profile if it doesn't exist
                        $profileRepo->getProfile($guestName, $guestName);
                    } catch (\Exception $e) {
                        error_log('Failed to create guest profile: ' . $e->getMessage());
                        // Continue anyway - profile will be created on first view
                    }
                } catch (\Exception $e) {
                    error_log('Failed to record guest session: ' . $e->getMessage());
                    // Continue anyway - session is set
                }
                
                header('Location: /iChat/');
                exit;
            }
        } catch (\Exception $e) {
            error_log('Guest registration error: ' . $e->getMessage());
            $error = 'Registration failed. Please try again.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Registration - Sentinel Chat</title>
    <link rel="stylesheet" href="/iChat/css/styles.css">
    <style>
        .guest-register-container {
            max-width: 500px;
            margin: 4rem auto;
            padding: 2rem;
            background-color: var(--surface-white);
            border-radius: 8px;
            box-shadow: var(--shadow-lg);
        }
        .guest-register-container h1 {
            color: var(--blizzard-blue);
            margin-bottom: 1rem;
        }
        .guest-register-container p {
            color: var(--text-medium);
            margin-bottom: 2rem;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-weight: 500;
        }
        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 1rem;
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--blizzard-blue);
            box-shadow: 0 0 0 3px rgba(0, 112, 255, 0.1);
        }
        .error-message {
            background-color: #fee;
            color: #c33;
            padding: 0.75rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        .success-message {
            background-color: #efe;
            color: #3c3;
            padding: 0.75rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        .btn-primary {
            width: 100%;
            padding: 0.75rem;
            background-color: var(--blizzard-blue);
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color var(--transition-fast);
        }
        .btn-primary:hover {
            background-color: var(--blizzard-blue-dark);
        }
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            color: var(--text-medium);
        }
        .login-link a {
            color: var(--blizzard-blue);
            text-decoration: none;
        }
        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="guest-register-container">
        <h1>Choose Your Guest Name</h1>
        <p>Enter a name to use as a guest. This name will be visible to others and can be used for moderation actions.</p>
        
        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="guest_name">Guest Name:</label>
                <input 
                    type="text" 
                    id="guest_name" 
                    name="guest_name" 
                    required 
                    minlength="3" 
                    maxlength="50" 
                    pattern="[a-zA-Z0-9_-]+"
                    placeholder="Enter your guest name"
                    autofocus
                >
                <small style="color: var(--text-light); font-size: 0.85rem;">
                    Letters, numbers, underscores, and hyphens only (3-50 characters)
                </small>
            </div>
            
            <button type="submit" class="btn-primary">Continue as Guest</button>
        </form>
        
        <div class="login-link">
            Already have an account? <a href="/iChat/login.php">Login</a> or <a href="/iChat/register.php">Register</a>
        </div>
    </div>
</body>
</html>

