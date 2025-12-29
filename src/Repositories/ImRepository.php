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
     * @return string IM ID or file identifier
     */
    public function sendIm(
        string $fromUser,
        string $toUser,
        string $cipherBlob
    ): string {
        // Check if database is available
        if (DatabaseHealth::isAvailable()) {
            try {
                // Use 'conversation' as folder for conversation-based system
                // This maintains backward compatibility with existing schema
                $sql = 'INSERT INTO im_messages 
                        (from_user, to_user, folder, status, cipher_blob, queued_at) 
                        VALUES (:from_user, :to_user, :folder, :status, :cipher_blob, NOW())';
                
                Database::execute($sql, [
                    ':from_user' => $fromUser,
                    ':to_user' => $toUser,
                    ':folder' => 'conversation', // Single entry for conversation
                    ':status' => 'sent', // Mark as sent immediately (no queuing needed)
                    ':cipher_blob' => $cipherBlob,
                ]);
                
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
                $sql = 'SELECT id, from_user, to_user, folder, status, 
                               cipher_blob, queued_at, sent_at, read_at
                        FROM im_messages
                        WHERE to_user = :user_handle 
                          AND folder = :folder
                          AND deleted_at IS NULL
                        ORDER BY queued_at DESC
                        LIMIT ' . (string)$limit;
                
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
            $sql = 'SELECT id, from_user, to_user, folder, status,
                           cipher_blob, queued_at, sent_at, read_at
                    FROM im_messages
                    WHERE ((from_user = :user_handle AND to_user = :other_user)
                       OR (from_user = :other_user AND to_user = :user_handle))
                      AND deleted_at IS NULL
                    ORDER BY queued_at ASC
                    LIMIT ' . (string)$limit;
            
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
}

