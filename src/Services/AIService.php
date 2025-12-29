<?php
/**
 * Sentinel Chat Platform - AI Service
 * 
 * Handles AI-powered features: auto-moderation, smart replies, summarization, and bot features.
 * Supports multiple AI providers (OpenAI, Anthropic, local, custom).
 */

declare(strict_types=1);

namespace iChat\Services;

use iChat\Repositories\AIConfigRepository;
use iChat\Repositories\AIModerationRepository;

class AIService
{
    private AIConfigRepository $configRepo;
    private AIModerationRepository $moderationRepo;

    public function __construct()
    {
        $this->configRepo = new AIConfigRepository();
        $this->moderationRepo = new AIModerationRepository();
    }

    /**
     * Check if an AI system is enabled
     * 
     * @param string $systemName System name (moderation, smart_replies, summarization, bot)
     * @return bool True if enabled
     */
    public function isSystemEnabled(string $systemName): bool
    {
        $config = $this->configRepo->getConfig($systemName);
        return $config && !empty($config['enabled']);
    }

    /**
     * Moderate a message using AI
     * 
     * @param string $messageContent Message content to moderate
     * @param string $userHandle User who sent the message
     * @param array $flaggedWords Words from filter list that were found
     * @param int|null $messageId Message ID (if available)
     * @param string $messageType Message type ('room' or 'im')
     * @return array Moderation result with action and score
     */
    public function moderateMessage(
        string $messageContent,
        string $userHandle,
        array $flaggedWords = [],
        ?int $messageId = null,
        string $messageType = 'room'
    ): array {
        if (!$this->isSystemEnabled('moderation')) {
            return [
                'action' => 'none',
                'score' => 0.0,
                'reason' => 'AI moderation is disabled',
            ];
        }

        $config = $this->configRepo->getConfig('moderation');
        $provider = $config['provider'] ?? null;
        $model = $config['model_name'] ?? null;

        // If words from filter list are present, use AI to analyze
        if (!empty($flaggedWords)) {
            $result = $this->callModerationAPI($messageContent, $provider, $model);
            
            // Log the moderation action (messageId may be null if called before message is saved)
            $this->moderationRepo->logModeration(
                $messageId,
                $messageType,
                $userHandle,
                $messageContent,
                implode(',', $flaggedWords),
                $result['score'] ?? 0.0,
                $result['action'] ?? 'flag',
                $provider,
                $model,
                $result
            );

            return $result;
        }

        return [
            'action' => 'none',
            'score' => 0.0,
            'reason' => 'No flagged words found',
        ];
    }

    /**
     * Call AI moderation API
     * 
     * @param string $messageContent Message to moderate
     * @param string|null $provider AI provider
     * @param string|null $model Model name
     * @return array Moderation result
     */
    private function callModerationAPI(string $messageContent, ?string $provider, ?string $model): array
    {
        // Default: simple keyword-based scoring
        // In production, integrate with OpenAI, Anthropic, or local ML model
        
        $toxicityKeywords = [
            'hate', 'violence', 'harassment', 'threat', 'abuse',
            'toxic', 'offensive', 'inappropriate', 'harmful'
        ];
        
        $score = 0.0;
        $lowerContent = strtolower($messageContent);
        
        foreach ($toxicityKeywords as $keyword) {
            if (strpos($lowerContent, $keyword) !== false) {
                $score += 0.1;
            }
        }
        
        $score = min(1.0, $score);
        
        // Determine action based on score
        $action = 'none';
        if ($score >= 0.8) {
            $action = 'delete';
        } elseif ($score >= 0.6) {
            $action = 'hide';
        } elseif ($score >= 0.4) {
            $action = 'warn';
        } elseif ($score >= 0.2) {
            $action = 'flag';
        }
        
        return [
            'action' => $action,
            'score' => $score,
            'reason' => 'AI analysis completed',
            'provider' => $provider ?? 'local',
            'model' => $model ?? 'keyword-based',
        ];
    }

    /**
     * Generate smart reply suggestions
     * 
     * @param string $conversationId Conversation identifier
     * @param string $conversationType Type ('room' or 'im')
     * @param array $recentMessages Recent messages for context
     * @return array Array of suggested replies
     */
    public function generateSmartReplies(
        string $conversationId,
        string $conversationType,
        array $recentMessages
    ): array {
        if (!$this->isSystemEnabled('smart_replies')) {
            return [];
        }

        // Simple implementation: extract common phrases and suggest responses
        // In production, use AI to generate contextual replies
        
        $suggestions = [
            'Thanks!',
            'Got it.',
            'I see.',
            'Interesting.',
        ];
        
        return array_slice($suggestions, 0, 3);
    }

    /**
     * Summarize a thread
     * 
     * @param string $threadId Thread identifier
     * @param string $threadType Type ('room', 'im', 'thread')
     * @param array $messages Messages in the thread
     * @return array Summary with key points
     */
    public function summarizeThread(
        string $threadId,
        string $threadType,
        array $messages
    ): array {
        if (!$this->isSystemEnabled('summarization')) {
            return [
                'summary' => '',
                'key_points' => [],
            ];
        }

        if (count($messages) < 10) {
            return [
                'summary' => 'Thread too short to summarize.',
                'key_points' => [],
            ];
        }

        // Simple implementation: extract key topics
        // In production, use AI to generate comprehensive summaries
        
        $topics = [];
        foreach ($messages as $msg) {
            $content = $msg['content'] ?? '';
            $words = explode(' ', strtolower($content));
            $topics = array_merge($topics, array_slice($words, 0, 5));
        }
        
        $topics = array_count_values($topics);
        arsort($topics);
        $keyPoints = array_slice(array_keys($topics), 0, 5);
        
        $summary = 'This thread discusses: ' . implode(', ', $keyPoints);
        
        return [
            'summary' => $summary,
            'key_points' => $keyPoints,
            'message_count' => count($messages),
        ];
    }
}

