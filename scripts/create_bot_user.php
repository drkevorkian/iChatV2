<?php
/**
 * Sentinel Chat Platform - Create Bot User Script
 * 
 * Creates a bot user account in the database.
 * 
 * Usage:
 *     php scripts/create_bot_user.php --handle=ChatBot --email=chatbot@sentinel.local --role=user
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\AuthRepository;
use iChat\Services\SecurityService;

// Parse command line arguments
$options = getopt('', ['handle:', 'email:', 'role:', 'password:', 'help']);

if (isset($options['help']) || empty($options['handle'])) {
    echo "Usage: php scripts/create_bot_user.php [options]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --handle=HANDLE     Bot username (required)\n";
    echo "  --email=EMAIL       Bot email address (required)\n";
    echo "  --role=ROLE         User role (default: user)\n";
    echo "  --password=PASS     Bot password (default: auto-generated)\n";
    echo "  --help              Show this help message\n";
    echo "\n";
    echo "Example:\n";
    echo "  php scripts/create_bot_user.php --handle=ChatBot --email=chatbot@sentinel.local --role=user\n";
    exit(0);
}

$botHandle = $options['handle'] ?? '';
$botEmail = $options['email'] ?? '';
$botRole = $options['role'] ?? 'user';
$botPassword = $options['password'] ?? null;

if (empty($botHandle) || empty($botEmail)) {
    echo "Error: --handle and --email are required\n";
    exit(1);
}

// Validate handle
$security = new SecurityService();
if (!$security->validateHandle($botHandle)) {
    echo "Error: Invalid bot handle format\n";
    exit(1);
}

// Validate email
if (!filter_var($botEmail, FILTER_VALIDATE_EMAIL)) {
    echo "Error: Invalid email format\n";
    exit(1);
}

// Validate role
$validRoles = ['guest', 'user', 'moderator', 'administrator', 'trusted_admin', 'owner'];
if (!in_array($botRole, $validRoles, true)) {
    echo "Error: Invalid role. Must be one of: " . implode(', ', $validRoles) . "\n";
    exit(1);
}

// Generate password if not provided
if ($botPassword === null) {
    $botPassword = bin2hex(random_bytes(16)); // 32 character random password
    echo "Generated password: {$botPassword}\n";
    echo "Save this password securely!\n\n";
}

try {
    $authRepo = new AuthRepository();
    
    // Check if user already exists
    $existingUser = $authRepo->getUserByUsernameOrEmail($botHandle);
    if ($existingUser) {
        echo "Error: User '{$botHandle}' already exists\n";
        exit(1);
    }
    
    // Hash password (bcrypt)
    $passwordHash = password_hash($botPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    
    // Create bot user
    $userId = $authRepo->createUser($botHandle, $botEmail, $passwordHash, $botRole);
    
    if ($userId) {
        echo "Success! Bot user created:\n";
        echo "  Handle: {$botHandle}\n";
        echo "  Email: {$botEmail}\n";
        echo "  Role: {$botRole}\n";
        echo "  User ID: {$userId}\n";
        echo "\n";
        echo "You can now run the chatbot bot with:\n";
        echo "  python chatbot-bot.py\n";
        echo "\n";
        echo "Make sure to set BOT_HANDLE={$botHandle} and API_SECRET in your environment.\n";
    } else {
        echo "Error: Failed to create bot user\n";
        exit(1);
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

