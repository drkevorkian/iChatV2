<?php
/**
 * Sentinel Chat Platform - Configuration Manager
 * 
 * Centralized configuration management with environment variable support.
 * All configuration values are loaded from environment variables with
 * sensible defaults for development.
 * 
 * Security: Never hardcode secrets. All sensitive values must come from
 * environment variables or secure configuration files.
 */

declare(strict_types=1);

namespace iChat;

class Config
{
    private static ?self $instance = null;
    private array $config = [];

    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct()
    {
        $this->loadConfig();
    }

    /**
     * Initialize and return singleton instance
     * 
     * @return self
     */
    public static function init(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get singleton instance (must call init() first)
     * 
     * @return self
     * @throws \RuntimeException if not initialized
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Config not initialized. Call Config::init() first.');
        }
        return self::$instance;
    }

    /**
     * Load configuration from environment variables
     * 
     * Reads environment variables and sets defaults for development.
     * Production should always set these via environment or secure config.
     */
    private function loadConfig(): void
    {
        $this->config = [
            // Application settings
            'app.env' => getenv('APP_ENV') ?: 'development',
            'app.debug' => filter_var(getenv('APP_DEBUG') ?: 'true', FILTER_VALIDATE_BOOLEAN),
            'app.base_url' => getenv('APP_BASE_URL') ?: 'https://localhost',
            'app.api_base' => getenv('APP_API_BASE') ?: '/iChat/api',
            
            // Database configuration
            'db.host' => getenv('DB_HOST') ?: '127.0.0.1',
            'db.port' => (int)(getenv('DB_PORT') ?: '3306'),
            'db.name' => getenv('DB_NAME') ?: 'sentinel_temp',
            'db.user' => getenv('DB_USER') ?: 'root',
            'db.password' => getenv('DB_PASSWORD') ?: 'M13@ng3l123',
            'db.charset' => 'utf8mb4',
            
            // UI configuration
            'ui.default_room' => getenv('UI_DEFAULT_ROOM') ?: 'lobby',
            'ui.word_filter_enabled' => filter_var(getenv('UI_WORD_FILTER_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
            
            // API security
            'api.shared_secret' => getenv('API_SHARED_SECRET') ?: 'change-me-now',
            
            // WebSocket configuration
            'websocket.enabled' => filter_var(getenv('WEBSOCKET_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
            'websocket.host' => getenv('WEBSOCKET_HOST') ?: 'localhost',
            'websocket.port' => (int)(getenv('WEBSOCKET_PORT') ?: '8420'), // Default to 8420 (Node.js secondary server port)
            'websocket.secure' => filter_var(getenv('WEBSOCKET_SECURE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
            
            // Security settings
            'security.require_https' => filter_var(getenv('SECURITY_REQUIRE_HTTPS') ?: 'true', FILTER_VALIDATE_BOOLEAN),
            'security.session_lifetime' => (int)(getenv('SECURITY_SESSION_LIFETIME') ?: '3600'),
        ];
    }

    /**
     * Get configuration value by key
     * 
     * Supports dot notation for nested keys (e.g., 'db.host')
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set configuration value (for testing purposes)
     * 
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     */
    public function set(string $key, $value): void
    {
        $this->config[$key] = $value;
    }

    /**
     * Check if configuration key exists
     * 
     * @param string $key Configuration key
     * @return bool True if key exists
     */
    public function has(string $key): bool
    {
        return isset($this->config[$key]);
    }

    /**
     * Get all configuration as array
     * 
     * @return array All configuration values
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup(): void
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}

