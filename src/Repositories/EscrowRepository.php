<?php
/**
 * Sentinel Chat Platform - Escrow Repository
 * 
 * Handles database operations for key escrow requests.
 * Escrow requests allow authorized operators to request access to
 * encrypted room keys for security or compliance purposes.
 * 
 * Security: All escrow requests require justification and are logged
 * for audit purposes. Dual-control systems should verify requests.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;
use iChat\Services\FileStorage;

class EscrowRepository
{
    private FileStorage $fileStorage;

    public function __construct()
    {
        $this->fileStorage = new FileStorage();
    }

    /**
     * Create an escrow request
     * 
     * Creates a new escrow request with justification and operator details.
     * Falls back to file storage if database is unavailable.
     * Requests start in 'pending' status until approved.
     * 
     * @param string $roomId Room identifier
     * @param string $operatorHandle Operator's handle
     * @param string $justification Reason for escrow request
     * @return string Request ID or file identifier
     */
    public function createRequest(
        string $roomId,
        string $operatorHandle,
        string $justification
    ): string {
        // Check if database is available
        if (DatabaseHealth::isAvailable()) {
            try {
                $sql = 'INSERT INTO admin_escrow_requests 
                        (room_id, operator_handle, justification, status, requested_at) 
                        VALUES (:room_id, :operator_handle, :justification, :status, NOW())';
                
                Database::execute($sql, [
                    ':room_id' => $roomId,
                    ':operator_handle' => $operatorHandle,
                    ':justification' => $justification,
                    ':status' => 'pending',
                ]);
                
                return Database::lastInsertId();
            } catch (\Exception $e) {
                // Database operation failed, fall back to file storage
                error_log('Database escrow request failed, using file storage: ' . $e->getMessage());
                DatabaseHealth::checkFresh(); // Force fresh check next time
            }
        }
        
        // Fallback to file storage
        $data = [
            'room_id' => $roomId,
            'operator_handle' => $operatorHandle,
            'justification' => $justification,
            'status' => 'pending',
        ];
        
        $filepath = $this->fileStorage->queueMessage('escrow', $data);
        return 'file:' . basename($filepath);
    }

    /**
     * Get all escrow requests
     * 
     * Retrieves all escrow requests, optionally filtered by status.
     * Combines database and file storage requests.
     * Used by admin dashboard.
     * 
     * @param string|null $status Filter by status (pending, approved, denied)
     * @return array Array of escrow requests
     */
    public function getAllRequests(?string $status = null): array
    {
        $requests = [];
        
        // Get requests from database if available
        if (DatabaseHealth::isAvailable()) {
            try {
                $sql = 'SELECT id, room_id, operator_handle, justification, status, 
                               requested_at, updated_at
                        FROM admin_escrow_requests
                        WHERE deleted_at IS NULL';
                
                $params = [];
                
                if ($status !== null) {
                    $sql .= ' AND status = :status';
                    $params[':status'] = $status;
                }
                
                $sql .= ' ORDER BY requested_at DESC';
                
                $dbRequests = Database::query($sql, $params);
                foreach ($dbRequests as $req) {
                    $requests[] = $req;
                }
            } catch (\Exception $e) {
                error_log('Database escrow query failed: ' . $e->getMessage());
            }
        }
        
        // Get requests from file storage
        $fileRequests = $this->fileStorage->getQueuedMessages('escrow', true);
        foreach ($fileRequests as $fileReq) {
            // Apply status filter if specified
            if ($status !== null && ($fileReq['status'] ?? '') !== $status) {
                continue;
            }
            
            $requests[] = [
                'id' => 'file:' . basename($fileReq['_metadata']['filepath'] ?? ''),
                'room_id' => $fileReq['room_id'] ?? '',
                'operator_handle' => $fileReq['operator_handle'] ?? '',
                'justification' => $fileReq['justification'] ?? '',
                'status' => $fileReq['status'] ?? 'pending',
                'requested_at' => $fileReq['_metadata']['queued_at'] ?? date('Y-m-d H:i:s'),
                'updated_at' => null,
            ];
        }
        
        // Sort by requested_at descending
        usort($requests, function($a, $b) {
            $tsA = strtotime($a['requested_at'] ?? '1970-01-01');
            $tsB = strtotime($b['requested_at'] ?? '1970-01-01');
            return $tsB <=> $tsA;
        });
        
        return $requests;
    }

    /**
     * Update escrow request status
     * 
     * Updates the status of an escrow request (approve/deny).
     * Updates the updated_at timestamp.
     * 
     * @param int $requestId Request ID
     * @param string $status New status (approved, denied)
     * @return bool True if request was updated
     */
    public function updateStatus(int $requestId, string $status): bool
    {
        if (!in_array($status, ['approved', 'denied'], true)) {
            return false;
        }
        
        $sql = 'UPDATE admin_escrow_requests 
                SET status = :status, updated_at = NOW() 
                WHERE id = :id 
                  AND deleted_at IS NULL';
        
        return Database::execute($sql, [
            ':id' => $requestId,
            ':status' => $status,
        ]) > 0;
    }

    /**
     * Get escrow request by ID
     * 
     * @param int $requestId Request ID
     * @return array|null Request data or null if not found
     */
    public function getById(int $requestId): ?array
    {
        $sql = 'SELECT id, room_id, operator_handle, justification, status, 
                       requested_at, updated_at
                FROM admin_escrow_requests
                WHERE id = :id 
                  AND deleted_at IS NULL';
        
        return Database::queryOne($sql, [':id' => $requestId]);
    }
}

