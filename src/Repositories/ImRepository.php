<?php
/**
 * Sentinel Chat Platform - Instant Message Repository
 * 
 * Handles all database operations for instant messages (IMs).
 * IMs are private messages between users with read receipts and
 * folder organization (inbox/sent).
 * 
 * Security: All queries use prepared statements. User input is validated
 * and sanitized before database operations.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;
use iChat\Services\FileStorage;

class ImRepository
{
    private FileStorage $fileStorage;

    public function __construct()
    {
        $this->fileStorage = new FileStorage();
    }

    /**
     * Send an instant message (conversation-based)
     * 
     * Creates a new IM record in the database. Falls back to file storage
     * if database is unavailable. The message starts in 'queued' status
     * until the primary server confirms delivery.
     * 
     * @param string $fromUser Sender's handle
     * @param string $toUser Recipient's handle
     * @param string $cipherBlob Encrypted message data
     * @param string $encryptionType Type of encryption ('none' or 'e2ee')
     * @param string|null $nonce Nonce for E2EE messages
     * @return string IM ID or file identifier
     */
    public function sendIm(
        string $fromUser,
        string $toUser,
        string $cipherBlob,
        string $encryptionType = 'none',
        ?string $nonce = null
    ): string {
        // Check if database is available
        if (DatabaseHealth::isAvailable()) {
            try {
                // Check if encryption_type and nonce columns exist
                $db = Database::getConnection();
                $columnsCheck = $db->query("SHOW COLUMNS FROM im_messages LIKE 'encryption_type'");
                $hasEncryptionType = $columnsCheck->rowCount() > 0;
                
                if ($hasEncryptionType) {
                    // Use 'conversation' as folder for conversation-based system
                    // This maintains backward compatibility with existing schema
                    $sql = 'INSERT INTO im_messages 
                            (from_user, to_user, folder, status, cipher_blob, encryption_type, nonce, queued_at) 
                            VALUES (:from_user, :to_user, :folder, :status, :cipher_blob, :encryption_type, :nonce, NOW())';
                    
                    Database::execute($sql, [
                        ':from_user' => $fromUser,
                        ':to_user' => $toUser,
                        ':folder' => 'conversation', // Single entry for conversation
                        ':status' => 'sent', // Mark as sent immediately (no queuing needed)
                        ':cipher_blob' => $cipherBlob,
                        ':encryption_type' => $encryptionType,
                        ':nonce' => $nonce,
                    ]);
                } else {
                    // Fallback for older schema
                    $sql = 'INSERT INTO im_messages 
                            (from_user, to_user, folder, status, cipher_blob, queued_at) 
                            VALUES (:from_user, :to_user, :folder, :status, :cipher_blob, NOW())';
                    
                    Database::execute($sql, [
                        ':from_user' => $fromUser,
                        ':to_user' => $toUser,
                        ':folder' => 'conversation',
                        ':status' => 'sent',
                        ':cipher_blob' => $cipherBlob,
                    ]);
                }
                
                return (string)Database::lastInsertId();
            } catch (\Exception $e) {
                // Database operation failed, fall back to file storage
                error_log('Database IM send failed, using file storage: ' . $e->getMessage());
                DatabaseHealth::checkFresh(); // Force fresh check next time
            }
        }
        
        // Fallback to file storage
        $data = [
            'from_user' => $fromUser,
            'to_user' => $toUser,
            'folder' => 'conversation',
            'status' => 'sent',
            'cipher_blob' => $cipherBlob,
        ];
        
        $filepath = $this->fileStorage->queueMessage('im', $data);
        return 'file:' . basename($filepath);
    }

    /**
     * Get inbox for a user
     * 
     * Retrieves all messages in the user's inbox, ordered by most recent first.
     * Combines database and file storage messages.
     * Only returns non-deleted messages.
     * 
     * @param string $userHandle User's handle
     * @param int $limit Maximum number of messages to retrieve
     * @return array Array of IM messages
     */
    public function getInbox(string $userHandle, int $limit = 50): array
    {
        $messages = [];
        $limit = max(1, min(100, (int)$limit)); // Sanitize limit
        
        // Get messages from database if available
        if (DatabaseHealth::isAvailable()) {
            try {
                // Check if encryption_type column exists
                $db = Database::getConnection();
                $columnsCheck = $db->query("SHOW COLUMNS FROM im_messages LIKE 'encryption_type'");
                $hasEncryptionType = $columnsCheck->rowCount() > 0;
                
                if ($hasEncryptionType) {
                    $sql = 'SELECT id, from_user, to_user, folder, status, 
                                   cipher_blob, encryption_type, nonce, queued_at, sent_at, read_at
                            FROM im_messages
                            WHERE to_user = :user_handle 
                              AND folder = :folder
                              AND deleted_at IS NULL
                            ORDER BY queued_at DESC
                            LIMIT ' . (string)$limit;
                } else {
                    $sql = 'SELECT id, from_user, to_user, folder, status, 
                                   cipher_blob, queued_at, sent_at, read_at
                            FROM im_messages
                            WHERE to_user = :user_handle 
                              AND folder = :folder
                              AND deleted_at IS NULL
                            ORDER BY queued_at DESC
                            LIMIT ' . (string)$limit;
                }
                
                $dbMessages = Database::query($sql, [
                    ':user_handle' => $userHandle,
                    ':folder' => 'inbox',
                ]);
                
                foreach ($dbMessages as $msg) {
                    $messages[] = $msg;
                }
            } catch (\Exception $e) {
                error_log('Database inbox query failed: ' . $e->getMessage());
            }
        }
        
        // Get messages from file storage
        $fileMessages = $this->fileStorage->getQueuedMessages('im', true);
        foreach ($fileMessages as $fileMsg) {
            if (count($messages) >= $limit) {
                break;
            }
            
            // Only include messages for this user in inbox folder
            if (($fileMsg['to_user'] ?? '') === $userHandle && ($fileMsg['folder'] ?? '') === 'inbox') {
                $messages[] = [
                    'id' => 'file:' . basename($fileMsg['_metadata']['filepath'] ?? ''),
                    'from_user' => $fileMsg['from_user'] ?? '',
                    'to_user' => $fileMsg['to_user'] ?? '',
                    'folder' => $fileMsg['folder'] ?? 'inbox',
                    'status' => $fileMsg['status'] ?? 'queued',
                    'cipher_blob' => $fileMsg['cipher_blob'] ?? '',
                    'queued_at' => $fileMsg['_metadata']['queued_at'] ?? date('Y-m-d H:i:s'),
                    'sent_at' => null,
                    'read_at' => null,
                ];
            }
        }
        
        // Sort by queued_at descending
        usort($messages, function($a, $b) {
            $tsA = strtotime($a['queued_at'] ?? '1970-01-01');
            $tsB = strtotime($b['queued_at'] ?? '1970-01-01');
            return $tsB <=> $tsA;
        });
        
        return array_slice($messages, 0, $limit);
    }

    /**
     * Get conversations for a user (grouped by other user)
     * 
     * Returns a list of conversations with the most recent message from each user.
     * 
     * @param string $userHandle User's handle
     * @return array Array of conversations with latest message info
     */
    public function getConversations(string $userHandle): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }
        
        try {
            // Get all unique conversations (both sent and received)
            // Note: Must use unique parameter names for each occurrence
            $sql = 'SELECT 
                        CASE 
                            WHEN from_user = :user_handle1 THEN to_user
                            ELSE from_user
                        END AS other_user,
                        MAX(queued_at) AS last_message_at,
                        SUM(CASE WHEN to_user = :user_handle2 AND read_at IS NULL THEN 1 ELSE 0 END) AS unread_count,
                        (SELECT cipher_blob FROM im_messages 
                         WHERE ((from_user = :user_handle3 AND to_user = other_user) 
                                OR (from_user = other_user AND to_user = :user_handle4))
                           AND deleted_at IS NULL
                         ORDER BY queued_at DESC LIMIT 1) AS last_message_blob,
                        (SELECT from_user FROM im_messages 
                         WHERE ((from_user = :user_handle5 AND to_user = other_user) 
                                OR (from_user = other_user AND to_user = :user_handle6))
                           AND deleted_at IS NULL
                         ORDER BY queued_at DESC LIMIT 1) AS last_message_from
                    FROM im_messages
                    WHERE (from_user = :user_handle7 OR to_user = :user_handle8)
                      AND deleted_at IS NULL
                    GROUP BY other_user
                    ORDER BY last_message_at DESC';
            
            $conversations = Database::query($sql, [
                ':user_handle1' => $userHandle,
                ':user_handle2' => $userHandle,
                ':user_handle3' => $userHandle,
                ':user_handle4' => $userHandle,
                ':user_handle5' => $userHandle,
                ':user_handle6' => $userHandle,
                ':user_handle7' => $userHandle,
                ':user_handle8' => $userHandle,
            ]);
            
            return $conversations;
        } catch (\Exception $e) {
            error_log('Get conversations failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get conversation messages between two users
     * 
     * @param string $userHandle Current user's handle
     * @param string $otherUser Other user's handle
     * @param int $limit Maximum number of messages
     * @return array Array of messages
     */
    public function getConversationMessages(string $userHandle, string $otherUser, int $limit = 100): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }
        
        try {
            // Check if encryption_type column exists
            $db = Database::getConnection();
            $columnsCheck = $db->query("SHOW COLUMNS FROM im_messages LIKE 'encryption_type'");
            $hasEncryptionType = $columnsCheck->rowCount() > 0;
            
            if ($hasEncryptionType) {
                $sql = 'SELECT id, from_user, to_user, folder, status,
                               cipher_blob, encryption_type, nonce, queued_at, sent_at, read_at
                        FROM im_messages
                        WHERE ((from_user = :user_handle AND to_user = :other_user)
                           OR (from_user = :other_user AND to_user = :user_handle))
                          AND deleted_at IS NULL
                        ORDER BY queued_at ASC
                        LIMIT ' . (string)$limit;
            } else {
                $sql = 'SELECT id, from_user, to_user, folder, status,
                               cipher_blob, queued_at, sent_at, read_at
                        FROM im_messages
                        WHERE ((from_user = :user_handle AND to_user = :other_user)
                           OR (from_user = :other_user AND to_user = :user_handle))
                          AND deleted_at IS NULL
                        ORDER BY queued_at ASC
                        LIMIT ' . (string)$limit;
            }
            
            return Database::query($sql, [
                ':user_handle' => $userHandle,
                ':other_user' => $otherUser,
            ]);
        } catch (\Exception $e) {
            error_log('Get conversation messages failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Mark IM as sent
     * 
     * Updates the status and sent_at timestamp when the primary server
     * confirms delivery. This is called during the "login" action.
     * 
     * @param array $imIds Array of IM IDs to mark as sent
     * @return int Number of IMs updated
     */
    public function markAsSent(array $imIds): int
    {
        if (empty($imIds)) {
            return 0;
        }
        
        $placeholders = [];
        $params = [];
        foreach ($imIds as $index => $id) {
            $key = ':id' . $index;
            $placeholders[] = $key;
            $params[$key] = (int)$id;
        }
        
        $sql = 'UPDATE im_messages 
                SET status = :status, sent_at = NOW() 
                WHERE id IN (' . implode(',', $placeholders) . ')
                  AND status = :old_status
                  AND deleted_at IS NULL';
        
        $params[':status'] = 'sent';
        $params[':old_status'] = 'queued';
        
        return Database::execute($sql, $params);
    }

    /**
     * Mark IM as read
     * 
     * Updates the read_at timestamp when a user opens/reads a message.
     * This provides read receipt functionality.
     * 
     * @param int $imId IM ID to mark as read
     * @param string $userHandle User's handle (for verification)
     * @return bool True if IM was marked as read
     */
    public function markAsRead(int $imId, string $userHandle): bool
    {
        $sql = 'UPDATE im_messages 
                SET read_at = NOW() 
                WHERE id = :id 
                  AND to_user = :user_handle
                  AND read_at IS NULL
                  AND deleted_at IS NULL';
        
        return Database::execute($sql, [
            ':id' => $imId,
            ':user_handle' => $userHandle,
        ]) > 0;
    }

    /**
     * Mark a specific message as read
     * 
     * @param int $messageId Message ID
     * @param string $userHandle Current user (recipient)
     * @param string $fromUser Sender user handle
     * @return bool True on success
     */
    public function markMessageAsRead(int $messageId, string $userHandle, string $fromUser): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            $sql = 'UPDATE im_messages
                    SET read_at = NOW()
                    WHERE id = :message_id
                      AND to_user = :user_handle
                      AND from_user = :from_user
                      AND read_at IS NULL';

            return Database::execute($sql, [
                ':message_id' => $messageId,
                ':user_handle' => $userHandle,
                ':from_user' => $fromUser,
            ]) > 0;
        } catch (\Exception $e) {
            error_log('Mark message as read failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get unread count for a user (conversation-based)
     * 
     * Returns the number of unread messages across all conversations.
     * Used for badge notifications.
     * 
     * @param string $userHandle User's handle
     * @return int Number of unread messages
     */
    public function getUnreadCount(string $userHandle): int
    {
        if (!DatabaseHealth::isAvailable()) {
            return 0;
        }
        
        try {
            $sql = 'SELECT COUNT(*) as count
                    FROM im_messages
                    WHERE to_user = :user_handle 
                      AND read_at IS NULL
                      AND deleted_at IS NULL';
            
            $result = Database::queryOne($sql, [
                ':user_handle' => $userHandle,
            ]);
            
            return $result ? (int)$result['count'] : 0;
        } catch (\Exception $e) {
            error_log('Get unread count failed: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get unread conversations count
     * 
     * Returns the number of conversations with unread messages.
     * 
     * @param string $userHandle User's handle
     * @return int Number of conversations with unread messages
     */
    public function getUnreadConversationsCount(string $userHandle): int
    {
        if (!DatabaseHealth::isAvailable()) {
            return 0;
        }
        
        try {
            // Note: Must use unique parameter names for each occurrence
            $sql = 'SELECT COUNT(DISTINCT 
                        CASE 
                            WHEN from_user = :user_handle1 THEN to_user
                            ELSE from_user
                        END
                    ) as count
                    FROM im_messages
                    WHERE (from_user = :user_handle2 OR to_user = :user_handle3)
                      AND to_user = :user_handle4
                      AND read_at IS NULL
                      AND deleted_at IS NULL';
            
            $result = Database::queryOne($sql, [
                ':user_handle1' => $userHandle,
                ':user_handle2' => $userHandle,
                ':user_handle3' => $userHandle,
                ':user_handle4' => $userHandle,
            ]);
            
            return $result ? (int)$result['count'] : 0;
        } catch (\Exception $e) {
            error_log('Get unread conversations count failed: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Mark conversation as read
     * 
     * Marks all messages from a specific user as read.
     * 
     * @param string $userHandle Current user's handle
     * @param string $otherUser Other user's handle
     * @return int Number of messages marked as read
     */
    public function markConversationAsRead(string $userHandle, string $otherUser): int
    {
        if (!DatabaseHealth::isAvailable()) {
            return 0;
        }
        
        try {
            $sql = 'UPDATE im_messages 
                    SET read_at = NOW() 
                    WHERE from_user = :other_user
                      AND to_user = :user_handle
                      AND read_at IS NULL
                      AND deleted_at IS NULL';
            
            return Database::execute($sql, [
                ':user_handle' => $userHandle,
                ':other_user' => $otherUser,
            ]);
        } catch (\Exception $e) {
            error_log('Mark conversation as read failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Soft delete an IM
     * 
     * Marks an IM as deleted without removing it from the database.
     * 
     * @param int $imId IM ID to delete
     * @param string $userHandle User's handle (for verification)
     * @return bool True if IM was deleted
     */
    public function softDelete(int $imId, string $userHandle): bool
    {
        $sql = 'UPDATE im_messages 
                SET deleted_at = NOW() 
                WHERE id = :id 
                  AND (from_user = :user_handle OR to_user = :user_handle)
                  AND deleted_at IS NULL';
        
        return Database::execute($sql, [
            ':id' => $imId,
            ':user_handle' => $userHandle,
        ]) > 0;
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
            $columnsCheck = $db->query("SHOW COLUMNS FROM im_messages LIKE 'is_permanent'");
            $hasPermanent = $columnsCheck->rowCount() > 0;

            if ($hasPermanent) {
                $sql = 'SELECT from_user, is_permanent, edit_disabled, queued_at
                        FROM im_messages
                        WHERE id = :message_id
                          AND deleted_at IS NULL';
            } else {
                $sql = 'SELECT from_user, edit_disabled, queued_at
                        FROM im_messages
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
            $ageLimit = time() - (24 * 3600 + 5 * 60);
            if ($queuedAt < $ageLimit) {
                return false;
            }

            // User must own the message, or be moderator/admin
            return ($message['from_user'] === $userHandle) || 
                   in_array($userRole, ['moderator', 'administrator', 'owner'], true);
        } catch (\Exception $e) {
            error_log('CanEditMessage (IM) failed: ' . $e->getMessage());
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
            $columnsCheck = $db->query("SHOW COLUMNS FROM im_messages LIKE 'is_permanent'");
            $hasPermanent = $columnsCheck->rowCount() > 0;

            if ($hasPermanent) {
                $sql = 'SELECT from_user, is_permanent, queued_at
                        FROM im_messages
                        WHERE id = :message_id
                          AND deleted_at IS NULL';
            } else {
                $sql = 'SELECT from_user, queued_at
                        FROM im_messages
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

            // User must own the message, or be moderator/admin
            // Note: Deleted messages are kept in DB forever (soft delete)
            return ($message['from_user'] === $userHandle) || 
                   in_array($userRole, ['moderator', 'administrator', 'owner'], true);
        } catch (\Exception $e) {
            error_log('CanDeleteMessage (IM) failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Edit a message
     * 
     * @param int $messageId Message ID
     * @param string $userHandle User editing the message
     * @param string $newCipherBlob New encrypted content
     * @return bool True on success
     */
    public function editMessage(int $messageId, string $userHandle, string $newCipherBlob): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            // Get current message content and edit count
            $current = Database::queryOne(
                'SELECT cipher_blob, edit_count, edit_disabled FROM im_messages WHERE id = :id AND deleted_at IS NULL',
                [':id' => $messageId]
            );

            if (!$current) {
                return false;
            }

            // Check if editing is disabled (100 edits reached)
            if (!empty($current['edit_disabled'])) {
                error_log("EditMessage (IM): Message {$messageId} has reached 100 edits and editing is disabled");
                return false;
            }

            $currentEditCount = (int)($current['edit_count'] ?? 0);
            $newEditCount = $currentEditCount + 1;

            // Archive the current version before updating
            $archiveRepo = new \iChat\Repositories\MessageEditArchiveRepository();
            $archiveRepo->archiveMessage(
                $messageId,
                'im',
                $current['cipher_blob'],
                $userHandle,
                $newEditCount
            );

            // Check if we've reached 100 edits
            $editDisabled = ($newEditCount >= 100);

            // Check if columns exist
            $db = Database::getConnection();
            $columnsCheck = $db->query("SHOW COLUMNS FROM im_messages LIKE 'edited_at'");
            $hasEditColumns = $columnsCheck->rowCount() > 0;

            if ($hasEditColumns) {
                $sql = 'UPDATE im_messages
                        SET cipher_blob = :new_cipher_blob,
                            edited_at = NOW(),
                            edited_by = :edited_by,
                            edit_count = :edit_count,
                            edit_disabled = :edit_disabled
                        WHERE id = :message_id
                          AND deleted_at IS NULL';
                
                return Database::execute($sql, [
                    ':new_cipher_blob' => $newCipherBlob,
                    ':edited_by' => $userHandle,
                    ':edit_count' => $newEditCount,
                    ':edit_disabled' => $editDisabled ? 1 : 0,
                    ':message_id' => $messageId,
                ]) > 0;
            } else {
                // Fallback: just update content
                $sql = 'UPDATE im_messages
                        SET cipher_blob = :new_cipher_blob
                        WHERE id = :message_id
                          AND deleted_at IS NULL';
                
                return Database::execute($sql, [
                    ':new_cipher_blob' => $newCipherBlob,
                    ':message_id' => $messageId,
                ]) > 0;
            }
        } catch (\Exception $e) {
            error_log('EditMessage (IM) failed: ' . $e->getMessage());
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
            $sql = 'UPDATE im_messages
                    SET deleted_at = NOW()
                    WHERE id = :message_id
                      AND deleted_at IS NULL';

            // If not moderator, ensure user owns the message
            if (!$isModerator) {
                $sql .= ' AND from_user = :user_handle';
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
            error_log('DeleteMessage (IM) failed: ' . $e->getMessage());
            return false;
        }
    }
}

