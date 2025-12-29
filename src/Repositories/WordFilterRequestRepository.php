<?php
/**
 * Sentinel Chat Platform - Word Filter Request Repository
 * 
 * Handles all database operations for word filter change requests.
 * Moderators can request adding, editing, or removing words from the filter list.
 * 
 * Security: All queries use prepared statements. User input is never
 * directly concatenated into SQL queries.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;
use iChat\Services\FileStorage;

class WordFilterRequestRepository
{
    private FileStorage $fileStorage;

    public function __construct()
    {
        $this->fileStorage = new FileStorage();
    }

    /**
     * Create a new word filter request
     * 
     * @param string $requestType Type: 'add', 'edit', or 'remove'
     * @param string $requesterHandle Moderator handle requesting the change
     * @param int|null $requesterUserId User ID if registered user
     * @param string $justification Reason for the request
     * @param string|null $filterId Original filter ID (for edit/remove)
     * @param string|null $wordPattern Word pattern to add/edit
     * @param string|null $replacement Replacement text
     * @param int|null $severity Severity level (1-4)
     * @param array|null $tags Tags array
     * @param array|null $exceptions Exceptions array
     * @param bool|null $isRegex Whether pattern is regex
     * @return int Request ID
     */
    public function createRequest(
        string $requestType,
        string $requesterHandle,
        ?int $requesterUserId,
        string $justification,
        ?string $filterId = null,
        ?string $wordPattern = null,
        ?string $replacement = null,
        ?int $severity = null,
        ?array $tags = null,
        ?array $exceptions = null,
        ?bool $isRegex = null
    ): int {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("
                    INSERT INTO word_filter_requests 
                    (request_type, filter_id, word_pattern, replacement, severity, tags, exceptions, is_regex, 
                     justification, requester_handle, requester_user_id, status)
                    VALUES (:request_type, :filter_id, :word_pattern, :replacement, :severity, :tags, :exceptions, 
                            :is_regex, :justification, :requester_handle, :requester_user_id, 'pending')
                ");
                
                $tagsJson = $tags ? json_encode($tags) : null;
                $exceptionsJson = $exceptions ? json_encode($exceptions) : null;
                
                $stmt->execute([
                    ':request_type' => $requestType,
                    ':filter_id' => $filterId,
                    ':word_pattern' => $wordPattern,
                    ':replacement' => $replacement,
                    ':severity' => $severity,
                    ':tags' => $tagsJson,
                    ':exceptions' => $exceptionsJson,
                    ':is_regex' => $isRegex ? 1 : 0,
                    ':justification' => $justification,
                    ':requester_handle' => $requesterHandle,
                    ':requester_user_id' => $requesterUserId
                ]);
                
                return (int)$db->lastInsertId();
            } catch (\PDOException $e) {
                error_log('WordFilterRequestRepository::createRequest database error: ' . $e->getMessage());
                throw new \RuntimeException('Failed to create word filter request: ' . $e->getMessage(), 0, $e);
            }
        }
        
        throw new \RuntimeException('Database not available');
    }

    /**
     * Get all word filter requests (for admin)
     * 
     * @param string|null $status Filter by status (pending, approved, denied)
     * @return array List of requests
     */
    public function getAllRequests(?string $status = null): array
    {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                
                if ($status !== null) {
                    $stmt = $db->prepare("
                        SELECT * FROM word_filter_requests 
                        WHERE deleted_at IS NULL AND status = :status
                        ORDER BY created_at DESC
                    ");
                    $stmt->execute([':status' => $status]);
                } else {
                    $stmt = $db->prepare("
                        SELECT * FROM word_filter_requests 
                        WHERE deleted_at IS NULL
                        ORDER BY created_at DESC
                    ");
                    $stmt->execute();
                }
                
                $requests = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                
                // Decode JSON fields
                foreach ($requests as &$request) {
                    if ($request['tags']) {
                        $request['tags'] = json_decode($request['tags'], true);
                    }
                    if ($request['exceptions']) {
                        $request['exceptions'] = json_decode($request['exceptions'], true);
                    }
                }
                
                return $requests;
            } catch (\PDOException $e) {
                error_log('WordFilterRequestRepository::getAllRequests database error: ' . $e->getMessage());
            }
        }
        
        return [];
    }

    /**
     * Get request by ID
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
                    SELECT * FROM word_filter_requests 
                    WHERE id = :id AND deleted_at IS NULL
                ");
                $stmt->execute([':id' => $requestId]);
                $request = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($request) {
                    if ($request['tags']) {
                        $request['tags'] = json_decode($request['tags'], true);
                    }
                    if ($request['exceptions']) {
                        $request['exceptions'] = json_decode($request['exceptions'], true);
                    }
                }
                
                return $request ?: null;
            } catch (\PDOException $e) {
                error_log('WordFilterRequestRepository::getRequestById database error: ' . $e->getMessage());
            }
        }
        
        return null;
    }

    /**
     * Update request status (approve or deny)
     * 
     * @param int $requestId Request ID
     * @param string $status New status ('approved' or 'denied')
     * @param string $reviewedBy Admin handle who reviewed
     * @param string|null $reviewNotes Optional review notes
     * @return bool True if successful
     */
    public function updateRequestStatus(
        int $requestId,
        string $status,
        string $reviewedBy,
        ?string $reviewNotes = null
    ): bool {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("
                    UPDATE word_filter_requests 
                    SET status = :status, reviewed_by = :reviewed_by, reviewed_at = NOW(), review_notes = :review_notes
                    WHERE id = :id AND deleted_at IS NULL
                ");
                
                return $stmt->execute([
                    ':status' => $status,
                    ':reviewed_by' => $reviewedBy,
                    ':review_notes' => $reviewNotes,
                    ':id' => $requestId
                ]);
            } catch (\PDOException $e) {
                error_log('WordFilterRequestRepository::updateRequestStatus database error: ' . $e->getMessage());
                return false;
            }
        }
        
        return false;
    }
}

