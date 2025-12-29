/**
 * Sentinel Chat Platform - Message Edit/Delete UI
 * 
 * Provides UI for editing and deleting messages.
 * Messages older than 24 hours 5 minutes are permanent.
 */

(function() {
    'use strict';

    const MessageEdit = {
        /**
         * Initialize message edit/delete functionality
         */
        init() {
            // Add edit/delete buttons to messages on hover
            this.setupMessageActions();
            
            // Setup event delegation for edit/delete buttons
            $(document).on('click', '.message-edit-btn', (e) => {
                e.stopPropagation();
                const messageId = $(e.target).closest('.message-item, .im-message').data('message-id');
                const messageType = $(e.target).closest('.message-item, .im-message').data('message-type') || 'room';
                this.showEditDialog(messageId, messageType);
            });

            $(document).on('click', '.message-delete-btn', (e) => {
                e.stopPropagation();
                const messageId = $(e.target).closest('.message-item, .im-message').data('message-id');
                const messageType = $(e.target).closest('.message-item, .im-message').data('message-type') || 'room';
                this.showDeleteDialog(messageId, messageType);
            });
        },

        /**
         * Setup message action buttons
         */
        setupMessageActions() {
            // Add edit/delete buttons to messages
            $(document).on('mouseenter', '.message-item, .im-message', function() {
                const $msg = $(this);
                const messageId = $msg.data('message-id');
                
                if (!messageId) return;
                
                // Check if buttons already exist
                if ($msg.find('.message-actions').length > 0) return;
                
                // Check permissions
                MessageEdit.checkPermissions(messageId, $msg.data('message-type') || 'room').then((perms) => {
                    if (perms.can_edit || perms.can_delete) {
                        const actions = $('<div class="message-actions"></div>');
                        
                        if (perms.can_edit) {
                            actions.append('<button class="message-edit-btn" title="Edit message"><i class="fas fa-edit"></i></button>');
                        }
                        
                        if (perms.can_delete) {
                            actions.append('<button class="message-delete-btn" title="Delete message"><i class="fas fa-trash"></i></button>');
                        }
                        
                        $msg.append(actions);
                    }
                });
            });
        },

        /**
         * Check if user can edit/delete a message
         */
        async checkPermissions(messageId, messageType) {
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=message-edit.php&action=check&message_id=${messageId}&message_type=${messageType}`);
                const result = await response.json();
                
                return {
                    can_edit: result.can_edit || false,
                    can_delete: result.can_delete || false,
                };
            } catch (e) {
                console.error('Failed to check message permissions:', e);
                return { can_edit: false, can_delete: false };
            }
        },

        /**
         * Show edit dialog
         */
        async showEditDialog(messageId, messageType) {
            // Get current message content
            const $msg = $(`.message-item[data-message-id="${messageId}"], .im-message[data-message-id="${messageId}"]`);
            const currentText = $msg.find('.message-text, .im-message-text').text().trim();
            
            // Show prompt
            const newText = await window.App.showPrompt(
                'Edit your message:',
                'Edit Message',
                currentText,
                true // multiline
            );
            
            if (newText === null || newText === currentText) {
                return; // Cancelled or unchanged
            }
            
            // Send edit request
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=message-edit.php&action=edit`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        message_id: messageId,
                        message_type: messageType,
                        new_content: newText,
                    }),
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Update message in UI
                    $msg.find('.message-text, .im-message-text').html(this.escapeHtml(newText));
                    
                    // Add "edited" label
                    if ($msg.find('.message-edited-label').length === 0) {
                        $msg.find('.message-header, .im-message-header').append(
                            '<span class="message-edited-label" title="This message was edited">(edited)</span>'
                        );
                    }
                    
                    // Reload messages to get updated timestamp
                    if (window.App && messageType === 'room') {
                        window.App.loadMessages();
                    } else if (window.App && messageType === 'im') {
                        window.App.loadImConversationMessages(window.App.currentImConversation);
                    }
                } else {
                    window.App.showAlert(result.error || 'Failed to edit message', 'Error', 'error');
                }
            } catch (e) {
                console.error('Failed to edit message:', e);
                window.App.showAlert('Failed to edit message. Please try again.', 'Error', 'error');
            }
        },

        /**
         * Show delete confirmation dialog
         */
        async showDeleteDialog(messageId, messageType) {
            const confirmed = await window.App.showConfirm(
                'Are you sure you want to delete this message? This action cannot be undone.',
                'Delete Message',
                'Delete',
                'Cancel'
            );
            
            if (!confirmed) {
                return;
            }
            
            // Send delete request
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=message-edit.php&action=delete`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        message_id: messageId,
                        message_type: messageType,
                    }),
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Remove message from UI
                    $(`.message-item[data-message-id="${messageId}"], .im-message[data-message-id="${messageId}"]`).fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    window.App.showAlert(result.error || 'Failed to delete message', 'Error', 'error');
                }
            } catch (e) {
                console.error('Failed to delete message:', e);
                window.App.showAlert('Failed to delete message. Please try again.', 'Error', 'error');
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
    window.MessageEdit = MessageEdit;

    // Auto-initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => MessageEdit.init());
    } else {
        MessageEdit.init();
    }
})();

