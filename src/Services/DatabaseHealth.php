<?php
/**
 * Sentinel Chat Platform - Database Health Checker
 * 
 * Checks if the database is available and responsive.
 * Used to determine when to use file storage vs database storage.
 * 
 * Security: This service performs lightweight checks to avoid
 * impacting performance when database is available.
 */

declare(strict_types=1);

namespace iChat\Services;

use iChat\Database;
use iChat\Config;
use PDOException;

class DatabaseHealth
{
    private static ?bool $lastStatus = null;
    private static int $lastCheckTime = 0;
    private const CACHE_DURATION = 5; // Cache status for 5 seconds

    /**
     * Check if database is available
     * 
     * Uses caching to avoid excessive connection attempts.
     * 
     * @return bool True if database is available
     */
    public static function isAvailable(): bool
    {
        $now = time();
        
        // Use cached status if recent
        if (self::$lastStatus !== null && ($now - self::$lastCheckTime) < self::CACHE_DURATION) {
            return self::$lastStatus;
        }
        
        // Perform actual check
        try {
            $conn = Database::getConnection();
            // Simple query to verify connection
            $conn->query('SELECT 1');
            self::$lastStatus = true;
        } catch (\Exception $e) {
            self::$lastStatus = false;
            error_log('Database health check failed: ' . $e->getMessage());
            
            // Attempt auto-repair if connection fails (only once per minute)
            self::attemptAutoRepair();
        }
        
        self::$lastCheckTime = $now;
        return self::$lastStatus;
    }

    /**
     * Force a fresh database check (bypass cache)
     * 
     * @return bool True if database is available
     */
    public static function checkFresh(): bool
    {
        self::$lastStatus = null;
        self::$lastCheckTime = 0;
        return self::isAvailable();
    }

    /**
     * Get last known status (from cache)
     * 
     * @return bool|null Last known status or null if never checked
     */
    public static function getLastStatus(): ?bool
    {
        return self::$lastStatus;
    }
    
    /**
     * Attempt to automatically repair database issues
     * 
     * Tries to create the database if it doesn't exist.
     * Only attempts repair once per minute to avoid infinite loops.
     */
    private static function attemptAutoRepair(): void
    {
        static $lastRepairAttempt = 0;
        $currentTime = time();
        
        // Wait at least 60 seconds between repair attempts
        if ($currentTime - $lastRepairAttempt < 60) {
            return;
        }
        
        $lastRepairAttempt = $currentTime;
        
        try {
            $config = Config::getInstance();
            $dbName = $config->get('db.name');
            $host = $config->get('db.host');
            $port = $config->get('db.port');
            $user = $config->get('db.user');
            $password = $config->get('db.password');
            
            // Connect without database name to create it
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
            $pdo = new \PDO($dsn, $user, $password);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            error_log("Auto-repair: Created database '{$dbName}'");
        } catch (\Exception $e) {
            error_log('Auto-repair failed: ' . $e->getMessage());
        }
    }
}

