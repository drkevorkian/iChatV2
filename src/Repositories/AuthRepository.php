<?php
/**
 * Sentinel Chat Platform - Authentication Repository
 * 
 * Handles database operations for authentication: users, sessions, etc.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;
use iChat\Services\FileStorage;

class AuthRepository
{
    private FileStorage $fileStorage;
    
    public function __construct()
    {
        $this->fileStorage = new FileStorage();
    }
    
    /**
     * Create a new user account
     * 
     * @param string $username Username
     * @param string $email Email
     * @param string $passwordHash Hashed password
     * @param string $role User role
     * @return int|null User ID or null on failure
     */
    public function createUser(
        string $username,
        string $email,
        string $passwordHash,
        string $role = 'user'
    ): ?int {
        if (!DatabaseHealth::isAvailable()) {
            throw new \RuntimeException('Database is not available. Please ensure the database is set up and patch 005_add_authentication_system has been applied.');
        }
        
        // Validate password hash is not empty
        if (empty($passwordHash)) {
            throw new \RuntimeException('Password hash cannot be empty');
        }
        
        $sql = 'INSERT INTO users 
                (username, email, password_hash, role, created_at)
                VALUES (:username, :email, :password_hash, :role, NOW())';
        
        try {
            $params = [
                ':username' => $username,
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':role' => $role,
            ];
            
            // Log for debugging (remove in production)
            error_log('Creating user with params: username=' . $username . ', email=' . $email . ', role=' . $role . ', hash_length=' . strlen($passwordHash));
            
            Database::execute($sql, $params);
            
            $userId = (int)Database::lastInsertId();
            
            // Verify the user was created with password hash
            if ($userId > 0) {
                $verifySql = 'SELECT password_hash FROM users WHERE id = :user_id';
                $verifyResult = Database::queryOne($verifySql, [':user_id' => $userId]);
                if (empty($verifyResult['password_hash'])) {
                    error_log('WARNING: User created but password_hash is empty! User ID: ' . $userId);
                    throw new \RuntimeException('User created but password hash was not saved. Please check database constraints.');
                }
            }
            
            return $userId;
        } catch (\RuntimeException $e) {
            error_log('User creation failed: ' . $e->getMessage());
            $message = $e->getMessage();
            
            // Check if it's a table missing error (check both the message and the previous exception)
            $previous = $e->getPrevious();
            if ($previous instanceof \PDOException) {
                $pdoMessage = $previous->getMessage();
                if (strpos($pdoMessage, "Table") !== false && 
                    (strpos($pdoMessage, "doesn't exist") !== false || 
                     strpos($pdoMessage, "does not exist") !== false ||
                     strpos($pdoMessage, "Unknown table") !== false)) {
                    throw new \RuntimeException('Database tables not set up. Please apply patch 005_add_authentication_system first. Error: ' . $pdoMessage);
                }
                // Use the PDO error message for better details
                $message = $pdoMessage;
            } elseif (strpos($message, "Table") !== false && 
                      (strpos($message, "doesn't exist") !== false || 
                       strpos($message, "does not exist") !== false ||
                       strpos($message, "Unknown table") !== false)) {
                throw new \RuntimeException('Database tables not set up. Please apply patch 005_add_authentication_system first. Error: ' . $message);
            }
            
            throw new \RuntimeException('Failed to create user: ' . $message);
        } catch (\Exception $e) {
            error_log('User creation failed: ' . $e->getMessage());
            throw new \RuntimeException('Failed to create user: ' . $e->getMessage());
        }
    }
    
    /**
     * Get user by username or email
     * 
     * @param string $identifier Username or email
     * @return array|null User data or null
     */
    public function getUserByUsernameOrEmail(string $identifier): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }
        
        try {
            // Query without is_active filter (check in PHP instead for better compatibility)
            // Note: PDO requires separate parameters for OR clauses
            $sql = 'SELECT id, username, email, password_hash, role, is_active, is_verified
                    FROM users
                    WHERE username = :identifier1 OR email = :identifier2';
            
            $result = Database::queryOne($sql, [
                ':identifier1' => $identifier,
                ':identifier2' => $identifier,
            ]);
            
            // Debug logging
            if (empty($result)) {
                error_log('getUserByUsernameOrEmail: No user found for identifier: ' . $identifier);
            } else {
                error_log('getUserByUsernameOrEmail: Found user ID ' . ($result['id'] ?? 'unknown') . ' for identifier: ' . $identifier . ', is_active=' . ($result['is_active'] ?? 'unknown'));
                // Check is_active in PHP (more reliable than SQL filter)
                if (!($result['is_active'] ?? true)) {
                    error_log('getUserByUsernameOrEmail: User found but account is inactive');
                    // Return null for inactive accounts
                    return null;
                }
            }
            
            return $result;
        } catch (\Exception $e) {
            error_log('getUserByUsernameOrEmail failed: ' . $e->getMessage());
            // Return null on error (user not found)
            return null;
        }
    }
    
    /**
     * Get user by ID
     * 
     * @param int $userId User ID
     * @return array|null User data or null
     */
    public function getUserById(int $userId): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }
        
        $sql = 'SELECT id, username, email, role, is_active, is_verified, created_at, last_login
                FROM users
                WHERE id = :user_id AND is_active = TRUE';
        
        return Database::queryOne($sql, [':user_id' => $userId]);
    }
    
    /**
     * Check if username exists
     * 
     * @param string $username Username
     * @return bool True if exists
     */
    public function usernameExists(string $username): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        try {
            $sql = 'SELECT COUNT(*) FROM users WHERE username = :username';
            $result = Database::queryOne($sql, [':username' => $username]);
            return (int)($result['COUNT(*)'] ?? 0) > 0;
        } catch (\Exception $e) {
            // If table doesn't exist, return false (username doesn't exist)
            $message = $e->getMessage();
            $previous = $e->getPrevious();
            if ($previous instanceof \PDOException) {
                $message = $previous->getMessage();
            }
            if (strpos($message, "Table") !== false || 
                strpos($message, "doesn't exist") !== false ||
                strpos($message, "does not exist") !== false ||
                strpos($message, "Unknown table") !== false) {
                return false;
            }
            // Re-throw other errors
            throw $e;
        }
    }
    
    /**
     * Check if email exists
     * 
     * @param string $email Email
     * @return bool True if exists
     */
    public function emailExists(string $email): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        try {
            $sql = 'SELECT COUNT(*) FROM users WHERE email = :email';
            $result = Database::queryOne($sql, [':email' => $email]);
            return (int)($result['COUNT(*)'] ?? 0) > 0;
        } catch (\Exception $e) {
            // If table doesn't exist, return false (email doesn't exist)
            $message = $e->getMessage();
            $previous = $e->getPrevious();
            if ($previous instanceof \PDOException) {
                $message = $previous->getMessage();
            }
            if (strpos($message, "Table") !== false || 
                strpos($message, "doesn't exist") !== false ||
                strpos($message, "does not exist") !== false ||
                strpos($message, "Unknown table") !== false) {
                return false;
            }
            // Re-throw other errors
            throw $e;
        }
    }
    
    /**
     * Create authentication session
     * 
     * @param int $userId User ID
     * @param string $sessionToken Session token
     * @param string $phpSessionId PHP session ID
     * @param string $ipAddress IP address
     * @param string $userAgent User agent
     * @param string $expiresAt Expiration timestamp
     * @return bool True if successful
     */
    public function createSession(
        int $userId,
        string $sessionToken,
        string $phpSessionId,
        string $ipAddress,
        string $userAgent,
        string $expiresAt
    ): bool {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        $sql = 'INSERT INTO auth_sessions 
                (user_id, session_token, php_session_id, ip_address, user_agent, expires_at, created_at)
                VALUES (:user_id, :session_token, :php_session_id, :ip_address, :user_agent, :expires_at, NOW())';
        
        try {
            Database::execute($sql, [
                ':user_id' => $userId,
                ':session_token' => $sessionToken,
                ':php_session_id' => $phpSessionId,
                ':ip_address' => $ipAddress,
                ':user_agent' => $userAgent,
                ':expires_at' => $expiresAt,
            ]);
            
            return true;
        } catch (\Exception $e) {
            error_log('Session creation failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get session by token
     * 
     * @param string $sessionToken Session token
     * @return array|null Session data or null
     */
    public function getSession(string $sessionToken): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }
        
        $sql = 'SELECT user_id, session_token, php_session_id, ip_address, expires_at
                FROM auth_sessions
                WHERE session_token = :session_token
                  AND expires_at > NOW()';
        
        return Database::queryOne($sql, [':session_token' => $sessionToken]);
    }
    
    /**
     * Destroy session
     * 
     * @param string $sessionToken Session token
     * @return bool True if successful
     */
    public function destroySession(string $sessionToken): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        $sql = 'DELETE FROM auth_sessions WHERE session_token = :session_token';
        
        try {
            Database::execute($sql, [':session_token' => $sessionToken]);
            return true;
        } catch (\Exception $e) {
            error_log('Session destruction failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update last login timestamp
     * 
     * @param int $userId User ID
     */
    public function updateLastLogin(int $userId): void
    {
        if (!DatabaseHealth::isAvailable()) {
            return;
        }
        
        $sql = 'UPDATE users SET last_login = NOW() WHERE id = :user_id';
        
        try {
            Database::execute($sql, [':user_id' => $userId]);
        } catch (\Exception $e) {
            error_log('Last login update failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Update user password hash
     * 
     * @param int $userId User ID
     * @param string $passwordHash New password hash
     * @return bool True if successful
     */
    public function updatePasswordHash(int $userId, string $passwordHash): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        $sql = 'UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :user_id';
        
        try {
            Database::execute($sql, [
                ':user_id' => $userId,
                ':password_hash' => $passwordHash,
            ]);
            return true;
        } catch (\Exception $e) {
            error_log('Password hash update failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clean up expired sessions
     * 
     * @return int Number of sessions cleaned up
     */
    public function cleanupExpiredSessions(): int
    {
        if (!DatabaseHealth::isAvailable()) {
            return 0;
        }
        
        $sql = 'DELETE FROM auth_sessions WHERE expires_at < NOW()';
        
        try {
            return Database::execute($sql);
        } catch (\Exception $e) {
            error_log('Session cleanup failed: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Update user role
     * 
     * @param int $userId User ID
     * @param string $role New role
     * @return bool True if successful
     */
    public function updateUserRole(int $userId, string $role): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        $allowedRoles = ['guest', 'user', 'moderator', 'administrator'];
        if (!in_array($role, $allowedRoles, true)) {
            return false;
        }
        
        $sql = 'UPDATE users SET role = :role, updated_at = NOW() WHERE id = :user_id';
        
        try {
            Database::execute($sql, [
                ':user_id' => $userId,
                ':role' => $role,
            ]);
            return true;
        } catch (\Exception $e) {
            error_log('Role update failed: ' . $e->getMessage());
            return false;
        }
    }
}

