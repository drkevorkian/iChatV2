/**
 * Sentinel Chat Platform - Typing Indicators
 * 
 * Real-time typing indicators for IM conversations.
 * Broadcasts typing status via WebSocket and displays indicators in UI.
 */

(function() {
    'use strict';

    const TypingIndicators = {
        typingTimeouts: {},
        lastTypingTime: {},
        typingDebounce: 1000, // Stop typing indicator after 1 second of inactivity
        typingThrottle: 300, // Send typing event max once per 300ms

        /**
         * Initialize typing indicators
         */
        init() {
            // Setup typing detection for IM input
            this.setupTypingDetection();
            
            // Setup WebSocket handlers
            if (window.App && window.App.websocket) {
                this.setupWebSocketHandlers();
            }
        },

        /**
         * Setup typing detection on input fields
         */
        setupTypingDetection() {
            // IM message input
            $(document).on('input', '#im-compose-message, #im-message-input', (e) => {
                const input = e.target;
                const conversationWith = this.getCurrentConversation();
                
                if (conversationWith) {
                    this.handleTyping(conversationWith);
                }
            });

            // Stop typing when input loses focus
            $(document).on('blur', '#im-compose-message, #im-message-input', (e) => {
                const conversationWith = this.getCurrentConversation();
                if (conversationWith) {
                    this.stopTyping(conversationWith);
                }
            });
        },

        /**
         * Get current conversation partner
         */
        getCurrentConversation() {
            if (window.App && window.App.currentImConversation) {
                return window.App.currentImConversation;
            }
            
            // Try to get from UI
            const toUserInput = $('#im-to-user');
            if (toUserInput.length && toUserInput.val()) {
                return toUserInput.val();
            }
            
            return null;
        },

        /**
         * Handle typing event (throttled)
         */
        handleTyping(conversationWith) {
            const now = Date.now();
            const lastTime = this.lastTypingTime[conversationWith] || 0;
            
            // Throttle: only send if enough time has passed
            if (now - lastTime < this.typingThrottle) {
                return;
            }
            
            this.lastTypingTime[conversationWith] = now;
            
            // Send typing event via WebSocket
            this.sendTypingEvent(conversationWith, true);
            
            // Clear existing timeout
            if (this.typingTimeouts[conversationWith]) {
                clearTimeout(this.typingTimeouts[conversationWith]);
            }
            
            // Set timeout to stop typing
            this.typingTimeouts[conversationWith] = setTimeout(() => {
                this.stopTyping(conversationWith);
            }, this.typingDebounce);
        },

        /**
         * Stop typing indicator
         */
        stopTyping(conversationWith) {
            if (this.typingTimeouts[conversationWith]) {
                clearTimeout(this.typingTimeouts[conversationWith]);
                delete this.typingTimeouts[conversationWith];
            }
            
            this.sendTypingEvent(conversationWith, false);
        },

        /**
         * Send typing event via WebSocket or API
         */
        sendTypingEvent(conversationWith, isTyping) {
            // Try WebSocket first
            if (window.App && window.App.websocket && window.App.websocket.readyState === WebSocket.OPEN) {
                window.App.websocket.send(JSON.stringify({
                    type: 'typing',
                    conversation_with: conversationWith,
                    is_typing: isTyping,
                }));
            } else {
                // Fallback to API
                $.ajax({
                    url: `${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=typing.php&action=update`,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        conversation_with: conversationWith,
                        is_typing: isTyping,
                    }),
                }).fail(() => {
                    console.error('Failed to send typing indicator');
                });
            }
        },

        /**
         * Setup WebSocket handlers for typing events
         */
        setupWebSocketHandlers() {
            // This will be called when WebSocket receives a typing event
            if (window.App && window.App.handleWebSocketMessage) {
                const originalHandler = window.App.handleWebSocketMessage;
                window.App.handleWebSocketMessage = (message) => {
                    if (message.type === 'typing') {
                        this.handleReceivedTyping(message);
                    } else {
                        originalHandler(message);
                    }
                };
            }
        },

        /**
         * Handle received typing indicator
         */
        handleReceivedTyping(message) {
            const fromUser = message.from_user || message.user_handle;
            const isTyping = message.is_typing !== false;
            
            // Show/hide typing indicator in UI
            this.updateTypingIndicator(fromUser, isTyping);
        },

        /**
         * Update typing indicator in UI
         */
        updateTypingIndicator(userHandle, isTyping) {
            // Find or create typing indicator element
            let indicator = $(`.typing-indicator[data-user="${userHandle}"]`);
            
            if (isTyping) {
                if (indicator.length === 0) {
                    // Create new indicator
                    indicator = $(`
                        <div class="typing-indicator" data-user="${userHandle}">
                            <span>${this.escapeHtml(userHandle)} is typing</span>
                            <div class="typing-dots">
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>
                        </div>
                    `);
                    
                    // Insert after last message in conversation
                    const conversationContainer = $('#im-messages');
                    if (conversationContainer.length) {
                        conversationContainer.append(indicator);
                    }
                }
                indicator.fadeIn(200);
            } else {
                if (indicator.length) {
                    indicator.fadeOut(200, () => {
                        indicator.remove();
                    });
                }
            }
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
    window.TypingIndicators = TypingIndicators;

    // Auto-initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => TypingIndicators.init());
    } else {
        TypingIndicators.init();
    }
})();

