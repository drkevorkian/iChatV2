<?php
/**
 * Sentinel Chat Platform - Typing Indicator Repository
 * 
 * Handles database operations for typing indicators.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;

class TypingIndicatorRepository
{
    /**
     * Update typing status
     * 
     * @param string $userHandle User who is typing
     * @param string $conversationWith Conversation partner
     * @param bool $isTyping Whether user is typing
     * @return bool True on success
     */
    public function updateTyping(string $userHandle, string $conversationWith, bool $isTyping): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            if ($isTyping) {
                $sql = 'INSERT INTO typing_indicators (user_handle, conversation_with, is_typing, last_activity)
                        VALUES (:user_handle, :conversation_with, TRUE, NOW())
                        ON DUPLICATE KEY UPDATE
                            is_typing = TRUE,
                            last_activity = NOW()';
            } else {
                $sql = 'UPDATE typing_indicators
                        SET is_typing = FALSE,
                            last_activity = NOW()
                        WHERE user_handle = :user_handle
                          AND conversation_with = :conversation_with';
            }

            Database::execute($sql, [
                ':user_handle' => $userHandle,
                ':conversation_with' => $conversationWith,
            ]);

            // Clean up old entries (older than 5 minutes)
            $this->cleanupOldEntries();

            return true;
        } catch (\Exception $e) {
            error_log("TypingIndicatorRepository: Failed to update typing: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get typing status for a conversation
     * 
     * @param string $userHandle Current user
     * @param string $conversationWith Conversation partner
     * @return array|null Typing status or null
     */
    public function getTypingStatus(string $userHandle, string $conversationWith): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }

        try {
            // Get typing status from the conversation partner's perspective
            $sql = 'SELECT * FROM typing_indicators
                    WHERE user_handle = :conversation_with
                      AND conversation_with = :user_handle
                      AND is_typing = TRUE
                      AND last_activity > DATE_SUB(NOW(), INTERVAL 5 SECOND)';

            return Database::queryOne($sql, [
                ':conversation_with' => $conversationWith,
                ':user_handle' => $userHandle,
            ]);
        } catch (\Exception $e) {
            error_log("TypingIndicatorRepository: Failed to get typing status: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Clean up old typing indicator entries
     * 
     * @return int Number of entries cleaned up
     */
    private function cleanupOldEntries(): int
    {
        try {
            $sql = 'DELETE FROM typing_indicators
                    WHERE last_activity < DATE_SUB(NOW(), INTERVAL 5 MINUTE)';

            return Database::execute($sql);
        } catch (\Exception $e) {
            error_log("TypingIndicatorRepository: Failed to cleanup old entries: " . $e->getMessage());
            return 0;
        }
    }
}

