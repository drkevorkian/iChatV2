<?php
/**
 * Sentinel Chat Platform - Cache Clearing Utility
 * 
 * Clears PHP opcode cache to ensure latest code is loaded.
 * Run this after making code changes if you're experiencing
 * issues with old code being executed.
 * 
 * SECURITY: Delete this file after use in production!
 */

declare(strict_types=1);

// Clear OPcache if available
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache cleared successfully.\n";
} else {
    echo "OPcache is not enabled or not available.\n";
}

// Clear APCu cache if available
if (function_exists('apcu_clear_cache')) {
    apcu_clear_cache();
    echo "APCu cache cleared successfully.\n";
} else {
    echo "APCu is not enabled or not available.\n";
}

echo "\nCache clearing complete. If you're still seeing old code, restart your web server.\n";

