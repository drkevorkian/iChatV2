<?php
/**
 * Sentinel Chat Platform - End-to-End Encryption Service
 * 
 * Provides server-side support for E2EE using libsodium.
 * Handles key validation, key exchange management, and encryption metadata.
 * 
 * Security: This service does NOT decrypt messages - all encryption/decryption
 * happens client-side. The server only validates keys and manages key exchanges.
 * This ensures true E2EE where the server cannot read message content.
 * 
 * Note: Requires libsodium PHP extension (sodium extension).
 */

declare(strict_types=1);

namespace iChat\Services;

use iChat\Repositories\KeyRepository;
use iChat\Services\SecurityService;

class E2EEService
{
    private KeyRepository $keyRepo;
    private SecurityService $security;

    public function __construct()
    {
        $this->keyRepo = new KeyRepository();
        $this->security = new SecurityService();
    }

    /**
     * Check if libsodium is available
     * 
     * @return bool True if libsodium extension is loaded
     */
    public function isLibsodiumAvailable(): bool
    {
        return extension_loaded('sodium');
    }

    /**
     * Validate a public key format
     * 
     * @param string $publicKey Base64-encoded public key
     * @return bool True if key format is valid
     */
    public function validatePublicKey(string $publicKey): bool
    {
        if (empty($publicKey)) {
            return false;
        }

        // Decode base64
        $decoded = base64_decode($publicKey, true);
        if ($decoded === false) {
            return false;
        }

        // libsodium box public key is 32 bytes
        if (strlen($decoded) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            return false;
        }

        return true;
    }

    /**
     * Validate a nonce format
     * 
     * @param string $nonce Base64-encoded nonce
     * @return bool True if nonce format is valid
     */
    public function validateNonce(string $nonce): bool
    {
        if (empty($nonce)) {
            return false;
        }

        // Decode base64
        $decoded = base64_decode($nonce, true);
        if ($decoded === false) {
            return false;
        }

        // libsodium box nonce is 24 bytes
        if (strlen($decoded) !== SODIUM_CRYPTO_BOX_NONCEBYTES) {
            return false;
        }

        return true;
    }

    /**
     * Register or update a user's public key
     * 
     * @param int $userId User ID
     * @param string $userHandle User handle
     * @param string $publicKey Base64-encoded public key
     * @return bool True on success
     */
    public function registerPublicKey(int $userId, string $userHandle, string $publicKey): bool
    {
        if (!$this->validatePublicKey($publicKey)) {
            return false;
        }

        return $this->keyRepo->savePublicKey($userId, $userHandle, $publicKey);
    }

    /**
     * Get a user's public key
     * 
     * @param string $userHandle User handle
     * @return string|null Public key or null if not found
     */
    public function getPublicKey(string $userHandle): ?string
    {
        return $this->keyRepo->getPublicKey($userHandle);
    }

    /**
     * Request a key exchange with another user
     * 
     * @param int $fromUserId Requesting user ID
     * @param string $fromUserHandle Requesting user handle
     * @param int $toUserId Target user ID
     * @param string $toUserHandle Target user handle
     * @param string $publicKey Requesting user's public key
     * @return array Result with exchange_id or error
     */
    public function requestKeyExchange(
        int $fromUserId,
        string $fromUserHandle,
        int $toUserId,
        string $toUserHandle,
        string $publicKey
    ): array {
        if (!$this->validatePublicKey($publicKey)) {
            return [
                'success' => false,
                'error' => 'Invalid public key format',
            ];
        }

        // Check if target user already has a public key
        $targetKey = $this->getPublicKey($toUserHandle);
        if ($targetKey !== null) {
            // User already has a key, auto-accept and return their key
            return [
                'success' => true,
                'exchange_id' => null,
                'target_public_key' => $targetKey,
                'status' => 'accepted',
            ];
        }

        // Create key exchange request
        $exchangeId = $this->keyRepo->createKeyExchange(
            $fromUserId,
            $fromUserHandle,
            $toUserId,
            $toUserHandle,
            $publicKey
        );

        if ($exchangeId === false) {
            return [
                'success' => false,
                'error' => 'Failed to create key exchange request',
            ];
        }

        return [
            'success' => true,
            'exchange_id' => $exchangeId,
            'status' => 'pending',
        ];
    }

    /**
     * Accept a key exchange request
     * 
     * @param int $exchangeId Exchange request ID
     * @param int $userId User accepting the request
     * @param string $publicKey User's public key
     * @return bool True on success
     */
    public function acceptKeyExchange(int $exchangeId, int $userId, string $publicKey): bool
    {
        if (!$this->validatePublicKey($publicKey)) {
            return false;
        }

        $exchange = $this->keyRepo->getKeyExchange($exchangeId);
        if ($exchange === null) {
            return false;
        }

        // Verify the user is the target of this exchange
        if ($exchange['to_user_id'] != $userId) {
            return false;
        }

        // Register the accepting user's public key
        $this->registerPublicKey($userId, $exchange['to_user_handle'], $publicKey);

        // Mark exchange as accepted
        return $this->keyRepo->acceptKeyExchange($exchangeId);
    }

    /**
     * Get pending key exchange requests for a user
     * 
     * @param int $userId User ID
     * @return array Array of pending exchanges
     */
    public function getPendingExchanges(int $userId): array
    {
        return $this->keyRepo->getPendingExchanges($userId);
    }

    /**
     * Clean up expired key exchanges
     * 
     * @return int Number of exchanges cleaned up
     */
    public function cleanupExpiredExchanges(): int
    {
        return $this->keyRepo->cleanupExpiredExchanges();
    }
}

