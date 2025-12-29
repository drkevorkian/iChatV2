<?php
/**
 * Sentinel Chat Platform - Room Request Repository
 * 
 * Handles all database operations for room requests.
 * This repository uses prepared statements for all queries to prevent
 * SQL injection attacks.
 * 
 * Security: All queries use prepared statements. User input is never
 * directly concatenated into SQL queries.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;
use iChat\Services\FileStorage;

class RoomRequestRepository
{
    private FileStorage $fileStorage;

    public function __construct()
    {
        $this->fileStorage = new FileStorage();
    }

    /**
     * Create a new room request
     * 
     * @param string $roomName Room identifier
     * @param string $roomDisplayName Display name for the room
     * @param string $requesterHandle User handle requesting the room
     * @param int|null $requesterUserId User ID if registered user
     * @param string|null $passwordHash Bcrypt hash of room password (optional)
     * @param string|null $description Description/purpose of the room
     * @return int Request ID
     */
    public function createRequest(
        string $roomName,
        string $roomDisplayName,
        string $requesterHandle,
        ?int $requesterUserId = null,
        ?string $passwordHash = null,
        ?string $description = null
    ): int {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("
                    INSERT INTO room_requests 
                    (room_name, room_display_name, password_hash, requester_handle, requester_user_id, description, status)
                    VALUES (:room_name, :room_display_name, :password_hash, :requester_handle, :requester_user_id, :description, 'pending')
                ");
                
                $stmt->execute([
                    ':room_name' => $roomName,
                    ':room_display_name' => $roomDisplayName,
                    ':password_hash' => $passwordHash,
                    ':requester_handle' => $requesterHandle,
                    ':requester_user_id' => $requesterUserId,
                    ':description' => $description
                ]);
                
                return (int)$db->lastInsertId();
            } catch (\PDOException $e) {
                error_log('RoomRequestRepository::createRequest database error: ' . $e->getMessage());
                error_log('SQL State: ' . $e->getCode());
                if (isset($stmt)) {
                    error_log('Error Info: ' . print_r($stmt->errorInfo() ?? [], true));
                }
                // Fall through to file storage
            }
        }
        
        // File storage fallback
        try {
            $requestData = [
                'room_name' => $roomName,
                'room_display_name' => $roomDisplayName,
                'password_hash' => $passwordHash,
                'requester_handle' => $requesterHandle,
                'requester_user_id' => $requesterUserId,
                'description' => $description,
                'status' => 'pending',
                'requested_at' => date('Y-m-d H:i:s')
            ];
            
            return $this->fileStorage->queueRoomRequest($requestData);
        } catch (\Exception $e) {
            error_log('RoomRequestRepository::createRequest file storage error: ' . $e->getMessage());
            throw new \RuntimeException('Failed to create room request: Database and file storage both failed. ' . ($e->getMessage() ?? 'Unknown error'), 0, $e);
        }
    }

    /**
     * Get all room requests (for admin)
     * 
     * @param string|null $status Filter by status (pending, approved, denied, active)
     * @return array List of room requests
     */
    public function getAllRequests(?string $status = null): array
    {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                
                if ($status !== null) {
                    $stmt = $db->prepare("
                        SELECT * FROM room_requests 
                        WHERE deleted_at IS NULL AND status = :status
                        ORDER BY requested_at DESC
                    ");
                    $stmt->execute([':status' => $status]);
                } else {
                    $stmt = $db->prepare("
                        SELECT * FROM room_requests 
                        WHERE deleted_at IS NULL
                        ORDER BY requested_at DESC
                    ");
                    $stmt->execute();
                }
                
                return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            } catch (\PDOException $e) {
                error_log('RoomRequestRepository::getAllRequests database error: ' . $e->getMessage());
            }
        }
        
        return [];
    }

    /**
     * Get room request by ID
     * 
     * @param int $requestId Request ID
     * @return array|null Request data or null if not found
     */
    public function getRequestById(int $requestId): ?array
    {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("
                    SELECT * FROM room_requests 
                    WHERE id = :id AND deleted_at IS NULL
                ");
                $stmt->execute([':id' => $requestId]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $result ?: null;
            } catch (\PDOException $e) {
                error_log('RoomRequestRepository::getRequestById database error: ' . $e->getMessage());
            }
        }
        
        return null;
    }

    /**
     * Update room request status
     * 
     * @param int $requestId Request ID
     * @param string $status New status (approved, denied, active)
     * @param string $reviewedBy Admin handle who reviewed
     * @param string|null $adminNotes Admin notes
     * @param string|null $inviteCode Generated invite code (for approved requests)
     * @return bool Success
     */
    public function updateRequestStatus(
        int $requestId,
        string $status,
        string $reviewedBy,
        ?string $adminNotes = null,
        ?string $inviteCode = null
    ): bool {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("
                    UPDATE room_requests 
                    SET status = :status, 
                        reviewed_at = NOW(), 
                        reviewed_by = :reviewed_by,
                        admin_notes = :admin_notes,
                        invite_code = COALESCE(:invite_code, invite_code)
                    WHERE id = :id AND deleted_at IS NULL
                ");
                
                return $stmt->execute([
                    ':id' => $requestId,
                    ':status' => $status,
                    ':reviewed_by' => $reviewedBy,
                    ':admin_notes' => $adminNotes,
                    ':invite_code' => $inviteCode
                ]);
            } catch (\PDOException $e) {
                error_log('RoomRequestRepository::updateRequestStatus database error: ' . $e->getMessage());
                return false;
            }
        }
        
        return false;
    }

    /**
     * Check if user is room owner
     * 
     * @param string $roomName Room identifier
     * @param string $userHandle User handle to check
     * @return bool True if user is owner
     */
    public function isRoomOwner(string $roomName, string $userHandle): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT COUNT(*) as count FROM room_requests 
                WHERE room_name = :room_name 
                AND requester_handle = :user_handle 
                AND status = 'approved'
                AND deleted_at IS NULL
            ");
            $stmt->execute([
                ':room_name' => $roomName,
                ':user_handle' => $userHandle
            ]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return (int)($result['count'] ?? 0) > 0;
        } catch (\PDOException $e) {
            error_log('RoomRequestRepository::isRoomOwner database error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update room password
     * 
     * @param string $roomName Room identifier
     * @param string $userHandle User handle (must be owner)
     * @param string|null $passwordHash New password hash (null to remove password)
     * @return bool Success
     */
    public function updateRoomPassword(string $roomName, string $userHandle, ?string $passwordHash): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        // Verify user is owner
        if (!$this->isRoomOwner($roomName, $userHandle)) {
            return false;
        }
        
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                UPDATE room_requests 
                SET password_hash = :password_hash,
                    updated_at = NOW()
                WHERE room_name = :room_name 
                AND requester_handle = :user_handle 
                AND status = 'approved'
                AND deleted_at IS NULL
            ");
            
            return $stmt->execute([
                ':room_name' => $roomName,
                ':user_handle' => $userHandle,
                ':password_hash' => $passwordHash
            ]);
        } catch (\PDOException $e) {
            error_log('RoomRequestRepository::updateRoomPassword database error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get room info for owner
     * 
     * @param string $roomName Room identifier
     * @param string $userHandle User handle (must be owner)
     * @return array|null Room info or null if not found/not owner
     */
    public function getRoomInfoForOwner(string $roomName, string $userHandle): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }
        
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT room_name, room_display_name, password_hash IS NOT NULL as has_password, invite_code, status
                FROM room_requests 
                WHERE room_name = :room_name 
                AND requester_handle = :user_handle 
                AND status = 'approved'
                AND deleted_at IS NULL
            ");
            $stmt->execute([
                ':room_name' => $roomName,
                ':user_handle' => $userHandle
            ]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (\PDOException $e) {
            error_log('RoomRequestRepository::getRoomInfoForOwner database error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Grant room access to a user
     * 
     * @param string $roomName Room identifier
     * @param string $userHandle User handle
     * @param int|null $userId User ID if registered user
     * @param string $accessType Type of access (owner, invited, password)
     * @param \DateTime|null $expiresAt Optional expiration time
     * @return bool Success
     */
    public function grantRoomAccess(
        string $roomName,
        string $userHandle,
        ?int $userId = null,
        string $accessType = 'password',
        ?\DateTime $expiresAt = null
    ): bool {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("
                    INSERT INTO room_access 
                    (room_name, user_handle, user_id, access_type, expires_at)
                    VALUES (:room_name, :user_handle, :user_id, :access_type, :expires_at)
                    ON DUPLICATE KEY UPDATE 
                        access_type = VALUES(access_type),
                        expires_at = VALUES(expires_at)
                ");
                
                return $stmt->execute([
                    ':room_name' => $roomName,
                    ':user_handle' => $userHandle,
                    ':user_id' => $userId,
                    ':access_type' => $accessType,
                    ':expires_at' => $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null
                ]);
            } catch (\PDOException $e) {
                error_log('RoomRequestRepository::grantRoomAccess database error: ' . $e->getMessage());
                return false;
            }
        }
        
        return false;
    }

    /**
     * Check if user has access to a room
     * 
     * @param string $roomName Room identifier
     * @param string $userHandle User handle
     * @return bool Has access
     */
    public function hasRoomAccess(string $roomName, string $userHandle): bool
    {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("
                    SELECT COUNT(*) as count FROM room_access 
                    WHERE room_name = :room_name 
                    AND user_handle = :user_handle
                    AND (expires_at IS NULL OR expires_at > NOW())
                ");
                $stmt->execute([
                    ':room_name' => $roomName,
                    ':user_handle' => $userHandle
                ]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                return ($result['count'] ?? 0) > 0;
            } catch (\PDOException $e) {
                error_log('RoomRequestRepository::hasRoomAccess database error: ' . $e->getMessage());
            }
        }
        
        return false;
    }

    /**
     * Get room by invite code
     * 
     * @param string $inviteCode Invite code
     * @return array|null Room request data or null if not found
     */
    public function getRoomByInviteCode(string $inviteCode): ?array
    {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("
                    SELECT * FROM room_requests 
                    WHERE invite_code = :invite_code 
                    AND status = 'approved'
                    AND deleted_at IS NULL
                ");
                $stmt->execute([':invite_code' => $inviteCode]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $result ?: null;
            } catch (\PDOException $e) {
                error_log('RoomRequestRepository::getRoomByInviteCode database error: ' . $e->getMessage());
            }
        }
        
        return null;
    }

    /**
     * Generate a unique invite code
     * 
     * @return string Unique invite code
     */
    public function generateInviteCode(): string
    {
        do {
            // Generate 8-character alphanumeric code
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            
            // Check if code already exists
            if (DatabaseHealth::isAvailable()) {
                try {
                    $db = Database::getConnection();
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM room_requests WHERE invite_code = :code");
                    $stmt->execute([':code' => $code]);
                    $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if (($result['count'] ?? 0) === 0) {
                        return $code;
                    }
                } catch (\PDOException $e) {
                    // If database check fails, return the code anyway
                    return $code;
                }
            } else {
                return $code;
            }
        } while (true);
    }
}

