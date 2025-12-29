<?php
/**
 * Sentinel Chat Platform - ASCII Art Service
 * 
 * Handles ASCII art in messages - detects, validates, and formats.
 * Prevents abuse by limiting size and checking patterns.
 * 
 * Security: Validates ASCII art to prevent XSS and abuse.
 */

declare(strict_types=1);

namespace iChat\Services;

class AsciiArtService
{
    private const MAX_ASCII_LINES = 20;
    private const MAX_ASCII_WIDTH = 100;
    private const MIN_ASCII_LINES = 3;
    
    /**
     * Detect if message contains ASCII art
     * 
     * @param string $message Message to check
     * @return bool True if ASCII art detected
     */
    public function containsAsciiArt(string $message): bool
    {
        $lines = explode("\n", $message);
        
        // Check if message has multiple lines (potential ASCII art)
        if (count($lines) < $this::MIN_ASCII_LINES) {
            return false;
        }
        
        // Check for ASCII art patterns (multiple lines with spacing/characters)
        $asciiLineCount = 0;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            // ASCII art typically has spaces, special chars, or repeated patterns
            if (strlen($trimmed) > 5 && preg_match('/[^\w\s]/', $trimmed)) {
                $asciiLineCount++;
            }
        }
        
        // If more than MIN_ASCII_LINES lines match pattern, likely ASCII art
        return $asciiLineCount >= $this::MIN_ASCII_LINES;
    }
    
    /**
     * Format ASCII art for display (preserve spacing, use monospace font)
     * 
     * @param string $message Message containing ASCII art
     * @return string Formatted message with ASCII art wrapped
     */
    public function formatAsciiArt(string $message): string
    {
        if (!$this->containsAsciiArt($message)) {
            return $message;
        }
        
        $lines = explode("\n", $message);
        
        // Limit size to prevent abuse
        if (count($lines) > $this::MAX_ASCII_LINES) {
            $lines = array_slice($lines, 0, $this::MAX_ASCII_LINES);
            $message = implode("\n", $lines) . "\n[... truncated ...]";
        }
        
        // Wrap in pre tag for monospace display
        return '<pre class="ascii-art">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    
    /**
     * Validate ASCII art (check size, content)
     * 
     * @param string $message Message to validate
     * @return array Validation result ['valid' => bool, 'error' => string|null]
     */
    public function validateAsciiArt(string $message): array
    {
        $lines = explode("\n", $message);
        
        // Check line count
        if (count($lines) > $this::MAX_ASCII_LINES) {
            return [
                'valid' => false,
                'error' => 'ASCII art exceeds maximum ' . $this::MAX_ASCII_LINES . ' lines',
            ];
        }
        
        // Check line width
        foreach ($lines as $line) {
            if (strlen($line) > $this::MAX_ASCII_WIDTH) {
                return [
                    'valid' => false,
                    'error' => 'ASCII art line exceeds maximum ' . $this::MAX_ASCII_WIDTH . ' characters',
                ];
            }
        }
        
        // Check total size
        if (strlen($message) > 5000) {
            return [
                'valid' => false,
                'error' => 'ASCII art exceeds maximum size limit',
            ];
        }
        
        return ['valid' => true, 'error' => null];
    }
}

