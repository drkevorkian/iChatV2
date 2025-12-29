<?php
/**
 * Sentinel Chat Platform - Key Repository
 * 
 * Handles database operations for E2EE keys and key exchanges.
 * 
 * Security: All queries use prepared statements. Keys are stored as-is
 * (base64-encoded) - the server never sees private keys.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;

class KeyRepository
{
    /**
     * Save or update a user's public key
     * 
     * @param int $userId User ID
     * @param string $userHandle User handle
     * @param string $publicKey Base64-encoded public key
     * @return bool True on success
     */
    public function savePublicKey(int $userId, string $userHandle, string $publicKey): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            $sql = 'INSERT INTO user_keys (user_id, user_handle, public_key, key_type, is_active)
                    VALUES (:user_id, :user_handle, :public_key, :key_type, TRUE)
                    ON DUPLICATE KEY UPDATE
                        public_key = VALUES(public_key),
                        updated_at = NOW(),
                        is_active = TRUE';

            Database::execute($sql, [
                ':user_id' => $userId,
                ':user_handle' => $userHandle,
                ':public_key' => $publicKey,
                ':key_type' => 'box',
            ]);

            return true;
        } catch (\Exception $e) {
            error_log("KeyRepository: Failed to save public key: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a user's active public key
     * 
     * @param string $userHandle User handle
     * @return string|null Public key or null if not found
     */
    public function getPublicKey(string $userHandle): ?string
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }

        try {
            $sql = 'SELECT public_key FROM user_keys
                    WHERE user_handle = :user_handle
                      AND key_type = :key_type
                      AND is_active = TRUE
                    ORDER BY updated_at DESC
                    LIMIT 1';

            $result = Database::queryOne($sql, [
                ':user_handle' => $userHandle,
                ':key_type' => 'box',
            ]);

            return $result['public_key'] ?? null;
        } catch (\Exception $e) {
            error_log("KeyRepository: Failed to get public key: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a key exchange request
     * 
     * @param int $fromUserId Requesting user ID
     * @param string $fromUserHandle Requesting user handle
     * @param int $toUserId Target user ID
     * @param string $toUserHandle Target user handle
     * @param string $publicKey Requesting user's public key
     * @return int|false Exchange ID or false on failure
     */
    public function createKeyExchange(
        int $fromUserId,
        string $fromUserHandle,
        int $toUserId,
        string $toUserHandle,
        string $publicKey
    ) {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            // Check if there's already a pending exchange
            $existing = Database::queryOne(
                'SELECT id FROM key_exchanges
                 WHERE from_user_id = :from_user_id
                   AND to_user_id = :to_user_id
                   AND status = :status
                   AND expires_at > NOW()',
                [
                    ':from_user_id' => $fromUserId,
                    ':to_user_id' => $toUserId,
                    ':status' => 'pending',
                ]
            );

            if ($existing !== null) {
                // Update existing exchange
                $sql = 'UPDATE key_exchanges
                        SET public_key = :public_key,
                            created_at = NOW(),
                            expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)
                        WHERE id = :id';

                Database::execute($sql, [
                    ':public_key' => $publicKey,
                    ':id' => $existing['id'],
                ]);

                return (int)$existing['id'];
            }

            // Create new exchange
            $sql = 'INSERT INTO key_exchanges
                    (from_user_id, from_user_handle, to_user_id, to_user_handle, public_key, expires_at)
                    VALUES (:from_user_id, :from_user_handle, :to_user_id, :to_user_handle, :public_key, DATE_ADD(NOW(), INTERVAL 24 HOUR))';

            Database::execute($sql, [
                ':from_user_id' => $fromUserId,
                ':from_user_handle' => $fromUserHandle,
                ':to_user_id' => $toUserId,
                ':to_user_handle' => $toUserHandle,
                ':public_key' => $publicKey,
            ]);

            return (int)Database::lastInsertId();
        } catch (\Exception $e) {
            error_log("KeyRepository: Failed to create key exchange: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a key exchange by ID
     * 
     * @param int $exchangeId Exchange ID
     * @return array|null Exchange data or null if not found
     */
    public function getKeyExchange(int $exchangeId): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }

        try {
            $sql = 'SELECT * FROM key_exchanges WHERE id = :id';
            return Database::queryOne($sql, [':id' => $exchangeId]);
        } catch (\Exception $e) {
            error_log("KeyRepository: Failed to get key exchange: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Accept a key exchange request
     * 
     * @param int $exchangeId Exchange ID
     * @return bool True on success
     */
    public function acceptKeyExchange(int $exchangeId): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            $sql = 'UPDATE key_exchanges
                    SET status = :status,
                        accepted_at = NOW()
                    WHERE id = :id
                      AND status = :old_status';

            return Database::execute($sql, [
                ':status' => 'accepted',
                ':id' => $exchangeId,
                ':old_status' => 'pending',
            ]) > 0;
        } catch (\Exception $e) {
            error_log("KeyRepository: Failed to accept key exchange: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get pending key exchange requests for a user
     * 
     * @param int $userId User ID
     * @return array Array of pending exchanges
     */
    public function getPendingExchanges(int $userId): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }

        try {
            $sql = 'SELECT * FROM key_exchanges
                    WHERE to_user_id = :user_id
                      AND status = :status
                      AND expires_at > NOW()
                    ORDER BY created_at DESC';

            return Database::query($sql, [
                ':user_id' => $userId,
                ':status' => 'pending',
            ]);
        } catch (\Exception $e) {
            error_log("KeyRepository: Failed to get pending exchanges: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean up expired key exchanges
     * 
     * @return int Number of exchanges cleaned up
     */
    public function cleanupExpiredExchanges(): int
    {
        if (!DatabaseHealth::isAvailable()) {
            return 0;
        }

        try {
            $sql = 'UPDATE key_exchanges
                    SET status = :status
                    WHERE status = :old_status
                      AND expires_at <= NOW()';

            return Database::execute($sql, [
                ':status' => 'expired',
                ':old_status' => 'pending',
            ]);
        } catch (\Exception $e) {
            error_log("KeyRepository: Failed to cleanup expired exchanges: " . $e->getMessage());
            return 0;
        }
    }
}

