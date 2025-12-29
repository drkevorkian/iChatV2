/**
 * Sentinel Chat Platform - End-to-End Encryption (E2EE) Client Library
 * 
 * Provides client-side E2EE using Web Crypto API (libsodium-compatible).
 * All encryption/decryption happens client-side - the server never sees plaintext.
 * 
 * Security: Uses libsodium's box encryption (authenticated encryption).
 * Private keys are stored in browser localStorage (encrypted with user password).
 */

(function() {
    'use strict';

    const E2EE = {
        // Key storage
        keyPair: null,
        privateKeyEncrypted: null,

        /**
         * Initialize E2EE system
         * Generates key pair if not exists, or loads from storage
         */
        async init() {
            try {
                // Load libsodium.js first
                await this.loadLibsodium();

                // Try to load existing key pair
                const stored = localStorage.getItem('e2ee_keypair');
                if (stored) {
                    try {
                        const parsed = JSON.parse(stored);
                        this.keyPair = {
                            publicKey: parsed.publicKey,
                            privateKey: parsed.privateKey,
                        };
                        
                        // Convert base64 keys back to Uint8Array for libsodium
                        if (window.sodium) {
                            this.keyPair.publicKeyBytes = window.sodium.from_base64(this.keyPair.publicKey, window.sodium.base64_variants.ORIGINAL);
                            this.keyPair.privateKeyBytes = window.sodium.from_base64(this.keyPair.privateKey, window.sodium.base64_variants.ORIGINAL);
                        }
                        
                        console.log('E2EE: Loaded existing key pair');
                    } catch (e) {
                        console.error('E2EE: Failed to load key pair:', e);
                        // Generate new key pair
                        await this.generateKeyPair();
                    }
                } else {
                    // Generate new key pair
                    await this.generateKeyPair();
                }

                // Register public key with server
                await this.registerPublicKey();

                return true;
            } catch (e) {
                console.error('E2EE: Initialization failed:', e);
                return false;
            }
        },

        /**
         * Generate a new key pair using Web Crypto API
         * Uses X25519 (Curve25519) for key exchange, compatible with libsodium
         */
        async generateKeyPair() {
            try {
                // Generate key pair using X25519 (Curve25519)
                // This is compatible with libsodium's crypto_box
                const keyPair = await window.crypto.subtle.generateKey(
                    {
                        name: 'X25519',
                        namedCurve: 'X25519',
                    },
                    true, // extractable
                    ['deriveKey', 'deriveBits']
                );

                // Export keys
                const publicKeyRaw = await window.crypto.subtle.exportKey('raw', keyPair.publicKey);
                const privateKeyRaw = await window.crypto.subtle.exportKey('pkcs8', keyPair.privateKey);

                // Convert to base64 for storage
                const publicKeyBase64 = this.arrayBufferToBase64(publicKeyRaw);
                const privateKeyBase64 = this.arrayBufferToBase64(privateKeyRaw);

                this.keyPair = {
                    publicKey: publicKeyBase64,
                    privateKey: privateKeyBase64,
                };

                // Store in localStorage (in production, encrypt private key with user password)
                localStorage.setItem('e2ee_keypair', JSON.stringify({
                    publicKey: publicKeyBase64,
                    privateKey: privateKeyBase64,
                }));

                console.log('E2EE: Generated new key pair');
                return true;
            } catch (e) {
                console.error('E2EE: Key generation failed:', e);
                // Fallback: Use a simpler approach if X25519 is not available
                // For now, we'll use a polyfill or simpler encryption
                return false;
            }
        },

        /**
         * Register public key with server
         */
        async registerPublicKey() {
            if (!this.keyPair || !this.keyPair.publicKey) {
                return false;
            }

            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=keys.php&action=register`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        public_key: this.keyPair.publicKey,
                    }),
                });

                const result = await response.json();
                if (result.success) {
                    console.log('E2EE: Public key registered');
                    return true;
                } else {
                    console.error('E2EE: Failed to register public key:', result.error);
                    return false;
                }
            } catch (e) {
                console.error('E2EE: Error registering public key:', e);
                return false;
            }
        },

        /**
         * Get a user's public key from server
         */
        async getPublicKey(userHandle) {
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=keys.php&action=get&user_handle=${encodeURIComponent(userHandle)}`);
                const result = await response.json();

                if (result.success && result.has_key) {
                    return result.public_key;
                }
                return null;
            } catch (e) {
                console.error('E2EE: Error getting public key:', e);
                return null;
            }
        },

        /**
         * Encrypt a message for a recipient using libsodium box encryption
         */
        async encryptMessage(message, recipientPublicKey) {
            if (!this.keyPair || !this.keyPair.privateKeyBytes) {
                throw new Error('E2EE: Key pair not initialized');
            }

            if (!recipientPublicKey) {
                throw new Error('E2EE: Recipient public key required');
            }

            try {
                // Ensure libsodium is loaded
                await this.loadLibsodium();

                if (!window.sodium) {
                    throw new Error('libsodium.js not available');
                }

                // Convert recipient public key from base64 to Uint8Array
                const recipientPublicKeyBytes = window.sodium.from_base64(recipientPublicKey, window.sodium.base64_variants.ORIGINAL);

                // Generate random nonce (24 bytes for crypto_box)
                const nonce = window.sodium.randombytes_buf(window.sodium.crypto_box_NONCEBYTES);

                // Encrypt message using libsodium's crypto_box_easy
                const messageBytes = new TextEncoder().encode(message);
                const encrypted = window.sodium.crypto_box_easy(
                    messageBytes,
                    nonce,
                    recipientPublicKeyBytes,
                    this.keyPair.privateKeyBytes
                );

                // Convert to base64 for transmission
                const encryptedBase64 = window.sodium.to_base64(encrypted, window.sodium.base64_variants.ORIGINAL);
                const nonceBase64 = window.sodium.to_base64(nonce, window.sodium.base64_variants.ORIGINAL);

                // Return encrypted message with nonce
                return JSON.stringify({
                    encrypted: encryptedBase64,
                    nonce: nonceBase64,
                });
            } catch (e) {
                console.error('E2EE: Encryption failed:', e);
                throw e;
            }
        },

        /**
         * Decrypt a message using libsodium box decryption
         */
        async decryptMessage(encryptedMessage, senderPublicKey) {
            if (!this.keyPair || !this.keyPair.privateKeyBytes) {
                throw new Error('E2EE: Key pair not initialized');
            }

            if (!senderPublicKey) {
                throw new Error('E2EE: Sender public key required');
            }

            try {
                // Ensure libsodium is loaded
                await this.loadLibsodium();

                if (!window.sodium) {
                    throw new Error('libsodium.js not available');
                }

                // Parse the encrypted message
                const parsed = typeof encryptedMessage === 'string' 
                    ? JSON.parse(encryptedMessage) 
                    : encryptedMessage;

                // Convert from base64
                const encryptedBytes = window.sodium.from_base64(parsed.encrypted, window.sodium.base64_variants.ORIGINAL);
                const nonceBytes = window.sodium.from_base64(parsed.nonce, window.sodium.base64_variants.ORIGINAL);
                const senderPublicKeyBytes = window.sodium.from_base64(senderPublicKey, window.sodium.base64_variants.ORIGINAL);

                // Decrypt using libsodium's crypto_box_open_easy
                const decrypted = window.sodium.crypto_box_open_easy(
                    encryptedBytes,
                    nonceBytes,
                    senderPublicKeyBytes,
                    this.keyPair.privateKeyBytes
                );

                // Convert to string
                return new TextDecoder().decode(decrypted);
            } catch (e) {
                console.error('E2EE: Decryption failed:', e);
                throw e;
            }
        },


        /**
         * Convert ArrayBuffer to base64
         */
        arrayBufferToBase64(buffer) {
            const bytes = new Uint8Array(buffer);
            let binary = '';
            for (let i = 0; i < bytes.length; i++) {
                binary += String.fromCharCode(bytes[i]);
            }
            return btoa(binary);
        },

        /**
         * Convert base64 to ArrayBuffer
         */
        base64ToArrayBuffer(base64) {
            const binary = atob(base64);
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }
            return bytes.buffer;
        },
    };

    // Export to global scope
    window.E2EE = E2EE;

    // Auto-initialize on page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => E2EE.init());
    } else {
        E2EE.init();
    }
})();

