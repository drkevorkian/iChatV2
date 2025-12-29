<?php
/**
 * Sentinel Chat Platform - Patch Repository
 * 
 * Handles database operations for tracking applied patches.
 * 
 * Security: All queries use prepared statements.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;
use iChat\Services\FileStorage;

class PatchRepository
{
    private FileStorage $fileStorage;

    public function __construct()
    {
        $this->fileStorage = new FileStorage();
    }

    /**
     * Check if patch has been applied
     * 
     * @param string $patchId Patch identifier
     * @return bool True if applied
     */
    public function isPatchApplied(string $patchId): bool
    {
        if (DatabaseHealth::isAvailable()) {
            try {
                $sql = 'SELECT COUNT(*) as count FROM patch_history WHERE patch_id = :patch_id';
                $result = Database::queryOne($sql, [':patch_id' => $patchId]);
                return ($result['count'] ?? 0) > 0;
            } catch (\Exception $e) {
                // Table might not exist yet
                error_log('Patch check failed: ' . $e->getMessage());
            }
        }
        
        // Fallback: check file storage
        $patches = $this->fileStorage->getQueuedMessages('patch', false);
        foreach ($patches as $patch) {
            if (($patch['patch_id'] ?? '') === $patchId && ($patch['status'] ?? '') === 'applied') {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Record patch as applied
     * 
     * @param string $patchId Patch identifier
     * @param array $patchInfo Patch information
     * @param float $duration Execution duration
     */
    public function recordApplied(string $patchId, array $patchInfo, float $duration): void
    {
        if (DatabaseHealth::isAvailable()) {
            try {
                $sql = 'INSERT INTO patch_history 
                        (patch_id, version, description, applied_at, duration, patch_info)
                        VALUES (:patch_id, :version, :description, NOW(), :duration, :patch_info)';
                
                Database::execute($sql, [
                    ':patch_id' => $patchId,
                    ':version' => $patchInfo['version'] ?? '1.0.0',
                    ':description' => $patchInfo['description'] ?? '',
                    ':duration' => $duration,
                    ':patch_info' => json_encode($patchInfo),
                ]);
                
                return;
            } catch (\Exception $e) {
                error_log('Patch record failed: ' . $e->getMessage());
                DatabaseHealth::checkFresh();
            }
        }
        
        // Fallback to file storage
        $data = [
            'patch_id' => $patchId,
            'version' => $patchInfo['version'] ?? '1.0.0',
            'description' => $patchInfo['description'] ?? '',
            'status' => 'applied',
            'applied_at' => date('Y-m-d H:i:s'),
            'duration' => $duration,
            'patch_info' => $patchInfo,
        ];
        
        $this->fileStorage->queueMessage('patch', $data);
    }

    /**
     * Get applied date for a patch
     * 
     * @param string $patchId Patch identifier
     * @return string|null Applied date or null
     */
    public function getAppliedDate(string $patchId): ?string
    {
        if (DatabaseHealth::isAvailable()) {
            try {
                $sql = 'SELECT applied_at FROM patch_history WHERE patch_id = :patch_id ORDER BY applied_at DESC LIMIT 1';
                $result = Database::queryOne($sql, [':patch_id' => $patchId]);
                return $result['applied_at'] ?? null;
            } catch (\Exception $e) {
                error_log('Get applied date failed: ' . $e->getMessage());
            }
        }
        
        return null;
    }

    /**
     * Get all applied patches
     * 
     * @return array Array of applied patch information
     */
    public function getAllAppliedPatches(): array
    {
        $patches = [];
        
        if (DatabaseHealth::isAvailable()) {
            try {
                $sql = 'SELECT patch_id, version, description, applied_at, duration, patch_info
                        FROM patch_history
                        ORDER BY applied_at DESC';
                
                $dbPatches = Database::query($sql);
                foreach ($dbPatches as $patch) {
                    $patches[] = [
                        'patch_id' => $patch['patch_id'],
                        'version' => $patch['version'],
                        'description' => $patch['description'],
                        'applied_at' => $patch['applied_at'],
                        'duration' => $patch['duration'],
                        'patch_info' => json_decode($patch['patch_info'] ?? '{}', true),
                    ];
                }
            } catch (\Exception $e) {
                error_log('Get applied patches failed: ' . $e->getMessage());
            }
        }
        
        return $patches;
    }

    /**
     * Remove patch from patch_history (for test patches)
     * 
     * @param string $patchId Patch identifier
     * @return bool True on success
     */
    public function removePatch(string $patchId): bool
    {
        if (DatabaseHealth::isAvailable()) {
            try {
                $sql = 'DELETE FROM patch_history WHERE patch_id = :patch_id';
                Database::execute($sql, [':patch_id' => $patchId]);
                return true;
            } catch (\Exception $e) {
                error_log('Remove patch failed: ' . $e->getMessage());
                return false;
            }
        }
        
        return false;
    }
}

