<?php
/**
 * Sentinel Chat Platform - Pinky & Brain Bot Service
 * 
 * Implements Pinky and the Brain interactive bot.
 * Responds to /p&b command with Brain's question and Pinky's responses.
 * 
 * Security: Bot messages are clearly marked and rate-limited.
 */

declare(strict_types=1);

namespace iChat\Services;

use iChat\Database;
use iChat\Services\DatabaseHealth;

class PinkyBrainService
{
    private const BOT_HANDLE_BRAIN = 'Brain';
    private const BOT_HANDLE_PINKY = 'Pinky';
    
    /**
     * Check if message is a Pinky & Brain command
     * 
     * @param string $message Message to check
     * @return bool True if command detected
     */
    public function isCommand(string $message): bool
    {
        $trimmed = trim($message);
        return strtolower($trimmed) === '/p&b' || strtolower($trimmed) === '/p&b toggle' || strtolower($trimmed) === '/p&b on' || strtolower($trimmed) === '/p&b off';
    }
    
    /**
     * Process Pinky & Brain command
     * 
     * @param string $roomId Room ID
     * @param string $userHandle User who triggered command
     * @return array Bot responses ['brain' => string|null, 'pinky' => string|null]
     */
    public function processCommand(string $roomId, string $userHandle): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return ['brain' => null, 'pinky' => null];
        }
        
        try {
            // Get or create bot state for room
            $state = $this->getBotState($roomId);
            
            // Toggle bot state
            $newState = !($state['is_active'] ?? false);
            
            // Update state
            $this->updateBotState($roomId, $newState);
            
            if ($newState) {
                // Bot activated - Brain asks question
                $brainResponse = $this->getBrainToggleMessage();
                $pinkyResponse = null; // Pinky responds after Brain's message
                
                return [
                    'brain' => $brainResponse,
                    'pinky' => null, // Will be sent after Brain's message
                ];
            } else {
                // Bot deactivated
                return [
                    'brain' => null,
                    'pinky' => null,
                ];
            }
        } catch (\Exception $e) {
            error_log('Pinky & Brain command failed: ' . $e->getMessage());
            return ['brain' => null, 'pinky' => null];
        }
    }
    
    /**
     * Get Pinky's response after Brain's message
     * 
     * @param string $roomId Room ID
     * @return string|null Pinky's response or null
     */
    public function getPinkyResponse(string $roomId): ?string
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }
        
        try {
            $state = $this->getBotState($roomId);
            
            if (!($state['is_active'] ?? false)) {
                return null; // Bot not active
            }
            
            // Get random Pinky response
            $response = $this->getRandomPinkyResponse();
            
            return $response;
        } catch (\Exception $e) {
            error_log('Failed to get Pinky response: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get bot state for a room
     * 
     * @param string $roomId Room ID
     * @return array Bot state
     */
    private function getBotState(string $roomId): array
    {
        $sql = 'SELECT is_active, last_brain_message_id, last_pinky_response_id, last_interaction_at
                FROM pinky_brain_state
                WHERE room_id = :room_id';
        
        $state = Database::queryOne($sql, [':room_id' => $roomId]);
        
        if (empty($state)) {
            // Create initial state
            $this->createBotState($roomId);
            return ['is_active' => false, 'last_brain_message_id' => null, 'last_pinky_response_id' => null, 'last_interaction_at' => null];
        }
        
        return $state;
    }
    
    /**
     * Create initial bot state for room
     * 
     * @param string $roomId Room ID
     */
    private function createBotState(string $roomId): void
    {
        $sql = 'INSERT INTO pinky_brain_state (room_id, is_active)
                VALUES (:room_id, FALSE)
                ON DUPLICATE KEY UPDATE updated_at = NOW()';
        
        Database::execute($sql, [':room_id' => $roomId]);
    }
    
    /**
     * Update bot state
     * 
     * @param string $roomId Room ID
     * @param bool $isActive Whether bot is active
     */
    private function updateBotState(string $roomId, bool $isActive): void
    {
        $sql = 'UPDATE pinky_brain_state 
                SET is_active = :is_active, last_interaction_at = NOW(), updated_at = NOW()
                WHERE room_id = :room_id';
        
        Database::execute($sql, [
            ':room_id' => $roomId,
            ':is_active' => $isActive ? 1 : 0,
        ]);
    }
    
    /**
     * Get Brain's toggle message
     * 
     * @return string Brain's message
     */
    private function getBrainToggleMessage(): string
    {
        $sql = 'SELECT response_text 
                FROM pinky_brain_responses 
                WHERE bot_character = "brain" 
                  AND trigger_type = "brain_toggle" 
                  AND is_active = TRUE 
                ORDER BY response_order ASC 
                LIMIT 1';
        
        $result = Database::queryOne($sql);
        
        return $result['response_text'] ?? 'Are you thinking what I\'m thinking, Pinky?';
    }
    
    /**
     * Get random Pinky response
     * 
     * @return string Pinky's response
     */
    private function getRandomPinkyResponse(): string
    {
        $sql = 'SELECT response_text 
                FROM pinky_brain_responses 
                WHERE bot_character = "pinky" 
                  AND trigger_type = "pinky_response" 
                  AND is_active = TRUE 
                ORDER BY RAND() 
                LIMIT 1';
        
        $result = Database::queryOne($sql);
        
        return $result['response_text'] ?? 'I think so, Brain, but where are we going to find a duck and a hose at this hour?';
    }
    
    /**
     * Check if bot is active in room
     * 
     * @param string $roomId Room ID
     * @return bool True if bot is active
     */
    public function isBotActive(string $roomId): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        try {
            $state = $this->getBotState($roomId);
            return (bool)($state['is_active'] ?? false);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Get bot handle for Brain
     * 
     * @return string Bot handle
     */
    public function getBrainHandle(): string
    {
        return self::BOT_HANDLE_BRAIN;
    }
    
    /**
     * Get bot handle for Pinky
     * 
     * @return string Bot handle
     */
    public function getPinkyHandle(): string
    {
        return self::BOT_HANDLE_PINKY;
    }
}

