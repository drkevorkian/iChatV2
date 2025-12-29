<?php
/**
 * Sentinel Chat Platform - Unique User ID Service
 * 
 * Generates and manages unique user IDs for all users (guests and registered).
 * Format: Guest_x01445_(datetime)_(IP_ID)
 * Where IP_ID is a unique serial number assigned to each IP address.
 * 
 * Security: All operations use prepared statements and input validation.
 */

declare(strict_types=1);

namespace iChat\Services;

use iChat\Database;
use iChat\Services\DatabaseHealth;
use iChat\Services\SecurityService;

class UniqueUserIdService
{
    private SecurityService $security;
    
    public function __construct()
    {
        $this->security = new SecurityService();
    }
    
    /**
     * Generate unique user ID for a user
     * 
     * Format: Guest_x0123_(datetime)_(IP_ID)
     * - Guest_x0123: Base "Guest_x0" prefix with random 4-digit number (0001-9999)
     * - datetime: Login date/time in format Ymd_His (24-hour format)
     * - IP_ID: Unique serial number for the IP address
     * 
     * @param string $userHandle User's handle (username or guest handle)
     * @param string|null $ipAddress IP address (if null, gets from request)
     * @param string|null $loginDatetime Login datetime (if null, uses current time)
     * @return string Unique user ID
     */
    public function generateUniqueUserId(
        string $userHandle,
        ?string $ipAddress = null,
        ?string $loginDatetime = null
    ): string {
        // Get IP address if not provided
        if ($ipAddress === null) {
            $ipAddress = $this->security->getClientIp();
        }
        
        // Get login datetime if not provided
        if ($loginDatetime === null) {
            $loginDatetime = date('Y-m-d H:i:s');
        }
        
        // Get or create IP serial ID
        $ipSerialId = $this->getOrCreateIpSerialId($ipAddress);
        
        // Format datetime: Ymd_His (e.g., 20240115_163045 for 4:30:45 PM on Jan 15, 2024)
        $datetimeFormatted = date('Ymd_His', strtotime($loginDatetime));
        
        // Generate random 4-digit number (0001-9999)
        $randomNumber = str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Generate unique user ID
        // Format: Guest_x0123_(datetime)_(IP_ID)
        $uniqueUserId = sprintf(
            'Guest_x0%s_%s_%d',
            $randomNumber,
            $datetimeFormatted,
            $ipSerialId
        );
        
        return $uniqueUserId;
    }
    
    /**
     * Get or create IP serial ID
     * 
     * Retrieves the unique serial ID for an IP address, creating it if it doesn't exist.
     * 
     * @param string $ipAddress IP address
     * @return int IP serial ID
     */
    public function getOrCreateIpSerialId(string $ipAddress): int
    {
        if (!DatabaseHealth::isAvailable()) {
            // Fallback: use hash of IP address as serial ID (not ideal but works)
            return abs(crc32($ipAddress)) % 1000000;
        }
        
        try {
            // Check if IP exists
            $existing = Database::queryOne(
                'SELECT id, ip_serial_id FROM ip_address_registry WHERE ip_address = :ip_address LIMIT 1',
                [':ip_address' => $ipAddress]
            );
            
            if ($existing) {
                // Update last_seen_at
                Database::execute(
                    'UPDATE ip_address_registry SET last_seen_at = NOW() WHERE ip_address = :ip_address',
                    [':ip_address' => $ipAddress]
                );
                return (int)$existing['ip_serial_id'];
            } else {
                // Get the next serial ID (max + 1)
                $maxSerial = Database::queryOne(
                    'SELECT COALESCE(MAX(ip_serial_id), 0) as max_serial FROM ip_address_registry',
                    []
                );
                $nextSerialId = (int)($maxSerial['max_serial'] ?? 0) + 1;
                
                // Create new entry with explicit serial ID
                Database::execute(
                    'INSERT INTO ip_address_registry (ip_address, ip_serial_id) VALUES (:ip_address, :serial_id)',
                    [
                        ':ip_address' => $ipAddress,
                        ':serial_id' => $nextSerialId,
                    ]
                );
                return $nextSerialId;
            }
        } catch (\Exception $e) {
            error_log('Failed to get/create IP serial ID: ' . $e->getMessage());
            // Final fallback: use hash
            return abs(crc32($ipAddress)) % 1000000;
        }
    }
    
    /**
     * Record user login session with unique ID
     * 
     * Creates a record in user_login_sessions table with the unique user ID.
     * 
     * @param string $userHandle User's handle
     * @param string|null $sessionId PHP session ID
     * @param int|null $userId User ID if registered user
     * @param string $userType 'guest' or 'registered'
     * @param string|null $ipAddress IP address (if null, gets from request)
     * @return string Unique user ID
     */
    public function recordUserLogin(
        string $userHandle,
        ?string $sessionId = null,
        ?int $userId = null,
        string $userType = 'guest',
        ?string $ipAddress = null
    ): string {
        // Get IP address if not provided
        if ($ipAddress === null) {
            $ipAddress = $this->security->getClientIp();
        }
        
        // Get or create IP serial ID
        $ipSerialId = $this->getOrCreateIpSerialId($ipAddress);
        
        // Generate unique user ID
        $uniqueUserId = $this->generateUniqueUserId($userHandle, $ipAddress);
        
        // Record login session
        if (DatabaseHealth::isAvailable()) {
            try {
                $sql = 'INSERT INTO user_login_sessions 
                        (user_handle, unique_user_id, ip_address, ip_serial_id, login_datetime, session_id, user_type, user_id)
                        VALUES (:user_handle, :unique_user_id, :ip_address, :ip_serial_id, :login_datetime, :session_id, :user_type, :user_id)';
                
                Database::execute($sql, [
                    ':user_handle' => $userHandle,
                    ':unique_user_id' => $uniqueUserId,
                    ':ip_address' => $ipAddress,
                    ':ip_serial_id' => $ipSerialId,
                    ':login_datetime' => date('Y-m-d H:i:s'),
                    ':session_id' => $sessionId,
                    ':user_type' => $userType,
                    ':user_id' => $userId,
                ]);
            } catch (\Exception $e) {
                error_log('Failed to record user login session: ' . $e->getMessage());
            }
        }
        
        return $uniqueUserId;
    }
    
    /**
     * Get unique user ID for current session
     * 
     * Retrieves the unique user ID from the session or generates a new one.
     * 
     * @param string $userHandle User's handle
     * @param string|null $sessionId PHP session ID
     * @return string Unique user ID
     */
    public function getUniqueUserIdForSession(string $userHandle, ?string $sessionId = null): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if unique user ID is already in session
        if (isset($_SESSION['unique_user_id'])) {
            return $_SESSION['unique_user_id'];
        }
        
        // Generate new unique user ID
        $uniqueUserId = $this->recordUserLogin($userHandle, $sessionId);
        
        // Store in session
        $_SESSION['unique_user_id'] = $uniqueUserId;
        
        return $uniqueUserId;
    }
}

