<?php
/**
 * Sentinel Chat Platform - Bootstrap File
 * 
 * This file handles autoloading, environment configuration, and initial setup.
 * It must be included at the start of every PHP file that needs access to
 * the application's classes and configuration.
 * 
 * Security: This file sets up error handling, timezone, and includes security
 * configurations before any other code executes.
 */

declare(strict_types=1);

// Define base paths first (before any other operations)
define('ICHAT_ROOT', __DIR__);
define('ICHAT_SRC', ICHAT_ROOT . '/src');
define('ICHAT_API', ICHAT_ROOT . '/api');
define('ICHAT_TESTS', ICHAT_ROOT . '/tests');

// Create logs directory if it doesn't exist
$logsDir = ICHAT_ROOT . '/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0755, true);
}

// Set error reporting based on environment
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Never display errors to users
ini_set('log_errors', '1');

// Helper function to get error type name (define before error handler)
if (!function_exists('getErrorTypeName')) {
    function getErrorTypeName($errno): string {
        switch ($errno) {
            case E_ERROR: return 'E_ERROR';
            case E_WARNING: return 'E_WARNING';
            case E_PARSE: return 'E_PARSE';
            case E_NOTICE: return 'E_NOTICE';
            case E_CORE_ERROR: return 'E_CORE_ERROR';
            case E_CORE_WARNING: return 'E_CORE_WARNING';
            case E_COMPILE_ERROR: return 'E_COMPILE_ERROR';
            case E_COMPILE_WARNING: return 'E_COMPILE_WARNING';
            case E_USER_ERROR: return 'E_USER_ERROR';
            case E_USER_WARNING: return 'E_USER_WARNING';
            case E_USER_NOTICE: return 'E_USER_NOTICE';
            case E_STRICT: return 'E_STRICT';
            case E_RECOVERABLE_ERROR: return 'E_RECOVERABLE_ERROR';
            case E_DEPRECATED: return 'E_DEPRECATED';
            case E_USER_DEPRECATED: return 'E_USER_DEPRECATED';
            default: return 'UNKNOWN';
        }
    }
}

// Set error log path (for error_log() function)
$errorLogFile = $logsDir . '/error.log';
ini_set('error_log', $errorLogFile);

// Set timezone
date_default_timezone_set('UTC');

/**
 * PSR-4 Autoloader
 * 
 * Automatically loads classes based on namespace and directory structure.
 * This follows PSR-4 standards for class autoloading.
 */
spl_autoload_register(function (string $className): void {
    // Only handle iChat namespace
    if (strpos($className, 'iChat\\') !== 0) {
        return;
    }
    
    // Remove 'iChat\' namespace prefix
    $relativePath = substr($className, 6); // Remove 'iChat\'
    
    // Convert namespace separators to directory separators
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativePath);
    
    // Build file path
    $filePath = ICHAT_SRC . DIRECTORY_SEPARATOR . $relativePath . '.php';
    
    // Load file if it exists
    if (file_exists($filePath)) {
        require_once $filePath;
    }
});

// Load environment configuration
$envFile = ICHAT_ROOT . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse KEY=VALUE pairs
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            $value = trim($value, '"\'');
            
            // Set environment variable if not already set
            if (!getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

// Initialize configuration (autoloader will handle loading the class)
\iChat\Config::init();

// Initialize log rotation service (after autoloader is ready)
use iChat\Services\LogRotationService;
$GLOBALS['logRotation'] = new LogRotationService($logsDir, 5000);

// Set up custom error handler with log rotation
set_error_handler(function($errno, $errstr, $errfile, $errline) use ($errorLogFile) {
    // Rotate log if needed before writing
    if (isset($GLOBALS['logRotation'])) {
        $GLOBALS['logRotation']->rotateIfNeeded('error.log');
    }
    
    // Format error message
    $message = sprintf(
        "[%s] %s: %s in %s on line %d\n",
        date('Y-m-d H:i:s'),
        getErrorTypeName($errno),
        $errstr,
        $errfile,
        $errline
    );
    
    // Write to log file
    @file_put_contents($errorLogFile, $message, FILE_APPEND | LOCK_EX);
    
    // Return false to allow PHP's default error handler to also run
    return false;
});

// Create wrapper function for error_log() that handles rotation
if (!function_exists('rotated_error_log')) {
    function rotated_error_log(string $message, int $messageType = 0, ?string $destination = null, ?string $extraHeaders = null): bool {
        // Rotate log if needed before writing
        if (isset($GLOBALS['logRotation'])) {
            $GLOBALS['logRotation']->rotateIfNeeded('error.log');
        }
        
        // Use PHP's built-in error_log function
        return error_log($message, $messageType, $destination, $extraHeaders);
    }
}

