<?php
/**
 * Sentinel Chat Platform - Database Connection Manager
 * 
 * Manages database connections using PDO with prepared statements.
 * This class ensures all database queries use prepared statements
 * to prevent SQL injection attacks.
 * 
 * Security: All queries MUST use prepared statements. This class
 * provides helper methods to make prepared statements easy to use.
 */

declare(strict_types=1);

namespace iChat;

use PDO;
use PDOException;
use PDOStatement;

class Database
{
    private static ?PDO $connection = null;
    private static Config $config;

    /**
     * Initialize database connection
     * 
     * Creates a singleton PDO connection with error handling and
     * proper character set configuration.
     * 
     * @return PDO Database connection
     * @throws \RuntimeException if connection fails
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            self::$config = Config::getInstance();
            
            $host = self::$config->get('db.host');
            $port = self::$config->get('db.port');
            $name = self::$config->get('db.name');
            $user = self::$config->get('db.user');
            $password = self::$config->get('db.password');
            $charset = self::$config->get('db.charset');
            
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $host,
                $port,
                $name,
                $charset
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false, // Use native prepared statements
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
            ];
            
            try {
                self::$connection = new PDO($dsn, $user, $password, $options);
            } catch (PDOException $e) {
                // Log error but don't expose details to user
                error_log('Database connection failed: ' . $e->getMessage());
                throw new \RuntimeException('Database connection failed', 0, $e);
            }
        }
        
        return self::$connection;
    }

    /**
     * Execute a prepared SELECT query and return all results
     * 
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind to placeholders
     * @return array Array of result rows
     */
    public static function query(string $sql, array $params = []): array
    {
        try {
            $stmt = self::getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Database query failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw new \RuntimeException('Database query failed', 0, $e);
        }
    }

    /**
     * Execute a prepared SELECT query and return single row
     * 
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind to placeholders
     * @return array|null Single row or null if not found
     */
    public static function queryOne(string $sql, array $params = []): ?array
    {
        try {
            $stmt = self::getConnection()->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result === false ? null : $result;
        } catch (PDOException $e) {
            error_log('Database query failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw new \RuntimeException('Database query failed', 0, $e);
        }
    }

    /**
     * Execute a prepared INSERT/UPDATE/DELETE query
     * 
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind to placeholders
     * @return int Number of affected rows
     */
    public static function execute(string $sql, array $params = []): int
    {
        try {
            $stmt = self::getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('Database execute failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
            // Preserve the original exception message
            throw new \RuntimeException('Database execute failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get last insert ID
     * 
     * @return string Last inserted ID
     */
    public static function lastInsertId(): string
    {
        return (string)self::getConnection()->lastInsertId();
    }

    /**
     * Begin a database transaction
     * 
     * @return bool True on success
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    /**
     * Commit a database transaction
     * 
     * @return bool True on success
     */
    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    /**
     * Rollback a database transaction
     * 
     * @return bool True on success
     */
    public static function rollback(): bool
    {
        $conn = self::getConnection();
        // Check if we're actually in a transaction before trying to rollback
        if ($conn->inTransaction()) {
            return $conn->rollBack();
        }
        return true; // Not in transaction, nothing to rollback
    }
    
    /**
     * Check if currently in a transaction
     * 
     * @return bool True if in transaction
     */
    public static function inTransaction(): bool
    {
        return self::getConnection()->inTransaction();
    }

    /**
     * Close database connection (for testing)
     */
    public static function close(): void
    {
        self::$connection = null;
    }
}

