<?php
/**
 * Sentinel Chat Platform - Smiley Service
 * 
 * Converts text emoticons (like :), :D, :P) to emojis or images.
 * Uses database-stored mappings for flexibility.
 * 
 * Security: Only converts known patterns, prevents XSS.
 */

declare(strict_types=1);

namespace iChat\Services;

use iChat\Database;
use iChat\Services\DatabaseHealth;

class SmileyService
{
    private array $smileyCache = [];
    private bool $cacheLoaded = false;
    
    /**
     * Convert smileys in a message to emojis/images
     * 
     * @param string $message Original message
     * @return string Message with smileys converted
     */
    public function convertSmileys(string $message): string
    {
        $converted = $message;
        
        // Load smiley mappings from database
        $smileys = $this->getActiveSmileys();
        
        // Sort by length (longest first) to avoid partial matches
        usort($smileys, function($a, $b) {
            return strlen($b['text_pattern']) - strlen($a['text_pattern']);
        });
        
        foreach ($smileys as $smiley) {
            $pattern = preg_quote($smiley['text_pattern'], '/');
            $replacement = '';
            
            // Prefer emoji, fallback to image URL
            if (!empty($smiley['emoji'])) {
                $replacement = $smiley['emoji'];
            } elseif (!empty($smiley['image_url'])) {
                $replacement = '<img src="' . htmlspecialchars($smiley['image_url'], ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($smiley['text_pattern'], ENT_QUOTES, 'UTF-8') . '" class="smiley-emoji" title="' . htmlspecialchars($smiley['description'] ?? '', ENT_QUOTES, 'UTF-8') . '">';
            } else {
                continue; // Skip if no replacement available
            }
            
            // Replace pattern (word boundaries for text patterns, or exact match)
            $converted = preg_replace('/\b' . $pattern . '\b/', $replacement, $converted);
        }
        
        return $converted;
    }
    
    /**
     * Get active smiley mappings from database
     * 
     * @return array Array of smiley mappings
     */
    private function getActiveSmileys(): array
    {
        if ($this->cacheLoaded) {
            return $this->smileyCache;
        }
        
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }
        
        try {
            $sql = 'SELECT text_pattern, image_url, emoji, description 
                    FROM smiley_mappings 
                    WHERE is_active = TRUE 
                    ORDER BY LENGTH(text_pattern) DESC, id ASC';
            
            $this->smileyCache = Database::query($sql);
            $this->cacheLoaded = true;
            
            return $this->smileyCache;
        } catch (\Exception $e) {
            error_log('Failed to load smiley mappings: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clear smiley cache (useful after adding/removing smileys)
     */
    public function clearCache(): void
    {
        $this->smileyCache = [];
        $this->cacheLoaded = false;
    }
}

