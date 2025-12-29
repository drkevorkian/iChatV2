<?php
/**
 * Sentinel Chat Platform - Word Filter Service
 * 
 * Filters profanity and inappropriate words from messages.
 * Uses database-stored patterns for flexibility.
 * 
 * Security: All filtering happens server-side to prevent bypassing.
 */

declare(strict_types=1);

namespace iChat\Services;

use iChat\Database;
use iChat\Services\DatabaseHealth;

class WordFilterService
{
    private array $filterCache = [];
    private bool $cacheLoaded = false;
    
    /**
     * Filter a message for inappropriate words
     * 
     * @param string $message Original message
     * @param string|null $userHandle User handle to check if filtering is enabled for them
     * @return array Filtered message and flag status ['filtered' => string, 'flagged' => bool, 'flagged_words' => array]
     */
    public function filterMessage(string $message, ?string $userHandle = null): array
    {
        // Check if user has word filtering enabled
        if ($userHandle !== null && !$this->isWordFilterEnabled($userHandle)) {
            return [
                'filtered' => $message,
                'flagged' => false,
                'flagged_words' => [],
            ];
        }
        
        $filtered = $message;
        $flagged = false;
        $flaggedWords = [];
        
        // Load filters from database
        $filters = $this->getActiveFilters();
        
        foreach ($filters as $filter) {
            $pattern = $filter['word_pattern'];
            $replacement = $filter['replacement'] ?? '*';
            $isRegex = (bool)($filter['is_regex'] ?? false);
            $exceptions = isset($filter['exceptions']) ? json_decode($filter['exceptions'], true) : null;
            
            // Check exceptions - if message matches an exception pattern, skip this filter
            if ($exceptions && is_array($exceptions)) {
                $skipFilter = false;
                foreach ($exceptions as $exception) {
                    // Convert wildcard pattern (*) to regex
                    $exceptionPattern = str_replace(['*', '?'], ['.*', '.'], preg_quote($exception, '/'));
                    if (preg_match('/' . $exceptionPattern . '/i', $filtered)) {
                        $skipFilter = true;
                        break;
                    }
                }
                if ($skipFilter) {
                    continue; // Skip this filter due to exception match
                }
            }
            
            if ($isRegex) {
                // Use regex pattern - patterns may contain | for alternation
                try {
                    // Patterns from profanity list use | for alternation but aren't full regex
                    // Wrap in word boundaries and case-insensitive flag
                    $regexPattern = '/\b(?:' . $pattern . ')\b/i';
                    
                    if (preg_match($regexPattern, $filtered)) {
                        $filtered = preg_replace($regexPattern, $replacement, $filtered);
                        $flagged = true;
                        $flaggedWords[] = $pattern;
                    }
                } catch (\Exception $e) {
                    // Invalid regex - skip this filter
                    error_log('Invalid regex pattern in word filter: ' . $pattern . ' - ' . $e->getMessage());
                }
            } else {
                // Simple word match (case-insensitive, whole word)
                $wordBoundaryPattern = '/\b' . preg_quote($pattern, '/') . '\b/i';
                if (preg_match($wordBoundaryPattern, $filtered)) {
                    $filtered = preg_replace($wordBoundaryPattern, $replacement, $filtered);
                    $flagged = true;
                    $flaggedWords[] = $pattern;
                }
            }
        }
        
        return [
            'filtered' => $filtered,
            'flagged' => $flagged,
            'flagged_words' => $flaggedWords,
        ];
    }
    
    /**
     * Check if word filtering is enabled for a user
     * 
     * @param string $userHandle User handle
     * @return bool True if filtering is enabled
     */
    private function isWordFilterEnabled(string $userHandle): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return true; // Default to enabled if DB unavailable
        }
        
        try {
            $sql = 'SELECT word_filter_enabled FROM user_settings WHERE user_handle = :user_handle';
            $result = Database::queryOne($sql, [':user_handle' => $userHandle]);
            
            // Default to true if no settings found
            return $result ? (bool)($result['word_filter_enabled'] ?? true) : true;
        } catch (\Exception $e) {
            error_log('Failed to check word filter setting: ' . $e->getMessage());
            return true; // Default to enabled on error
        }
    }
    
    /**
     * Get active word filters from database
     * 
     * @return array Array of filter patterns
     */
    private function getActiveFilters(): array
    {
        if ($this->cacheLoaded) {
            return $this->filterCache;
        }
        
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }
        
        try {
            $sql = 'SELECT word_pattern, replacement, is_regex, severity, exceptions 
                    FROM word_filter 
                    WHERE is_active = TRUE 
                    ORDER BY severity DESC, id ASC';
            
            $this->filterCache = Database::query($sql);
            $this->cacheLoaded = true;
            
            return $this->filterCache;
        } catch (\Exception $e) {
            error_log('Failed to load word filters: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clear filter cache (useful after adding/removing filters)
     */
    public function clearCache(): void
    {
        $this->filterCache = [];
        $this->cacheLoaded = false;
    }
}

