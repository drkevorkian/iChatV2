<?php
/**
 * Sentinel Chat Platform - AI Moderation Repository
 * 
 * Handles database operations for AI moderation logs.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;

class AIModerationRepository
{
    /**
     * Log a moderation action
     * 
     * @param int|null $messageId Message ID
     * @param string $messageType Message type
     * @param string $userHandle User handle
     * @param string $messageContent Message content
     * @param string|null $flaggedWords Flagged words
     * @param float $toxicityScore Toxicity score
     * @param string $action Action taken
     * @param string|null $provider AI provider
     * @param string|null $model AI model
     * @param array|null $aiResponse Full AI response
     * @return bool True on success
     */
    public function logModeration(
        ?int $messageId,
        string $messageType,
        string $userHandle,
        string $messageContent,
        ?string $flaggedWords,
        float $toxicityScore,
        string $action,
        ?string $provider,
        ?string $model,
        ?array $aiResponse
    ): bool {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            $sql = 'INSERT INTO ai_moderation_log 
                    (message_id, message_type, user_handle, message_content, flagged_words,
                     toxicity_score, moderation_action, ai_provider, ai_model, ai_response, created_at)
                    VALUES 
                    (:message_id, :message_type, :user_handle, :message_content, :flagged_words,
                     :toxicity_score, :moderation_action, :ai_provider, :ai_model, :ai_response, NOW())';
            
            return Database::execute($sql, [
                ':message_id' => $messageId,
                ':message_type' => $messageType,
                ':user_handle' => $userHandle,
                ':message_content' => $messageContent,
                ':flagged_words' => $flaggedWords,
                ':toxicity_score' => $toxicityScore,
                ':moderation_action' => $action,
                ':ai_provider' => $provider,
                ':ai_model' => $model,
                ':ai_response' => $aiResponse ? json_encode($aiResponse) : null,
            ]) > 0;
        } catch (\Exception $e) {
            error_log('AIModerationRepository: Failed to log moderation: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get moderation logs with filters
     * 
     * @param array $filters Filters (user_handle, action, start_date, end_date)
     * @param int $limit Limit
     * @param int $offset Offset
     * @return array Array of logs
     */
    public function getLogs(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }

        try {
            $sql = 'SELECT * FROM ai_moderation_log WHERE 1=1';
            $params = [];

            if (isset($filters['user_handle'])) {
                $sql .= ' AND user_handle = :user_handle';
                $params[':user_handle'] = $filters['user_handle'];
            }
            if (isset($filters['action'])) {
                $sql .= ' AND moderation_action = :action';
                $params[':action'] = $filters['action'];
            }
            if (isset($filters['start_date'])) {
                $sql .= ' AND created_at >= :start_date';
                $params[':start_date'] = $filters['start_date'];
            }
            if (isset($filters['end_date'])) {
                $sql .= ' AND created_at <= :end_date';
                $params[':end_date'] = $filters['end_date'];
            }

            $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;

            return Database::query($sql, $params);
        } catch (\Exception $e) {
            error_log('AIModerationRepository: Failed to get logs: ' . $e->getMessage());
            return [];
        }
    }
}

