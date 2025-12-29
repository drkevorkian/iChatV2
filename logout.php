<?php
/**
 * Sentinel Chat Platform - Logout Page
 * 
 * Handles user logout and session destruction.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use iChat\Services\AuthService;

$authService = new AuthService();

// Get session token
session_start();
$sessionToken = $_SESSION['auth_token'] ?? null;

// Logout
if ($sessionToken !== null) {
    $authService->logout($sessionToken);
}

// Destroy PHP session
session_destroy();
$_SESSION = [];

// Clear session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Redirect to login page
header('Location: /iChat/login.php');
exit;

