/**
 * Sentinel Chat Platform - Key Exchange UI
 * 
 * Provides UI for requesting and accepting E2EE key exchanges.
 * Shows notifications for pending exchanges and allows users to accept/reject.
 */

(function() {
    'use strict';

    const KeyExchangeUI = {
        pendingExchanges: [],

        /**
         * Initialize key exchange UI
         */
        async init() {
            // Check for pending key exchanges on load
            await this.checkPendingExchanges();
            
            // Setup periodic check for pending exchanges
            setInterval(() => this.checkPendingExchanges(), 30000); // Check every 30 seconds
        },

        /**
         * Check for pending key exchanges
         */
        async checkPendingExchanges() {
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=keys.php&action=pending`);
                const result = await response.json();

                if (result.success && result.exchanges && result.exchanges.length > 0) {
                    this.pendingExchanges = result.exchanges;
                    this.showPendingExchanges();
                } else {
                    this.hidePendingExchanges();
                }
            } catch (e) {
                console.error('KeyExchangeUI: Failed to check pending exchanges:', e);
            }
        },

        /**
         * Show pending key exchange notifications
         */
        showPendingExchanges() {
            // Remove existing notifications
            $('.key-exchange-notification').remove();

            this.pendingExchanges.forEach((exchange) => {
                const notification = $(`
                    <div class="key-exchange-notification" data-exchange-id="${exchange.id}">
                        <div class="key-exchange-info">
                            <strong>${this.escapeHtml(exchange.from_user_handle)}</strong> wants to start an encrypted conversation.
                            <br><small>Click "Accept" to exchange encryption keys and enable E2EE.</small>
                        </div>
                        <div class="key-exchange-actions">
                            <button class="key-exchange-btn accept" data-exchange-id="${exchange.id}">Accept</button>
                            <button class="key-exchange-btn reject" data-exchange-id="${exchange.id}">Reject</button>
                        </div>
                    </div>
                `);

                // Insert at top of IM view
                const imView = $('#im-view');
                if (imView.length) {
                    imView.prepend(notification);
                } else {
                    // Fallback: append to body
                    $('body').prepend(notification);
                }

                // Setup event handlers
                notification.find('.key-exchange-btn.accept').on('click', () => {
                    this.acceptExchange(exchange.id);
                });

                notification.find('.key-exchange-btn.reject').on('click', () => {
                    this.rejectExchange(exchange.id);
                });
            });
        },

        /**
         * Hide pending exchange notifications
         */
        hidePendingExchanges() {
            $('.key-exchange-notification').fadeOut(200, function() {
                $(this).remove();
            });
        },

        /**
         * Accept a key exchange
         */
        async acceptExchange(exchangeId) {
            try {
                // Ensure E2EE is initialized
                if (!window.E2EE || !window.E2EE.keyPair) {
                    await window.E2EE.init();
                }

                const publicKey = window.E2EE.keyPair.publicKey;

                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=keys.php&action=exchange`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        exchange_action: 'accept',
                        exchange_id: exchangeId,
                        public_key: publicKey,
                    }),
                });

                const result = await response.json();

                if (result.success) {
                    // Remove notification
                    $(`.key-exchange-notification[data-exchange-id="${exchangeId}"]`).fadeOut(200, function() {
                        $(this).remove();
                    });

                    // Show success message
                    if (window.App && window.App.showAlert) {
                        window.App.showAlert('Key exchange accepted! Your conversation is now encrypted.', 'E2EE Enabled', 'success');
                    }

                    // Refresh pending exchanges
                    await this.checkPendingExchanges();
                } else {
                    throw new Error(result.error || 'Failed to accept key exchange');
                }
            } catch (e) {
                console.error('KeyExchangeUI: Failed to accept exchange:', e);
                if (window.App && window.App.showAlert) {
                    window.App.showAlert('Failed to accept key exchange: ' + e.message, 'Error', 'error');
                }
            }
        },

        /**
         * Reject a key exchange
         */
        async rejectExchange(exchangeId) {
            // For now, just remove the notification
            // In the future, we could add a reject API endpoint
            $(`.key-exchange-notification[data-exchange-id="${exchangeId}"]`).fadeOut(200, function() {
                $(this).remove();
            });
        },

        /**
         * Request key exchange with a user
         */
        async requestKeyExchange(userHandle) {
            try {
                // Ensure E2EE is initialized
                if (!window.E2EE || !window.E2EE.keyPair) {
                    await window.E2EE.init();
                }

                const publicKey = window.E2EE.keyPair.publicKey;

                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=keys.php&action=exchange`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        exchange_action: 'request',
                        to_user_handle: userHandle,
                        public_key: publicKey,
                    }),
                });

                const result = await response.json();

                if (result.success) {
                    if (result.status === 'accepted' && result.target_public_key) {
                        // Exchange was auto-accepted (user already has a key)
                        // Store their public key for future use
                        this.storePublicKey(userHandle, result.target_public_key);
                        
                        if (window.App && window.App.showAlert) {
                            window.App.showAlert('Encrypted conversation enabled!', 'E2EE Enabled', 'success');
                        }
                    } else {
                        // Exchange request sent
                        if (window.App && window.App.showAlert) {
                            window.App.showAlert('Key exchange request sent. Waiting for ' + userHandle + ' to accept...', 'Key Exchange', 'info');
                        }
                    }
                } else {
                    throw new Error(result.error || 'Failed to request key exchange');
                }
            } catch (e) {
                console.error('KeyExchangeUI: Failed to request exchange:', e);
                if (window.App && window.App.showAlert) {
                    window.App.showAlert('Failed to request key exchange: ' + e.message, 'Error', 'error');
                }
            }
        },

        /**
         * Store a user's public key locally
         */
        storePublicKey(userHandle, publicKey) {
            const keyStorage = JSON.parse(localStorage.getItem('e2ee_public_keys') || '{}');
            keyStorage[userHandle] = publicKey;
            localStorage.setItem('e2ee_public_keys', JSON.stringify(keyStorage));
        },

        /**
         * Get a user's public key from local storage
         */
        getPublicKey(userHandle) {
            const keyStorage = JSON.parse(localStorage.getItem('e2ee_public_keys') || '{}');
            return keyStorage[userHandle] || null;
        },

        /**
         * Escape HTML
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
    };

    // Export to global scope
    window.KeyExchangeUI = KeyExchangeUI;

    // Auto-initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => KeyExchangeUI.init());
    } else {
        KeyExchangeUI.init();
    }
})();

