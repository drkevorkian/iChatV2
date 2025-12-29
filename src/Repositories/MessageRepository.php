<?php
/**
 * Sentinel Chat Platform - Message Repository
 * 
 * Handles all database operations for messages in the temporary outbox.
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

class MessageRepository
{
    private FileStorage $fileStorage;

    public function __construct()
    {
        $this->fileStorage = new FileStorage();
    }

    /**
     * Enqueue a message to the temporary outbox
     * 
     * Stores encrypted message data in the temporary database for later
     * delivery to the primary server. Falls back to file storage if database
     * is unavailable.
     * 
     * @param string $roomId Room identifier
     * @param string $senderHandle Sender's handle/username
     * @param string $cipherBlob Encrypted message data (base64 encoded)
     * @param int $filterVersion Word filter version used
     * @return string Message ID or file path
     */
    public function enqueueMessage(
        string $roomId,
        string $senderHandle,
        string $cipherBlob,
        int $filterVersion,
        bool $isHidden = false
    ): string {
        // Check if database is available
        if (DatabaseHealth::isAvailable()) {
            try {
                // Check if is_hidden column exists
                $db = Database::getConnection();
                $columnsCheck = $db->query("SHOW COLUMNS FROM temp_outbox LIKE 'is_hidden'");
                $hasHiddenColumn = $columnsCheck->rowCount() > 0;
                
                if ($hasHiddenColumn) {
                    $sql = 'INSERT INTO temp_outbox 
                            (room_id, sender_handle, cipher_blob, filter_version, is_hidden, queued_at) 
                            VALUES (:room_id, :sender_handle, :cipher_blob, :filter_version, :is_hidden, NOW())';
                    
                    Database::execute($sql, [
                        ':room_id' => $roomId,
                        ':sender_handle' => $senderHandle,
                        ':cipher_blob' => $cipherBlob,
                        ':filter_version' => $filterVersion,
                        ':is_hidden' => $isHidden ? 1 : 0,
                    ]);
                } else {
                    $sql = 'INSERT INTO temp_outbox 
                            (room_id, sender_handle, cipher_blob, filter_version, queued_at) 
                            VALUES (:room_id, :sender_handle, :cipher_blob, :filter_version, NOW())';
                    
                    Database::execute($sql, [
                        ':room_id' => $roomId,
                        ':sender_handle' => $senderHandle,
                        ':cipher_blob' => $cipherBlob,
                        ':filter_version' => $filterVersion,
                    ]);
                }
                
                return Database::lastInsertId();
            } catch (\Exception $e) {
                // Database operation failed, fall back to file storage
                error_log('Database enqueue failed, using file storage: ' . $e->getMessage());
                DatabaseHealth::checkFresh(); // Force fresh check next time
            }
        }
        
        // Fallback to file storage
        $data = [
            'room_id' => $roomId,
            'sender_handle' => $senderHandle,
            'cipher_blob' => $cipherBlob,
            'filter_version' => $filterVersion,
        ];
        
        $filepath = $this->fileStorage->queueMessage('message', $data);
        return 'file:' . basename($filepath);
    }

    /**
     * Get pending messages from outbox
     * 
     * Retrieves messages that have been queued but not yet delivered.
     * Combines database and file storage messages.
     * Used by the Python runtime service to drain the queue.
     * 
     * @param int $limit Maximum number of messages to retrieve
     * @param bool $includeHidden Whether to include hidden messages (for moderators/admins)
     * @return array Array of pending messages
     */
    public function getPendingMessages(int $limit = 100, bool $includeHidden = false, bool $includeDelivered = true): array
    {
        $messages = [];
        $limit = max(1, min(1000, (int)$limit)); // Sanitize limit
        
        // Get messages from database if available
        if (DatabaseHealth::isAvailable()) {
            try {
                $sql = 'SELECT id, room_id, sender_handle, cipher_blob, filter_version, queued_at,
                               is_hidden, edited_at, edited_by, original_cipher_blob, hidden_by
                        FROM temp_outbox
                        WHERE deleted_at IS NULL';
                
                // Only filter by delivered_at if we're NOT including delivered messages
                // For chat display, we want ALL messages (delivered or not)
                // This parameter defaults to false for backward compatibility with draining service
                
                if (!$includeHidden) {
                    $sql .= ' AND is_hidden = FALSE';
                }
                
                $sql .= ' ORDER BY queued_at DESC LIMIT ' . (string)$limit;
                
                $dbMessages = Database::query($sql);
                foreach ($dbMessages as $msg) {
                    $messages[] = $msg;
                }
            } catch (\Exception $e) {
                error_log('Database query failed: ' . $e->getMessage());
            }
        }
        
        // Get messages from file storage
        $fileMessages = $this->fileStorage->getQueuedMessages('message', true);
        foreach ($fileMessages as $fileMsg) {
            if (count($messages) >= $limit) {
                break;
            }
            
            // Convert file message format to match database format
            $messages[] = [
                'id' => 'file:' . basename($fileMsg['_metadata']['filepath'] ?? ''),
                'room_id' => $fileMsg['room_id'] ?? '',
                'sender_handle' => $fileMsg['sender_handle'] ?? '',
                'cipher_blob' => $fileMsg['cipher_blob'] ?? '',
                'filter_version' => $fileMsg['filter_version'] ?? 1,
                'queued_at' => $fileMsg['_metadata']['queued_at'] ?? date('Y-m-d H:i:s'),
                'is_hidden' => false,
                'edited_at' => null,
                'edited_by' => null,
                'original_cipher_blob' => null,
                'hidden_by' => null,
            ];
        }
        
        return $messages;
    }

    /**
     * Mark messages as delivered
     * 
     * Updates the delivered_at timestamp for messages that have been
     * successfully sent to the primary server.
     * 
     * @param array $messageIds Array of message IDs to mark as delivered
     * @return int Number of messages updated
     */
    public function markAsDelivered(array $messageIds): int
    {
        if (empty($messageIds)) {
            return 0;
        }
        
        // Build safe IN clause with placeholders
        $placeholders = [];
        $params = [];
        foreach ($messageIds as $index => $id) {
            $key = ':id' . $index;
            $placeholders[] = $key;
            $params[$key] = (int)$id; // Ensure integer
        }
        
        $sql = 'UPDATE temp_outbox 
                SET delivered_at = NOW() 
                WHERE id IN (' . implode(',', $placeholders) . ')
                  AND deleted_at IS NULL';
        
        return Database::execute($sql, $params);
    }

    /**
     * Soft delete a message
     * 
     * Marks a message as deleted without actually removing it from the database.
     * This preserves audit trails and allows for recovery if needed.
     * 
     * @param int $messageId Message ID to delete
     * @return bool True if message was deleted
     */
    public function softDelete(int $messageId): bool
    {
        $sql = 'UPDATE temp_outbox 
                SET deleted_at = NOW() 
                WHERE id = :id 
                  AND deleted_at IS NULL';
        
        return Database::execute($sql, [':id' => $messageId]) > 0;
    }

    /**
     * Get message count by room
     * 
     * Returns the number of pending messages for a specific room.
     * 
     * @param string $roomId Room identifier
     * @return int Number of pending messages
     */
    public function getPendingCountByRoom(string $roomId): int
    {
        $sql = 'SELECT COUNT(*) as count
                FROM temp_outbox
                WHERE room_id = :room_id 
                  AND delivered_at IS NULL 
                  AND deleted_at IS NULL
                  AND is_hidden = FALSE';
        
        $result = Database::queryOne($sql, [':room_id' => $roomId]);
        
        return $result ? (int)$result['count'] : 0;
    }

    /**
     * Hide a message (moderator action)
     * 
     * @param int $messageId Message ID
     * @param string $hiddenBy User handle who hid the message
     * @return bool True if message was hidden
     */
    public function hideMessage(int $messageId, string $hiddenBy): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        $sql = 'UPDATE temp_outbox 
                SET is_hidden = TRUE, hidden_by = :hidden_by
                WHERE id = :id 
                  AND deleted_at IS NULL';
        
        return Database::execute($sql, [
            ':id' => $messageId,
            ':hidden_by' => $hiddenBy,
        ]) > 0;
    }

    /**
     * Edit a message (admin/moderator action)
     * 
     * @param int $messageId Message ID
     * @param string $newCipherBlob New encrypted message content
     * @param string $editedBy User handle who edited the message
     * @return bool True if message was edited
     */
    public function editMessage(int $messageId, string $newCipherBlob, string $editedBy): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        // Store original content if not already stored
        $sql = 'UPDATE temp_outbox 
                SET cipher_blob = :cipher_blob,
                    edited_at = NOW(),
                    edited_by = :edited_by,
                    original_cipher_blob = COALESCE(original_cipher_blob, cipher_blob)
                WHERE id = :id 
                  AND deleted_at IS NULL';
        
        return Database::execute($sql, [
            ':id' => $messageId,
            ':cipher_blob' => $newCipherBlob,
            ':edited_by' => $editedBy,
        ]) > 0;
    }

    /**
     * Get message by ID
     * 
     * @param int $messageId Message ID
     * @return array|null Message data or null if not found
     */
    public function getMessageById(int $messageId): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }
        
        $sql = 'SELECT id, room_id, sender_handle, cipher_blob, filter_version, queued_at,
                       is_hidden, edited_at, edited_by, original_cipher_blob, hidden_by
                FROM temp_outbox
                WHERE id = :id 
                  AND deleted_at IS NULL';
        
        return Database::queryOne($sql, [':id' => $messageId]);
    }

    /**
     * Create a mock message (admin action - impersonate another user)
     * 
     * @param string $roomId Room identifier
     * @param string $senderHandle User handle to impersonate
     * @param string $cipherBlob Encrypted message content
     * @param int $filterVersion Word filter version
     * @param string $createdBy Admin who created the mock message
     * @return string Message ID
     */
    public function createMockMessage(
        string $roomId,
        string $senderHandle,
        string $cipherBlob,
        int $filterVersion,
        string $createdBy
    ): string {
        // Use regular enqueue, but mark as edited by admin (for audit trail)
        $messageId = $this->enqueueMessage($roomId, $senderHandle, $cipherBlob, $filterVersion);
        
        // Mark as edited by admin (for audit trail)
        if (DatabaseHealth::isAvailable() && is_numeric($messageId)) {
            try {
                $sql = 'UPDATE temp_outbox 
                        SET edited_by = :edited_by, edited_at = NOW()
                        WHERE id = :id';
                Database::execute($sql, [
                    ':id' => (int)$messageId,
                    ':edited_by' => $createdBy . ' (mock)',
                ]);
            } catch (\Exception $e) {
                error_log('Failed to mark mock message: ' . $e->getMessage());
            }
        }
        
        return $messageId;
    }

    /**
     * Check if a message can be edited
     * 
     * @param int $messageId Message ID
     * @param string $userHandle Current user handle
     * @param string $userRole Current user role
     * @return bool True if message can be edited
     */
    public function canEditMessage(int $messageId, string $userHandle, string $userRole): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            // Check if columns exist
            $db = Database::getConnection();
            $columnsCheck = $db->query("SHOW COLUMNS FROM temp_outbox LIKE 'is_permanent'");
            $hasPermanent = $columnsCheck->rowCount() > 0;

            if ($hasPermanent) {
                $sql = 'SELECT sender_handle, is_permanent, queued_at
                        FROM temp_outbox
                        WHERE id = :message_id
                          AND deleted_at IS NULL';
            } else {
                // Fallback: check age manually
                $sql = 'SELECT sender_handle, queued_at
                        FROM temp_outbox
                        WHERE id = :message_id
                          AND deleted_at IS NULL';
            }

            $message = Database::queryOne($sql, [':message_id' => $messageId]);

            if (!$message) {
                return false;
            }

            // Check if message is permanent
            if ($hasPermanent && !empty($message['is_permanent'])) {
                return false;
            }

            // Check if editing is disabled (100 edits reached)
            if (!empty($message['edit_disabled'])) {
                return false;
            }

            // Check age (24 hours 5 minutes)
            $queuedAt = strtotime($message['queued_at']);
            $ageLimit = time() - (24 * 3600 + 5 * 60); // 24 hours 5 minutes
            if ($queuedAt < $ageLimit) {
                return false;
            }

            // User must own the message, or be moderator/admin
            return ($message['sender_handle'] === $userHandle) || 
                   in_array($userRole, ['moderator', 'administrator', 'owner'], true);
        } catch (\Exception $e) {
            error_log('CanEditMessage failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a message can be deleted
     * 
     * @param int $messageId Message ID
     * @param string $userHandle Current user handle
     * @param string $userRole Current user role
     * @return bool True if message can be deleted
     */
    public function canDeleteMessage(int $messageId, string $userHandle, string $userRole): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            // Check if columns exist
            $db = Database::getConnection();
            $columnsCheck = $db->query("SHOW COLUMNS FROM temp_outbox LIKE 'is_permanent'");
            $hasPermanent = $columnsCheck->rowCount() > 0;

            if ($hasPermanent) {
                $sql = 'SELECT sender_handle, is_permanent, queued_at
                        FROM temp_outbox
                        WHERE id = :message_id
                          AND deleted_at IS NULL';
            } else {
                $sql = 'SELECT sender_handle, queued_at
                        FROM temp_outbox
                        WHERE id = :message_id
                          AND deleted_at IS NULL';
            }

            $message = Database::queryOne($sql, [':message_id' => $messageId]);

            if (!$message) {
                return false;
            }

            // Check if message is permanent
            if ($hasPermanent && !empty($message['is_permanent'])) {
                return false;
            }

            // Check age (24 hours 5 minutes)
            $queuedAt = strtotime($message['queued_at']);
            $ageLimit = time() - (24 * 3600 + 5 * 60);
            if ($queuedAt < $ageLimit) {
                return false;
            }

            // User must own the message, or be moderator/admin (moderators can delete any message)
            // Note: Deleted messages are kept in DB forever (soft delete)
            return ($message['sender_handle'] === $userHandle) || 
                   in_array($userRole, ['moderator', 'administrator', 'owner'], true);
        } catch (\Exception $e) {
            error_log('CanDeleteMessage failed: ' . $e->getMessage());
            return false;
        }
    }


    /**
     * Delete a message (soft delete)
     * 
     * @param int $messageId Message ID
     * @param string $userHandle User deleting the message
     * @param bool $isModerator Whether user is moderator/admin
     * @return bool True on success
     */
    public function deleteMessage(int $messageId, string $userHandle, bool $isModerator = false): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            $sql = 'UPDATE temp_outbox
                    SET deleted_at = NOW()
                    WHERE id = :message_id
                      AND deleted_at IS NULL';

            // If not moderator, ensure user owns the message
            if (!$isModerator) {
                $sql .= ' AND sender_handle = :user_handle';
                return Database::execute($sql, [
                    ':message_id' => $messageId,
                    ':user_handle' => $userHandle,
                ]) > 0;
            } else {
                // Moderators can delete any message
                return Database::execute($sql, [
                    ':message_id' => $messageId,
                ]) > 0;
            }
        } catch (\Exception $e) {
            error_log('DeleteMessage failed: ' . $e->getMessage());
            return false;
        }
    }
}

