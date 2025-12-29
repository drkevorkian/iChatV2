<?php
/**
 * Sentinel Chat Platform - Presence Repository
 * 
 * Handles tracking of online users in chat rooms.
 * Uses a heartbeat system where users must update their presence
 * periodically to remain "online".
 * 
 * Security: All queries use prepared statements. User presence data
 * is temporary and expires after inactivity.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;
use iChat\Services\FileStorage;

class PresenceRepository
{
    private FileStorage $fileStorage;
    private const HEARTBEAT_TIMEOUT = 30; // Seconds before user is considered offline

    public function __construct()
    {
        $this->fileStorage = new FileStorage();
    }

    /**
     * Update user presence in a room
     * 
     * Records that a user is active in a room. This should be called
     * periodically (heartbeat) to keep the user online.
     * 
     * @param string $roomId Room identifier
     * @param string $userHandle User's handle
     * @param string|null $ipAddress IP address (optional)
     * @param string|null $sessionId Session ID (optional)
     * @return bool True if successful
     */
    public function updatePresence(string $roomId, string $userHandle, ?string $ipAddress = null, ?string $sessionId = null): bool
    {
        // Check if database is available
        if (DatabaseHealth::isAvailable()) {
            try {
                // Check if ip_address and session_id columns exist
                $hasIpColumn = $this->hasColumn('room_presence', 'ip_address');
                
                if ($hasIpColumn && $ipAddress !== null && $sessionId !== null) {
                    $sql = 'INSERT INTO room_presence (room_id, user_handle, ip_address, session_id, last_seen, created_at)
                            VALUES (:room_id, :user_handle, :ip_address, :session_id, NOW(), NOW())
                            ON DUPLICATE KEY UPDATE 
                                ip_address = VALUES(ip_address),
                                session_id = VALUES(session_id),
                                last_seen = NOW()';
                    
                    Database::execute($sql, [
                        ':room_id' => $roomId,
                        ':user_handle' => $userHandle,
                        ':ip_address' => $ipAddress,
                        ':session_id' => $sessionId,
                    ]);
                } else {
                    // Fallback to basic presence update
                    $sql = 'INSERT INTO room_presence (room_id, user_handle, last_seen, created_at)
                            VALUES (:room_id, :user_handle, NOW(), NOW())
                            ON DUPLICATE KEY UPDATE last_seen = NOW()';
                    
                    Database::execute($sql, [
                        ':room_id' => $roomId,
                        ':user_handle' => $userHandle,
                    ]);
                }
                
                return true;
            } catch (\Exception $e) {
                error_log('Database presence update failed: ' . $e->getMessage());
                DatabaseHealth::checkFresh();
            }
        }
        
        // Fallback to file storage
        $data = [
            'room_id' => $roomId,
            'user_handle' => $userHandle,
            'last_seen' => date('Y-m-d H:i:s'),
            'ip_address' => $ipAddress,
            'session_id' => $sessionId,
        ];
        
        $this->fileStorage->queueMessage('presence', $data);
        return true;
    }
    
    /**
     * Check if a table column exists
     * 
     * @param string $tableName Table name
     * @param string $columnName Column name
     * @return bool True if column exists
     */
    private function hasColumn(string $tableName, string $columnName): bool
    {
        try {
            $dbName = \iChat\Config::getInstance()->get('db.name');
            $sql = 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = :db_name 
                      AND TABLE_NAME = :table_name 
                      AND COLUMN_NAME = :column_name';
            
            $result = Database::queryOne($sql, [
                ':db_name' => $dbName,
                ':table_name' => $tableName,
                ':column_name' => $columnName,
            ]);
            
            return (int)($result['COUNT(*)'] ?? 0) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get online users for a room
     * 
     * Returns list of users who have been active within the heartbeat timeout.
     * 
     * @param string $roomId Room identifier
     * @return array Array of online users with their handles and last seen times
     */
    public function getOnlineUsers(string $roomId): array
    {
        $users = [];
        
        // Get users from database if available
        if (DatabaseHealth::isAvailable()) {
            try {
                $sql = 'SELECT user_handle, last_seen
                        FROM room_presence
                        WHERE room_id = :room_id
                          AND last_seen > DATE_SUB(NOW(), INTERVAL :timeout SECOND)
                        ORDER BY last_seen DESC';
                
                $dbUsers = Database::query($sql, [
                    ':room_id' => $roomId,
                    ':timeout' => self::HEARTBEAT_TIMEOUT,
                ]);
                
                foreach ($dbUsers as $user) {
                    $users[$user['user_handle']] = [
                        'handle' => $user['user_handle'],
                        'last_seen' => $user['last_seen'],
                    ];
                }
            } catch (\Exception $e) {
                error_log('Database presence query failed: ' . $e->getMessage());
            }
        }
        
        // Get users from file storage (if available)
        try {
            $filePresence = $this->fileStorage->getQueuedMessages('presence', true);
        } catch (\Exception $e) {
            $filePresence = [];
        }
        $cutoffTime = time() - self::HEARTBEAT_TIMEOUT;
        
        foreach ($filePresence as $presence) {
            if (($presence['room_id'] ?? '') !== $roomId) {
                continue;
            }
            
            $lastSeen = strtotime($presence['last_seen'] ?? '1970-01-01');
            if ($lastSeen > $cutoffTime) {
                $handle = $presence['user_handle'] ?? '';
                if (!empty($handle) && !isset($users[$handle])) {
                    $users[$handle] = [
                        'handle' => $handle,
                        'last_seen' => $presence['last_seen'] ?? date('Y-m-d H:i:s'),
                    ];
                }
            }
        }
        
        // Sort by last seen (most recent first)
        usort($users, function($a, $b) {
            return strtotime($b['last_seen']) <=> strtotime($a['last_seen']);
        });
        
        return array_values($users);
    }

    /**
     * Remove user presence (user left room)
     * 
     * @param string $roomId Room identifier
     * @param string $userHandle User's handle
     * @return bool True if successful
     */
    public function removePresence(string $roomId, string $userHandle): bool
    {
        if (DatabaseHealth::isAvailable()) {
            try {
                $sql = 'DELETE FROM room_presence 
                        WHERE room_id = :room_id AND user_handle = :user_handle';
                
                Database::execute($sql, [
                    ':room_id' => $roomId,
                    ':user_handle' => $userHandle,
                ]);
                
                return true;
            } catch (\Exception $e) {
                error_log('Database presence removal failed: ' . $e->getMessage());
            }
        }
        
        // Note: File storage presence will expire naturally
        return true;
    }

    /**
     * Clean up expired presence records
     * 
     * Removes users who haven't updated their presence within the timeout period.
     * Should be called periodically via cron or background job.
     * 
     * @return int Number of records cleaned up
     */
    public function cleanupExpired(): int
    {
        if (!DatabaseHealth::isAvailable()) {
            return 0;
        }
        
        try {
            $sql = 'DELETE FROM room_presence 
                    WHERE last_seen < DATE_SUB(NOW(), INTERVAL :timeout SECOND)';
            
            return Database::execute($sql, [
                ':timeout' => self::HEARTBEAT_TIMEOUT,
            ]);
        } catch (\Exception $e) {
            error_log('Presence cleanup failed: ' . $e->getMessage());
            return 0;
        }
    }
}

