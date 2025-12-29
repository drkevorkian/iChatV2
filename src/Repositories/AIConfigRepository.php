<?php
/**
 * Sentinel Chat Platform - AI Config Repository
 * 
 * Handles database operations for AI system configurations.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;

class AIConfigRepository
{
    /**
     * Get configuration for an AI system
     * 
     * @param string $systemName System name
     * @return array|null Configuration or null
     */
    public function getConfig(string $systemName): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }

        try {
            $sql = 'SELECT * FROM ai_systems_config WHERE system_name = :system_name';
            return Database::queryOne($sql, [':system_name' => $systemName]);
        } catch (\Exception $e) {
            error_log('AIConfigRepository: Failed to get config: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all AI system configurations
     * 
     * @return array Array of configurations
     */
    public function getAllConfigs(): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }

        try {
            $sql = 'SELECT * FROM ai_systems_config ORDER BY system_name';
            return Database::query($sql);
        } catch (\Exception $e) {
            error_log('AIConfigRepository: Failed to get all configs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Update AI system configuration
     * 
     * @param string $systemName System name
     * @param array $config Configuration data
     * @return bool True on success
     */
    public function updateConfig(string $systemName, array $config): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            // Build SQL dynamically to handle optional api_key update
            $updates = [
                'enabled = :enabled',
                'provider = :provider',
                'model_name = :model_name',
                'config_json = :config_json',
                'updated_at = NOW()',
            ];
            
            $params = [
                ':system_name' => $systemName,
                ':enabled' => !empty($config['enabled']) ? 1 : 0,
                ':provider' => $config['provider'] ?? null,
                ':model_name' => $config['model_name'] ?? null,
                ':config_json' => !empty($config['config_json']) ? json_encode($config['config_json']) : null,
            ];
            
            // Only update API key if provided (to avoid overwriting with null)
            if (isset($config['api_key']) && $config['api_key'] !== null) {
                $updates[] = 'api_key_encrypted = :api_key_encrypted';
                // TODO: Add encryption here - for now storing as plain text (NOT SECURE - FIX LATER)
                $params[':api_key_encrypted'] = $config['api_key'];
            }
            
            $sql = 'UPDATE ai_systems_config 
                    SET ' . implode(', ', $updates) . '
                    WHERE system_name = :system_name';
            
            return Database::execute($sql, $params) > 0;
        } catch (\Exception $e) {
            error_log('AIConfigRepository: Failed to update config: ' . $e->getMessage());
            return false;
        }
    }
}

