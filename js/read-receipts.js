/**
 * Sentinel Chat Platform - Enhanced Read Receipts
 * 
 * Displays read receipts (✓✓) for IM messages.
 * Shows single check (sent) and double check (read).
 */

(function() {
    'use strict';

    const ReadReceipts = {
        /**
         * Initialize read receipts
         */
        init() {
            // Update read receipts when messages are displayed
            this.updateReadReceipts();
            
            // Setup WebSocket handlers for read receipts
            if (window.App && window.App.websocket) {
                this.setupWebSocketHandlers();
            }
        },

        /**
         * Render read receipt for a message
         * 
         * @param {string} messageId Message ID
         * @param {boolean} isRead Whether message is read
         * @param {boolean} isOwnMessage Whether this is the current user's message
         * @returns {string} HTML for read receipt
         */
        renderReadReceipt(messageId, isRead, isOwnMessage) {
            if (!isOwnMessage) {
                return ''; // Only show receipts on own messages
            }

            const receiptClass = isRead ? 'read' : '';
            const checkClass = isRead ? 'double' : 'single';
            
            return `
                <div class="read-receipt ${receiptClass}" data-message-id="${messageId}">
                    <i class="fas fa-check check-icon ${checkClass}"></i>
                    ${isRead ? '<i class="fas fa-check check-icon double"></i>' : ''}
                </div>
            `;
        },

        /**
         * Update read receipts for displayed messages
         */
        updateReadReceipts() {
            // This will be called when messages are loaded/displayed
            // Check each message's read_at status and update UI
            $('.im-message.own').each((index, element) => {
                const $msg = $(element);
                const messageId = $msg.data('message-id');
                const readAt = $msg.data('read-at');
                const isRead = readAt && readAt !== null && readAt !== 'null';
                
                // Remove existing receipt
                $msg.find('.read-receipt').remove();
                
                // Add updated receipt
                const receipt = this.renderReadReceipt(messageId, isRead, true);
                $msg.append(receipt);
            });
        },

        /**
         * Mark a message as read
         * 
         * @param {string} messageId Message ID
         * @param {string} fromUser Sender user handle
         */
        markAsRead(messageId, fromUser) {
            // Update via API
            $.ajax({
                url: `${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=im.php&action=mark-read`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    message_id: messageId,
                    from_user: fromUser,
                }),
            }).done(() => {
                // Update UI
                this.updateReadReceipt(messageId, true);
            }).fail(() => {
                console.error('Failed to mark message as read');
            });
        },

        /**
         * Update read receipt for a specific message
         */
        updateReadReceipt(messageId, isRead) {
            const $receipt = $(`.read-receipt[data-message-id="${messageId}"]`);
            if ($receipt.length) {
                if (isRead) {
                    $receipt.addClass('read');
                    if ($receipt.find('.check-icon.double').length === 0) {
                        $receipt.append('<i class="fas fa-check check-icon double"></i>');
                    }
                }
            }
        },

        /**
         * Setup WebSocket handlers
         */
        setupWebSocketHandlers() {
            if (window.App && window.App.handleWebSocketMessage) {
                const originalHandler = window.App.handleWebSocketMessage;
                window.App.handleWebSocketMessage = (message) => {
                    if (message.type === 'read_receipt') {
                        this.handleReadReceipt(message);
                    } else {
                        originalHandler(message);
                    }
                };
            }
        },

        /**
         * Handle received read receipt
         */
        handleReadReceipt(message) {
            const messageId = message.message_id;
            const isRead = message.is_read !== false;
            
            this.updateReadReceipt(messageId, isRead);
        },
    };

    // Export to global scope
    window.ReadReceipts = ReadReceipts;

    // Auto-initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => ReadReceipts.init());
    } else {
        ReadReceipts.init();
    }
})();

