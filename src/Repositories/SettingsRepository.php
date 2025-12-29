<?php
/**
 * Sentinel Chat Platform - Settings Repository
 * 
 * Handles all database operations for user settings and preferences.
 * 
 * Security: All queries use prepared statements. User input is validated
 * and sanitized before database operations.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;

class SettingsRepository
{
    /**
     * Get user settings
     * 
     * @param string $userHandle User handle
     * @return array User settings with defaults
     */
    public function getSettings(string $userHandle): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return $this->getDefaultSettings();
        }
        
        try {
            $sql = 'SELECT * FROM user_settings WHERE user_handle = :user_handle';
            $settings = Database::queryOne($sql, [':user_handle' => $userHandle]);
            
            if ($settings) {
                return $settings;
            }
            
            // Create default settings if none exist
            return $this->createDefaultSettings($userHandle);
        } catch (\Exception $e) {
            error_log('SettingsRepository::getSettings error: ' . $e->getMessage());
            return $this->getDefaultSettings();
        }
    }
    
    /**
     * Update user settings
     * 
     * @param string $userHandle User handle
     * @param int|null $userId User ID if registered
     * @param array $settings Settings to update
     * @return bool Success
     */
    public function updateSettings(string $userHandle, ?int $userId, array $settings): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }
        
        // Validate and sanitize settings
        $allowedSettings = [
            'chat_text_color',
            'chat_name_color',
            'font_size',
            'show_timestamps',
            'sound_notifications',
            'desktop_notifications',
            'auto_scroll',
            'compact_mode',
            'word_filter_enabled',
            'theme',
            'custom_theme_colors',
            'language',
            'timezone'
        ];
        
        $updateFields = [];
        $updateParams = [':user_handle' => $userHandle];
        
        foreach ($settings as $key => $value) {
            if (!in_array($key, $allowedSettings, true)) {
                continue; // Skip invalid settings
            }
            
            // Validate color format
            if (strpos($key, '_color') !== false) {
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                    continue; // Skip invalid color
                }
            }
            
            // Handle custom_theme_colors as JSON
            if ($key === 'custom_theme_colors' && is_array($value)) {
                $value = json_encode($value);
            }
            
            // Validate boolean values
            if (in_array($key, ['show_timestamps', 'sound_notifications', 'desktop_notifications', 'auto_scroll', 'compact_mode', 'word_filter_enabled'], true)) {
                $value = $value ? 1 : 0;
            }
            
            $updateFields[] = "{$key} = :{$key}";
            $updateParams[":{$key}"] = $value;
        }
        
        if (empty($updateFields)) {
            return false;
        }
        
        try {
            // Check if settings exist
            $existing = Database::queryOne(
                'SELECT id FROM user_settings WHERE user_handle = :user_handle',
                [':user_handle' => $userHandle]
            );
            
            if ($existing) {
                // Update existing
                $sql = 'UPDATE user_settings SET ' . implode(', ', $updateFields) . ', updated_at = NOW() WHERE user_handle = :user_handle';
                Database::execute($sql, $updateParams);
            } else {
                // Create new - build INSERT statement properly
                $allFields = ['user_handle', 'user_id'];
                $allPlaceholders = [':user_handle', ':user_id'];
                $insertParams = [':user_handle' => $userHandle, ':user_id' => $userId];
                
                foreach ($updateFields as $field) {
                    // Extract field name and placeholder from "field_name = :field_name"
                    $parts = explode(' = ', $field);
                    $fieldName = $parts[0];
                    $placeholder = $parts[1];
                    
                    $allFields[] = $fieldName;
                    $allPlaceholders[] = $placeholder;
                    $insertParams[$placeholder] = $updateParams[$placeholder];
                }
                
                $sql = 'INSERT INTO user_settings (' . implode(', ', $allFields) . ') VALUES (' . implode(', ', $allPlaceholders) . ')';
                Database::execute($sql, $insertParams);
            }
            
            return true;
        } catch (\Exception $e) {
            error_log('SettingsRepository::updateSettings error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create default settings for a user
     * 
     * @param string $userHandle User handle
     * @param int|null $userId User ID if registered
     * @return array Default settings
     */
    private function createDefaultSettings(string $userHandle, ?int $userId = null): array
    {
        $defaults = $this->getDefaultSettings();
        $defaults['user_handle'] = $userHandle;
        $defaults['user_id'] = $userId;
        
        try {
            $sql = 'INSERT INTO user_settings (user_handle, user_id, chat_text_color, chat_name_color, font_size, show_timestamps, sound_notifications, desktop_notifications, auto_scroll, compact_mode, theme, custom_theme_colors, language, timezone)
                    VALUES (:user_handle, :user_id, :chat_text_color, :chat_name_color, :font_size, :show_timestamps, :sound_notifications, :desktop_notifications, :auto_scroll, :compact_mode, :theme, :custom_theme_colors, :language, :timezone)';
            
            Database::execute($sql, [
                ':user_handle' => $userHandle,
                ':user_id' => $userId,
                ':chat_text_color' => $defaults['chat_text_color'],
                ':chat_name_color' => $defaults['chat_name_color'],
                ':font_size' => $defaults['font_size'],
                ':show_timestamps' => $defaults['show_timestamps'] ? 1 : 0,
                ':sound_notifications' => $defaults['sound_notifications'] ? 1 : 0,
                ':desktop_notifications' => $defaults['desktop_notifications'] ? 1 : 0,
                ':auto_scroll' => $defaults['auto_scroll'] ? 1 : 0,
                ':compact_mode' => $defaults['compact_mode'] ? 1 : 0,
                ':theme' => $defaults['theme'],
                ':custom_theme_colors' => $defaults['custom_theme_colors'],
                ':language' => $defaults['language'],
                ':timezone' => $defaults['timezone'],
            ]);
            
            return $this->getSettings($userHandle);
        } catch (\Exception $e) {
            error_log('SettingsRepository::createDefaultSettings error: ' . $e->getMessage());
            return $defaults;
        }
    }
    
    /**
     * Get default settings
     * 
     * @return array Default settings values
     */
    private function getDefaultSettings(): array
    {
        return [
            'chat_text_color' => '#000000',
            'chat_name_color' => '#0070ff',
            'font_size' => 'medium',
            'show_timestamps' => true,
            'sound_notifications' => true,
            'desktop_notifications' => false,
            'auto_scroll' => true,
            'compact_mode' => false,
            'word_filter_enabled' => true,
            'theme' => 'default',
            'custom_theme_colors' => null,
            'language' => 'en',
            'timezone' => 'UTC',
        ];
    }
}

