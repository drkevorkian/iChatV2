<?php
/**
 * Sentinel Chat Platform - Mail Repository
 * 
 * Handles all database operations for mail messages.
 * Mail is distinct from IM - it's for longer, formal messages with subjects,
 * attachments, folders, and threading.
 * 
 * Security: All queries use prepared statements. User input is validated
 * and sanitized before database operations.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;
use iChat\Services\FileStorage;

class MailRepository
{
    private FileStorage $fileStorage;
    
    public function __construct()
    {
        $this->fileStorage = new FileStorage();
    }
    
    /**
     * Send a mail message
     * 
     * Creates a new mail record in the database. Falls back to file storage
     * if database is unavailable.
     * 
     * @param string $fromUser Sender's handle
     * @param string $toUser Recipient's handle
     * @param string $subject Message subject
     * @param string $cipherBlob Encrypted message body (base64 encoded)
     * @param array $ccUsers CC recipients (optional)
     * @param array $bccUsers BCC recipients (optional)
     * @param int|null $replyToId ID of message being replied to (optional)
     * @param int|null $threadId Thread ID for conversation threading (optional)
     * @return int Mail ID
     */
    public function sendMail(
        string $fromUser,
        string $toUser,
        string $subject,
        string $cipherBlob,
        array $ccUsers = [],
        array $bccUsers = [],
        ?int $replyToId = null,
        ?int $threadId = null
    ): int {
        if (!DatabaseHealth::isAvailable()) {
            // Fallback to file storage
            $data = [
                'from_user' => $fromUser,
                'to_user' => $toUser,
                'subject' => $subject,
                'cipher_blob' => $cipherBlob,
                'cc_users' => $ccUsers,
                'bcc_users' => $bccUsers,
                'reply_to_id' => $replyToId,
                'thread_id' => $threadId,
                'folder' => 'sent',
                'status' => 'sent',
            ];
            $filepath = $this->fileStorage->queueMessage('mail', $data);
            return 0; // Return 0 for file-stored messages
        }
        
        // Determine thread ID if replying
        if ($replyToId !== null && $threadId === null) {
            $originalMail = $this->getMailById($replyToId);
            if ($originalMail) {
                $threadId = $originalMail['thread_id'] ?? $replyToId;
            }
        }
        
        // Create sent mail for sender
        $sql = 'INSERT INTO mail_messages 
                (from_user, to_user, cc_users, bcc_users, subject, cipher_blob, folder, status, thread_id, reply_to_id, sent_at)
                VALUES (:from_user, :to_user, :cc_users, :bcc_users, :subject, :cipher_blob, :folder, :status, :thread_id, :reply_to_id, NOW())';
        
        $ccUsersStr = !empty($ccUsers) ? implode(',', $ccUsers) : null;
        $bccUsersStr = !empty($bccUsers) ? implode(',', $bccUsers) : null;
        
        Database::execute($sql, [
            ':from_user' => $fromUser,
            ':to_user' => $toUser,
            ':cc_users' => $ccUsersStr,
            ':bcc_users' => $bccUsersStr,
            ':subject' => $subject,
            ':cipher_blob' => $cipherBlob,
            ':folder' => 'sent',
            ':status' => 'sent',
            ':thread_id' => $threadId,
            ':reply_to_id' => $replyToId,
        ]);
        
        $sentMailId = (int)Database::lastInsertId();
        
        // Create inbox mail for recipient
        Database::execute($sql, [
            ':from_user' => $fromUser,
            ':to_user' => $toUser,
            ':cc_users' => $ccUsersStr,
            ':bcc_users' => $bccUsersStr,
            ':subject' => $subject,
            ':cipher_blob' => $cipherBlob,
            ':folder' => 'inbox',
            ':status' => 'sent',
            ':thread_id' => $threadId ?? $sentMailId,
            ':reply_to_id' => $replyToId,
        ]);
        
        // Create inbox mail for CC recipients
        foreach ($ccUsers as $ccUser) {
            if ($ccUser !== $toUser) {
                Database::execute($sql, [
                    ':from_user' => $fromUser,
                    ':to_user' => $ccUser,
                    ':cc_users' => $ccUsersStr,
                    ':bcc_users' => null, // BCC users don't see each other
                    ':subject' => $subject,
                    ':cipher_blob' => $cipherBlob,
                    ':folder' => 'inbox',
                    ':status' => 'sent',
                    ':thread_id' => $threadId ?? $sentMailId,
                    ':reply_to_id' => $replyToId,
                ]);
            }
        }
        
        // Create inbox mail for BCC recipients (they don't see each other)
        foreach ($bccUsers as $bccUser) {
            if ($bccUser !== $toUser && !in_array($bccUser, $ccUsers)) {
                Database::execute($sql, [
                    ':from_user' => $fromUser,
                    ':to_user' => $bccUser,
                    ':cc_users' => null, // BCC recipients don't see CC list
                    ':bcc_users' => null,
                    ':subject' => $subject,
                    ':cipher_blob' => $cipherBlob,
                    ':folder' => 'inbox',
                    ':status' => 'sent',
                    ':thread_id' => $threadId ?? $sentMailId,
                    ':reply_to_id' => $replyToId,
                ]);
            }
        }
        
        // Update has_attachments flag if needed
        if (!empty($attachmentIds)) {
            Database::execute(
                'UPDATE mail_messages SET has_attachments = TRUE WHERE id = :id',
                [':id' => $sentMailId]
            );
        }
        
        return $sentMailId;
    }
    
    /**
     * Add attachment to mail message
     * 
     * @param int $mailId Mail message ID
     * @param string $filename Original filename
     * @param string $filePath Path to stored file
     * @param int $fileSize File size in bytes
     * @param string|null $mimeType MIME type
     * @return int Attachment ID
     */
    public function addAttachment(
        int $mailId,
        string $filename,
        string $filePath,
        int $fileSize,
        ?string $mimeType = null
    ): int {
        if (!DatabaseHealth::isAvailable()) {
            return 0;
        }
        
        $sql = 'INSERT INTO mail_attachments (mail_id, filename, file_path, file_size, mime_type)
                VALUES (:mail_id, :filename, :file_path, :file_size, :mime_type)';
        
        Database::execute($sql, [
            ':mail_id' => $mailId,
            ':filename' => $filename,
            ':file_path' => $filePath,
            ':file_size' => $fileSize,
            ':mime_type' => $mimeType,
        ]);
        
        return (int)Database::lastInsertId();
    }
    
    /**
     * Get attachments for a mail message
     * 
     * @param int $mailId Mail message ID
     * @return array List of attachments
     */
    public function getAttachments(int $mailId): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }
        
        $sql = 'SELECT id, filename, file_path, file_size, mime_type, uploaded_at
                FROM mail_attachments
                WHERE mail_id = :mail_id
                ORDER BY uploaded_at ASC';
        
        $result = Database::query($sql, [':mail_id' => $mailId]);
        return $result ?: [];
    }
    
    /**
     * Save a draft mail message
     * 
     * @param string $fromUser Sender's handle
     * @param string $toUser Recipient's handle (can be empty for draft)
     * @param string $subject Message subject
     * @param string $cipherBlob Encrypted message body
     * @param int|null $draftId Existing draft ID to update (optional)
     * @return int Draft mail ID
     */
    public function saveDraft(
        string $fromUser,
        string $toUser,
        string $subject,
        string $cipherBlob,
        ?int $draftId = null
    ): int {
        if (!DatabaseHealth::isAvailable()) {
            return 0;
        }
        
        if ($draftId !== null) {
            // Update existing draft
            $sql = 'UPDATE mail_messages 
                    SET to_user = :to_user, subject = :subject, cipher_blob = :cipher_blob, created_at = NOW()
                    WHERE id = :id AND from_user = :from_user AND folder = :folder';
            
            Database::execute($sql, [
                ':id' => $draftId,
                ':from_user' => $fromUser,
                ':to_user' => $toUser,
                ':subject' => $subject,
                ':cipher_blob' => $cipherBlob,
                ':folder' => 'drafts',
            ]);
            
            return $draftId;
        } else {
            // Create new draft
            $sql = 'INSERT INTO mail_messages 
                    (from_user, to_user, subject, cipher_blob, folder, status)
                    VALUES (:from_user, :to_user, :subject, :cipher_blob, :folder, :status)';
            
            Database::execute($sql, [
                ':from_user' => $fromUser,
                ':to_user' => $toUser,
                ':subject' => $subject,
                ':cipher_blob' => $cipherBlob,
                ':folder' => 'drafts',
                ':status' => 'draft',
            ]);
            
            return (int)Database::lastInsertId();
        }
    }
    
    /**
     * Get mail messages for a user in a specific folder
     * 
     * @param string $userHandle User's handle
     * @param string $folder Folder name (inbox, sent, drafts, trash, archive, spam)
     * @param int $limit Maximum number of messages
     * @param int $offset Offset for pagination
     * @return array Array of mail messages
     */
    public function getMailByFolder(
        string $userHandle,
        string $folder,
        int $limit = 50,
        int $offset = 0
    ): array {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }
        
        $validFolders = ['inbox', 'sent', 'drafts', 'trash', 'archive', 'spam'];
        if (!in_array($folder, $validFolders, true)) {
            return [];
        }
        
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);
        
        // Build query based on folder
        if ($folder === 'sent') {
            $sql = 'SELECT id, from_user, to_user, cc_users, bcc_users, subject, folder, status,
                           thread_id, reply_to_id, is_starred, is_important, has_attachments,
                           created_at, sent_at, read_at
                    FROM mail_messages
                    WHERE from_user = :user_handle
                      AND folder = :folder
                      AND deleted_at IS NULL
                    ORDER BY created_at DESC
                    LIMIT :limit OFFSET :offset';
        } else {
            $sql = 'SELECT id, from_user, to_user, cc_users, bcc_users, subject, folder, status,
                           thread_id, reply_to_id, is_starred, is_important, has_attachments,
                           created_at, sent_at, read_at
                    FROM mail_messages
                    WHERE to_user = :user_handle
                      AND folder = :folder
                      AND deleted_at IS NULL
                    ORDER BY created_at DESC
                    LIMIT :limit OFFSET :offset';
        }
        
        $messages = Database::query($sql, [
            ':user_handle' => $userHandle,
            ':folder' => $folder,
            ':limit' => $limit,
            ':offset' => $offset,
        ]);
        
        // Parse CC and BCC users
        foreach ($messages as &$message) {
            $message['cc_users'] = !empty($message['cc_users']) ? explode(',', $message['cc_users']) : [];
            $message['bcc_users'] = !empty($message['bcc_users']) ? explode(',', $message['bcc_users']) : [];
        }
        
        return $messages;
    }
    
    /**
     * Get a single mail message by ID
     * 
     * @param int $mailId Mail message ID
     * @param string|null $userHandle User handle for access control (optional)
     * @return array|null Mail message or null if not found/not accessible
     */
    public function getMailById(int $mailId, ?string $userHandle = null): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }
        
        $sql = 'SELECT id, from_user, to_user, cc_users, bcc_users, subject, cipher_blob,
                       folder, status, thread_id, reply_to_id, is_starred, is_important,
                       has_attachments, created_at, sent_at, read_at
                FROM mail_messages
                WHERE id = :id AND deleted_at IS NULL';
        
        $params = [':id' => $mailId];
        
        if ($userHandle !== null) {
            $sql .= ' AND (from_user = :user_handle OR to_user = :user_handle)';
            $params[':user_handle'] = $userHandle;
        }
        
        $message = Database::queryOne($sql, $params);
        
        if (empty($message)) {
            return null;
        }
        
        // Parse CC and BCC users
        $message['cc_users'] = !empty($message['cc_users']) ? explode(',', $message['cc_users']) : [];
        $message['bcc_users'] = !empty($message['bcc_users']) ? explode(',', $message['bcc_users']) : [];
        
        return $message;
    }
    
    /**
     * Mark mail message as read
     * 
     * @param int $mailId Mail message ID
     * @param string $userHandle User handle (must be recipient)
     * @return bool True if successful
     */
    public function markAsRead(int $mailId, string $userHandle): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        $sql = 'UPDATE mail_messages 
                SET read_at = NOW(), status = :status
                WHERE id = :id AND to_user = :user_handle AND read_at IS NULL';
        
        return Database::execute($sql, [
            ':id' => $mailId,
            ':user_handle' => $userHandle,
            ':status' => 'read',
        ]) > 0;
    }
    
    /**
     * Move mail message to folder
     * 
     * @param int $mailId Mail message ID
     * @param string $userHandle User handle
     * @param string $folder Target folder
     * @return bool True if successful
     */
    public function moveToFolder(int $mailId, string $userHandle, string $folder): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        $validFolders = ['inbox', 'sent', 'drafts', 'trash', 'archive', 'spam'];
        if (!in_array($folder, $validFolders, true)) {
            return false;
        }
        
        $sql = 'UPDATE mail_messages 
                SET folder = :folder
                WHERE id = :id AND (from_user = :user_handle OR to_user = :user_handle)';
        
        return Database::execute($sql, [
            ':id' => $mailId,
            ':user_handle' => $userHandle,
            ':folder' => $folder,
        ]) > 0;
    }
    
    /**
     * Delete mail message (soft delete)
     * 
     * @param int $mailId Mail message ID
     * @param string $userHandle User handle
     * @return bool True if successful
     */
    public function deleteMail(int $mailId, string $userHandle): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        // Move to trash instead of hard delete
        return $this->moveToFolder($mailId, $userHandle, 'trash');
    }
    
    /**
     * Permanently delete mail message
     * 
     * @param int $mailId Mail message ID
     * @param string $userHandle User handle
     * @return bool True if successful
     */
    public function permanentlyDeleteMail(int $mailId, string $userHandle): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        $sql = 'UPDATE mail_messages 
                SET deleted_at = NOW()
                WHERE id = :id AND (from_user = :user_handle OR to_user = :user_handle)';
        
        return Database::execute($sql, [
            ':id' => $mailId,
            ':user_handle' => $userHandle,
        ]) > 0;
    }
    
    /**
     * Toggle starred flag
     * 
     * @param int $mailId Mail message ID
     * @param string $userHandle User handle
     * @return bool True if successful
     */
    public function toggleStarred(int $mailId, string $userHandle): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        $sql = 'UPDATE mail_messages 
                SET is_starred = NOT is_starred
                WHERE id = :id AND (from_user = :user_handle OR to_user = :user_handle)';
        
        return Database::execute($sql, [
            ':id' => $mailId,
            ':user_handle' => $userHandle,
        ]) > 0;
    }
    
    /**
     * Get unread mail count for a user
     * 
     * @param string $userHandle User's handle
     * @return int Unread count
     */
    public function getUnreadCount(string $userHandle): int
    {
        if (!DatabaseHealth::isAvailable()) {
            return 0;
        }
        
        $sql = 'SELECT COUNT(*) FROM mail_messages 
                WHERE to_user = :user_handle
                  AND folder = :folder
                  AND read_at IS NULL
                  AND deleted_at IS NULL';
        
        $result = Database::queryOne($sql, [
            ':user_handle' => $userHandle,
            ':folder' => 'inbox',
        ]);
        
        return (int)($result['COUNT(*)'] ?? 0);
    }
    
    /**
     * Get mail thread (conversation)
     * 
     * @param int $threadId Thread ID
     * @param string $userHandle User handle for access control
     * @return array Array of mail messages in thread
     */
    public function getThread(int $threadId, string $userHandle): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }
        
        $sql = 'SELECT id, from_user, to_user, cc_users, bcc_users, subject, folder, status,
                       thread_id, reply_to_id, is_starred, is_important, has_attachments,
                       created_at, sent_at, read_at
                FROM mail_messages
                WHERE thread_id = :thread_id
                  AND (from_user = :user_handle OR to_user = :user_handle)
                  AND deleted_at IS NULL
                ORDER BY created_at ASC';
        
        $messages = Database::query($sql, [
            ':thread_id' => $threadId,
            ':user_handle' => $userHandle,
        ]);
        
        // Parse CC and BCC users
        foreach ($messages as &$message) {
            $message['cc_users'] = !empty($message['cc_users']) ? explode(',', $message['cc_users']) : [];
            $message['bcc_users'] = !empty($message['bcc_users']) ? explode(',', $message['bcc_users']) : [];
        }
        
        return $messages;
    }
}

