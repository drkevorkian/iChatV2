<?php
/**
 * Sentinel Chat Platform - Message Edit Archive Repository
 * 
 * Handles archiving of message edits.
 * Stores up to 100 edit versions per message.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;

class MessageEditArchiveRepository
{
    /**
     * Archive a message version before editing
     * 
     * @param int $messageId Message ID
     * @param string $messageType Message type ('room' or 'im')
     * @param string $cipherBlob Message content to archive
     * @param string $archivedBy User who is making the edit
     * @param int $editNumber Edit number (1-100)
     * @return bool True on success
     */
    public function archiveMessage(int $messageId, string $messageType, string $cipherBlob, string $archivedBy, int $editNumber): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            $sql = 'INSERT INTO message_edit_archive 
                    (message_id, message_type, archive_number, cipher_blob, archived_by, archived_at)
                    VALUES (:message_id, :message_type, :archive_number, :cipher_blob, :archived_by, NOW())';

            return Database::execute($sql, [
                ':message_id' => $messageId,
                ':message_type' => $messageType,
                ':archive_number' => $editNumber,
                ':cipher_blob' => $cipherBlob,
                ':archived_by' => $archivedBy,
            ]) > 0;
        } catch (\Exception $e) {
            error_log('MessageEditArchiveRepository: Failed to archive message: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get edit history for a message
     * 
     * @param int $messageId Message ID
     * @param string $messageType Message type ('room' or 'im')
     * @return array Array of archived versions
     */
    public function getEditHistory(int $messageId, string $messageType): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }

        try {
            $sql = 'SELECT * FROM message_edit_archive
                    WHERE message_id = :message_id
                      AND message_type = :message_type
                    ORDER BY archive_number ASC';

            return Database::query($sql, [
                ':message_id' => $messageId,
                ':message_type' => $messageType,
            ]);
        } catch (\Exception $e) {
            error_log('MessageEditArchiveRepository: Failed to get edit history: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get the current edit count for a message
     * 
     * @param int $messageId Message ID
     * @param string $messageType Message type ('room' or 'im')
     * @return int Current edit count
     */
    public function getEditCount(int $messageId, string $messageType): int
    {
        if (!DatabaseHealth::isAvailable()) {
            return 0;
        }

        try {
            $tableName = $messageType === 'room' ? 'temp_outbox' : 'im_messages';
            $sql = "SELECT edit_count FROM {$tableName} WHERE id = :message_id";
            
            $result = Database::queryOne($sql, [':message_id' => $messageId]);
            return $result ? (int)$result['edit_count'] : 0;
        } catch (\Exception $e) {
            error_log('MessageEditArchiveRepository: Failed to get edit count: ' . $e->getMessage());
            return 0;
        }
    }
}

