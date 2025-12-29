<?php
/**
 * Sentinel Chat Platform - Security Service
 * 
 * Provides security utilities including API secret validation,
 * input sanitization, and security header management.
 * 
 * Security: This service implements multiple layers of security
 * including input validation, output encoding, and authentication.
 */

declare(strict_types=1);

namespace iChat\Services;

use iChat\Config;

class SecurityService
{
    private Config $config;

    public function __construct()
    {
        $this->config = Config::getInstance();
    }

    /**
     * Validate API secret from request headers
     * 
     * Checks if the X-API-SECRET header matches the configured secret.
     * This prevents unauthorized access to API endpoints.
     * 
     * @return bool True if secret is valid
     */
    public function validateApiSecret(): bool
    {
        $headers = $this->getAllHeaders();
        $providedSecret = $headers['X-API-SECRET'] ?? '';
        $expectedSecret = $this->config->get('api.shared_secret');
        
        // Use constant-time comparison to prevent timing attacks
        return hash_equals($expectedSecret, $providedSecret);
    }

    /**
     * Get all HTTP headers (case-insensitive)
     * 
     * @return array Associative array of headers
     */
    private function getAllHeaders(): array
    {
        $headers = [];
        
        // Get headers from $_SERVER
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[$headerName] = $value;
            }
        }
        
        // Also check getallheaders() if available
        if (function_exists('getallheaders')) {
            $allHeaders = getallheaders();
            if (is_array($allHeaders)) {
                foreach ($allHeaders as $key => $value) {
                    $headers[$key] = $value;
                }
            }
        }
        
        return $headers;
    }

    /**
     * Sanitize user input
     * 
     * Removes potentially dangerous characters and normalizes input.
     * 
     * @param string $input User input to sanitize
     * @param int $maxLength Maximum allowed length
     * @return string Sanitized input
     */
    public function sanitizeInput(string $input, int $maxLength = 1000): string
    {
        // Trim whitespace
        $input = trim($input);
        
        // Limit length
        if (mb_strlen($input) > $maxLength) {
            $input = mb_substr($input, 0, $maxLength);
        }
        
        // Remove null bytes
        $input = str_replace("\0", '', $input);
        
        return $input;
    }

    /**
     * Validate room ID format
     * 
     * Ensures room IDs match expected format (alphanumeric, dash, underscore).
     * 
     * @param string $roomId Room ID to validate
     * @return bool True if valid
     */
    public function validateRoomId(string $roomId): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]+$/', $roomId) === 1;
    }

    /**
     * Validate user handle format
     * 
     * Ensures user handles match expected format.
     * 
     * @param string $handle User handle to validate
     * @return bool True if valid
     */
    public function validateHandle(string $handle): bool
    {
        // Allow alphanumeric, dash, underscore, dot
        // Length between 1 and 50 characters
        return preg_match('/^[a-zA-Z0-9._-]{1,50}$/', $handle) === 1;
    }

    /**
     * Set security headers
     * 
     * Sets HTTP security headers to protect against common attacks.
     * Should be called before any output is sent.
     */
    public function setSecurityHeaders(): void
    {
        // Content Security Policy
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://code.jquery.com; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self';");
        
        // Prevent clickjacking
        header('X-Frame-Options: DENY');
        
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // XSS protection (legacy but still useful)
        header('X-XSS-Protection: 1; mode=block');
        
        // Strict Transport Security (if HTTPS)
        if ($this->isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Check if request is HTTPS
     * 
     * @return bool True if HTTPS
     */
    public function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    }
    
    /**
     * Get client IP address
     * 
     * Retrieves the client's IP address, considering proxy headers.
     * 
     * @return string Client IP address
     */
    public function getClientIp(): string
    {
        // Check for proxy headers first (in order of trustworthiness)
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_REAL_IP',            // Nginx proxy
            'HTTP_X_FORWARDED_FOR',      // Standard proxy header
            'HTTP_CLIENT_IP',            // Some proxies
        ];
        
        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        // Fallback to REMOTE_ADDR
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Encode output for HTML
     * 
     * Escapes special characters to prevent XSS attacks.
     * 
     * @param string $output Output to encode
     * @return string HTML-encoded output
     */
    public function encodeHtml(string $output): string
    {
        return htmlspecialchars($output, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Generate CSRF token
     * 
     * Generates a cryptographically secure token for CSRF protection.
     * 
     * @return string CSRF token
     */
    public function generateCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate CSRF token
     * 
     * @param string $token Token to validate
     * @return bool True if valid
     */
    public function validateCsrfToken(string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

