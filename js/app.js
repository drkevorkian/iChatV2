/**
 * Sentinel Chat Platform - Main JavaScript Application
 * 
 * Handles all client-side interactions including message sending,
 * real-time updates, IM management, and admin dashboard.
 * 
 * Security: Never exposes API secrets. All API calls go through
 * the secure proxy endpoint.
 */

(function() {
    'use strict';

    // Application state
    const App = {
        config: window.SENTINEL_CONFIG || {},
        currentRoom: null,
        currentView: 'user',
        refreshInterval: null,
        imRefreshInterval: null,
        healthCheckInterval: null,
        websocket: null, // WebSocket connection
        websocketReconnectAttempts: 0,
        maxReconnectAttempts: 5,
        websocketReconnectTimeout: null,
        websocketStatusInterval: null, // WebSocket admin status polling interval
        modalCallbacks: null, // Store callbacks for modal actions
        youtubeModalOpen: false, // Track if YouTube modal is open
        scrollPositionBeforeModal: 0, // Store scroll position before modal opens
        autoScrollEnabled: true, // Track if auto-scroll is enabled
        isUserAtBottom: true, // Track if user is at bottom of chat
        lastScrollHeight: 0, // Track last scroll height to detect new messages
        userScrolling: false, // Track if user is actively scrolling
        scrollTimeout: null, // Timeout to detect when user stops scrolling
        currentMailFolder: 'inbox', // Current mail folder being viewed
        currentMailId: null, // Currently viewed mail ID
        currentMailThreadId: null, // Current mail thread ID
        userSettings: null, // User settings for chat appearance
        chatMediaFiles: [], // Files selected for chat upload
        voiceRecording: null, // Voice recording state
        activeAjaxRequests: 0, // Track active AJAX requests for loading indicator
        userProfileCache: {}, // Cache for user profiles (for avatar URLs)
        
        /**
         * Initialize the application
         */
        init: function() {
            this.currentRoom = this.config.defaultRoom || 'lobby';
            this.presenceInterval = null;
            this.currentImConversation = null;
            this.modalCallbacks = {};
            this.setupModalSystem();
            this.setupEventListeners();
            this.loadInitialData();
            
            // Initialize WebSocket if enabled, otherwise fall back to polling
            if (this.config.websocket && this.config.websocket.enabled) {
                this.connectWebSocket();
            } else {
                this.startAutoRefresh();
            }
            
            this.startPresenceHeartbeat();
            this.startAdminNotificationCheck();
            this.setupArea51Unlock();
            this.setupProfileLink();
            this.initImSystem();
            this.initMailSystem();
            this.initSettingsSystem();
            
            // Load and apply user theme on page load
            this.loadUserTheme();
            
            // Update profile button avatar on page load
            this.updateProfileButtonAvatar();
        },
        
        /**
         * Setup modal system event handlers
         */
        setupModalSystem: function() {
            // Close modal handlers
            $('#generic-modal-close, #generic-modal-cancel').on('click', () => {
                this.closeModal();
            });
            
            // Confirm button
            $('#generic-modal-confirm').on('click', () => {
                this.confirmModal();
            });
            
            // Close on backdrop click
            $('#generic-modal').on('click', (e) => {
                if ($(e.target).is('#generic-modal')) {
                    this.closeModal();
                }
            });
            
            // ESC key to close
            $(document).on('keydown', (e) => {
                if (e.key === 'Escape' && $('#generic-modal').hasClass('active')) {
                    // Only close if we're not typing in an input
                    const $target = $(e.target);
                    if (!$target.is('input, textarea') || $target.attr('id') === 'generic-modal-input' || $target.attr('id') === 'generic-modal-textarea') {
                        this.closeModal();
                    }
                }
            });
        },
        
        /**
         * Show alert modal
         */
        showAlert: function(message, title = 'Alert', type = 'info') {
            return new Promise((resolve) => {
                const $modal = $('#generic-modal');
                if ($modal.length === 0) {
                    console.error('Modal element not found!');
                    resolve(true);
                    return;
                }
                
                // Clear previous content
                $('#generic-modal-message').html('');
                $('#generic-modal-input-container').hide();
                
                // Set title
                $('#generic-modal-title').text(title);
                
                // Add icon
                let iconClass = 'info';
                let iconName = 'info-circle';
                if (type === 'success') {
                    iconClass = 'success';
                    iconName = 'check-circle';
                } else if (type === 'error') {
                    iconClass = 'error';
                    iconName = 'exclamation-circle';
                } else if (type === 'warning') {
                    iconClass = 'warning';
                    iconName = 'exclamation-triangle';
                }
                
                // Build message HTML with icon (escape message to prevent XSS)
                const messageHtml = `<div class="modal-icon ${iconClass}"><i class="fas fa-${iconName}"></i></div><p>${this.escapeHtml(message)}</p>`;
                $('#generic-modal-message').html(messageHtml);
                
                // Set footer buttons
                $('#generic-modal-footer').html('<button class="btn-primary" id="generic-modal-confirm">OK</button>');
                
                this.modalCallbacks = {
                    confirm: () => {
                        this.closeModal();
                        resolve(true);
                    }
                };
                
                // Show modal
                $modal.addClass('active');
                
                // Re-attach handler for new button (use event delegation)
                $(document).off('click.modal', '#generic-modal-confirm').on('click.modal', '#generic-modal-confirm', () => {
                    if (this.modalCallbacks && this.modalCallbacks.confirm) {
                        this.modalCallbacks.confirm();
                    }
                });
                
                // Focus the button
                setTimeout(() => {
                    $('#generic-modal-confirm').focus();
                }, 100);
            });
        },
        
        /**
         * Show confirm modal
         */
        showConfirm: function(message, title = 'Confirm') {
            return new Promise((resolve) => {
                const $modal = $('#generic-modal');
                if ($modal.length === 0) {
                    console.error('Modal element not found!');
                    resolve(false);
                    return;
                }
                
                // Clear previous content
                $('#generic-modal-message').html('');
                $('#generic-modal-input-container').hide();
                
                // Set title and message
                $('#generic-modal-title').text(title);
                $('#generic-modal-message').html(`<p>${this.escapeHtml(message)}</p>`);
                
                // Set footer buttons
                $('#generic-modal-footer').html(`
                    <button class="btn-secondary" id="generic-modal-cancel">Cancel</button>
                    <button class="btn-primary" id="generic-modal-confirm">Confirm</button>
                `);
                
                this.modalCallbacks = {
                    confirm: () => {
                        this.closeModal();
                        resolve(true);
                    },
                    cancel: () => {
                        this.closeModal();
                        resolve(false);
                    }
                };
                
                // Show modal
                $modal.addClass('active');
                
                // Re-attach handlers for new buttons (use event delegation with namespace)
                $(document).off('click.modal', '#generic-modal-confirm, #generic-modal-cancel')
                    .on('click.modal', '#generic-modal-confirm', () => {
                        if (this.modalCallbacks && this.modalCallbacks.confirm) {
                            this.modalCallbacks.confirm();
                        }
                    })
                    .on('click.modal', '#generic-modal-cancel', () => {
                        if (this.modalCallbacks && this.modalCallbacks.cancel) {
                            this.modalCallbacks.cancel();
                        }
                    });
                
                // Focus the confirm button
                setTimeout(() => {
                    $('#generic-modal-confirm').focus();
                }, 100);
            });
        },
        
        /**
         * Show prompt modal
         */
        showPrompt: function(message, title = 'Input', defaultValue = '', multiline = false) {
            return new Promise((resolve) => {
                $('#generic-modal-title').text(title);
                $('#generic-modal-message').html(message);
                $('#generic-modal-input-container').show();
                
                if (multiline) {
                    $('#generic-modal-input').hide();
                    $('#generic-modal-textarea').show().val(defaultValue).focus().select();
                } else {
                    $('#generic-modal-textarea').hide();
                    $('#generic-modal-input').show().val(defaultValue).focus().select();
                }
                
                $('#generic-modal-footer').html(`
                    <button class="btn-secondary" id="generic-modal-cancel">Cancel</button>
                    <button class="btn-primary" id="generic-modal-confirm">OK</button>
                `);
                
                // Remove any existing icons
                $('#generic-modal-message .modal-icon').remove();
                
                this.modalCallbacks = {
                    confirm: () => {
                        const value = multiline 
                            ? $('#generic-modal-textarea').val().trim()
                            : $('#generic-modal-input').val().trim();
                        this.closeModal();
                        resolve(value);
                    },
                    cancel: () => {
                        this.closeModal();
                        resolve(null);
                    }
                };
                
                $('#generic-modal').addClass('active');
                
                // Re-attach handlers for new buttons
                $('#generic-modal-confirm').off('click').on('click', () => {
                    if (this.modalCallbacks && this.modalCallbacks.confirm) {
                        this.modalCallbacks.confirm();
                    }
                });
                $('#generic-modal-cancel').off('click').on('click', () => {
                    if (this.modalCallbacks && this.modalCallbacks.cancel) {
                        this.modalCallbacks.cancel();
                    }
                });
                
                // Enter key to confirm (only for single-line input)
                const inputElement = multiline ? $('#generic-modal-textarea') : $('#generic-modal-input');
                inputElement.off('keydown').on('keydown', (e) => {
                    if (e.key === 'Enter' && !multiline && !e.shiftKey) {
                        e.preventDefault();
                        if (this.modalCallbacks && this.modalCallbacks.confirm) {
                            this.modalCallbacks.confirm();
                        }
                    }
                });
            });
        },
        
        /**
         * Close modal
         */
        closeModal: function() {
            $('#generic-modal').removeClass('active');
            $('#generic-modal-message').html(''); // Clear message content including icons
            $('#generic-modal-input').val('');
            $('#generic-modal-textarea').val('');
            $('#generic-modal-input-container').hide();
            this.modalCallbacks = {};
        },
        
        /**
         * Confirm modal action
         */
        confirmModal: function() {
            if (this.modalCallbacks && this.modalCallbacks.confirm) {
                this.modalCallbacks.confirm();
            }
        },
        
        /**
         * Setup profile link in header
         */
        setupProfileLink: function() {
            // Check if user is authenticated (not a guest)
            const userHandle = this.config.userHandle;
            const userRole = this.config.userRole;
            
            // Only show profile button for authenticated users (not guests)
            if (userHandle && !userHandle.startsWith('Guest') && userRole !== 'guest') {
                $('#my-profile-btn').show().on('click', () => {
                    // Open settings for own profile
                    this.switchView('settings');
                });
            }
        },
        
        /**
         * Setup event listeners for user interactions
         */
        setupEventListeners: function() {
            // View navigation
            $('.nav-btn').on('click', (e) => {
                const view = $(e.target).data('view');
                this.switchView(view);
            });
            
            // Admin category tabs (top row)
            $(document).on('click', '.admin-category-btn', (e) => {
                e.preventDefault();
                const category = $(e.target).closest('.admin-category-btn').data('category');
                this.switchAdminCategory(category);
            });
            
            // Admin sub-tabs (second row)
            $(document).on('click', '.admin-subtab-btn', (e) => {
                e.preventDefault();
                const tabName = $(e.target).closest('.admin-subtab-btn').data('tab');
                this.switchAdminTab(tabName);
            });
            
            // Legacy admin-tab-btn support (for backwards compatibility)
            $(document).on('click', '.admin-tab-btn', (e) => {
                e.preventDefault();
                const tabName = $(e.target).closest('.admin-tab-btn').data('tab');
                this.switchAdminTab(tabName);
            });
            
            // Moderator tab switching
            $(document).on('click', '.moderator-tab-btn', (e) => {
                e.preventDefault();
                const tabName = $(e.target).closest('.moderator-tab-btn').data('tab');
                this.switchModeratorTab(tabName);
            });
            
            // Log viewer controls (admin)
            $(document).on('change', '#log-file-select', () => {
                const logFile = $('#log-file-select').val();
                if (logFile) {
                    this.viewLog(logFile);
                }
            });
            
            $(document).on('click', '#refresh-logs-btn', () => {
                const logFile = $('#log-file-select').val();
                if (logFile) {
                    this.viewLog(logFile);
                } else {
                    this.loadLogsList();
                }
            });
            
            $(document).on('click', '#rotate-log-btn', () => {
                const logFile = $('#log-file-select').val() || 'error.log';
                if (confirm(`Rotate log file: ${logFile}?`)) {
                    this.rotateLog(logFile);
                }
            });
            
            // Log viewer controls (moderator)
            $(document).on('change', '#log-file-select-moderator', () => {
                const logFile = $('#log-file-select-moderator').val();
                if (logFile) {
                    this.viewLog(logFile, '-moderator');
                }
            });
            
            $(document).on('click', '#refresh-logs-btn-moderator', () => {
                const logFile = $('#log-file-select-moderator').val();
                if (logFile) {
                    this.viewLog(logFile, '-moderator');
                } else {
                    this.loadLogsList('moderator');
                }
            });
            
            $(document).on('click', '#rotate-log-btn-moderator', () => {
                const logFile = $('#log-file-select-moderator').val() || 'error.log';
                if (confirm(`Rotate log file: ${logFile}?`)) {
                    this.rotateLog(logFile, '-moderator');
                }
            });
            
            // Join room button
            $(document).on('click', '#join-room-btn', () => {
                this.showJoinRoomModal();
            });
            
            // Join room modal close
            $(document).on('click', '#join-room-modal-close, #join-room-cancel', () => {
                $('#join-room-modal').removeClass('active');
                $('#join-room-form')[0].reset();
                $('#join-room-password-group').hide();
            });
            
            // Join room submit
            $(document).on('click', '#join-room-submit', () => {
                this.joinRoomWithCode();
            });
            
            // Auto-uppercase invite code input
            $(document).on('input', '#join-room-invite-code', function() {
                $(this).val($(this).val().toUpperCase());
            });
            
            // Manage room button
            $(document).on('click', '#manage-room-btn', () => {
                this.showManageRoomModal();
            });
            
            // Manage room modal close
            $(document).on('click', '#manage-room-modal-close, #manage-room-cancel', () => {
                $('#manage-room-modal').removeClass('active');
                $('#manage-room-form')[0].reset();
            });
            
            // Manage room remove password checkbox
            $(document).on('change', '#manage-room-remove-password', function() {
                if ($(this).is(':checked')) {
                    $('#manage-room-password').val('').prop('disabled', true);
                } else {
                    $('#manage-room-password').prop('disabled', false);
                }
            });
            
            // Manage room submit
            $(document).on('click', '#manage-room-submit', () => {
                this.updateRoomPassword();
            });
            
            // Room request button
            $(document).on('click', '#request-room-btn', () => {
                this.showRoomRequestModal();
            });
            
            // Room request modal close
            $(document).on('click', '#room-request-modal-close, #room-request-cancel', () => {
                this.closeRoomRequestModal();
            });
            
            // Room request form submission
            $(document).on('submit', '#room-request-form', (e) => {
                e.preventDefault();
                this.submitRoomRequest();
            });
            
            // Refresh room requests
            $(document).on('click', '#refresh-room-requests-btn', () => {
                this.loadRoomRequests();
            });
            
            // Room request status filter
            $(document).on('change', '#room-request-status-filter', () => {
                this.loadRoomRequests();
            });
            
            // Approve/deny room requests
            $(document).on('click', '.approve-room-request-btn', (e) => {
                const requestId = $(e.target).data('request-id');
                this.approveRoomRequest(requestId);
            });
            
            $(document).on('click', '.deny-room-request-btn', (e) => {
                const requestId = $(e.target).data('request-id');
                this.denyRoomRequest(requestId);
            });
            
            // Word filter management (admin)
            $(document).on('click', '#refresh-word-filters-btn', () => {
                this.loadWordFilters();
            });
            
            $(document).on('click', '#add-word-filter-btn', () => {
                this.openWordFilterModal();
            });
            
            $(document).on('click', '.edit-word-filter', (e) => {
                const filterId = $(e.target).data('id');
                this.openWordFilterModal(filterId);
            });
            
            $(document).on('click', '.delete-word-filter', (e) => {
                const filterId = $(e.target).data('id');
                this.deleteWordFilter(filterId);
            });
            
            $(document).on('change', '#word-filter-status-filter, #word-filter-severity-filter', () => {
                this.loadWordFilters();
            });
            
            $(document).on('input', '#word-filter-search', () => {
                this.loadWordFilters();
            });
            
            // Word filter modal handlers
            $(document).on('click', '#word-filter-modal-close, #word-filter-cancel', () => {
                this.closeWordFilterModal();
            });
            
            $(document).on('click', '#word-filter-save', () => {
                this.saveWordFilter();
            });
            
            // Word filter requests (admin)
            $(document).on('click', '#refresh-word-filter-requests-btn', () => {
                this.loadWordFilterRequests();
            });
            
            $(document).on('change', '#word-filter-request-status-filter', () => {
                this.loadWordFilterRequests();
            });
            
            $(document).on('click', '.approve-word-filter-request', (e) => {
                const requestId = $(e.target).data('id');
                this.approveWordFilterRequest(requestId);
            });
            
            $(document).on('click', '.deny-word-filter-request', (e) => {
                const requestId = $(e.target).data('id');
                this.denyWordFilterRequest(requestId);
            });
            
            // Word filter request modal (moderator)
            $(document).on('click', '#request-word-filter-btn', () => {
                this.showWordFilterRequestModal();
            });
            
            $(document).on('click', '#word-filter-request-modal-close, #word-filter-request-cancel', () => {
                this.closeWordFilterRequestModal();
            });
            
            $(document).on('change', '#word-filter-request-type', function() {
                const requestType = $(this).val();
                if (requestType === 'remove') {
                    $('#word-filter-request-filter-id-group').show();
                    $('#word-filter-request-pattern-group, #word-filter-request-replacement-group, #word-filter-request-severity-group, #word-filter-request-tags-group').hide();
                } else if (requestType === 'edit') {
                    $('#word-filter-request-filter-id-group').show();
                    $('#word-filter-request-pattern-group, #word-filter-request-replacement-group, #word-filter-request-severity-group, #word-filter-request-tags-group').show();
                } else {
                    $('#word-filter-request-filter-id-group').hide();
                    $('#word-filter-request-pattern-group, #word-filter-request-replacement-group, #word-filter-request-severity-group, #word-filter-request-tags-group').show();
                }
            });
            
            $(document).on('submit', '#word-filter-request-form', (e) => {
                e.preventDefault();
                this.submitWordFilterRequest();
            });
            
            // WebSocket server management
            $(document).on('click', '#websocket-start-btn', () => {
                this.startWebSocketServer();
            });
            
            $(document).on('click', '#websocket-stop-btn', () => {
                this.stopWebSocketServer();
            });
            
            $(document).on('click', '#websocket-restart-btn', () => {
                this.restartWebSocketServer();
            });
            
            $(document).on('click', '#websocket-refresh-btn', () => {
                console.log('[WS] Manual refresh triggered');
                this.loadWebSocketStatus();
                this.loadWebSocketLogs();
            });
            
            $(document).on('click', '#websocket-logs-refresh', () => {
                this.loadWebSocketLogs();
            });
            
            $(document).on('click', '#websocket-logs-clear', () => {
                this.clearWebSocketLogs();
            });
            
            $(document).on('change', '#websocket-logs-lines', () => {
                this.loadWebSocketLogs();
            });
            
            // Python server controls
            $(document).on('click', '#python-start-btn', () => {
                this.startPythonServer();
            });
            
            $(document).on('click', '#python-stop-btn', () => {
                this.stopPythonServer();
            });
            
            $(document).on('click', '#python-restart-btn', () => {
                this.restartPythonServer();
            });
            
            $(document).on('change', '#websocket-logs-server', () => {
                this.loadWebSocketLogs();
            });
            
            // Room selection
            $('#room-select').on('change', (e) => {
                // Leave old room
                this.leaveRoom();
                
                // Join new room
                const newRoom = $(e.target).val();
                this.switchRoom(newRoom);
            });
            
            // Send message
            $('#send-btn').on('click', () => {
                this.sendMessage();
            });
            
            // Enter key to send message
            $('#message-input').on('keydown', (e) => {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
            
            // Media upload button
            $('#media-upload-btn').on('click', () => {
                $('#chat-media-input').click();
            });
            
            // Handle media file selection
            $('#chat-media-input').on('change', (e) => {
                const files = Array.from(e.target.files);
                if (files.length > 0) {
                    this.handleMediaUpload(files);
                }
            });
            
            // Voice record button
            let isRecording = false;
            $('#voice-record-btn').on('click', () => {
                if (!isRecording) {
                    this.startVoiceRecording();
                    isRecording = true;
                    $('#voice-record-btn').addClass('recording').html('<i class="fas fa-stop"></i>');
                } else {
                    this.stopVoiceRecording();
                    isRecording = false;
                    $('#voice-record-btn').removeClass('recording').html('<i class="fas fa-microphone"></i>');
                }
            });
            
            // Escrow form submission
            $('#escrow-form').on('submit', (e) => {
                e.preventDefault();
                this.submitEscrowRequest();
            });
            
            // Refresh patches button (use event delegation to handle dynamically added elements)
            $(document).on('click', '#refresh-patches-btn', (e) => {
                e.preventDefault();
                e.stopPropagation();
                console.log('Refresh patches button clicked!');
                this.loadPatches();
            });
            
            // Database repair buttons
            $(document).on('click', '#check-db-health-btn', (e) => {
                e.preventDefault();
                this.checkDatabaseHealth();
            });
            
            $(document).on('click', '#repair-db-btn', async (e) => {
                e.preventDefault();
                const confirmed = await this.showConfirm(
                    'Are you sure you want to repair the database? This will attempt to fix any missing tables or columns.',
                    'Repair Database'
                );
                if (confirmed) {
                    this.repairDatabase();
                }
            });
            
            // Debug: Check if button exists after a short delay
            setTimeout(() => {
                const btn = $('#refresh-patches-btn');
                console.log('Button check:', btn.length, btn);
                if (btn.length === 0) {
                    console.warn('Refresh patches button not found in DOM!');
                } else {
                    console.log('Button found, attaching direct handler');
                    btn.on('click', (e) => {
                        console.log('Direct handler fired!');
                        e.preventDefault();
                        App.loadPatches();
                    });
                }
            }, 1000);
            
            // Apply patch buttons (use event delegation)
            $(document).on('click', '.apply-patch-btn', (e) => {
                const patchId = $(e.target).data('patch-id');
                if (patchId) {
                    this.applyPatch(patchId);
                }
            });
            
            // Rollback patch buttons (use event delegation)
            $(document).on('click', '.rollback-patch-btn', (e) => {
                const patchId = $(e.target).data('patch-id');
                if (patchId) {
                    this.rollbackPatch(patchId);
                }
            });
            
            // IM panel toggle (could be triggered by mail badge click)
            $('#mail-badge').on('click', () => {
                this.toggleImPanel();
            });
            
            // Profile modal close
            $(document).on('click', '#profile-modal-close, #profile-modal', (e) => {
                if (e.target.id === 'profile-modal' || e.target.id === 'profile-modal-close') {
                    this.closeProfileModal();
                }
            });
            
            // Refresh all users button
            $(document).on('click', '#refresh-all-users-btn', () => {
                this.loadAllUsers();
            });
            
            // Refresh online users only button
            $(document).on('click', '#refresh-online-users-btn', () => {
                $('#user-filter-select').val('online');
                this.loadAllUsers();
            });
            
            // User filter change
            $('#user-filter-select').on('change', () => {
                this.loadAllUsers();
            });
            
            // User search
            $('#user-search-input').on('input', () => {
                // Debounce search
                clearTimeout(this.userSearchTimeout);
                this.userSearchTimeout = setTimeout(() => {
                    this.loadAllUsers();
                }, 300);
            });
            
            // Mock message button (admin only)
            $(document).on('click', '#mock-message-btn', (e) => {
                e.preventDefault();
                this.createMockMessage();
            });
            
            // Prevent modal close when clicking inside modal content
            $(document).on('click', '.profile-modal-content', (e) => {
                e.stopPropagation();
            });
            
            // Emoji picker
            $(document).on('click', '#emoji-picker-btn', (e) => {
                e.preventDefault();
                this.toggleEmojiPicker();
            });
            
            $(document).on('click', '#emoji-picker-close', () => {
                this.hideEmojiPicker();
            });
            
            $(document).on('click', '.emoji-tab', (e) => {
                e.preventDefault();
                const tab = $(e.target).data('tab');
                this.switchEmojiTab(tab);
            });
            
            $(document).on('click', '.emoji-item', (e) => {
                const emoji = $(e.target).text().trim();
                if (emoji) {
                    this.insertEmoji(emoji);
                    // Record usage
                    const emojiId = $(e.target).data('emoji-id');
                    if (emojiId) {
                        this.recordEmojiUsage(emojiId);
                    }
                }
            });
            
            // Emoji search
            let emojiSearchTimeout;
            $(document).on('input', '#emoji-search-input', () => {
                clearTimeout(emojiSearchTimeout);
                emojiSearchTimeout = setTimeout(() => {
                    const search = $('#emoji-search-input').val().trim();
                    this.searchEmojis(search);
                }, 300);
            });
            
            // Close emoji picker when clicking outside
            $(document).on('click', (e) => {
                if ($('#emoji-picker').is(':visible')) {
                    if (!$(e.target).closest('#emoji-picker, #emoji-picker-btn').length) {
                        this.hideEmojiPicker();
                    }
                }
            });
            
            // File storage management
            $(document).on('click', '#refresh-file-storage-btn', () => {
                this.loadFileStorageList();
            });
            
            $(document).on('change', '#file-storage-type-filter', () => {
                this.loadFileStorageList();
            });
            
            $(document).on('click', '#view-file-storage-btn', () => {
                this.viewFileStorageFile(this.selectedFileStorageFile);
            });
            
            $(document).on('click', '#edit-file-storage-btn', () => {
                this.editFileStorageFile(this.selectedFileStorageFile);
            });
            
            $(document).on('click', '#delete-file-storage-btn', () => {
                this.deleteFileStorageFile(this.selectedFileStorageFile);
            });
            
            $(document).on('click', '#file-storage-modal-close, #file-storage-modal-cancel', () => {
                $('#file-storage-modal').hide();
            });
            
            // Track scroll position in messages container to preserve position on refresh
            $(document).on('scroll', '#messages-container', () => {
                const container = $('#messages-container');
                if (container.length === 0) return;
                
                // Update whether user is at bottom
                const atBottom = this.isAtBottom();
                
                // If user scrolls to bottom, enable auto-scroll and stay at bottom
                if (atBottom) {
                    this.isUserAtBottom = true;
                    this.autoScrollEnabled = true;
                    // Ensure we're actually at the bottom
                    setTimeout(() => {
                        if (this.isAtBottom()) {
                            container.scrollTop(container[0].scrollHeight);
                        }
                    }, 10);
                } else {
                    // User scrolled up - disable auto-scroll
                    this.isUserAtBottom = false;
                }
                
                this.userScrolling = true;
                
                // Clear existing timeout
                if (this.scrollTimeout) {
                    clearTimeout(this.scrollTimeout);
                }
                
                // Mark as not scrolling after 150ms of no scroll activity
                this.scrollTimeout = setTimeout(() => {
                    this.userScrolling = false;
                }, 150);
            });
        },
        
        /**
         * Switch between different views (user, moderator, admin, area51)
         */
        switchView: function(view) {
            this.currentView = view;
            
            // Update active button
            $('.nav-btn').removeClass('active');
            $(`.nav-btn[data-view="${view}"]`).addClass('active');
            
            // Clear intervals when switching views to prevent hanging
            this.clearAllIntervals();
            
            // Show/hide view containers
            $('.view-container').removeClass('active');
            $(`#${view}-view`).addClass('active');
            
            // Load view-specific data
            switch(view) {
                case 'user':
                    this.loadRoomMessages();
                    this.loadOnlineUsers();
                    break;
                case 'mail':
                    this.loadMailFolder('inbox');
                    this.updateUnreadMailCount();
                    break;
                case 'settings':
                    this.loadSettings();
                    break;
                case 'moderator':
                    this.loadModeratorDashboard();
                    break;
                case 'admin':
                    this.loadAdminDashboard();
                    break;
                case 'area51':
                    this.loadArea51Data();
                    break;
            }
        },
        
        /**
         * Load initial data on page load
         */
        loadInitialData: function() {
            // Ensure currentRoom is set before loading data
            if (!this.currentRoom) {
                this.currentRoom = this.config.defaultRoom || 'lobby';
            }
            
            // Determine initial view based on user role
            const userRole = this.config.userRole || 'guest';
            if (userRole === 'administrator') {
                this.currentView = 'admin';
                $('.nav-btn').removeClass('active');
                $('.nav-btn[data-view="admin"]').addClass('active');
                $('.view-container').removeClass('active');
                $('#admin-view').addClass('active');
                this.loadAdminDashboard();
            } else {
                this.loadRoomMessages();
                this.loadOnlineUsers();
                this.updatePresence();
            }
            this.checkHealth();
        },
        
        /**
         * Send a message to the current room
         */
        sendMessage: function() {
            const messageInput = $('#message-input');
            const message = messageInput.val().trim();
            
            // Check if we have media files or message text
            if (!message && this.chatMediaFiles.length === 0) {
                return; // Don't show alert, just return
            }
            
            if (message.length > 1000) {
                this.showAlert('Message is too long (max 1000 characters)', 'Message Too Long', 'error');
                return;
            }
            
            // If we have media files, use sendMediaMessage instead
            if (this.chatMediaFiles.length > 0) {
                this.sendMediaMessage(message);
                return;
            }
            
            // Disable send button during request
            const sendBtn = $('#send-btn');
            sendBtn.prop('disabled', true).text('Sending...');
            
            // Encrypt message (simplified - in production, use proper encryption)
            const cipherBlob = btoa(unescape(encodeURIComponent(message)));
            
            // Send via proxy (handles API secret server-side)
            this.showLoading();
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=messages.php',
                method: 'POST',
                contentType: 'application/json',
                timeout: 10000, // 10 second timeout
                data: JSON.stringify({
                    room_id: this.currentRoom,
                    sender_handle: this.config.userHandle,
                    cipher_blob: cipherBlob,
                    filter_version: 1
                }),
                success: (response) => {
                    this.hideLoading();
                    // Always reset button state
                    sendBtn.prop('disabled', false).text('Send');
                    
                    if (response && response.success) {
                        messageInput.val('');
                        
                        // Handle Pinky & Brain bot command
                        if (response.bot_command && response.bot_responses) {
                            // If WebSocket is connected, wait for message via WebSocket
                            // Otherwise reload to show Brain's message
                            if (!this.websocket || this.websocket.readyState !== WebSocket.OPEN) {
                                this.loadRoomMessages();
                            }
                            
                            // Schedule Pinky's response if available
                            if (response.bot_responses.pinky_delay) {
                                setTimeout(() => {
                                    this.sendPinkyResponse(this.currentRoom);
                                }, response.bot_responses.pinky_delay);
                            }
                        }
                        // If WebSocket is connected, message will arrive via WebSocket
                        // Don't reload messages here - let WebSocket handle it
                    } else {
                        const errorMsg = response && response.message ? response.message : 'Unknown error';
                        this.showAlert('Failed to send message: ' + errorMsg, 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    this.hideLoading();
                    let errorMsg = 'Error sending message. Please try again.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    this.showAlert(errorMsg, 'Error', 'error');
                    console.error('Send error:', xhr);
                    sendBtn.prop('disabled', false).text('Send');
                }
            });
        },
        
        /**
         * Check if user is at bottom of chat (within 50px threshold)
         */
        isAtBottom: function() {
            const container = $('#messages-container');
            if (container.length === 0) return true;
            
            const scrollTop = container.scrollTop();
            const scrollHeight = container[0].scrollHeight;
            const clientHeight = container[0].clientHeight;
            const threshold = 50; // pixels from bottom
            
            return (scrollHeight - scrollTop - clientHeight) <= threshold;
        },
        
        /**
         * Load messages for the current room
         */
        loadRoomMessages: function() {
            const container = $('#messages-container');
            
            // Ensure we have a current room set
            if (!this.currentRoom) {
                this.currentRoom = this.config.defaultRoom || 'lobby';
            }
            
            // Always load messages if container is empty or only has loading/no-messages divs
            // Don't skip just because WebSocket is connected - we need initial messages
            const existingContent = container.children();
            const hasRealMessages = existingContent.filter('.chat-message').length > 0;
            
            // If WebSocket is connected and we have real messages, don't reload unnecessarily
            if (this.websocket && this.websocket.readyState === WebSocket.OPEN && hasRealMessages) {
                // We have messages already, WebSocket will handle new ones
                console.log('[WS] Skipping loadRoomMessages - WebSocket active and messages exist');
                return;
            }
            
            // Check if user is at bottom BEFORE loading new messages
            // Use both the current check AND the stored state to be more reliable
            const wasAtBottom = container.length > 0 ? (this.isAtBottom() || this.isUserAtBottom) : true;
            const previousScrollTop = container.length > 0 ? container.scrollTop() : 0;
            const previousScrollHeight = container.length > 0 ? container[0].scrollHeight : 0;
            
            // Store that we're refreshing - preserve bottom state
            const shouldStayAtBottom = wasAtBottom || this.isUserAtBottom;
            
            container.html('<div class="loading-messages">Loading messages...</div>');
            
            // Check if user is moderator/admin (to include hidden messages)
            const userRole = this.config.userRole || 'guest';
            const isModerator = userRole === 'moderator' || userRole === 'administrator';
            
            // Load via proxy (handles API secret server-side)
            this.showLoading();
            $.ajax({
                url: this.config.apiBase + '/proxy.php',
                method: 'GET',
                data: {
                    path: 'messages.php',
                    limit: 100,
                    room_id: this.currentRoom,
                    include_hidden: isModerator ? '1' : '0'
                },
                timeout: 10000, // 10 second timeout
                success: (response) => {
                    this.hideLoading();
                    if (response && response.success) {
                        this.renderRoomMessages(response.messages || [], shouldStayAtBottom, previousScrollTop, previousScrollHeight);
                    } else {
                        container.html('<div class="no-messages">No messages yet. Be the first to post!</div>');
                        // If we should stay at bottom, scroll there even with no messages
                        if (shouldStayAtBottom) {
                            requestAnimationFrame(() => {
                                container.scrollTop(container[0].scrollHeight);
                            });
                        }
                    }
                },
                error: (xhr, status, error) => {
                    this.hideLoading();
                    container.html('<div class="no-messages">Unable to load messages. Please try again.</div>');
                    console.error('Failed to load messages:', status, error, xhr);
                }
            });
        },
        
        /**
         * Render room messages in chat-like interface
         * @param {Array} messages - Array of message objects
         * @param {boolean} wasAtBottom - Whether user was at bottom before refresh
         * @param {number} previousScrollTop - Previous scroll position
         * @param {number} previousScrollHeight - Previous scroll height
         */
        renderRoomMessages: function(messages, wasAtBottom = true, previousScrollTop = 0, previousScrollHeight = 0) {
            const container = $('#messages-container');
            container.empty();
            
            // Filter messages for current room (and hidden messages if user is mod/admin)
            const userRole = this.config.userRole || 'guest';
            const isModerator = userRole === 'moderator' || userRole === 'administrator';
            const roomMessages = messages.filter(msg => {
                if (msg.room_id !== this.currentRoom) return false;
                // Hide hidden messages from non-moderators
                if (!isModerator && msg.is_hidden) return false;
                return true;
            });
            
            if (roomMessages.length === 0) {
                container.html('<div class="no-messages">No messages in this room yet. Be the first to post!</div>');
                return;
            }
            
            roomMessages.forEach((msg) => {
                // Decrypt message (simplified - in production, use proper decryption)
                let messageText = '';
                try {
                    // Decode base64, then URL decode (handles UTF-8 emojis properly)
                    const base64Decoded = atob(msg.cipher_blob);
                    // Replace + signs with %20 before decoding (urlencode uses +, but decodeURIComponent expects %20)
                    const decodedWithSpaces = base64Decoded.replace(/\+/g, '%20');
                    messageText = decodeURIComponent(decodedWithSpaces);
                } catch (e) {
                    console.error('Failed to decode message:', e);
                    messageText = '[Encrypted message]';
                }
                
                const isOwnMessage = msg.sender_handle === this.config.userHandle;
                const isHidden = msg.is_hidden || false;
                const isEdited = msg.edited_at !== null && msg.edited_at !== undefined;
                const messageDiv = $('<div>').addClass('chat-message' + (isOwnMessage ? ' own-message' : '') + (isHidden ? ' message-hidden' : ''));
                messageDiv.attr('data-message-id', msg.id);
                
                // Build moderation buttons (for mods/admins)
                let moderationButtons = '';
                if (isModerator && !isHidden) {
                    moderationButtons = `
                        <div class="message-moderation">
                            <button class="btn-mod-hide" data-message-id="${msg.id}" title="Hide message">
                                <i class="fas fa-eye-slash"></i>
                            </button>
                    `;
                    if (userRole === 'administrator') {
                        moderationButtons += `
                            <button class="btn-mod-edit" data-message-id="${msg.id}" title="Edit message">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn-mod-delete" data-message-id="${msg.id}" title="Delete message">
                                <i class="fas fa-trash"></i>
                            </button>
                        `;
                    }
                    moderationButtons += '</div>';
                }
                
                // Show edit indicator if message was edited
                const editIndicator = isEdited ? `<span class="message-edited" title="Edited by ${this.escapeHtml(msg.edited_by || 'moderator')}">(edited)</span>` : '';
                
                // Check if message is from bot
                const isBot = msg.sender_handle === 'Brain' || msg.sender_handle === 'Pinky';
                if (isBot) {
                    messageDiv.addClass('bot-message');
                }
                
                // Process message for display: smileys and ASCII art
                let displayText = messageText;
                if (!isHidden) {
                    displayText = this.processMessageForDisplay(messageText);
                } else {
                    displayText = '<em>[Message hidden by moderator]</em>';
                }
                
                const botBadge = isBot ? `<span class="bot-badge">${this.escapeHtml(msg.sender_handle)}</span>` : '';
                
                // Get avatar URL for sender (served from PHP endpoint with thumbnail size)
                const avatarUrl = this.getAvatarUrlForUser(msg.sender_handle, 50);
                
                messageDiv.html(`
                    <div class="message-avatar">
                        <img src="${avatarUrl}" alt="${this.escapeHtml(msg.sender_handle || 'User')}" class="avatar-img" onerror="this.onerror=null; this.src='/iChat/api/avatar-image.php?user=' + encodeURIComponent('${this.escapeHtml(msg.sender_handle || '')}') + '&size=50'; this.style.display='block';">
                    </div>
                    <div class="message-content-wrapper">
                        <div class="message-header">
                            <span class="message-sender clickable-user" data-user-handle="${this.escapeHtml(msg.sender_handle || 'Unknown')}" data-message-id="${msg.id}">${this.escapeHtml(msg.sender_handle || 'Unknown')}</span>
                            ${botBadge}
                            <span class="message-time">${this.formatTime(msg.queued_at)} ${editIndicator}</span>
                        </div>
                        <div class="message-text" style="color: ${this.getUserTextColor(msg.sender_handle)}">${displayText}</div>
                        ${msg.media && msg.media.length > 0 ? this.renderChatMedia(msg.media) : ''}
                        ${moderationButtons}
                    </div>
                `);
                
                container.append(messageDiv);
            });
            
            // Attach moderation event handlers
            if (isModerator) {
                this.attachModerationHandlers();
            }
            
            // Attach user name click handlers for context menu
            this.attachUserContextMenuHandlers();
            
            // Attach YouTube video click handlers
            this.attachYouTubeHandlers();
            
            // Handle scroll position
            if (this.youtubeModalOpen) {
                // Modal is open - restore previous position
                container.scrollTop(this.scrollPositionBeforeModal);
            } else if (wasAtBottom) {
                // User was at bottom - ALWAYS scroll to bottom to show new messages
                // Don't check autoScrollEnabled here - if they were at bottom, keep them there
                // Use requestAnimationFrame to ensure DOM is fully rendered
                requestAnimationFrame(() => {
                    const scrollHeight = container[0].scrollHeight;
                    container.scrollTop(scrollHeight);
                    this.isUserAtBottom = true;
                    this.autoScrollEnabled = true;
                    
                    // Double-check after a brief delay to ensure we stay at bottom
                    // This handles cases where content might still be loading/rendering
                    setTimeout(() => {
                        const newScrollHeight = container[0].scrollHeight;
                        if (this.isUserAtBottom) {
                            container.scrollTop(newScrollHeight);
                        }
                    }, 50);
                    
                    // One more check after images/embeds might load
                    setTimeout(() => {
                        const finalScrollHeight = container[0].scrollHeight;
                        if (this.isUserAtBottom) {
                            container.scrollTop(finalScrollHeight);
                        }
                    }, 200);
                });
            } else {
                // User was scrolled up - preserve scroll position relative to content
                // Calculate the difference in scroll height to maintain relative position
                const newScrollHeight = container[0].scrollHeight;
                const heightDifference = newScrollHeight - previousScrollHeight;
                
                requestAnimationFrame(() => {
                    if (heightDifference > 0 && previousScrollHeight > 0) {
                        // New messages were added - adjust scroll position to maintain relative position
                        container.scrollTop(previousScrollTop + heightDifference);
                    } else {
                        // First load or no height change - use previous position
                        container.scrollTop(previousScrollTop);
                    }
                    this.isUserAtBottom = false;
                });
            }
            
            // Update last scroll height
            this.lastScrollHeight = container[0].scrollHeight;
        },
        
        /**
         * Attach user context menu handlers
         */
        attachUserContextMenuHandlers: function() {
            // Click on user name to show context menu
            $(document).off('click', '.clickable-user').on('click', '.clickable-user', (e) => {
                e.stopPropagation();
                const userHandle = $(e.target).data('user-handle');
                const messageId = $(e.target).data('message-id');
                
                // Don't show menu for own name
                if (userHandle === this.config.userHandle) {
                    return;
                }
                
                this.showUserContextMenu(e.target, userHandle, messageId);
            });
            
            // Close menu when clicking outside
            $(document).on('click', (e) => {
                if (!$(e.target).closest('.user-context-menu, .clickable-user, .clickable-online-user').length) {
                    this.hideUserContextMenu();
                }
            });
        },
        
        /**
         * Attach click handlers for online users list
         */
        attachOnlineUserClickHandlers: function() {
            // Remove existing handlers to avoid duplicates
            $(document).off('click', '.clickable-online-user');
            
            // Click on online user badge to show context menu
            $(document).on('click', '.clickable-online-user', (e) => {
                e.stopPropagation();
                const badge = $(e.target).closest('.clickable-online-user');
                const userHandle = badge.data('user-handle');
                const isOwn = userHandle === this.config.userHandle;
                
                // Show context menu (with limited options for own user)
                this.showUserContextMenu(badge[0], userHandle, null, isOwn);
            });
        },
        
        /**
         * Show user context menu
         */
        showUserContextMenu: function(triggerElement, userHandle, messageId, isOwnUser = false) {
            // Hide any existing menu
            this.hideUserContextMenu();
            
            // Don't show menu for own user in chat messages (but allow in online list)
            if (isOwnUser === false && userHandle === this.config.userHandle) {
                return;
            }
            
            // Create menu if it doesn't exist
            let menu = $('#user-context-menu');
            if (menu.length === 0) {
                menu = $('<div id="user-context-menu" class="user-context-menu"></div>');
                $('body').append(menu);
            }
            
            // Build menu items - show limited options for own user
            let menuItems = `
                <div class="user-context-menu-item" data-action="profile" data-user="${this.escapeHtml(userHandle)}">
                    <i class="fas fa-user"></i> Profile
                </div>
            `;
            
            // Only show IM and Report for other users
            if (!isOwnUser && userHandle !== this.config.userHandle) {
                menuItems += `
                    <div class="user-context-menu-item" data-action="im" data-user="${this.escapeHtml(userHandle)}">
                        <i class="fas fa-comments"></i> IM
                    </div>
                    <div class="user-context-menu-item" data-action="report" data-user="${this.escapeHtml(userHandle)}" data-message-id="${messageId || ''}">
                        <i class="fas fa-flag"></i> Report
                    </div>
                `;
            }
            
            menu.html(menuItems);
            
            // Position menu near the clicked element
            const $trigger = $(triggerElement);
            const offset = $trigger.offset();
            const triggerHeight = $trigger.outerHeight();
            
            menu.css({
                top: offset.top + triggerHeight + 5,
                left: offset.left,
                display: 'block'
            });
            
            // Adjust if menu goes off screen
            setTimeout(() => {
                const menuWidth = menu.outerWidth();
                const menuHeight = menu.outerHeight();
                const windowWidth = $(window).width();
                const windowHeight = $(window).height();
                const scrollTop = $(window).scrollTop();
                
                let left = offset.left;
                let top = offset.top + triggerHeight + 5;
                
                // Adjust horizontal position
                if (left + menuWidth > windowWidth) {
                    left = windowWidth - menuWidth - 10;
                }
                if (left < 10) {
                    left = 10;
                }
                
                // Adjust vertical position
                if (top + menuHeight > windowHeight + scrollTop) {
                    top = offset.top - menuHeight - 5;
                }
                if (top < scrollTop + 10) {
                    top = scrollTop + 10;
                }
                
                menu.css({ top, left });
            }, 0);
            
            // Attach menu item click handlers
            menu.find('.user-context-menu-item').on('click', (e) => {
                e.stopPropagation();
                const action = $(e.target).closest('.user-context-menu-item').data('action');
                const targetUser = $(e.target).closest('.user-context-menu-item').data('user');
                const targetMessageId = $(e.target).closest('.user-context-menu-item').data('message-id');
                
                this.handleUserContextMenuAction(action, targetUser, targetMessageId);
                this.hideUserContextMenu();
            });
        },
        
        /**
         * Hide user context menu
         */
        hideUserContextMenu: function() {
            $('#user-context-menu').hide();
        },
        
        /**
         * Handle user context menu action
         */
        handleUserContextMenuAction: function(action, userHandle, messageId) {
            switch(action) {
                case 'profile':
                    this.viewProfile(userHandle);
                    break;
                case 'im':
                    this.sendImToUser(userHandle);
                    break;
                case 'report':
                    this.reportUser(userHandle, messageId);
                    break;
            }
        },
        
        /**
         * Report a user
         */
        reportUser: async function(userHandle, messageId) {
            const reason = await this.showPrompt(
                `Report ${userHandle}?<br><br>Please provide a reason:`,
                'Report User',
                '',
                true
            );
            if (!reason || reason.trim() === '') {
                return;
            }
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    path: 'user-management.php',
                    action: 'report',
                    reported_user: userHandle,
                    message_id: messageId,
                    reason: reason.trim()
                }),
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert('User reported successfully. Thank you for helping keep the community safe.', 'Report Submitted', 'success');
                    } else {
                        this.showAlert('Failed to report user: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    this.showAlert('Error reporting user. Please try again.', 'Error', 'error');
                    console.error('Report user error:', xhr);
                }
            });
        },
        
        /**
         * Attach event handlers for moderation actions
         */
        attachModerationHandlers: function() {
            // Hide message
            $(document).off('click', '.btn-mod-hide').on('click', '.btn-mod-hide', (e) => {
                e.stopPropagation();
                const messageId = $(e.target).closest('.btn-mod-hide').data('message-id');
                this.showConfirm('Hide this message? It will be hidden from regular users.', 'Hide Message').then((confirmed) => {
                    if (confirmed) {
                        this.hideMessage(messageId);
                    }
                });
            });
            
            // Edit message
            $(document).off('click', '.btn-mod-edit').on('click', '.btn-mod-edit', (e) => {
                e.stopPropagation();
                const messageId = $(e.target).closest('.btn-mod-edit').data('message-id');
                this.editMessage(messageId);
            });
            
            // Delete message
            $(document).off('click', '.btn-mod-delete').on('click', '.btn-mod-delete', (e) => {
                e.stopPropagation();
                const messageId = $(e.target).closest('.btn-mod-delete').data('message-id');
                this.showConfirm('Permanently delete this message? This action cannot be undone.', 'Delete Message').then((confirmed) => {
                    if (confirmed) {
                        this.deleteMessage(messageId);
                    }
                });
            });
        },
        
        /**
         * Hide a message (moderator action)
         */
        hideMessage: function(messageId) {
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=moderate.php&action=hide',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    message_id: messageId
                }),
                success: (response) => {
                    if (response.success) {
                        this.loadRoomMessages(); // Reload messages
                    } else {
                        this.showAlert('Failed to hide message: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    this.showAlert('Error hiding message. Please try again.', 'Error', 'error');
                    console.error('Hide message error:', xhr);
                }
            });
        },
        
        /**
         * Edit a message (admin action)
         */
        editMessage: function(messageId) {
            // Get current message text
            const messageDiv = $('.chat-message[data-message-id="${messageId}"]');
            const currentText = messageDiv.find('.message-text').text();
            
            // Show edit dialog
            this.showPrompt('Edit message:', 'Edit Message', currentText, true).then(async (newText) => {
                if (!newText || newText.trim() === '') {
                    await this.showAlert('Message cannot be empty', 'Error', 'error');
                    return;
                }
            
                $.ajax({
                    url: this.config.apiBase + '/proxy.php?path=moderate.php&action=edit',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        message_id: messageId,
                        cipher_blob: btoa(unescape(encodeURIComponent(newText.trim())))
                    }),
                    success: (response) => {
                        if (response.success) {
                            this.loadRoomMessages(); // Reload messages
                        } else {
                            this.showAlert('Failed to edit message: ' + (response.error || 'Unknown error'), 'Error', 'error');
                        }
                    },
                    error: (xhr) => {
                        this.showAlert('Error editing message. Please try again.', 'Error', 'error');
                        console.error('Edit message error:', xhr);
                    }
                });
            });
        },
        
        /**
         * Delete a message (admin action)
         */
        deleteMessage: function(messageId) {
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=moderate.php&action=delete',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    message_id: messageId
                }),
                success: (response) => {
                    if (response.success) {
                        this.loadRoomMessages(); // Reload messages
                    } else {
                        this.showAlert('Failed to delete message: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    this.showAlert('Error deleting message. Please try again.', 'Error', 'error');
                    console.error('Delete message error:', xhr);
                }
            });
        },
        
        /**
         * Create a mock message (admin action - impersonate another user)
         */
        createMockMessage: function() {
            this.showPrompt('Enter username to impersonate:', 'Create Mock Message', '').then(async (senderHandle) => {
                if (!senderHandle || senderHandle.trim() === '') {
                    return;
                }
                
                const message = await this.showPrompt('Enter message:', 'Create Mock Message', '', true);
                if (!message || message.trim() === '') {
                    return;
                }
                
                const confirmed = await this.showConfirm(
                    `Create message as "${senderHandle}"? This will appear as if sent by that user.`,
                    'Confirm Mock Message'
                );
                if (!confirmed) {
                    return;
                }
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=moderate.php&action=mock',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    room_id: this.currentRoom,
                    sender_handle: senderHandle.trim(),
                    message: message.trim()
                }),
                success: (response) => {
                    if (response.success) {
                        this.loadRoomMessages(); // Reload messages
                    } else {
                        this.showAlert('Failed to create mock message: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    this.showAlert('Error creating mock message. Please try again.', 'Error', 'error');
                    console.error('Mock message error:', xhr);
                }
            });
            });
        },
        
        /**
         * Initialize IM system
         */
        initImSystem: function() {
            // Toggle sidebar
            $('#im-toggle-btn').on('click', () => {
                this.toggleImSidebar();
            });
            
            // Close sidebar button
            $('#im-sidebar-close').on('click', () => {
                this.closeImSidebar();
            });
            
            // Tab switching
            $('.im-tab-btn').on('click', (e) => {
                const tab = $(e.target).closest('.im-tab-btn').data('im-tab');
                this.switchImTab(tab);
            });
            
            // Back button - go back to conversations or close if already there
            $('#im-back-btn').on('click', () => {
                if ($('#im-conversation-view').is(':visible')) {
                    this.showImConversations();
                } else {
                    this.closeImSidebar();
                }
            });
            
            // ESC key to close sidebar
            $(document).on('keydown', (e) => {
                if (e.key === 'Escape' && $('#im-sidebar').hasClass('active')) {
                    // Only close if we're not typing in an input
                    if (!$(e.target).is('input, textarea')) {
                        this.closeImSidebar();
                    }
                }
            });
            
            // Conversation item clicks
            $(document).on('click', '.im-conversation-item', (e) => {
                const otherUser = $(e.target).closest('.im-conversation-item').data('user');
                if (otherUser) {
                    this.openImConversation(otherUser);
                }
            });
            
            // Send message in conversation
            $('#im-send-btn').on('click', () => {
                this.sendImMessage();
            });
            
            $('#im-message-input').on('keypress', (e) => {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    this.sendImMessage();
                }
            });
            
            // Compose functionality removed
            
            // Load conversations
            this.loadImConversations();
            this.updateImBadge();
            
            // Refresh conversations every 10 seconds (only if WebSocket not available)
            // If WebSocket is connected, IM updates come via WebSocket
            if (!this.websocket || this.websocket.readyState !== WebSocket.OPEN) {
                this.imRefreshInterval = setInterval(() => {
                    if ($('#im-sidebar').hasClass('active')) {
                        this.loadImConversations();
                        this.updateImBadge();
                    }
                }, 10000);
            }
        },
        
        /**
         * Toggle IM sidebar
         */
        toggleImSidebar: function() {
            $('#im-sidebar').toggleClass('active');
            $('#im-toggle-btn').toggleClass('active');
            
            // If opening, reset to conversations view
            if ($('#im-sidebar').hasClass('active')) {
                this.showImConversations();
            }
        },
        
        /**
         * Close IM sidebar completely
         */
        closeImSidebar: function() {
            $('#im-sidebar').removeClass('active');
            $('#im-toggle-btn').removeClass('active');
            // Reset to conversations view for next time
            this.showImConversations();
        },
        
        /**
         * Switch IM tab (removed - no tabs anymore, just conversations)
         */
        switchImTab: function(tab) {
            // Always show conversations (compose removed)
            this.showImConversations();
        },
        
        /**
         * Show conversations view
         */
        showImConversations: function() {
            $('#im-conversation-view').hide();
            $('#im-compose-view').hide();
            $('#im-conversations').show();
            this.loadImConversations();
        },
        
        /**
         * Show compose view (removed - compose functionality removed)
         */
        showImCompose: function() {
            // Compose removed - just show conversations
            this.showImConversations();
        },
        
        /**
         * Load conversations list
         */
        loadImConversations: function() {
            if (!this.config.userHandle) {
                return;
            }
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php',
                method: 'GET',
                data: {
                    path: 'im.php',
                    action: 'conversations',
                    user: this.config.userHandle
                },
                success: (response) => {
                    if (response.success) {
                        this.renderImConversations(response.conversations || []);
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load conversations:', xhr);
                    $('#im-conversations-list').html('<div class="error-message">Failed to load conversations.</div>');
                }
            });
        },
        
        /**
         * Render conversations list
         */
        renderImConversations: function(conversations) {
            const container = $('#im-conversations-list');
            container.empty();
            
            if (conversations.length === 0) {
                container.html('<div class="no-messages">No conversations yet. Start a new message!</div>');
                return;
            }
            
            conversations.forEach((conv) => {
                let preview = '';
                try {
                    if (conv.last_message_blob) {
                        const base64Decoded = atob(conv.last_message_blob);
                        preview = decodeURIComponent(base64Decoded.replace(/\+/g, '%20'));
                        if (preview.length > 50) {
                            preview = preview.substring(0, 50) + '...';
                        }
                    }
                } catch (e) {
                    preview = '[Encrypted message]';
                }
                
                const isFromMe = conv.last_message_from === this.config.userHandle;
                const unreadBadge = conv.unread_count > 0 
                    ? `<span class="im-conversation-unread">${conv.unread_count}</span>` 
                    : '';
                
                const item = $(`
                    <div class="im-conversation-item" data-user="${this.escapeHtml(conv.other_user)}">
                        <div class="im-conversation-avatar">${this.getAvatar(conv.other_user)}</div>
                        <div class="im-conversation-info">
                            <div class="im-conversation-header-row">
                                <span class="im-conversation-user-name">${this.escapeHtml(conv.other_user)}</span>
                                <span class="im-conversation-time">${this.formatTime(conv.last_message_at)}</span>
                            </div>
                            <div class="im-conversation-preview">${isFromMe ? 'You: ' : ''}${this.escapeHtml(preview)}</div>
                        </div>
                        ${unreadBadge}
                    </div>
                `);
                container.append(item);
            });
        },
        
        /**
         * Open conversation with a user
         */
        openImConversation: function(otherUser) {
            this.currentImConversation = otherUser;
            
            // Update UI
            $('#im-conversations').hide();
            $('#im-conversation-view').show();
            $('#im-user-name').text(otherUser);
            
            // Check if user is online
            this.checkImUserStatus(otherUser);
            
            // Load messages
            this.loadImConversationMessages(otherUser);
            
            // Mark conversation as active
            $('.im-conversation-item').removeClass('active');
            $('.im-conversation-item[data-user="${otherUser}"]').addClass('active');
        },
        
        /**
         * Check if IM user is online
         */
        checkImUserStatus: function(userHandle) {
            // This would check presence - simplified for now
            $('#im-user-status').text(''); // Could show "Online" or "Offline"
        },
        
        /**
         * Load conversation messages
         */
        loadImConversationMessages: function(otherUser) {
            if (!this.config.userHandle) {
                return;
            }
            
            const container = $('#im-messages-container');
            container.html('<div class="loading-messages">Loading messages...</div>');
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php',
                method: 'GET',
                data: {
                    path: 'im.php',
                    action: 'conversation',
                    user: this.config.userHandle,
                    with: otherUser,
                    limit: 100
                },
                success: (response) => {
                    if (response.success) {
                        this.renderImConversationMessages(response.messages || [], otherUser);
                        // Mark messages as read
                        this.markImConversationAsRead(otherUser);
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load conversation:', xhr);
                    container.html('<div class="error-message">Failed to load messages.</div>');
                }
            });
        },
        
        /**
         * Render conversation messages
         */
        renderImConversationMessages: function(messages, otherUser) {
            const container = $('#im-messages-container');
            container.empty();
            
            if (messages.length === 0) {
                container.html('<div class="no-messages">No messages yet. Start the conversation!</div>');
                return;
            }
            
            messages.forEach(async (msg) => {
                const isOwn = msg.from_user === this.config.userHandle;
                let messageText = '';
                let isE2EE = false;
                
                try {
                    // Check if message is E2EE encrypted
                    if (msg.encryption_type === 'e2ee' && window.E2EE && window.E2EE.keyPair) {
                        try {
                            // Get sender's public key
                            let senderKey = window.KeyExchangeUI ? window.KeyExchangeUI.getPublicKey(msg.from_user) : null;
                            if (!senderKey) {
                                senderKey = await window.E2EE.getPublicKey(msg.from_user);
                                if (senderKey && window.KeyExchangeUI) {
                                    window.KeyExchangeUI.storePublicKey(msg.from_user, senderKey);
                                }
                            }
                            
                            if (senderKey) {
                                // Decrypt E2EE message
                                messageText = await window.E2EE.decryptMessage(msg.cipher_blob, senderKey);
                                isE2EE = true;
                            } else {
                                messageText = '[E2EE: Key exchange required]';
                            }
                        } catch (e) {
                            console.error('E2EE decryption failed:', e);
                            messageText = '[E2EE: Decryption failed]';
                        }
                    } else {
                        // Fallback to base64 decoding
                        const base64Decoded = atob(msg.cipher_blob);
                        messageText = decodeURIComponent(base64Decoded.replace(/\+/g, '%20'));
                    }
                } catch (e) {
                    messageText = '[Encrypted message]';
                }
                
                // Get read receipt status
                const isRead = msg.read_at && msg.read_at !== null && msg.read_at !== 'null';
                const readReceipt = window.ReadReceipts ? window.ReadReceipts.renderReadReceipt(msg.id, isRead, isOwn) : '';
                
                const e2eeBadge = isE2EE ? '<span class="e2ee-status encrypted" title="End-to-End Encrypted"><i class="fas fa-lock e2ee-icon"></i></span>' : '';
                
                const messageDiv = $(`
                    <div class="im-message ${isOwn ? 'own' : ''}" data-message-id="${msg.id}" data-read-at="${msg.read_at || ''}">
                        <div class="im-message-avatar">${this.getAvatar(isOwn ? this.config.userHandle : msg.from_user)}</div>
                        <div class="im-message-content">
                            <div class="im-message-header">
                                <span class="im-message-sender">${this.escapeHtml(msg.from_user)}</span>
                                <span class="im-message-time">${this.formatTime(msg.queued_at)}</span>
                                ${e2eeBadge}
                            </div>
                            <div class="im-message-text">${this.escapeHtml(messageText)}</div>
                            ${readReceipt}
                        </div>
                    </div>
                `);
                container.append(messageDiv);
                
                // Mark as read if viewing conversation
                if (!isOwn && this.currentImConversation === msg.from_user && window.ReadReceipts) {
                    window.ReadReceipts.markAsRead(msg.id, msg.from_user);
                }
            });
            
            // Scroll to bottom only if auto-scroll is enabled and modal is not open
            if (this.autoScrollEnabled && !this.youtubeModalOpen) {
                container.scrollTop(container[0].scrollHeight);
            }
        },
        
        /**
         * Send IM message in active conversation
         */
        async sendImMessage() {
            const input = $('#im-message-input');
            const message = input.val().trim();
            
            if (!message || !this.currentImConversation) {
                return;
            }
            
            let cipherBlob;
            let encryptionType = 'none';
            let nonce = null;
            
            // Try to use E2EE if available
            if (window.E2EE && window.E2EE.keyPair) {
                try {
                    // Get recipient's public key
                    let recipientKey = window.KeyExchangeUI ? window.KeyExchangeUI.getPublicKey(this.currentImConversation) : null;
                    
                    if (!recipientKey) {
                        // Try to get from server
                        recipientKey = await window.E2EE.getPublicKey(this.currentImConversation);
                        if (recipientKey && window.KeyExchangeUI) {
                            window.KeyExchangeUI.storePublicKey(this.currentImConversation, recipientKey);
                        }
                    }
                    
                    if (recipientKey) {
                        // Encrypt with E2EE
                        const encrypted = await window.E2EE.encryptMessage(message, recipientKey);
                        const encryptedData = JSON.parse(encrypted);
                        cipherBlob = encrypted; // Store full encrypted data
                        encryptionType = 'e2ee';
                        nonce = encryptedData.nonce;
                    } else {
                        // No public key available - request key exchange or use fallback
                        if (window.KeyExchangeUI) {
                            await window.KeyExchangeUI.requestKeyExchange(this.currentImConversation);
                        }
                        // Fallback to base64 encoding
                        cipherBlob = btoa(unescape(encodeURIComponent(message)));
                    }
                } catch (e) {
                    console.error('E2EE encryption failed, using fallback:', e);
                    // Fallback to base64 encoding
                    cipherBlob = btoa(unescape(encodeURIComponent(message)));
                }
            } else {
                // E2EE not available - use base64 encoding
                cipherBlob = btoa(unescape(encodeURIComponent(message)));
            }
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=im.php&action=send',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    from_user: this.config.userHandle,
                    to_user: this.currentImConversation,
                    cipher_blob: cipherBlob,
                    encryption_type: encryptionType,
                    nonce: nonce
                }),
                success: (response) => {
                    if (response.success) {
                        input.val('');
                        
                        // Stop typing indicator
                        if (window.TypingIndicators) {
                            window.TypingIndicators.stopTyping(this.currentImConversation);
                        }
                        
                        // If WebSocket is connected, wait for message via WebSocket
                        // Otherwise reload immediately
                        if (!this.websocket || this.websocket.readyState !== WebSocket.OPEN) {
                            this.loadImConversationMessages(this.currentImConversation);
                            this.loadImConversations();
                            this.updateImBadge();
                        } else {
                            // WebSocket will deliver the message - just update badge
                            this.updateImBadge();
                        }
                    } else {
                        this.showAlert('Failed to send message: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    this.showAlert('Error sending message. Please try again.', 'Error', 'error');
                    console.error('Send IM error:', xhr);
                }
            });
        },
        
        /**
         * Send composed IM (new message)
         */
        sendComposedIm: function() {
            const toUser = $('#im-to-user').val().trim();
            const message = $('#im-compose-message').val().trim();
            
            if (!toUser || !message) {
                this.showAlert('Please enter a recipient and message.', 'Missing Information', 'warning');
                return;
            }
            
            // Encrypt message
            const cipherBlob = btoa(unescape(encodeURIComponent(message)));
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=im.php&action=send',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    from_user: this.config.userHandle,
                    to_user: toUser,
                    cipher_blob: cipherBlob
                }),
                success: (response) => {
                    if (response.success) {
                        $('#im-to-user').val('');
                        $('#im-compose-message').val('');
                        this.openImConversation(toUser);
                        
                        // If WebSocket is connected, wait for message via WebSocket
                        // Otherwise reload immediately
                        if (!this.websocket || this.websocket.readyState !== WebSocket.OPEN) {
                            this.loadImConversations();
                            this.updateImBadge();
                        } else {
                            // WebSocket will deliver the message - just update badge
                            this.updateImBadge();
                        }
                    } else {
                        this.showAlert('Failed to send message: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    this.showAlert('Error sending message. Please try again.', 'Error', 'error');
                    console.error('Send IM error:', xhr);
                }
            });
        },
        
        /**
         * Search users for IM compose
         */
        searchImUsers: function() {
            const query = $('#im-to-user').val().trim();
            const suggestions = $('#im-user-suggestions');
            
            if (query.length < 2) {
                suggestions.removeClass('active').empty();
                return;
            }
            
            // This would search users - simplified for now
            // In production, call an API to search users
            suggestions.removeClass('active').empty();
        },
        
        /**
         * Mark conversation as read
         */
        markImConversationAsRead: function(otherUser) {
            // Mark all unread messages in this conversation as read
            // This would be done via API call
        },
        
        /**
         * Update IM badge with unread count
         */
        updateImBadge: function() {
            if (!this.config.userHandle) {
                return;
            }
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php',
                method: 'GET',
                data: {
                    path: 'im.php',
                    action: 'badge',
                    user: this.config.userHandle
                },
                success: (response) => {
                    if (response.success) {
                        const badge = $('#im-badge');
                        if (response.unread_count > 0) {
                            badge.text(response.unread_count).show();
                        } else {
                            badge.hide();
                        }
                    }
                },
                error: (xhr) => {
                    console.error('Failed to update IM badge:', xhr);
                }
            });
        },
        
        /**
         * Get avatar initial for a user
         */
        getAvatar: function(userHandle) {
            if (!userHandle) return '?';
            return userHandle.charAt(0).toUpperCase();
        },
        
        /**
         * Update mail badge with unread count (legacy - keeping for compatibility)
         */
        updateMailBadge: function() {
            this.updateImBadge();
        },
        
        /**
         * Load IM inbox (legacy - keeping for compatibility)
         */
        loadImInbox: function() {
            this.loadImConversations();
        },
        
        /**
         * Toggle IM panel (legacy - keeping for compatibility)
         */
        toggleImPanel: function() {
            this.toggleImSidebar();
        },
        
        /**
         * Send IM to user (legacy method - keeping for compatibility)
         */
        sendImToUser: function(userHandle) {
            this.toggleImSidebar();
            setTimeout(() => {
                this.switchImTab('compose');
                $('#im-to-user').val(userHandle);
                $('#im-compose-message').focus();
            }, 300);
        },
        
        /**
         * Send IM (legacy method - keeping for compatibility)
         */
        sendIm: function(userHandle, message) {
            if (!message || !message.trim()) {
                return;
            }
            
            const cipherBlob = btoa(unescape(encodeURIComponent(message.trim())));
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    path: 'im.php',
                    action: 'send',
                    from_user: this.config.userHandle,
                    to_user: userHandle,
                    cipher_blob: cipherBlob
                }),
                success: (response) => {
                    if (response.success) {
                        // If WebSocket is connected, wait for message via WebSocket
                        // Otherwise reload immediately
                        if (!this.websocket || this.websocket.readyState !== WebSocket.OPEN) {
                            this.loadImConversations();
                            this.updateImBadge();
                        } else {
                            // WebSocket will deliver the message - just update badge
                            this.updateImBadge();
                        }
                    }
                },
                error: (xhr) => {
                    console.error('Send IM error:', xhr);
                }
            });
        },
        
        /**
         * Mark IM as read (legacy method)
         */
        markImAsRead: function(imId) {
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=im.php&action=open',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    im_id: imId,
                    user_handle: this.config.userHandle
                }),
                success: () => {
                    this.loadImConversations();
                    this.updateImBadge();
                }
            });
        },
        
        /**
         * Update mail badge with unread count (legacy)
         */
        updateMailBadge: function() {
            this.updateImBadge();
        },
        
        /**
         * Update mail badge with unread count (legacy)
         */
        updateMailBadge: function() {
            this.updateImBadge();
        },
        
        /**
         * Update mail badge with unread count
         */
        updateMailBadge: function() {
            this.updateImBadge();
        },
        
        /**
         * Update mail badge with unread count
         */
        updateMailBadge: function() {
            if (!this.config.userHandle) {
                return;
            }
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php',
                method: 'GET',
                data: {
                    path: 'im.php',
                    action: 'badge',
                    user: this.config.userHandle
                },
                success: (response) => {
                    if (response.success) {
                        const badge = $('#mail-badge');
                        if (response.unread_count > 0) {
                            badge.text(response.unread_count).show();
                        } else {
                            badge.hide();
                        }
                    }
                }
            });
        },
        
        /**
         * Check for pending admin tasks and update badges
         */
        checkAdminNotifications: function() {
            // Only check if user is admin
            if (this.config.userRole !== 'administrator') {
                return;
            }
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=admin-notifications.php',
                method: 'GET',
                success: (response) => {
                    if (response && response.success && response.notifications) {
                        const notifs = response.notifications;
                        
                        // Update patch badge
                        const patchBadge = $('#badge-patches');
                        if (notifs.patches > 0) {
                            patchBadge.text(notifs.patches).show();
                        } else {
                            patchBadge.hide();
                        }
                        
                        // Update room requests badge
                        const roomRequestBadge = $('#badge-room-requests');
                        if (notifs.room_requests > 0) {
                            roomRequestBadge.text(notifs.room_requests).show();
                        } else {
                            roomRequestBadge.hide();
                        }
                        
                        const wordFilterRequestBadge = $('#badge-word-filter-requests');
                        if (notifs.word_filter_requests > 0) {
                            wordFilterRequestBadge.text(notifs.word_filter_requests).show();
                        } else {
                            wordFilterRequestBadge.hide();
                        }
                        
                        // Update reports badge (on moderation tab)
                        const reportsBadge = $('#badge-reports');
                        if (notifs.reports > 0) {
                            reportsBadge.text(notifs.reports).show();
                        } else {
                            reportsBadge.hide();
                        }
                    }
                },
                error: (xhr) => {
                    console.error('Failed to check admin notifications:', xhr);
                }
            });
        },
        
        /**
         * Load admin dashboard data
         */
        loadAdminDashboard: function() {
            // Check for admin notifications
            this.checkAdminNotifications();
            $.ajax({
                url: this.config.apiBase + '/proxy.php',
                method: 'GET',
                data: {
                    path: 'admin.php'
                },
                success: (response) => {
                    if (response.success) {
                        $('#queue-depth').text(response.telemetry.queue_depth || 0);
                        $('#escrow-count').text(response.telemetry.pending_escrow_requests || 0);
                        this.renderEscrowRequests(response.escrow_requests || []);
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load admin dashboard:', xhr);
                }
            });
            
            // Load patches immediately
            console.log('loadAdminDashboard: Calling loadPatches()');
            this.loadPatches();
            
            // Load online users
            this.loadOnlineUsersAdmin();
            
            // Load all users database
            this.loadAllUsers();
        },
        
        /**
         * Load all users (online and offline) for admin database view
         */
        loadAllUsers: function() {
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=user-management.php&action=all',
                method: 'GET',
                success: (response) => {
                    console.log('loadAllUsers response:', response);
                    if (response && response.success) {
                        this.renderUserDatabase(response.users || []);
                    } else {
                        const errorMsg = response && response.error ? response.error : 'Failed to load users.';
                        $('#user-database-list').html(`<div class="no-users" style="color: var(--error-color);">${this.escapeHtml(errorMsg)}</div>`);
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load all users:', xhr);
                    console.error('Response status:', xhr.status);
                    console.error('Response text:', xhr.responseText);
                    let errorMsg = 'Error loading users.';
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg = 'Error loading users: ' + xhr.responseJSON.error;
                        console.error('API error:', xhr.responseJSON.error);
                    } else if (xhr.responseText) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.error) {
                                errorMsg = 'Error loading users: ' + response.error;
                            }
                        } catch (e) {
                            // Not JSON, use default message
                            console.error('Failed to parse response:', e);
                        }
                    }
                    $('#user-database-list').html(`<div class="no-users" style="color: var(--error-color);">${this.escapeHtml(errorMsg)}</div>`);
                }
            });
        },
        
        /**
         * Render user database table
         */
        renderUserDatabase: function(users) {
            const container = $('#user-database-list');
            container.empty();
            
            if (users.length === 0) {
                container.html('<div class="no-users">No users found.</div>');
                return;
            }
            
            // Apply filters
            const filter = $('#user-filter-select').val();
            const searchTerm = $('#user-search-input').val().toLowerCase();
            
            let filteredUsers = users;
            
            if (filter === 'online') {
                filteredUsers = filteredUsers.filter(u => u.is_online);
            } else if (filter === 'offline') {
                filteredUsers = filteredUsers.filter(u => !u.is_online);
            } else if (filter === 'registered') {
                filteredUsers = filteredUsers.filter(u => !u.is_guest);
            } else if (filter === 'guests') {
                filteredUsers = filteredUsers.filter(u => u.is_guest);
            } else if (filter === 'banned') {
                filteredUsers = filteredUsers.filter(u => u.is_banned);
            } else if (filter === 'muted') {
                filteredUsers = filteredUsers.filter(u => u.is_muted);
            }
            
            if (searchTerm) {
                filteredUsers = filteredUsers.filter(u => 
                    (u.user_handle && u.user_handle.toLowerCase().includes(searchTerm)) ||
                    (u.email && u.email.toLowerCase().includes(searchTerm)) ||
                    (u.display_name && u.display_name.toLowerCase().includes(searchTerm))
                );
            }
            
            // Create table
            let tableHtml = `
                <table class="user-database-table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>User Handle</th>
                            <th>Display Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Current Room</th>
                            <th>IP Address</th>
                            <th>Location</th>
                            <th>Last Seen</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            filteredUsers.forEach((user) => {
                const statusClass = user.is_online ? 'status-online' : 'status-offline';
                const statusText = user.is_online ? '● Online' : '○ Offline';
                const roleBadge = user.is_guest ? '<span class="badge-guest">Guest</span>' : 
                                 user.role === 'administrator' ? '<span class="badge-admin">Admin</span>' :
                                 user.role === 'moderator' ? '<span class="badge-mod">Mod</span>' :
                                 '<span class="badge-user">User</span>';
                const bannedBadge = user.is_banned ? '<span class="badge-banned">Banned</span>' : '';
                const mutedBadge = user.is_muted ? '<span class="badge-muted">Muted</span>' : '';
                const location = user.geolocation ? 
                    `${user.geolocation.city || ''}, ${user.geolocation.country || ''}`.trim() : 
                    'Unknown';
                
                tableHtml += `
                    <tr class="user-row ${user.is_online ? 'user-online' : 'user-offline'}">
                        <td><span class="${statusClass}">${statusText}</span></td>
                        <td><strong class="clickable-user-name" data-user-handle="${this.escapeHtml(user.user_handle || 'Unknown')}" style="cursor: pointer; color: var(--blizzard-blue); text-decoration: underline;">${this.escapeHtml(user.user_handle || 'Unknown')}</strong> ${bannedBadge} ${mutedBadge}</td>
                        <td>${this.escapeHtml(user.display_name || '-')}</td>
                        <td>${this.escapeHtml(user.email || '-')}</td>
                        <td>
                            ${roleBadge}
                            ${!user.is_guest && user.user_id ? `
                                <select class="user-role-select" data-user-id="${user.user_id}" data-user-handle="${this.escapeHtml(user.user_handle)}" data-current-role="${user.role || 'user'}" style="margin-left: 0.5rem; padding: 0.25rem 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; font-size: 0.85rem;">
                                    <option value="user" ${(user.role || 'user') === 'user' ? 'selected' : ''}>User</option>
                                    <option value="moderator" ${user.role === 'moderator' ? 'selected' : ''}>Moderator</option>
                                    <option value="administrator" ${user.role === 'administrator' ? 'selected' : ''}>Administrator</option>
                                    <option value="trusted_admin" ${user.role === 'trusted_admin' ? 'selected' : ''}>Trusted Admin</option>
                                    <option value="owner" ${user.role === 'owner' ? 'selected' : ''}>Owner</option>
                                </select>
                            ` : ''}
                        </td>
                        <td>${this.escapeHtml(user.current_room || '-')}</td>
                        <td>${this.escapeHtml(user.ip_address || '-')}</td>
                        <td>${this.escapeHtml(location)}</td>
                        <td>${user.last_seen ? this.formatTime(user.last_seen) : '-'}</td>
                        <td>
                            <button class="btn-action btn-kick" data-user="${this.escapeHtml(user.user_handle)}" data-room="${this.escapeHtml(user.current_room || '')}" title="Kick">Kick</button>
                            <button class="btn-action btn-mute" data-user="${this.escapeHtml(user.user_handle)}" title="Mute">Mute</button>
                            ${user.role !== 'administrator' ? (user.is_banned ? `<button class="btn-action btn-unban" data-user="${this.escapeHtml(user.user_handle)}" data-ip="${this.escapeHtml(user.ip_address || '')}" title="Unban">Unban</button>` : `<button class="btn-action btn-ban" data-user="${this.escapeHtml(user.user_handle)}" data-ip="${this.escapeHtml(user.ip_address || '')}" title="Ban">Ban</button>`) : ''}
                            <button class="btn-action btn-im" data-user="${this.escapeHtml(user.user_handle)}" title="Send IM">IM</button>
                        </td>
                    </tr>
                `;
            });
            
            tableHtml += `
                    </tbody>
                </table>
                <div class="user-db-stats">
                    Showing ${filteredUsers.length} of ${users.length} users
                </div>
            `;
            
            container.html(tableHtml);
            
            // Attach action handlers
            this.attachUserDatabaseHandlers();
        },
        
        /**
         * Attach event handlers for user database actions
         */
        attachUserDatabaseHandlers: function() {
            // Click handler for usernames to view profile
            $(document).off('click', '.clickable-user-name').on('click', '.clickable-user-name', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const userHandle = $(e.target).data('user-handle');
                if (userHandle && userHandle !== 'Unknown') {
                    this.viewProfile(userHandle);
                }
            });
            
            $(document).off('click', '.btn-kick').on('click', '.btn-kick', (e) => {
                const userHandle = $(e.target).data('user');
                const roomId = $(e.target).data('room') || this.currentRoom;
                this.kickUser(userHandle, roomId);
            });
            
            $(document).off('click', '.btn-mute').on('click', '.btn-mute', (e) => {
                const userHandle = $(e.target).data('user');
                this.muteUser(userHandle);
            });
            
            $(document).off('click', '.btn-ban').on('click', '.btn-ban', (e) => {
                const userHandle = $(e.target).data('user');
                const ipAddress = $(e.target).data('ip');
                this.banUser(userHandle, ipAddress);
            });
            
            $(document).off('click', '.btn-unban').on('click', '.btn-unban', (e) => {
                const userHandle = $(e.target).data('user');
                const ipAddress = $(e.target).data('ip');
                this.unbanUser(userHandle, ipAddress);
            });
            
            $(document).off('click', '.btn-im').on('click', '.btn-im', (e) => {
                const userHandle = $(e.target).data('user');
            this.showPrompt(`Send IM to ${userHandle}:`, 'Send Instant Message', '', true).then((message) => {
                if (message) {
                    this.sendIm(userHandle, message);
                }
            });
            });
            
            // Role change handler
            $(document).off('change', '.user-role-select').on('change', '.user-role-select', (e) => {
                const select = $(e.target);
                const userId = select.data('user-id');
                const userHandle = select.data('user-handle');
                const currentRole = select.data('current-role');
                const newRole = select.val();
                
                if (newRole === currentRole) {
                    return; // No change
                }
                
                this.showConfirm(`Change ${userHandle}'s role from ${currentRole} to ${newRole}?`, 'Change User Role').then((confirmed) => {
                    if (confirmed) {
                        this.updateUserRole(userId, userHandle, newRole, select);
                    } else {
                        // Revert selection
                        select.val(currentRole);
                    }
                });
            });
        },
        
        /**
         * Update user role
         */
        updateUserRole: function(userId, userHandle, newRole, selectElement) {
            $.ajax({
                url: this.config.apiBase + '/proxy.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    path: 'user-management.php',
                    action: 'update-role',
                    user_id: userId,
                    user_handle: userHandle,
                    new_role: newRole
                }),
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert(`User role updated successfully. ${userHandle} is now a ${newRole}.`, 'Role Updated', 'success');
                        // Update the select element's data attribute
                        $(selectElement).data('current-role', newRole);
                        // Reload user list to reflect changes
                        this.loadAllUsers();
                    } else {
                        this.showAlert('Failed to update role: ' + (response.error || 'Unknown error'), 'Error', 'error');
                        // Revert selection
                        const currentRole = $(selectElement).data('current-role');
                        $(selectElement).val(currentRole);
                    }
                },
                error: (xhr) => {
                    this.showAlert('Error updating role. Please try again.', 'Error', 'error');
                    // Revert selection
                    const currentRole = $(selectElement).data('current-role');
                    $(selectElement).val(currentRole);
                    console.error('Update role error:', xhr);
                }
            });
        },
        
        /**
         * Switch admin category (top row)
         */
        switchAdminCategory: function(category) {
            // Update category buttons
            $('.admin-category-btn').removeClass('active');
            $(`.admin-category-btn[data-category="${category}"]`).addClass('active');
            
            // Hide all sub-tab groups
            $('.admin-subtab-group').hide();
            $('.admin-subtab-nav').hide();
            
            // Show sub-tabs for this category (if not overview)
            if (category !== 'overview') {
                const $subtabGroup = $(`.admin-subtab-group[data-category="${category}"]`);
                if ($subtabGroup.length > 0) {
                    $('.admin-subtab-nav').show();
                    $subtabGroup.show();
                    
                    // Activate first sub-tab
                    const $firstSubtab = $subtabGroup.find('.admin-subtab-btn').first();
                    if ($firstSubtab.length > 0) {
                        const firstTab = $firstSubtab.data('tab');
                        this.switchAdminTab(firstTab);
                    }
                } else {
                    // No sub-tabs, show category content directly
                    $('.admin-tab-pane').removeClass('active');
                    $(`#admin-tab-${category}`).addClass('active');
                }
            } else {
                // Overview - show directly
                $('.admin-tab-pane').removeClass('active');
                $('#admin-tab-overview').addClass('active');
            }
        },
        
        switchAdminTab: function(tabName) {
            // Update sub-tab buttons
            $('.admin-subtab-btn').removeClass('active');
            $(`.admin-subtab-btn[data-tab="${tabName}"]`).addClass('active');
            
            // Update legacy tab buttons (for backwards compatibility)
            $('.admin-tab-btn').removeClass('active');
            $(`.admin-tab-btn[data-tab="${tabName}"]`).addClass('active');
            
            // Update tab panes
            $('.admin-tab-pane').removeClass('active');
            $(`#admin-tab-${tabName}`).addClass('active');
            
            // Load tab-specific data if needed
            switch(tabName) {
                case 'word-filters':
                    this.loadWordFilters();
                    this.loadWordFilterRequests();
                    break;
                case 'database':
                    // Database tab - no auto-load needed
                    break;
                case 'users':
                    this.loadAllUsers();
                    break;
                case 'online':
                    this.loadOnlineUsersAdmin();
                    break;
                case 'patches':
                    this.loadPatches();
                    break;
                case 'room-requests':
                    this.loadRoomRequests();
                    break;
                case 'logs':
                    this.loadLogsList();
                    break;
                case 'websocket':
                    this.loadWebSocketManagement();
                    break;
                case 'file-storage':
                    this.loadFileStorageList();
                    break;
                case 'overview':
                    // Overview data is loaded with dashboard
                    break;
            }
        },
        
        /**
         * Load moderator dashboard
         */
        loadModeratorDashboard: function() {
            this.loadOnlineUsersModerator();
            this.loadFlaggedMessages();
        },
        
        /**
         * Switch moderator tab
         */
        switchModeratorTab: function(tabName) {
            $('.moderator-tab-pane').removeClass('active');
            $('.moderator-tab-btn').removeClass('active');
            $(`#moderator-tab-${tabName}`).addClass('active');
            $(`.moderator-tab-btn[data-tab="${tabName}"]`).addClass('active');
            
            // Load tab-specific data if needed
            switch(tabName) {
                case 'users':
                    this.loadOnlineUsersModerator();
                    break;
                case 'flagged':
                    this.loadFlaggedMessages();
                    break;
                case 'logs':
                    this.loadLogsList('moderator');
                    break;
            }
        },
        
        /**
         * Load list of available logs
         */
        loadLogsList: function(context = 'admin') {
            const prefix = context === 'moderator' ? '-moderator' : '';
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=log-viewer.php&action=list`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        // API returns current_logs and archived_logs arrays
                        const currentLogs = response.current_logs || [];
                        const archivedLogs = response.archived_logs || [];
                        this.renderLogsList(currentLogs, archivedLogs, prefix);
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load logs list:', xhr);
                    let errorMsg = 'Failed to load logs list.';
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg = xhr.responseJSON.error;
                    } else if (xhr.responseText) {
                        try {
                            const parsed = JSON.parse(xhr.responseText);
                            errorMsg = parsed.error || errorMsg;
                        } catch (e) {
                            errorMsg = xhr.statusText || errorMsg;
                        }
                    }
                    $(`#archived-logs-content${prefix}`).html(`<div class="error">${this.escapeHtml(errorMsg)}</div>`);
                }
            });
        },
        
        /**
         * Render logs list in select dropdown
         */
        renderLogsList: function(currentLogs, archivedLogs, prefix) {
            const select = $(`#log-file-select${prefix}`);
            select.empty();
            select.append('<option value="">Select a log file...</option>');
            
            // Add current logs
            let defaultLog = null;
            currentLogs.forEach(log => {
                const selected = log === 'error.log' ? 'selected' : '';
                if (log === 'error.log') {
                    defaultLog = log;
                }
                select.append(`<option value="${this.escapeHtml(log)}" ${selected}>${this.escapeHtml(log)} (Current)</option>`);
            });
            
            // Add archived logs to a separate section
            if (archivedLogs.length > 0) {
                select.append('<optgroup label="Archived Logs">');
                archivedLogs.forEach(log => {
                    select.append(`<option value="${this.escapeHtml(log)}">${this.escapeHtml(log)}</option>`);
                });
            }
            
            // Render archived logs list separately
            this.renderArchivedLogs(archivedLogs, prefix);
            
            // Automatically load error.log if available, otherwise load first log
            // Use setTimeout to ensure DOM is updated first
            setTimeout(() => {
                if (defaultLog) {
                    select.val(defaultLog);
                    this.viewLog(defaultLog, prefix);
                } else if (currentLogs.length > 0) {
                    select.val(currentLogs[0]);
                    this.viewLog(currentLogs[0], prefix);
                }
            }, 100);
        },
        
        /**
         * Render archived logs list
         */
        renderArchivedLogs: function(archivedLogs, prefix) {
            const container = $(`#archived-logs-content${prefix}`);
            container.empty();
            
            if (archivedLogs.length === 0) {
                container.html('<div class="no-messages">No archived logs found.</div>');
                return;
            }
            
            archivedLogs.forEach(logName => {
                const logItem = $(`
                    <div class="archived-log-item" style="padding: 0.5rem; border-bottom: 1px solid var(--border-color); cursor: pointer;">
                        <div style="font-weight: bold;">${this.escapeHtml(logName)}</div>
                    </div>
                `);
                logItem.on('click', () => {
                    $(`#log-file-select${prefix}`).val(logName);
                    this.viewLog(logName, prefix);
                });
                container.append(logItem);
            });
        },
        
        /**
         * View log file content
         */
        viewLog: function(logFile, prefix = '') {
            if (!logFile) {
                console.warn('viewLog called without logFile');
                return;
            }
            
            const limit = parseInt($(`#log-limit-input${prefix}`).val() || 1000);
            const container = $(`#log-content${prefix}`);
            
            if (container.length === 0) {
                console.error('Log content container not found:', `#log-content${prefix}`);
                return;
            }
            
            container.html('<div class="loading-messages">Loading log...</div>');
            
            const url = `${this.config.apiBase}/proxy.php?path=log-viewer.php&action=view&file=${encodeURIComponent(logFile)}&limit=${limit}&offset=0`;
            console.log('Loading log:', logFile, 'URL:', url);
            
            $.ajax({
                url: url,
                method: 'GET',
                success: (response) => {
                    console.log('Log API response:', response);
                    if (response && response.success) {
                        const content = response.content || [];
                        if (!Array.isArray(content)) {
                            console.error('Unexpected content format:', typeof content, content);
                            container.html('<div class="error">Invalid log format received. Expected array, got ' + this.escapeHtml(String(typeof content)) + '</div>');
                            return;
                        }
                        
                        if (content.length === 0) {
                            container.html('<div class="no-messages">Log file is empty.</div>');
                        } else {
                            // Show last N lines (most recent)
                            const displayLines = content.slice(-limit);
                            container.text(displayLines.join('\n'));
                            // Scroll to bottom after a brief delay to ensure content is rendered
                            setTimeout(() => {
                                if (container.length > 0 && container[0].scrollHeight) {
                                    container.scrollTop(container[0].scrollHeight);
                                }
                            }, 50);
                        }
                    } else {
                        const errorMsg = response?.error || 'Unknown error';
                        console.error('Log view error:', errorMsg, response);
                        container.html(`<div class="error">Failed to load log: ${this.escapeHtml(errorMsg)}</div>`);
                    }
                },
                error: (xhr) => {
                    console.error('Log API error:', xhr);
                    let errorMsg = 'Failed to load log file';
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg = xhr.responseJSON.error;
                    } else if (xhr.responseText) {
                        try {
                            const parsed = JSON.parse(xhr.responseText);
                            errorMsg = parsed.error || errorMsg;
                        } catch (e) {
                            errorMsg = xhr.statusText || errorMsg;
                        }
                    }
                    container.html(`<div class="error">${this.escapeHtml(errorMsg)}</div>`);
                }
            });
        },
        
        /**
         * Rotate log file
         */
        rotateLog: function(logFile, prefix = '') {
            if (!logFile) {
                this.showModal('Error', 'Please select a log file first.', 'error');
                return;
            }
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=log-viewer.php&action=rotate&file=${encodeURIComponent(logFile)}`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        this.showModal('Success', response.message || 'Log rotated successfully.', 'success');
                        // Reload logs list
                        this.loadLogsList(prefix ? 'moderator' : 'admin');
                        // Clear log content
                        $(`#log-content${prefix}`).html('');
                    } else {
                        this.showModal('Error', response.error || 'Failed to rotate log.', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Failed to rotate log file';
                    this.showModal('Error', errorMsg, 'error');
                }
            });
        },
        
        /**
         * Load online users for admin view
         */
        loadOnlineUsersAdmin: function() {
            const roomFilter = $('#room-filter-admin-select').val() || '';
            const url = roomFilter 
                ? '${this.config.apiBase}/proxy.php?path=user-management.php&action=room&room_id=${encodeURIComponent(roomFilter)}'
                : '${this.config.apiBase}/proxy.php?path=user-management.php&action=list';
            
            $.ajax({
                url: url,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        this.renderUsersList(response.users || [], 'users-admin-list');
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load users:', xhr);
                    $('#users-admin-list').html('<div class="no-messages">Failed to load users.</div>');
                }
            });
        },
        
        /**
         * Load online users for moderator view
         */
        loadOnlineUsersModerator: function() {
            const roomFilter = $('#room-filter-select').val() || '';
            const url = roomFilter 
                ? '${this.config.apiBase}/proxy.php?path=user-management.php&action=room&room_id=${encodeURIComponent(roomFilter)}'
                : '${this.config.apiBase}/proxy.php?path=user-management.php&action=list';
            
            $.ajax({
                url: url,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        this.renderUsersList(response.users || [], 'users-list');
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load users:', xhr);
                    $('#users-list').html('<div class="no-messages">Failed to load users.</div>');
                }
            });
        },
        
        /**
         * Render users list with management actions
         */
        renderUsersList: function(users, containerId) {
            const container = $('#' + containerId);
            container.empty();
            
            if (users.length === 0) {
                container.html('<div class="no-messages">No users online.</div>');
                return;
            }
            
            users.forEach((user) => {
                const userCard = $('<div>').addClass('user-card');
                
                // Avatar
                const avatar = user.avatar_url || user.avatar_data 
                    ? `<img src="${this.escapeHtml(user.avatar_url || 'data:image/png;base64,' + user.avatar_data)}" class="user-avatar-img" alt="Avatar">`
                    : `<div class="user-avatar-initial">${this.getAvatar(user.user_handle)}</div>`;
                
                // Geolocation info
                const geo = user.geolocation || {};
                const locationInfo = geo.country 
                    ? `<div class="user-location">
                        <span class="flag">${geo.flag || '🌐'}</span>
                        <span>${this.escapeHtml(geo.city || '')}, ${this.escapeHtml(geo.country || '')}</span>
                        ${geo.isp ? `<span class="isp">${this.escapeHtml(geo.isp)}</span>` : ''}
                       </div>`
                    : '';
                
                // User info
                const displayName = user.display_name || user.user_handle;
                const userInfo = `
                    <div class="user-info">
                        <div class="user-name">${this.escapeHtml(displayName)}</div>
                        <div class="user-handle">@${this.escapeHtml(user.user_handle)}</div>
                        <div class="user-room">Room: ${this.escapeHtml(user.room_id || 'Unknown')}</div>
                        <div class="user-ip">IP: ${this.escapeHtml(user.ip_address || 'Unknown')}</div>
                        ${locationInfo}
                        <div class="user-last-seen">Last seen: ${this.formatRelativeTime(user.last_seen)}</div>
                    </div>
                `;
                
                // Action buttons
                const isAdmin = this.config.userRole === 'administrator';
                const isModerator = this.config.userRole === 'moderator' || isAdmin;
                const actions = `
                    <div class="user-actions">
                        <button class="btn-small btn-profile" data-user="${this.escapeHtml(user.user_handle)}" title="View Profile">👤 Profile</button>
                        <button class="btn-small btn-im" data-user="${this.escapeHtml(user.user_handle)}" title="Send IM">💬 IM</button>
                        ${isModerator ? `
                            <button class="btn-small btn-kick" data-user="${this.escapeHtml(user.user_handle)}" data-room="${this.escapeHtml(user.room_id || '')}" title="Kick from room">👢 Kick</button>
                            <button class="btn-small btn-mute" data-user="${this.escapeHtml(user.user_handle)}" title="Mute user">🔇 Mute</button>
                        ` : ''}
                        ${isAdmin ? (user.is_banned ? `<button class="btn-small btn-unban" data-user="${this.escapeHtml(user.user_handle)}" data-ip="${this.escapeHtml(user.ip_address || '')}" title="Unban user">✅ Unban</button>` : `<button class="btn-small btn-ban" data-user="${this.escapeHtml(user.user_handle)}" data-ip="${this.escapeHtml(user.ip_address || '')}" title="Ban user">🚫 Ban</button>`) : ''}
                    </div>
                `;
                
                userCard.html(`
                    <div class="user-card-content">
                        <div class="user-avatar">${avatar}</div>
                        ${userInfo}
                        ${actions}
                    </div>
                `);
                
                container.append(userCard);
            });
            
            // Attach event handlers
            this.attachUserActionHandlers(containerId);
        },
        
        /**
         * Attach event handlers for user actions
         */
        attachUserActionHandlers: function(containerId) {
            const container = $('#' + containerId);
            
            // Profile button
            container.find('.btn-profile').on('click', (e) => {
                const userHandle = $(e.target).data('user');
                this.viewProfile(userHandle);
            });
            
            // IM button
            container.find('.btn-im').on('click', (e) => {
                const userHandle = $(e.target).data('user');
                this.sendImToUser(userHandle);
            });
            
            // Kick button
            container.find('.btn-kick').on('click', (e) => {
                const userHandle = $(e.target).data('user');
                const roomId = $(e.target).data('room');
                this.kickUser(userHandle, roomId);
            });
            
            // Mute button
            container.find('.btn-mute').on('click', (e) => {
                const userHandle = $(e.target).data('user');
                this.muteUser(userHandle);
            });
            
            // Ban button
            container.find('.btn-ban').on('click', (e) => {
                const userHandle = $(e.target).data('user');
                const ipAddress = $(e.target).data('ip');
                this.banUser(userHandle, ipAddress);
            });
            
            // Unban button
            container.find('.btn-unban').on('click', (e) => {
                const userHandle = $(e.target).data('user');
                const ipAddress = $(e.target).data('ip');
                this.unbanUser(userHandle, ipAddress);
            });
        },
        
        /**
         * View user profile
         */
        viewProfile: function(userHandle) {
            $('#profile-modal').addClass('active');
            $('#profile-modal-body').html('<div class="loading-messages">Loading profile...</div>');
            
            // Set title immediately (will be updated when profile loads)
            const title = $('#profile-modal-title');
            title.text(userHandle + "'s Profile");
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=profile.php&action=view&user=' + encodeURIComponent(userHandle),
                method: 'GET',
                success: (response) => {
                    console.log('Profile API response:', response);
                    if (response && response.success && response.profile) {
                        try {
                            // Always show public view, even for own profile (edit is done in settings)
                            this.renderProfile(response.profile, false, response.view_count || 0, response.public_gallery || []);
                        } catch (e) {
                            console.error('Error rendering profile:', e);
                            $('#profile-modal-body').html('<div class="no-messages">Error rendering profile: ' + this.escapeHtml(e.message) + '</div>');
                        }
                    } else {
                        const errorMsg = response && response.error ? response.error : 'Profile not found or not accessible.';
                        $('#profile-modal-body').html('<div class="no-messages">' + this.escapeHtml(errorMsg) + '</div>');
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load profile:', xhr);
                    let errorMsg = 'Failed to load profile.';
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg = xhr.responseJSON.error;
                    } else if (xhr.responseText) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.error) {
                                errorMsg = response.error;
                            }
                        } catch (e) {
                            // Ignore parse errors
                        }
                    }
                    $('#profile-modal-body').html('<div class="no-messages">' + this.escapeHtml(errorMsg) + '</div>');
                }
            });
        },
        
        /**
         * Render profile (view or edit mode)
         */
        renderProfile: function(profile, isOwner, viewCount, publicGallery) {
            // Ensure publicGallery is an array
            if (!publicGallery) {
                publicGallery = [];
            }
            
            const container = $('#profile-modal-body');
            const title = $('#profile-modal-title');
            
            // Always show public view (edit is done in settings page)
            title.text(`${profile.display_name || profile.user_handle}'s Profile`);
            this.renderProfileView(profile, viewCount, container, publicGallery);
        },
        
        /**
         * Render profile view (public view - always shown, even for own profile)
         */
        renderProfileView: function(profile, viewCount, container, publicGallery) {
            // Ensure publicGallery is an array
            if (!publicGallery) {
                publicGallery = [];
            }
            
            const isGuest = profile.is_guest || false;
            const isOwner = profile.user_handle === this.config.userHandle;
            
            // Get avatar URL
            let avatarUrl = '';
            if (profile.avatar_type === 'gallery' && profile.avatar_data) {
                avatarUrl = `${this.config.apiBase}/avatar-image.php?user=${encodeURIComponent(profile.user_handle)}&size=200`;
            } else if (profile.avatar_type === 'gravatar' && profile.gravatar_email) {
                avatarUrl = `${this.config.apiBase}/avatar-image.php?user=${encodeURIComponent(profile.user_handle)}&size=200`;
            } else if (profile.avatar_url) {
                avatarUrl = profile.avatar_url;
            }
            
            const avatar = avatarUrl 
                ? `<img src="${this.escapeHtml(avatarUrl)}" class="profile-avatar-large" alt="Avatar">`
                : `<div class="profile-avatar-large-initial">${this.getAvatar(profile.user_handle)}</div>`;
            
            const banner = profile.banner_url 
                ? `<div class="profile-banner" style="background-image: url('${this.escapeHtml(profile.banner_url)}');"></div>`
                : `<div class="profile-banner"></div>`;
            
            const guestBadge = isGuest ? '<span class="profile-guest-badge">Guest</span>' : '';
            
            // Render public gallery images
            let galleryHtml = '';
            if (publicGallery && publicGallery.length > 0) {
                galleryHtml = '<div class="profile-gallery-section" style="margin-top: 2rem;">';
                galleryHtml += '<h4 style="margin-bottom: 1rem;">Public Gallery</h4>';
                galleryHtml += '<div class="profile-gallery-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem;">';
                
                publicGallery.forEach(image => {
                    const imageUrl = `${this.config.apiBase}/gallery-image.php?user=${encodeURIComponent(profile.user_handle)}&id=${image.id}`;
                    galleryHtml += `
                        <div class="profile-gallery-item" style="position: relative; aspect-ratio: 1; overflow: hidden; border-radius: 8px; cursor: pointer;">
                            <img src="${this.escapeHtml(imageUrl)}" alt="${this.escapeHtml(image.filename || 'Gallery image')}" 
                                 style="width: 100%; height: 100%; object-fit: cover;"
                                 onclick="window.open('${this.escapeHtml(imageUrl)}', '_blank')">
                        </div>
                    `;
                });
                
                galleryHtml += '</div></div>';
            }
            
            // Add link to settings if viewing own profile
            const settingsLink = isOwner ? `
                <div style="margin-bottom: 1rem;">
                    <a href="#" class="btn-primary" id="go-to-settings-btn" onclick="App.switchView('settings'); App.closeProfileModal(); return false;">Edit Profile in Settings</a>
                </div>
            ` : '';
            
            const html = `
                ${banner}
                <div class="profile-content" style="overflow-y: auto; flex: 1; padding: 1.5rem;">
                    ${settingsLink}
                    <div class="profile-header">
                        ${avatar}
                        <div class="profile-header-info">
                            <h3 class="profile-name">${this.escapeHtml(profile.display_name || profile.user_handle)} ${guestBadge}</h3>
                            <div class="profile-handle">@${this.escapeHtml(profile.user_handle)}</div>
                            ${profile.status ? `<div class="profile-status status-${profile.status}">${this.escapeHtml(profile.status)}</div>` : ''}
                            ${profile.status_message ? `<div class="profile-status-message">${this.escapeHtml(profile.status_message)}</div>` : ''}
                        </div>
                    </div>
                    
                    ${!isGuest ? `
                    <div class="profile-stats">
                        <div class="profile-stat">
                            <div class="stat-value">${viewCount || 0}</div>
                            <div class="stat-label">Profile Views</div>
                        </div>
                        ${profile.join_date ? `
                        <div class="profile-stat">
                            <div class="stat-value">${this.formatRelativeTime(profile.join_date)}</div>
                            <div class="stat-label">Joined</div>
                        </div>
                        ` : ''}
                    </div>
                    ` : ''}
                    
                    ${profile.bio ? `<div class="profile-bio">${this.escapeHtml(profile.bio)}</div>` : ''}
                    
                    <div class="profile-details">
                        ${profile.location ? `<div class="profile-detail"><strong>Location:</strong> ${this.escapeHtml(profile.location)}</div>` : ''}
                        ${profile.website ? `<div class="profile-detail"><strong>Website:</strong> <a href="${this.escapeHtml(profile.website)}" target="_blank" rel="noopener">${this.escapeHtml(profile.website)}</a></div>` : ''}
                        ${isGuest ? `<div class="profile-detail profile-guest-note"><em>Guest accounts have limited profile features. <a href="/iChat/register.php">Register</a> for full access.</em></div>` : ''}
                    </div>
                    
                    ${galleryHtml}
                </div>
            `;
            
            container.html(html);
        },
        
        /**
         * Render profile edit (owner view)
         */
        renderProfileEdit: function(profile, container) {
            const avatar = profile.avatar_url || profile.avatar_data 
                ? `<img src="${this.escapeHtml(profile.avatar_url || 'data:image/png;base64,' + profile.avatar_data)}" class="profile-avatar-large" alt="Avatar" id="profile-avatar-preview">`
                : `<div class="profile-avatar-large-initial" id="profile-avatar-preview">${this.getAvatar(profile.user_handle)}</div>`;
            
            const banner = profile.banner_url 
                ? `<div class="profile-banner" id="profile-banner-preview" style="background-image: url('${this.escapeHtml(profile.banner_url)}');"></div>`
                : `<div class="profile-banner" id="profile-banner-preview"></div>`;
            
            const html = `
                ${banner}
                <div class="profile-content">
                    <form id="profile-edit-form">
                        <div class="profile-header">
                            ${avatar}
                            <div class="profile-header-info">
                                <h3 class="profile-name">Edit Profile</h3>
                            </div>
                        </div>
                        
                        <div class="profile-edit-section">
                            <h4>Basic Information</h4>
                            <div class="form-group">
                                <label for="profile-display-name">Display Name</label>
                                <input type="text" id="profile-display-name" name="display_name" value="${this.escapeHtml(profile.display_name || '')}" maxlength="100">
                            </div>
                            
                            <div class="form-group">
                                <label for="profile-bio">Bio</label>
                                <textarea id="profile-bio" name="bio" rows="4" maxlength="500">${this.escapeHtml(profile.bio || '')}</textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="profile-location">Location</label>
                                <input type="text" id="profile-location" name="location" value="${this.escapeHtml(profile.location || '')}" maxlength="255">
                            </div>
                            
                            <div class="form-group">
                                <label for="profile-website">Website</label>
                                <input type="url" id="profile-website" name="website" value="${this.escapeHtml(profile.website || '')}" maxlength="500">
                            </div>
                        </div>
                        
                        <div class="profile-edit-section">
                            <h4>Status</h4>
                            <div class="form-group">
                                <label for="profile-status">Status</label>
                                <select id="profile-status" name="status">
                                    <option value="online" ${profile.status === 'online' ? 'selected' : ''}>Online</option>
                                    <option value="away" ${profile.status === 'away' ? 'selected' : ''}>Away</option>
                                    <option value="busy" ${profile.status === 'busy' ? 'selected' : ''}>Busy</option>
                                    <option value="offline" ${profile.status === 'offline' ? 'selected' : ''}>Offline</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="profile-status-message">Status Message</label>
                                <input type="text" id="profile-status-message" name="status_message" value="${this.escapeHtml(profile.status_message || '')}" maxlength="255">
                            </div>
                        </div>
                        
                        <div class="profile-edit-section">
                            <h4>Privacy</h4>
                            <div class="form-group">
                                <label for="profile-visibility">Profile Visibility</label>
                                <select id="profile-visibility" name="profile_visibility">
                                    <option value="public" ${profile.profile_visibility === 'public' ? 'selected' : ''}>Public</option>
                                    <option value="private" ${profile.profile_visibility === 'private' ? 'selected' : ''}>Private</option>
                                    <option value="friends" ${profile.profile_visibility === 'friends' ? 'selected' : ''}>Friends Only</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="profile-edit-actions">
                            <button type="submit" class="btn-primary">Save Changes</button>
                            <button type="button" class="btn-secondary" id="profile-cancel-btn">Cancel</button>
                        </div>
                    </form>
                </div>
            `;
            
            container.html(html);
            
            // Attach form submit handler
            $('#profile-edit-form').on('submit', (e) => {
                e.preventDefault();
                this.saveProfile(profile.user_handle);
            });
            
            // Cancel button
            $('#profile-cancel-btn').on('click', () => {
                this.closeProfileModal();
            });
        },
        
        /**
         * Save profile changes
         */
        saveProfile: function(userHandle) {
            const formData = {
                display_name: $('#profile-display-name').val(),
                bio: $('#profile-bio').val(),
                location: $('#profile-location').val(),
                website: $('#profile-website').val(),
                status: $('#profile-status').val(),
                status_message: $('#profile-status-message').val(),
                profile_visibility: $('#profile-visibility').val(),
            };
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=profile.php&action=update&user=' + encodeURIComponent(userHandle),
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(formData),
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert('Profile updated successfully!', 'Success', 'success');
                        // Reload profile to show updated data
                        this.viewProfile(userHandle);
                    } else {
                        this.showAlert('Failed to update profile: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    this.showAlert('Error updating profile. Please try again.', 'Error', 'error');
                    console.error('Save profile error:', xhr);
                }
            });
        },
        
        /**
         * Close profile modal
         */
        closeProfileModal: function() {
            $('#profile-modal').removeClass('active');
            $('#profile-modal-body').empty();
        },
        
        /**
         * Send IM to a user
         */
        sendImToUser: function(userHandle) {
            this.showPrompt(`Send IM to ${userHandle}:`, 'Send Instant Message', '', true).then((message) => {
                if (!message) return;
            
                $.ajax({
                    url: this.config.apiBase + '/proxy.php?path=user-management.php&action=im',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        to_user: userHandle,
                        message: message
                    }),
                    success: (response) => {
                        if (response && response.success) {
                            this.showAlert('IM sent successfully!', 'Success', 'success');
                        } else {
                            this.showAlert('Failed to send IM: ' + (response.error || 'Unknown error'), 'Error', 'error');
                        }
                    },
                    error: (xhr) => {
                        this.showAlert('Error sending IM. Please try again.', 'Error', 'error');
                        console.error('Send IM error:', xhr);
                    }
                });
            });
        },
        
        /**
         * Kick user from room
         */
        kickUser: function(userHandle, roomId) {
            this.showConfirm(`Kick ${userHandle} from ${roomId}?`, 'Kick User').then((confirmed) => {
                if (!confirmed) return;
            
                $.ajax({
                    url: this.config.apiBase + '/proxy.php?path=user-management.php&action=kick',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        user_handle: userHandle,
                        room_id: roomId
                    }),
                    success: (response) => {
                        if (response && response.success) {
                            this.showAlert('User kicked successfully!', 'Success', 'success');
                            // Refresh user list
                            if (this.currentView === 'admin') {
                                this.loadOnlineUsersAdmin();
                            } else if (this.currentView === 'moderator') {
                                this.loadOnlineUsersModerator();
                            }
                        } else {
                            this.showAlert('Failed to kick user: ' + (response.error || 'Unknown error'), 'Error', 'error');
                        }
                    },
                    error: (xhr) => {
                        this.showAlert('Error kicking user. Please try again.', 'Error', 'error');
                        console.error('Kick user error:', xhr);
                    }
                });
            });
        },
        
        /**
         * Mute user
         */
        muteUser: function(userHandle) {
            this.showPrompt(`Mute ${userHandle}. Reason:`, 'Mute User', '', true).then(async (reason) => {
                if (!reason) return;
                const duration = await this.showPrompt('Duration (hours, leave empty for permanent):', 'Mute Duration', '');
                let expiresAt = null;
                if (duration && !isNaN(duration)) {
                    const hours = parseInt(duration);
                    expiresAt = new Date();
                    expiresAt.setHours(expiresAt.getHours() + hours);
                }
            
                $.ajax({
                    url: this.config.apiBase + '/proxy.php?path=user-management.php&action=mute',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        user_handle: userHandle,
                        reason: reason,
                        expires_at: expiresAt ? expiresAt.toISOString() : null
                    }),
                    success: (response) => {
                        if (response && response.success) {
                            this.showAlert('User muted successfully!', 'Success', 'success');
                        } else {
                            this.showAlert('Failed to mute user: ' + (response.error || 'Unknown error'), 'Error', 'error');
                        }
                    },
                    error: (xhr) => {
                        this.showAlert('Error muting user. Please try again.', 'Error', 'error');
                        console.error('Mute user error:', xhr);
                    }
                });
            });
        },
        
        /**
         * Ban user
         */
        banUser: function(userHandle, ipAddress) {
            this.showPrompt(`Ban ${userHandle}. Reason:`, 'Ban User', '', true).then(async (reason) => {
                if (!reason) return;
                const banIp = await this.showConfirm('Also ban IP address?', 'Ban IP Address');
                const duration = await this.showPrompt('Duration (hours, leave empty for permanent):', 'Ban Duration', '');
                const email = await this.showPrompt('Email address for unban link (optional):', 'Unban Email', '');
                
                let expiresAt = null;
                if (duration && !isNaN(duration)) {
                    const hours = parseInt(duration);
                    expiresAt = new Date();
                    expiresAt.setHours(expiresAt.getHours() + hours);
                }
            
                $.ajax({
                    url: this.config.apiBase + '/proxy.php?path=user-management.php&action=ban',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        user_handle: userHandle,
                        reason: reason,
                        ip_address: banIp ? ipAddress : null,
                        expires_at: expiresAt ? expiresAt.toISOString() : null,
                        email: email || null
                    }),
                    success: (response) => {
                        if (response && response.success) {
                            let message = 'User banned successfully!';
                            if (response.unban_url) {
                                message += `\n\nUnban URL: ${response.unban_url}\n\nCopy this URL and send it to the user via email.`;
                            }
                            this.showAlert(message, 'Success', 'success');
                            // Refresh user list
                            if (this.currentView === 'admin') {
                                this.loadAllUsers();
                            }
                        } else {
                            this.showAlert('Failed to ban user: ' + (response.error || 'Unknown error'), 'Error', 'error');
                        }
                    },
                    error: (xhr) => {
                        this.showAlert('Error banning user. Please try again.', 'Error', 'error');
                        console.error('Ban user error:', xhr);
                    }
                });
            });
        },
        
        /**
         * Unban user
         */
        unbanUser: function(userHandle, ipAddress) {
            this.showConfirm(`Unban ${userHandle}?`, 'Unban User').then((confirmed) => {
                if (!confirmed) return;
                
                $.ajax({
                    url: this.config.apiBase + '/proxy.php?path=user-management.php&action=unban',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        user_handle: userHandle,
                        ip_address: ipAddress || null
                    }),
                    success: (response) => {
                        if (response && response.success) {
                            this.showAlert('User unbanned successfully!', 'Success', 'success');
                            // Refresh user list
                            if (this.currentView === 'admin') {
                                this.loadAllUsers();
                            } else if (this.currentView === 'moderator') {
                                this.loadOnlineUsers();
                            }
                        } else {
                            this.showAlert('Failed to unban user: ' + (response.error || 'Unknown error'), 'Error', 'error');
                        }
                    },
                    error: (xhr) => {
                        this.showAlert('Error unbanning user. Please try again.', 'Error', 'error');
                        console.error('Unban user error:', xhr);
                    }
                });
            });
        },
        
        /**
         * Load available patches
         */
        loadPatches: function() {
            const container = $('#patches-list');
            container.html('<div class="loading-messages">Loading patches...</div>');
            
            console.log('Loading patches...');
            console.log('API Base:', this.config.apiBase);
            
            // Build URL with query parameters
            const url = this.config.apiBase + '/proxy.php?path=patch-status.php&action=list';
            console.log('Request URL:', url);
            
            $.ajax({
                url: url,
                method: 'GET',
                success: (response) => {
                    console.log('Patches response:', response);
                    if (response && response.success) {
                        console.log('Found patches:', response.patches);
                        this.renderPatches(response.patches || []);
                    } else {
                        console.warn('Response not successful:', response);
                        container.html('<div class="no-messages">No patches available or error loading patches.</div>');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Failed to load patches:', {
                        status: status,
                        error: error,
                        xhr: xhr,
                        responseText: xhr.responseText
                    });
                    container.html('<div class="no-messages">Unable to load patches. Check browser console for details.</div>');
                }
            });
        },
        
        /**
         * Render patches list
         */
        renderPatches: function(patches) {
            const container = $('#patches-list');
            container.empty();
            
            if (patches.length === 0) {
                container.html('<div class="no-messages">No patches available</div>');
                return;
            }
            
            patches.forEach((patch) => {
                const status = patch.applied ? 'applied' : 'pending';
                const patchItem = $('<div>').addClass('patch-item ' + status);
                
                const patchInfo = $('<div>').addClass('patch-info');
                patchInfo.html(`
                    <div class="patch-id">${this.escapeHtml(patch.patch_id || 'Unknown')}</div>
                    <div class="patch-description">${this.escapeHtml(patch.description || 'No description')}</div>
                    <div class="patch-meta">
                        <span>Version: ${this.escapeHtml(patch.version || '1.0.0')}</span>
                        ${patch.applied ? `<span>Applied: ${this.escapeHtml(patch.applied_date || 'Unknown')}</span>` : ''}
                        ${patch.log_url ? `<span><a href="${this.escapeHtml(patch.log_url)}" target="_blank" class="patch-log-link">View Log</a></span>` : ''}
                    </div>
                `);
                
                const patchActions = $('<div>').addClass('patch-actions');
                patchActions.html(`
                    <div class="patch-status ${status}">${status === 'applied' ? 'APPLIED' : 'PENDING'}</div>
                    ${!patch.applied ? `<button class="btn-primary apply-patch-btn" data-patch-id="${this.escapeHtml(patch.patch_id)}">Apply Patch</button>` : ''}
                    ${patch.applied && patch.has_rollback ? `<button class="btn-secondary rollback-patch-btn" data-patch-id="${this.escapeHtml(patch.patch_id)}" title="Rollback this patch to allow reapplication">Rollback</button>` : ''}
                `);
                
                patchItem.append(patchInfo);
                patchItem.append(patchActions);
                container.append(patchItem);
            });
            
            // Event handlers are attached via delegation in setupEventListeners
        },
        
        /**
         * Check database health
         */
        checkDatabaseHealth: function() {
            const container = $('#db-health-status');
            container.html('<div class="loading-messages">Checking database health...</div>');
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=db-repair.php&action=check',
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        this.renderDatabaseHealth(response);
                    } else {
                        container.html('<div class="no-messages">Failed to check database health.</div>');
                    }
                },
                error: (xhr) => {
                    console.error('Database health check error:', xhr);
                    container.html('<div class="no-messages">Error checking database health. Check console for details.</div>');
                }
            });
        },
        
        /**
         * Render database health status
         */
        renderDatabaseHealth: function(response) {
            const container = $('#db-health-status');
            container.empty();
            
            if (response.healthy) {
                container.html('<div class="success-message">✓ Database is healthy - no issues found!</div>');
            } else {
                let html = `<div class="warning-message"><strong>Found ${response.issue_count} issue(s):</strong></div><ul class="issue-list">`;
                response.issues.forEach((issue) => {
                    html += `<li class="issue-item severity-${issue.severity}">
                        <strong>${issue.type}</strong>: ${this.escapeHtml(issue.message)}
                    </li>`;
                });
                html += '</ul>';
                container.html(html);
            }
        },
        
        /**
         * Repair database
         */
        repairDatabase: function() {
            const container = $('#db-health-status');
            container.html('<div class="loading-messages">Repairing database...</div>');
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=db-repair.php&action=repair',
                method: 'POST',
                success: (response) => {
                    if (response && response.success) {
                        let html = '<div class="success-message">';
                        html += '<strong>Repair completed!</strong><br>';
                        html += 'Repaired: ${response.repaired_count}, Failed: ${response.failed_count}';
                        html += '</div>';
                        
                        if (response.results.repaired.length > 0) {
                            html += '<ul class="issue-list">';
                            response.results.repaired.forEach((item) => {
                                html += '<li class="issue-item severity-success">✓ ${this.escapeHtml(item.message)}</li>';
                            });
                            html += '</ul>';
                        }
                        
                        if (response.results.failed.length > 0) {
                            html += '<ul class="issue-list">';
                            response.results.failed.forEach((item) => {
                                html += `<li class="issue-item severity-error">✗ ${this.escapeHtml(item.error || 'Unknown error')}</li>`;
                            });
                            html += '</ul>';
                        }
                        
                        container.html(html);
                        
                        // Refresh health check after repair
                        setTimeout(() => {
                            this.checkDatabaseHealth();
                        }, 1000);
                    } else {
                        container.html('<div class="no-messages">Failed to repair database.</div>');
                    }
                },
                error: (xhr) => {
                    console.error('Database repair error:', xhr);
                    let errorMsg = 'Error repairing database. Check console for details.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    container.html('<div class="no-messages">' + this.escapeHtml(errorMsg) + '</div>');
                }
            });
        },
        
        /**
         * Apply a patch
         */
        applyPatch: function(patchId) {
            this.showConfirm(`Are you sure you want to apply patch "${patchId}"?`, 'Apply Patch').then((confirmed) => {
                if (!confirmed) {
                    return;
                }
            
                const btn = $(`.apply-patch-btn[data-patch-id="${patchId}"]`);
                btn.prop('disabled', true).text('Applying...');
            
                $.ajax({
                    url: this.config.apiBase + '/proxy.php?path=patch-apply.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        patch_id: patchId
                    }),
                    success: (response) => {
                        if (response && response.success) {
                            const duration = response.duration ? response.duration.toFixed(3) : 'N/A';
                            this.showAlert(`Patch "${patchId}" applied successfully!<br>Duration: ${duration}s`, 'Patch Applied', 'success');
                            this.loadPatches(); // Refresh list
                        } else {
                            this.showAlert('Failed to apply patch: ' + (response.error || 'Unknown error'), 'Error', 'error');
                            btn.prop('disabled', false).text('Apply Patch');
                        }
                    },
                    error: (xhr) => {
                        let errorMsg = 'Error applying patch. Please try again.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        this.showAlert(errorMsg, 'Error', 'error');
                        console.error('Apply patch error:', xhr);
                        btn.prop('disabled', false).text('Apply Patch');
                    }
                });
            });
        },
        
        /**
         * Rollback a patch (unapply it)
         */
        rollbackPatch: function(patchId) {
            if (!patchId) {
                return;
            }
            
            // Confirm rollback
            this.showConfirm(
                `Are you sure you want to rollback patch "${patchId}"? This will undo the patch changes and allow it to be reapplied.`,
                'Rollback Patch'
            ).then((confirmed) => {
                if (!confirmed) {
                    return;
                }
                
                // Disable button
                const btn = $(`.rollback-patch-btn[data-patch-id="${patchId}"]`);
                btn.prop('disabled', true).text('Rolling back...');
                
                $.ajax({
                    url: this.config.apiBase + '/proxy.php?path=patch-apply.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        action: 'rollback',
                        patch_id: patchId
                    }),
                    success: (response) => {
                        if (response && response.success) {
                            this.showAlert(
                                response.message || 'Patch rolled back successfully. It can now be reapplied.',
                                'Success',
                                'success'
                            );
                            this.loadPatches(); // Refresh list
                        } else {
                            this.showAlert('Failed to rollback patch: ' + (response.error || 'Unknown error'), 'Error', 'error');
                            btn.prop('disabled', false).text('Rollback');
                        }
                    },
                    error: (xhr) => {
                        let errorMsg = 'Error rolling back patch. Please try again.';
                        if (xhr.responseJSON && xhr.responseJSON.error) {
                            errorMsg = xhr.responseJSON.error;
                        } else if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        this.showAlert(errorMsg, 'Error', 'error');
                        console.error('Rollback patch error:', xhr);
                        btn.prop('disabled', false).text('Rollback');
                    }
                });
            });
        },
        
        /**
         * Render escrow requests
         */
        renderEscrowRequests: function(requests) {
            const escrowList = $('#escrow-list');
            escrowList.empty();
            
            if (requests.length === 0) {
                escrowList.html('<p class="text-muted">No escrow requests</p>');
                return;
            }
            
            requests.forEach((req) => {
                const escrowItem = $('<div>').addClass('escrow-item ' + req.status);
                escrowItem.html(`
                    <div class="message-header">
                        <span><strong>${this.escapeHtml(req.operator_handle)}</strong> - ${this.escapeHtml(req.room_id)}</span>
                        <span>${this.formatDate(req.requested_at)}</span>
                    </div>
                    <div class="message-content">${this.escapeHtml(req.justification)}</div>
                    <div class="message-header">
                        <span>Status: <strong>${this.escapeHtml(req.status)}</strong></span>
                    </div>
                `);
                escrowList.append(escrowItem);
            });
        },
        
        /**
         * Submit escrow request
         */
        submitEscrowRequest: function() {
            const roomId = $('#escrow-room').val().trim();
            const operatorHandle = $('#escrow-operator').val().trim();
            const justification = $('#escrow-justification').val().trim();
            
            if (!roomId || !operatorHandle || !justification) {
                this.showAlert('Please fill in all fields', 'Missing Information', 'warning');
                return;
            }
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=admin.php&action=escrow-request',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    room_id: roomId,
                    operator_handle: operatorHandle,
                    justification: justification
                }),
                success: (response) => {
                    if (response.success) {
                        this.showAlert('Escrow request submitted successfully', 'Success', 'success');
                        $('#escrow-form')[0].reset();
                        this.loadAdminDashboard();
                    } else {
                        this.showAlert('Failed to submit escrow request: ' + (response.message || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: () => {
                    this.showAlert('Error submitting escrow request. Please try again.', 'Error', 'error');
                }
            });
        },
        
        /**
         * Load flagged messages (moderator view)
         */
        loadFlaggedMessages: function() {
            // Placeholder - would connect to moderation API
            $('#flagged-list').html('<p class="text-muted">No flagged messages</p>');
        },
        
        /**
         * Load Area 51 secret data
         */
        loadArea51Data: function() {
            $('#area51-data').html('<p>Secret data loaded...</p>');
        },
        
        /**
         * Check server health status
         */
        checkHealth: function() {
            // Simplified health check
            const indicator = $('#health-status');
            indicator.removeClass('warning error').addClass('success');
            
            // In production, this would ping the actual health endpoint
        },
        
        /**
         * Start auto-refresh intervals
         */
        /**
         * Check admin notifications periodically
         */
        startAdminNotificationCheck: function() {
            // Check immediately
            this.checkAdminNotifications();
            
            // Then check every 30 seconds if admin
            if (this.config.userRole === 'administrator') {
                setInterval(() => {
                    if (this.currentView === 'admin') {
                        this.checkAdminNotifications();
                    }
                }, 30000); // Check every 30 seconds
            }
        },
        
        /**
         * Connect to WebSocket server for real-time messaging
         */
        connectWebSocket: function() {
            if (!this.config.websocket || !this.config.websocket.enabled) {
                console.log('[WS] WebSocket disabled, falling back to polling');
                this.startAutoRefresh();
                return;
            }
            
            // Ensure we have a current room set before connecting
            if (!this.currentRoom) {
                this.currentRoom = this.config.defaultRoom || 'lobby';
            }
            
            // Fetch WebSocket token from server (session-based, secure)
            $.ajax({
                url: `${this.config.apiBase}/websocket-token.php`,
                method: 'GET',
                timeout: 5000,
                success: (response) => {
                    if (response && response.success && response.token) {
                        this.connectWebSocketWithToken(response.token);
                    } else {
                        console.error('[WS] Failed to get WebSocket token:', response);
                        this.startAutoRefresh();
                    }
                },
                error: (xhr, status, error) => {
                    console.error('[WS] Error fetching WebSocket token:', error);
                    this.startAutoRefresh();
                }
            });
        },
        
        /**
         * Connect to WebSocket server with token
         */
        connectWebSocketWithToken: function(token) {
            const protocol = this.config.websocket.secure ? 'wss://' : 'ws://';
            const wsUrl = `${protocol}${this.config.websocket.host}:${this.config.websocket.port}`;
            
            // Build connection URL with token authentication
            const params = new URLSearchParams({
                user_handle: this.config.userHandle || '',
                token: token,
                room_id: this.currentRoom || 'lobby'
            });
            
            const fullUrl = `${wsUrl}?${params.toString()}`;
            
            console.log('[WS] Connecting to WebSocket server...');
            
            // Set a connection timeout to prevent hanging
            const connectTimeout = setTimeout(() => {
                if (this.websocket && this.websocket.readyState !== WebSocket.OPEN) {
                    console.warn('[WS] WebSocket connection timeout, falling back to polling');
                    if (this.websocket) {
                        this.websocket.close();
                        this.websocket = null;
                    }
                    if (!this.refreshInterval) {
                        this.startAutoRefresh();
                    }
                }
            }, 5000); // 5 second timeout
            
            try {
                this.websocket = new WebSocket(fullUrl);
                
                this.websocket.onopen = () => {
                    clearTimeout(connectTimeout);
                    console.log('[WS] Connected to WebSocket server');
                    this.websocketReconnectAttempts = 0;
                    
                    // Send initial presence update
                    this.sendWebSocketMessage({
                        type: 'presence_update',
                        status: 'online'
                    });
                };
                
                this.websocket.onmessage = (event) => {
                    try {
                        const message = JSON.parse(event.data);
                        this.handleWebSocketMessage(message);
                    } catch (error) {
                        console.error('[WS] Error parsing WebSocket message:', error);
                    }
                };
                
                this.websocket.onerror = (error) => {
                    clearTimeout(connectTimeout);
                    console.error('[WS] WebSocket error:', error);
                    // Fall back to polling if connection fails
                    if (!this.refreshInterval) {
                        this.startAutoRefresh();
                    }
                };
                
                this.websocket.onclose = (event) => {
                    clearTimeout(connectTimeout);
                    console.log(`[WS] WebSocket closed (code: ${event.code}, reason: ${event.reason || 'unknown'})`);
                    this.websocket = null;
                    
                    // Clear any pending reconnect timeout
                    if (this.websocketReconnectTimeout) {
                        clearTimeout(this.websocketReconnectTimeout);
                        this.websocketReconnectTimeout = null;
                    }
                    
                    // Attempt to reconnect if not a normal closure and page is still active
                    if (event.code !== 1000 && 
                        this.websocketReconnectAttempts < this.maxReconnectAttempts &&
                        document.visibilityState === 'visible') {
                        this.websocketReconnectAttempts++;
                        const delay = Math.min(1000 * Math.pow(2, this.websocketReconnectAttempts), 30000);
                        console.log(`[WS] Reconnecting in ${delay}ms (attempt ${this.websocketReconnectAttempts}/${this.maxReconnectAttempts})...`);
                        
                        this.websocketReconnectTimeout = setTimeout(() => {
                            // Only reconnect if page is still visible and not unloading
                            if (document.visibilityState === 'visible' && !document.hidden) {
                                this.connectWebSocket();
                            }
                        }, delay);
                    } else {
                        if (this.websocketReconnectAttempts >= this.maxReconnectAttempts) {
                            console.log('[WS] Max reconnection attempts reached, falling back to polling');
                        }
                        // Fall back to polling if WebSocket fails
                        if (!this.refreshInterval) {
                            this.startAutoRefresh();
                        }
                    }
                };
                
                // Cleanup on page unload
                $(window).on('beforeunload', () => {
                    this.clearAllIntervals();
                    this.disconnectWebSocket();
                    this.leaveRoom();
                });
                
            } catch (error) {
                console.error('[WS] Failed to create WebSocket connection:', error);
                console.log('[WS] Falling back to polling');
                this.startAutoRefresh();
            }
        },
        
        /**
         * Disconnect from WebSocket server
         */
        disconnectWebSocket: function() {
            if (this.websocketReconnectTimeout) {
                clearTimeout(this.websocketReconnectTimeout);
                this.websocketReconnectTimeout = null;
            }
            
            if (this.websocket) {
                this.websocket.close(1000, 'Client disconnecting');
                this.websocket = null;
            }
        },
        
        /**
         * Send message via WebSocket
         */
        sendWebSocketMessage: function(message) {
            if (this.websocket && this.websocket.readyState === WebSocket.OPEN) {
                this.websocket.send(JSON.stringify(message));
            }
        },
        
        /**
         * Handle incoming WebSocket messages
         */
        handleWebSocketMessage: function(message) {
            switch (message.type) {
                case 'connected':
                case 'room_joined':
                    console.log('[WS] Connection confirmed, loading initial messages');
                    // Always load initial messages when WebSocket connects
                    // This ensures we have messages from the database even if WebSocket is active
                    this.loadRoomMessages();
                    break;
                    
                case 'new_message':
                    // New message received - add to chat
                    if (message.message && message.message.room_id === this.currentRoom) {
                        this.addMessageToChat(message.message);
                    }
                    break;
                    
                case 'presence_update':
                    // Presence update - refresh online users
                    if (message.room_id === this.currentRoom) {
                        this.loadOnlineUsers();
                    }
                    break;
                    
                case 'new_im':
                    // New IM received - update conversations and badge
                    if (message.im && message.im.to_user === this.config.userHandle) {
                        // Reload conversations to show new message
                        this.loadImConversations();
                        this.updateImBadge();
                        
                        // If viewing the conversation, add message directly without reload
                        if (this.currentImConversation === message.im.from_user) {
                            this.addImMessageToChat(message.im);
                            // Mark as read since user is viewing the conversation
                            this.markImConversationAsRead(message.im.from_user);
                        }
                    }
                    break;
                    
                case 'im_delivered':
                    // IM was delivered to recipient
                    console.log(`[WS] IM ${message.im_id} delivered to ${message.to_user}`);
                    // Optionally update UI to show delivery status
                    break;
                    
                case 'pong':
                    // Keepalive response
                    break;
                    
                case 'room_joined':
                    // Successfully joined a room
                    console.log(`[WS] Joined room: ${message.room_id}`);
                    this.loadRoomMessages();
                    break;
                    
                case 'error':
                    console.error('[WS] Server error:', message.message);
                    break;
                    
                default:
                    console.log('[WS] Unknown message type:', message.type);
            }
        },
        
        /**
         * Show loading indicator
         */
        showLoading: function() {
            this.activeAjaxRequests++;
            $('#loading-indicator').show();
        },
        
        /**
         * Hide loading indicator
         */
        hideLoading: function() {
            this.activeAjaxRequests = Math.max(0, this.activeAjaxRequests - 1);
            if (this.activeAjaxRequests === 0) {
                $('#loading-indicator').hide();
            }
        },
        
        /**
         * Show loading indicator
         */
        showLoading: function() {
            this.activeAjaxRequests++;
            $('#loading-indicator').show();
        },
        
        /**
         * Hide loading indicator
         */
        hideLoading: function() {
            this.activeAjaxRequests = Math.max(0, this.activeAjaxRequests - 1);
            if (this.activeAjaxRequests === 0) {
                $('#loading-indicator').hide();
            }
        },
        
        /**
         * Add a single message to the chat (without full reload)
         */
        addMessageToChat: function(messageData) {
            // Decrypt message
            let messageText = '';
            try {
                const base64Decoded = atob(messageData.cipher_blob);
                const decodedWithSpaces = base64Decoded.replace(/\+/g, '%20');
                messageText = decodeURIComponent(decodedWithSpaces);
            } catch (e) {
                console.error('Failed to decode message:', e);
                messageText = '[Encrypted message]';
            }
            
            const isOwnMessage = messageData.sender_handle === this.config.userHandle;
            const isHidden = messageData.is_hidden || false;
            const isEdited = messageData.edited_at !== null && messageData.edited_at !== undefined;
            
            // Check if message already exists (prevent duplicates)
            const existingMessage = $(`[data-message-id="${messageData.id}"]`);
            if (existingMessage.length > 0) {
                return; // Message already displayed
            }
            
            // Check if user is moderator/admin
            const userRole = this.config.userRole || 'guest';
            const isModerator = userRole === 'moderator' || userRole === 'administrator';
            
            // Hide hidden messages from non-moderators
            if (!isModerator && isHidden) {
                return;
            }
            
            // Process message for display (word filter, smileys, etc.)
            const processedMessage = this.processMessageForDisplay(messageText, messageData.sender_handle);
            
            const messageDiv = $('<div>').addClass('chat-message' + (isOwnMessage ? ' own-message' : '') + (isHidden ? ' message-hidden' : ''));
            messageDiv.attr('data-message-id', messageData.id);
            
            // Build moderation buttons (for mods/admins)
            let moderationButtons = '';
            if (isModerator && !isHidden) {
                moderationButtons = `
                    <div class="message-moderation">
                        <button class="btn-mod-hide" data-message-id="${messageData.id}" title="Hide message">
                            <i class="fas fa-eye-slash"></i>
                        </button>
                        <button class="btn-mod-delete" data-message-id="${messageData.id}" title="Delete message">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                `;
            }
            
            // Get avatar URL
            const avatarUrl = this.getAvatarUrlForUser(messageData.sender_handle);
            
            // Check if message is from bot
            const isBot = messageData.sender_handle === 'Brain' || messageData.sender_handle === 'Pinky';
            if (isBot) {
                messageDiv.addClass('bot-message');
            }
            
            const editIndicator = isEdited ? `<span class="message-edited" title="Edited by ${this.escapeHtml(messageData.edited_by || 'moderator')}">(edited)</span>` : '';
            const botBadge = isBot ? `<span class="bot-badge">${this.escapeHtml(messageData.sender_handle)}</span>` : '';
            
            // Build message HTML (match structure from renderRoomMessages)
            const messageHtml = `
                <div class="message-avatar">
                    <img src="${avatarUrl}" alt="${this.escapeHtml(messageData.sender_handle || 'User')}" class="avatar-img" onerror="this.onerror=null; this.src='/iChat/api/avatar-image.php?user=' + encodeURIComponent('${this.escapeHtml(messageData.sender_handle || '')}') + '&size=50'; this.style.display='block';">
                </div>
                <div class="message-content-wrapper">
                    <div class="message-header">
                        <span class="message-sender clickable-user" data-user-handle="${this.escapeHtml(messageData.sender_handle || 'Unknown')}" data-message-id="${messageData.id}">${this.escapeHtml(messageData.sender_handle || 'Unknown')}</span>
                        ${botBadge}
                        <span class="message-time">${this.formatMessageTime ? this.formatMessageTime(messageData.queued_at) : (this.formatTime ? this.formatTime(messageData.queued_at) : new Date(messageData.queued_at).toLocaleTimeString())} ${editIndicator}</span>
                    </div>
                    <div class="message-text" style="color: ${this.getUserTextColor ? this.getUserTextColor(messageData.sender_handle) : '#000000'}">${processedMessage}</div>
                    ${messageData.media && messageData.media.length > 0 ? this.renderChatMedia ? this.renderChatMedia(messageData.media) : '' : ''}
                    ${moderationButtons}
                </div>
            `;
            
            messageDiv.html(messageHtml);
            
            // Append to messages container
            const container = $('#messages-container');
            if (container.length === 0) {
                console.error('[WS] Messages container not found!');
                return;
            }
            
            // Check if container only has loading/no-messages div (not real messages)
            const existingContent = container.children();
            const hasRealMessages = existingContent.filter('.chat-message').length > 0;
            
            if (!hasRealMessages && existingContent.length > 0) {
                // Check if it's just loading/no-messages divs
                const isOnlyPlaceholders = existingContent.length === existingContent.filter('.loading-messages, .no-messages').length;
                if (isOnlyPlaceholders) {
                    // Replace loading/no-messages with first message
                    container.empty();
                }
            }
            
            container.append(messageDiv);
            
            // Auto-scroll if user is at bottom
            if (this.isAtBottom() || this.isUserAtBottom) {
                requestAnimationFrame(() => {
                    container.scrollTop(container[0].scrollHeight);
                });
            }
            
            // Update last scroll height
            this.lastScrollHeight = container[0].scrollHeight;
        },
        
        /**
         * Clear all intervals to prevent memory leaks and hanging
         */
        clearAllIntervals: function() {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
            if (this.imRefreshInterval) {
                clearInterval(this.imRefreshInterval);
                this.imRefreshInterval = null;
            }
            if (this.healthCheckInterval) {
                clearInterval(this.healthCheckInterval);
                this.healthCheckInterval = null;
            }
            if (this.websocketStatusInterval) {
                clearInterval(this.websocketStatusInterval);
                this.websocketStatusInterval = null;
            }
            if (this.websocketReconnectTimeout) {
                clearTimeout(this.websocketReconnectTimeout);
                this.websocketReconnectTimeout = null;
            }
        },
        
        startAutoRefresh: function() {
            // Fallback: Refresh room messages every 5 seconds (only if WebSocket not available)
            if (this.websocket && this.websocket.readyState === WebSocket.OPEN) {
                return; // WebSocket is active, don't poll
            }
            
            // Clear existing interval first
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
            }
            
            this.refreshInterval = setInterval(() => {
                if (this.currentView === 'user' && (!this.websocket || this.websocket.readyState !== WebSocket.OPEN)) {
                    this.loadRoomMessages();
                }
            }, 5000);
            
            // Cleanup on page unload
            $(window).on('beforeunload', () => {
                this.clearAllIntervals();
                this.leaveRoom();
            });
            
            // IM system refreshes are handled in initImSystem
            
            // Health check every 30 seconds
            if (this.healthCheckInterval) {
                clearInterval(this.healthCheckInterval);
            }
            this.healthCheckInterval = setInterval(() => {
                this.checkHealth();
            }, 30000);
        },
        
        /**
         * Setup Area 51 unlock gesture (Ctrl+Shift+A)
         */
        setupArea51Unlock: function() {
            $(document).on('keydown', (e) => {
                if (e.ctrlKey && e.shiftKey && e.key === 'A') {
                    e.preventDefault();
                    this.unlockArea51();
                }
            });
            
            // Header gesture (double-click header)
            let headerClickCount = 0;
            $('.app-header').on('dblclick', () => {
                headerClickCount++;
                if (headerClickCount >= 3) {
                    this.unlockArea51();
                    headerClickCount = 0;
                }
            });
        },
        
        /**
         * Unlock Area 51 view
         */
        unlockArea51: function() {
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: {
                    area51_unlock: 'unlock'
                },
                success: () => {
                    location.reload();
                }
            });
        },
        
        /**
         * Toggle IM panel visibility
         */
        toggleImPanel: function() {
            $('#im-inbox').toggleClass('active');
        },
        
        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
        
        /**
         * Process message for display (smileys, ASCII art)
         */
        processMessageForDisplay: function(messageText) {
            if (!messageText) {
                return '';
            }
            
            // Check if message contains ASCII art (multiple lines)
            const lines = messageText.split('\n');
            if (lines.length >= 3) {
                // Likely ASCII art - wrap in pre tag
                return '<pre class="ascii-art">' + this.escapeHtml(messageText) + '</pre>';
            }
            
            // Process YouTube links FIRST (before HTML escaping)
            // This allows us to match raw URLs in the text
            let processed = this.processYouTubeLinks(messageText);
            
            // Check if YouTube links were found (if processed !== messageText, links were replaced)
            const hasYouTubeEmbeds = processed !== messageText;
            
            if (hasYouTubeEmbeds) {
                // Use a placeholder approach: replace YouTube embeds with placeholders, 
                // process the rest, then restore embeds
                const embedPlaceholders = [];
                let placeholderIndex = 0;
                
                // Find and replace YouTube embeds by properly matching nested divs
                let searchStart = 0;
                while (true) {
                    const embedStart = processed.indexOf('<div class="youtube-embed-container"', searchStart);
                    if (embedStart === -1) break;
                    
                    // Find the opening tag end
                    const tagEnd = processed.indexOf('>', embedStart) + 1;
                    let depth = 1;
                    let pos = tagEnd;
                    let embedEnd = -1;
                    
                    // Count div depth to find the matching closing tag
                    while (pos < processed.length && depth > 0) {
                        const nextDivOpen = processed.indexOf('<div', pos);
                        const nextDivClose = processed.indexOf('</div>', pos);
                        
                        if (nextDivClose === -1) break;
                        
                        if (nextDivOpen !== -1 && nextDivOpen < nextDivClose) {
                            depth++;
                            pos = nextDivOpen + 4;
                        } else {
                            depth--;
                            if (depth === 0) {
                                embedEnd = nextDivClose + 6;
                                break;
                            }
                            pos = nextDivClose + 6;
                        }
                    }
                    
                    if (embedEnd !== -1) {
                        const fullEmbed = processed.substring(embedStart, embedEnd);
                        const placeholder = `__YTEMBED${placeholderIndex}__`;
                        embedPlaceholders[placeholderIndex] = fullEmbed;
                        processed = processed.substring(0, embedStart) + placeholder + processed.substring(embedEnd);
                        placeholderIndex++;
                        searchStart = embedStart + placeholder.length;
                    } else {
                        break;
                    }
                }
                
                // Process text parts (escape HTML and convert smileys)
                // The placeholder won't be escaped because it doesn't contain HTML characters
                processed = this.processTextWithSmileys(processed);
                
                // Restore YouTube embeds (they're already HTML, don't escape them)
                // Replace in reverse order to avoid index conflicts
                for (let i = embedPlaceholders.length - 1; i >= 0; i--) {
                    const placeholder = `__YTEMBED${i}__`;
                    processed = processed.replace(placeholder, embedPlaceholders[i]);
                }
            } else {
                // No YouTube links, process normally
                processed = this.processTextWithSmileys(messageText);
            }
            
            return processed;
        },
        
        /**
         * Process text with HTML escaping and smiley conversion
         */
        processTextWithSmileys: function(text) {
            // Escape HTML first
            let processed = this.escapeHtml(text);
            
            // Common smiley mappings
            const smileyMap = {
                ':)': '😊',
                ':D': '😃',
                ':P': '😛',
                ';)': '😉',
                ':(': '😢',
                ':/': '😕',
                ':|': '😐',
                ':O': '😮',
                '<3': '❤️',
                '</3': '💔',
                ':*': '😘',
                'XD': '😆',
                '-_-': '😑',
                'o.O': '😕',
                '^_^': '😊',
                'T_T': '😭',
                '>:)': '😈',
                ':3': '😊',
                'B)': '😎',
            };
            
            // Replace smileys (sort by length descending to avoid partial matches)
            const sortedSmileys = Object.keys(smileyMap).sort((a, b) => b.length - a.length);
            sortedSmileys.forEach(smiley => {
                const regex = new RegExp(this.escapeRegex(smiley), 'g');
                processed = processed.replace(regex, smileyMap[smiley]);
            });
            
            return processed;
        },
        
        /**
         * Escape regex special characters
         */
        escapeRegex: function(text) {
            return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        },
        
        /**
         * Extract YouTube video ID from URL
         * Supports: youtube.com/watch?v=ID, youtube.com/embed/ID, youtu.be/ID, youtube.com/shorts/ID
         */
        extractYouTubeId: function(url) {
            const patterns = [
                /(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/shorts\/)([a-zA-Z0-9_-]{11})/,
                /youtube\.com\/watch\?.*v=([a-zA-Z0-9_-]{11})/
            ];
            
            for (const pattern of patterns) {
                const match = url.match(pattern);
                if (match && match[1]) {
                    return match[1];
                }
            }
            
            return null;
        },
        
        /**
         * Process YouTube links in message text and replace with embeds
         */
        processYouTubeLinks: function(text) {
            // Regex to find YouTube URLs (both long and short formats)
            const youtubeRegex = /(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})(?:\?[^\s]*)?/g;
            
            return text.replace(youtubeRegex, (match, videoId) => {
                // Generate unique ID for this embed
                const embedId = 'youtube-embed-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
                
                // Return YouTube embed HTML
                return `<div class="youtube-embed-container" data-video-id="${videoId}" data-embed-id="${embedId}">
                    <div class="youtube-thumbnail" style="position: relative; width: 100%; max-width: 560px; margin: 0.5rem 0; cursor: pointer; border-radius: 8px; overflow: hidden; background: #000;">
                        <img src="https://img.youtube.com/vi/${videoId}/maxresdefault.jpg" 
                             alt="YouTube Video" 
                             style="width: 100%; height: auto; display: block;"
                             onerror="this.src='https://img.youtube.com/vi/${videoId}/hqdefault.jpg'">
                        <div class="youtube-play-button" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 68px; height: 48px; background: rgba(23, 35, 34, 0.9); border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="white">
                                <path d="M8 5v14l11-7z"/>
                            </svg>
                        </div>
                        <div class="youtube-title" style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(transparent, rgba(0,0,0,0.8)); color: white; padding: 1rem 0.5rem 0.5rem; font-size: 0.9rem;">
                            <span class="youtube-link-text" style="display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${match}</span>
                        </div>
                    </div>
                </div>`;
            });
        },
        
        /**
         * Open YouTube video in modal
         */
        openYouTubeModal: function(videoId, embedId) {
            // Store current scroll position
            const container = $('#messages-container');
            this.scrollPositionBeforeModal = container.scrollTop();
            this.youtubeModalOpen = true;
            this.autoScrollEnabled = false;
            
            // Create modal HTML
            const modal = $(`
                <div id="youtube-modal" class="modal active" style="z-index: 10000;">
                    <div class="modal-content youtube-modal-content" style="max-width: 90vw; width: 900px; padding: 0;">
                        <div class="modal-header" style="padding: 1rem; border-bottom: 1px solid var(--border-color);">
                            <h2 style="margin: 0; font-size: 1.2rem;">YouTube Video</h2>
                            <button class="modal-close youtube-modal-close" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-dark);">&times;</button>
                        </div>
                        <div class="modal-body" style="padding: 0; position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden;">
                            <iframe id="youtube-modal-iframe" 
                                    src="https://www.youtube.com/embed/${videoId}?autoplay=1&rel=0" 
                                    frameborder="0" 
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                    allowfullscreen
                                    style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></iframe>
                        </div>
                    </div>
                </div>
            `);
            
            // Add to body
            $('body').append(modal);
            
            // Close handler
            modal.find('.youtube-modal-close').on('click', () => {
                this.closeYouTubeModal(embedId);
            });
            
            // Close on background click
            modal.on('click', (e) => {
                if ($(e.target).is('#youtube-modal')) {
                    this.closeYouTubeModal(embedId);
                }
            });
            
            // Close on ESC key
            $(document).on('keydown.youtubeModal', (e) => {
                if (e.key === 'Escape' && this.youtubeModalOpen) {
                    this.closeYouTubeModal(embedId);
                }
            });
        },
        
        /**
         * Close YouTube modal and restore scroll position
         */
        closeYouTubeModal: function(embedId) {
            const modal = $('#youtube-modal');
            const iframe = modal.find('#youtube-modal-iframe');
            
            // Stop video playback
            if (iframe.length) {
                iframe.attr('src', '');
            }
            
            // Remove modal
            modal.remove();
            
            // Restore scroll position
            const container = $('#messages-container');
            container.scrollTop(this.scrollPositionBeforeModal);
            
            // Re-enable auto-scroll
            this.youtubeModalOpen = false;
            this.autoScrollEnabled = true;
            
            // Remove ESC key handler
            $(document).off('keydown.youtubeModal');
        },
        
        /**
         * Attach YouTube video click handlers
         */
        attachYouTubeHandlers: function() {
            // Click handler for YouTube embeds
            $(document).off('click', '.youtube-thumbnail, .youtube-play-button').on('click', '.youtube-thumbnail, .youtube-play-button', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                const container = $(e.target).closest('.youtube-embed-container');
                const videoId = container.data('video-id');
                const embedId = container.data('embed-id');
                
                if (videoId) {
                    this.openYouTubeModal(videoId, embedId);
                }
            });
        },
        
        /**
         * Send Pinky's response after Brain's message
         */
        sendPinkyResponse: function(roomId) {
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=pinky-brain.php&action=get-response&room_id=' + encodeURIComponent(roomId),
                method: 'GET',
                success: (response) => {
                    if (response && response.success && response.pinky_response) {
                        // Send Pinky's message
                        const cipherBlob = btoa(unescape(encodeURIComponent(response.pinky_response)));
                        $.ajax({
                            url: this.config.apiBase + '/proxy.php?path=messages.php',
                            method: 'POST',
                            contentType: 'application/json',
                            data: JSON.stringify({
                                room_id: roomId,
                                sender_handle: 'Pinky',
                                cipher_blob: cipherBlob,
                                filter_version: 1
                            }),
                            success: () => {
                                // Reload messages to show Pinky's response
                                this.loadRoomMessages();
                            },
                            error: (xhr) => {
                                console.error('Failed to send Pinky response:', xhr);
                            }
                        });
                    }
                },
                error: (xhr) => {
                    console.error('Failed to get Pinky response:', xhr);
                }
            });
        },
        
        /**
         * Format date for display
         */
        formatDate: function(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            return date.toLocaleString();
        },
        
        /**
         * Format time for chat messages (shorter format)
         */
        formatRelativeTime: function(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            const now = new Date();
            const diffMs = now - date;
            const diffSecs = Math.floor(diffMs / 1000);
            const diffMins = Math.floor(diffSecs / 60);
            const diffHours = Math.floor(diffMins / 60);
            const diffDays = Math.floor(diffHours / 24);
            const diffMonths = Math.floor(diffDays / 30);
            const diffYears = Math.floor(diffDays / 365);
            
            if (diffSecs < 60) {
                return 'just now';
            } else if (diffMins < 60) {
                return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`;
            } else if (diffHours < 24) {
                return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`;
            } else if (diffDays < 30) {
                return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`;
            } else if (diffMonths < 12) {
                return `${diffMonths} month${diffMonths !== 1 ? 's' : ''} ago`;
            } else if (diffYears < 2) {
                return 'over a year ago';
            } else {
                return `${diffYears} years ago`;
            }
        },
        
        formatTime: function(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            const now = new Date();
            const diffMs = now - date;
            const diffMins = Math.floor(diffMs / 60000);
            
            if (diffMins < 1) return 'Just now';
            if (diffMins < 60) return diffMins + 'm ago';
            if (diffMins < 1440) return Math.floor(diffMins / 60) + 'h ago';
            
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
        },
        
        /**
         * Update user presence (heartbeat)
         */
        updatePresence: function() {
            // If WebSocket is connected, send presence update via WebSocket
            if (this.websocket && this.websocket.readyState === WebSocket.OPEN) {
                this.sendWebSocketMessage({
                    type: 'presence_update',
                    room_id: this.currentRoom,
                    status: 'online'
                });
                // Still update online users list
                this.loadOnlineUsers();
                return;
            }
            
            // Fallback to HTTP API
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=presence.php&action=heartbeat',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    room_id: this.currentRoom,
                    user_handle: this.config.userHandle
                }),
                success: () => {
                    // Presence updated
                },
                error: (xhr) => {
                    console.error('Failed to update presence:', xhr);
                }
            });
        },
        
        /**
         * Load online users for current room
         */
        loadOnlineUsers: function() {
            $.ajax({
                url: this.config.apiBase + '/proxy.php',
                method: 'GET',
                data: {
                    path: 'presence.php',
                    action: 'list',
                    room_id: this.currentRoom
                },
                success: (response) => {
                    if (response && response.success) {
                        this.renderOnlineUsers(response.users || []);
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load online users:', xhr);
                }
            });
        },
        
        /**
         * Render online users list
         */
        renderOnlineUsers: function(users) {
            const container = $('#online-users-list .online-users-list-content');
            const countElement = $('#online-count');
            
            countElement.text(users.length + ' online');
            
            if (users.length === 0) {
                container.html('<div class="users-loading">No users online</div>');
                return;
            }
            
            container.empty();
            
            users.forEach((user) => {
                const isOwn = user.handle === this.config.userHandle;
                const badge = $('<div>').addClass('user-badge clickable-online-user' + (isOwn ? ' own-user' : ''));
                
                // Add data attribute for context menu
                badge.attr('data-user-handle', user.handle);
                
                // Get avatar URL for user (served from PHP endpoint with thumbnail size)
                const avatarUrl = this.getAvatarUrlForUser(user.handle, 50);
                const avatar = $('<div>').addClass('user-avatar');
                avatar.html(`<img src="${avatarUrl}" alt="${this.escapeHtml(user.handle || 'User')}" class="avatar-img" onerror="this.onerror=null; this.src='/iChat/api/avatar-image.php?user=' + encodeURIComponent('${this.escapeHtml(user.handle || '')}') + '&size=50'; this.style.display='block';">`);
                
                badge.append(avatar);
                badge.append($('<span>').text(this.escapeHtml(user.handle)));
                
                container.append(badge);
            });
            
            // Attach click handlers for online users list
            this.attachOnlineUserClickHandlers();
        },
        
        /**
         * Start presence heartbeat (updates every 15 seconds)
         */
        startPresenceHeartbeat: function() {
            // Update immediately
            this.updatePresence();
            
            // Then update every 15 seconds
            this.presenceInterval = setInterval(() => {
                if (this.currentView === 'user') {
                    this.updatePresence();
                    this.loadOnlineUsers();
                }
            }, 15000);
        },
        
        /**
         * Switch to a different room
         */
        switchRoom: function(newRoom) {
            this.currentRoom = newRoom;
            $('#room-name').text(this.currentRoom);
            $('.room-container').attr('data-room', this.currentRoom);
            
            // If WebSocket is connected, send join_room message
            if (this.websocket && this.websocket.readyState === WebSocket.OPEN) {
                this.sendWebSocketMessage({
                    type: 'join_room',
                    room_id: newRoom
                });
            } else {
                // Fallback: load messages and update presence via HTTP
                this.loadRoomMessages();
                this.loadOnlineUsers();
                this.updatePresence();
            }
        },
        
        /**
         * Leave current room (cleanup presence)
         */
        leaveRoom: function() {
            // If WebSocket is connected, send leave_room message
            if (this.websocket && this.websocket.readyState === WebSocket.OPEN) {
                this.sendWebSocketMessage({
                    type: 'leave_room'
                });
            }
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=presence.php&action=leave',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    room_id: this.currentRoom,
                    user_handle: this.config.userHandle
                }),
                success: () => {
                    // Left room
                },
                error: (xhr) => {
                    console.error('Failed to leave room:', xhr);
                }
            });
        },
        
        /**
         * Show join room modal
         */
        showJoinRoomModal: function() {
            $('#join-room-modal').addClass('active');
            $('#join-room-form')[0].reset();
            $('#join-room-password-group').hide();
            $('#join-room-invite-code').focus();
        },
        
        /**
         * Join room with invite code
         */
        joinRoomWithCode: function() {
            const inviteCode = $('#join-room-invite-code').val().trim().toUpperCase();
            const password = $('#join-room-password').val() || null;
            
            if (!inviteCode) {
                this.showAlert('Please enter an invite code.', 'Error', 'error');
                return;
            }
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=room-requests.php&action=join`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    invite_code: inviteCode,
                    password: password
                }),
                success: (response) => {
                    if (response && response.success) {
                        // Check if password is required
                        if (response.password_required) {
                            $('#join-room-password-group').show();
                            $('#join-room-password').focus();
                            return;
                        }
                        
                        // Successfully joined room
                        const roomName = response.room_name;
                        const roomDisplayName = response.room_display_name || roomName;
                        
                        // Add room to selector if not already there
                        const roomSelect = $('#room-select');
                        const existingOption = roomSelect.find(`option[value="${roomName}"]`);
                        if (existingOption.length === 0) {
                            roomSelect.append($('<option>').val(roomName).text(roomDisplayName));
                        }
                        
                        // Close modal and switch to the new room
                        $('#join-room-modal').removeClass('active');
                        $('#join-room-form')[0].reset();
                        $('#join-room-password-group').hide();
                        
                        // Switch to the new room
                        roomSelect.val(roomName);
                        this.currentRoom = roomName;
                        $('#room-name').text(roomDisplayName);
                        $('.room-container').attr('data-room', roomName);
                        this.loadRoomMessages();
                        this.loadOnlineUsers();
                        this.updatePresence();
                        
                        this.showAlert(`Successfully joined room: ${roomDisplayName}`, 'Success', 'success');
                    } else {
                        this.showAlert(response.error || 'Failed to join room', 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const response = xhr.responseJSON;
                    if (response && response.error) {
                        // Check if password is required
                        if (response.error.includes('Password is required') || response.error.includes('password')) {
                            $('#join-room-password-group').show();
                            $('#join-room-password').focus();
                            return;
                        }
                        this.showAlert(response.error, 'Error', 'error');
                    } else {
                        this.showAlert('Failed to join room. Please try again.', 'Error', 'error');
                    }
                }
            });
        },
        
        /**
         * Show join room modal
         */
        showJoinRoomModal: function() {
            $('#join-room-modal').addClass('active');
            $('#join-room-form')[0].reset();
            $('#join-room-password-group').hide();
            $('#join-room-invite-code').focus();
        },
        
        /**
         * Join room with invite code
         */
        joinRoomWithCode: function() {
            const inviteCode = $('#join-room-invite-code').val().trim().toUpperCase();
            const password = $('#join-room-password').val() || null;
            
            if (!inviteCode) {
                this.showAlert('Please enter an invite code.', 'Error', 'error');
                return;
            }
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=room-requests.php&action=join`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    invite_code: inviteCode,
                    password: password
                }),
                success: (response) => {
                    if (response && response.success) {
                        // Successfully joined room
                        const roomName = response.room_name;
                        const roomDisplayName = response.room_display_name || roomName;
                        
                        // Add room to selector if not already there
                        const roomSelect = $('#room-select');
                        const existingOption = roomSelect.find(`option[value="${roomName}"]`);
                        if (existingOption.length === 0) {
                            roomSelect.append($('<option>').val(roomName).text(roomDisplayName));
                        }
                        
                        // Close modal
                        $('#join-room-modal').removeClass('active');
                        $('#join-room-form')[0].reset();
                        $('#join-room-password-group').hide();
                        
                        // Switch to the new room
                        roomSelect.val(roomName);
                        this.currentRoom = roomName;
                        $('#room-name').text(roomDisplayName);
                        $('.room-container').attr('data-room', roomName);
                        this.loadRoomMessages();
                        this.loadOnlineUsers();
                        this.updatePresence();
                        
                        this.showAlert(`Successfully joined room: ${roomDisplayName}`, 'Success', 'success');
                    } else {
                        this.showAlert(response.error || 'Failed to join room', 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const response = xhr.responseJSON;
                    if (response && response.error) {
                        // Check if password is required
                        if (response.error.includes('Password is required') || response.error.includes('password')) {
                            $('#join-room-password-group').show();
                            $('#join-room-password').focus();
                            return;
                        }
                        this.showAlert(response.error, 'Error', 'error');
                    } else {
                        this.showAlert('Failed to join room. Please try again.', 'Error', 'error');
                    }
                }
            });
        },
        
        /**
         * Show room request modal
         */
        showRoomRequestModal: function() {
            $('#room-request-modal').addClass('active');
            $('#room-request-form')[0].reset();
        },
        
        /**
         * Close room request modal
         */
        closeRoomRequestModal: function() {
            $('#room-request-modal').removeClass('active');
            $('#room-request-form')[0].reset();
        },
        
        /**
         * Submit room request
         */
        submitRoomRequest: function() {
            const roomName = $('#room-request-name').val().trim();
            const roomDisplayName = $('#room-request-display-name').val().trim();
            const password = $('#room-request-password').val();
            const description = $('#room-request-description').val().trim();
            
            if (!roomName || !roomDisplayName) {
                this.showAlert('Room name and display name are required.', 'Validation Error', 'error');
                return;
            }
            
            // Validate room name format
            if (!/^[a-zA-Z0-9_-]+$/.test(roomName)) {
                this.showAlert('Room name must contain only letters, numbers, underscores, and hyphens.', 'Validation Error', 'error');
                return;
            }
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=room-requests.php&action=create',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    room_name: roomName,
                    room_display_name: roomDisplayName,
                    password: password || null,
                    description: description || null
                }),
                success: (response) => {
                    // Close the room request modal first
                    this.closeRoomRequestModal();
                    
                    if (response && response.success) {
                        // Small delay to ensure modal is closed before showing alert
                        setTimeout(() => {
                            this.showAlert('Room request submitted successfully! An admin will review it shortly.', 'Request Submitted', 'success');
                        }, 100);
                    } else {
                        // Small delay to ensure modal is closed before showing alert
                        setTimeout(() => {
                            this.showAlert('Failed to submit request: ' + (response.error || 'Unknown error'), 'Error', 'error');
                        }, 100);
                    }
                },
                error: (xhr) => {
                    // Close the room request modal first
                    this.closeRoomRequestModal();
                    
                    const errorMsg = xhr.responseJSON?.error || 'Error submitting request. Please try again.';
                    // Small delay to ensure modal is closed before showing alert
                    setTimeout(() => {
                        this.showAlert(errorMsg, 'Error', 'error');
                    }, 100);
                }
            });
        },
        
        /**
         * Load room requests for admin
         */
        loadRoomRequests: function() {
            const statusFilter = $('#room-request-status-filter').val() || '';
            const url = statusFilter
                ? `${this.config.apiBase}/proxy.php?path=room-requests.php&action=list&status=${encodeURIComponent(statusFilter)}`
                : `${this.config.apiBase}/proxy.php?path=room-requests.php&action=list`;
            
            $.ajax({
                url: url,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        this.renderRoomRequests(response.requests || []);
                    } else {
                        $('#room-requests-list').html('<div class="no-messages">Failed to load room requests.</div>');
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load room requests:', xhr);
                    $('#room-requests-list').html('<div class="no-messages">Failed to load room requests.</div>');
                }
            });
        },
        
        /**
         * Render room requests list
         */
        renderRoomRequests: function(requests) {
            const container = $('#room-requests-list');
            
            if (requests.length === 0) {
                container.html('<div class="no-messages">No room requests found.</div>');
                return;
            }
            
            let html = '<div class="room-requests-grid">';
            requests.forEach((request) => {
                const statusClass = `status-${request.status}`;
                const statusLabel = request.status.charAt(0).toUpperCase() + request.status.slice(1);
                const requestedDate = new Date(request.requested_at).toLocaleString();
                const reviewedDate = request.reviewed_at ? new Date(request.reviewed_at).toLocaleString() : 'Not reviewed';
                const hasPassword = request.password_hash ? 'Yes' : 'No';
                const inviteCode = request.invite_code ? `<strong>${request.invite_code}</strong>` : 'N/A';
                
                html += `
                    <div class="room-request-card ${statusClass}">
                        <div class="room-request-header">
                            <h4>${this.escapeHtml(request.room_display_name)}</h4>
                            <span class="room-request-status ${statusClass}">${statusLabel}</span>
                        </div>
                        <div class="room-request-body">
                            <p><strong>Room ID:</strong> ${this.escapeHtml(request.room_name)}</p>
                            <p><strong>Requester:</strong> ${this.escapeHtml(request.requester_handle)}</p>
                            <p><strong>Password Protected:</strong> ${hasPassword}</p>
                            ${request.description ? `<p><strong>Description:</strong> ${this.escapeHtml(request.description)}</p>` : ''}
                            <p><strong>Invite Code:</strong> ${inviteCode}</p>
                            <p><strong>Requested:</strong> ${requestedDate}</p>
                            <p><strong>Reviewed:</strong> ${reviewedDate}</p>
                            ${request.reviewed_by ? `<p><strong>Reviewed By:</strong> ${this.escapeHtml(request.reviewed_by)}</p>` : ''}
                            ${request.admin_notes ? `<p><strong>Admin Notes:</strong> ${this.escapeHtml(request.admin_notes)}</p>` : ''}
                        </div>
                        <div class="room-request-actions">
                            ${request.status === 'pending' ? `
                                <button class="btn-primary approve-room-request-btn" data-request-id="${request.id}">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn-secondary deny-room-request-btn" data-request-id="${request.id}">
                                    <i class="fas fa-times"></i> Deny
                                </button>
                            ` : ''}
                            ${request.status === 'approved' && request.invite_code ? `
                                <div class="invite-code-display">
                                    <p><strong>Invite Code:</strong> <code>${request.invite_code}</code></p>
                                    <p>Share this code with friends to let them join the room.</p>
                                </div>
                            ` : ''}
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            container.html(html);
        },
        
        /**
         * Approve room request
         */
        approveRoomRequest: function(requestId) {
            this.showPrompt('Enter admin notes (optional):', 'Approve Room Request', '', true).then((adminNotes) => {
                $.ajax({
                    url: this.config.apiBase + '/proxy.php?path=room-requests.php&action=approve',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        request_id: requestId,
                        admin_notes: adminNotes || null
                    }),
                    success: (response) => {
                        if (response && response.success) {
                            this.showAlert(`Room request approved! Invite code: <strong>${response.invite_code}</strong>`, 'Request Approved', 'success');
                            this.loadRoomRequests();
                        } else {
                            this.showAlert('Failed to approve request: ' + (response.error || 'Unknown error'), 'Error', 'error');
                        }
                    },
                    error: (xhr) => {
                        const errorMsg = xhr.responseJSON?.error || 'Error approving request. Please try again.';
                        this.showAlert(errorMsg, 'Error', 'error');
                    }
                });
            }).catch(() => {
                // User cancelled
            });
        },
        
        /**
         * Deny room request
         */
        denyRoomRequest: function(requestId) {
            this.showPrompt('Enter reason for denial:', 'Deny Room Request', '', true).then((adminNotes) => {
                if (!adminNotes || adminNotes.trim() === '') {
                    this.showAlert('Please provide a reason for denial.', 'Validation Error', 'error');
                    return;
                }
                
                $.ajax({
                    url: this.config.apiBase + '/proxy.php?path=room-requests.php&action=deny',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        request_id: requestId,
                        admin_notes: adminNotes
                    }),
                    success: (response) => {
                        if (response && response.success) {
                            this.showAlert('Room request denied.', 'Request Denied', 'success');
                            this.loadRoomRequests();
                        } else {
                            this.showAlert('Failed to deny request: ' + (response.error || 'Unknown error'), 'Error', 'error');
                        }
                    },
                    error: (xhr) => {
                        const errorMsg = xhr.responseJSON?.error || 'Error denying request. Please try again.';
                        this.showAlert(errorMsg, 'Error', 'error');
                    }
                });
            }).catch(() => {
                // User cancelled
            });
        },
        
        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
        
        /**
         * Emoji Picker Functions
         */
        emojiPickerVisible: false,
        emojiCache: {},
        currentEmojiTab: 'recent',
        
        /**
         * Toggle emoji picker visibility
         */
        toggleEmojiPicker: function() {
            if (this.emojiPickerVisible) {
                this.hideEmojiPicker();
            } else {
                this.showEmojiPicker();
            }
        },
        
        /**
         * Show emoji picker
         */
        showEmojiPicker: function() {
            $('#emoji-picker').show();
            this.emojiPickerVisible = true;
            
            // Load emojis for current tab
            this.loadEmojisForTab(this.currentEmojiTab);
        },
        
        /**
         * Hide emoji picker
         */
        hideEmojiPicker: function() {
            $('#emoji-picker').hide();
            this.emojiPickerVisible = false;
            $('#emoji-search-input').val('');
        },
        
        /**
         * Switch emoji picker tab
         */
        switchEmojiTab: function(tab) {
            $('.emoji-tab').removeClass('active');
            $(`.emoji-tab[data-tab="${tab}"]`).addClass('active');
            this.currentEmojiTab = tab;
            this.loadEmojisForTab(tab);
        },
        
        /**
         * Load emojis for a specific tab
         */
        loadEmojisForTab: function(tab) {
            const content = $('#emoji-picker-content');
            content.html('<div class="emoji-loading">Loading emojis...</div>');
            
            let url = `${this.config.apiBase}/proxy.php?path=emojis.php&action=list&limit=100`;
            
            // Map tab names to categories
            const categoryMap = {
                'smileys': 'Smileys & Emotion',
                'people': 'People & Body',
                'animals': 'Animals & Nature',
                'food': 'Food & Drink',
                'travel': 'Travel & Places',
                'objects': 'Objects',
                'symbols': 'Symbols',
            };
            
            if (tab === 'recent') {
                url = `${this.config.apiBase}/proxy.php?path=emojis.php&action=recent&limit=50`;
            } else if (categoryMap[tab]) {
                url += `&category=${encodeURIComponent(categoryMap[tab])}`;
            }
            
            $.ajax({
                url: url,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        const emojis = tab === 'recent' ? (response.recent || []) : (response.emojis || []);
                        this.renderEmojis(emojis);
                    } else {
                        content.html('<div class="emoji-empty">Failed to load emojis.</div>');
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load emojis:', xhr);
                    content.html('<div class="emoji-empty">Failed to load emojis.</div>');
                }
            });
        },
        
        /**
         * Render emojis in the picker
         */
        renderEmojis: function(emojis) {
            const content = $('#emoji-picker-content');
            content.empty();
            
            if (emojis.length === 0) {
                content.html('<div class="emoji-empty">No emojis found.</div>');
                return;
            }
            
            emojis.forEach((emoji) => {
                const emojiBtn = $('<button>')
                    .addClass('emoji-item')
                    .attr('title', emoji.short_name || '')
                    .attr('data-emoji-id', emoji.id)
                    .text(emoji.emoji || '');
                content.append(emojiBtn);
            });
        },
        
        /**
         * Search emojis
         */
        searchEmojis: function(search) {
            if (!search || search.length < 2) {
                // Reload current tab if search is too short
                this.loadEmojisForTab(this.currentEmojiTab);
                return;
            }
            
            const content = $('#emoji-picker-content');
            content.html('<div class="emoji-loading">Searching...</div>');
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=emojis.php&action=list&search=${encodeURIComponent(search)}&limit=100`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        this.renderEmojis(response.emojis || []);
                    } else {
                        content.html('<div class="emoji-empty">No emojis found.</div>');
                    }
                },
                error: (xhr) => {
                    console.error('Failed to search emojis:', xhr);
                    content.html('<div class="emoji-empty">Search failed.</div>');
                }
            });
        },
        
        /**
         * Insert emoji into message input
         */
        insertEmoji: function(emoji) {
            const input = $('#message-input');
            const cursorPos = input[0].selectionStart || 0;
            const textBefore = input.val().substring(0, cursorPos);
            const textAfter = input.val().substring(cursorPos);
            
            input.val(textBefore + emoji + textAfter);
            
            // Set cursor position after inserted emoji
            const newPos = cursorPos + emoji.length;
            input[0].setSelectionRange(newPos, newPos);
            input.focus();
        },
        
        /**
         * Record emoji usage
         */
        recordEmojiUsage: function(emojiId) {
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=emojis.php&action=use`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ emoji_id: emojiId }),
                success: () => {
                    // Success - usage recorded
                },
                error: (xhr) => {
                    // Silently fail - not critical
                    console.warn('Failed to record emoji usage:', xhr);
                }
            });
        },
        
        /**
         * File Storage Management Functions
         */
        selectedFileStorageFile: null,
        
        /**
         * Load file storage list
         */
        loadFileStorageList: function() {
            const typeFilter = $('#file-storage-type-filter').val() || '';
            const container = $('#file-storage-list');
            container.html('<div class="loading-messages">Loading files...</div>');
            
            let url = `${this.config.apiBase}/proxy.php?path=file-storage.php&action=list`;
            if (typeFilter) {
                url += `&type=${encodeURIComponent(typeFilter)}`;
            }
            
            $.ajax({
                url: url,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        this.renderFileStorageList(response.files || []);
                    } else {
                        container.html('<div class="no-messages">Failed to load files.</div>');
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load file storage:', xhr);
                    container.html('<div class="no-messages">Failed to load files.</div>');
                }
            });
        },
        
        /**
         * Render file storage list
         */
        renderFileStorageList: function(files) {
            const container = $('#file-storage-list');
            container.empty();
            
            if (files.length === 0) {
                container.html('<div class="no-messages">No files found.</div>');
                return;
            }
            
            const table = $('<table>').addClass('file-storage-table').css({
                width: '100%',
                borderCollapse: 'collapse',
                marginTop: '1rem'
            });
            
            const thead = $('<thead>').append(
                $('<tr>').append(
                    $('<th>').text('Filename').css({ padding: '0.5rem', textAlign: 'left', borderBottom: '2px solid var(--border-color)' }),
                    $('<th>').text('Type').css({ padding: '0.5rem', textAlign: 'left', borderBottom: '2px solid var(--border-color)' }),
                    $('<th>').text('Size').css({ padding: '0.5rem', textAlign: 'right', borderBottom: '2px solid var(--border-color)' }),
                    $('<th>').text('Queued At').css({ padding: '0.5rem', textAlign: 'left', borderBottom: '2px solid var(--border-color)' }),
                    $('<th>').text('Status').css({ padding: '0.5rem', textAlign: 'center', borderBottom: '2px solid var(--border-color)' }),
                    $('<th>').text('Actions').css({ padding: '0.5rem', textAlign: 'center', borderBottom: '2px solid var(--border-color)' })
                )
            );
            
            const tbody = $('<tbody>');
            
            files.forEach((file) => {
                const sizeKB = (file.size / 1024).toFixed(2);
                const queuedAt = file.queued_at || 'N/A';
                const synced = file.synced ? 'Synced' : 'Pending';
                const syncedClass = file.synced ? 'success' : 'warning';
                
                const row = $('<tr>').css({
                    borderBottom: '1px solid var(--border-color)',
                    cursor: 'pointer'
                }).on('click', () => {
                    $('.file-storage-row').removeClass('selected');
                    row.addClass('selected');
                    this.selectedFileStorageFile = file.filename;
                    $('#view-file-storage-btn, #edit-file-storage-btn, #delete-file-storage-btn').show();
                });
                
                row.append(
                    $('<td>').text(file.filename).css({ padding: '0.5rem', fontFamily: 'monospace', fontSize: '0.9rem' }),
                    $('<td>').text(file.type || 'unknown').css({ padding: '0.5rem' }),
                    $('<td>').text(sizeKB + ' KB').css({ padding: '0.5rem', textAlign: 'right' }),
                    $('<td>').text(queuedAt).css({ padding: '0.5rem', fontSize: '0.9rem' }),
                    $('<td>').html(`<span class="badge badge-${syncedClass}">${synced}</span>`).css({ padding: '0.5rem', textAlign: 'center' }),
                    $('<td>').css({ padding: '0.5rem', textAlign: 'center' })
                );
                
                row.addClass('file-storage-row');
                tbody.append(row);
            });
            
            table.append(thead).append(tbody);
            container.append(table);
        },
        
        /**
         * View file storage file
         */
        viewFileStorageFile: function(filename) {
            if (!filename) {
                this.showAlert('Please select a file first.', 'Error', 'error');
                return;
            }
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=file-storage.php&action=view&file=${encodeURIComponent(filename)}`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        this.showFileStorageModal(filename, response.data, false);
                    } else {
                        this.showAlert('Failed to load file: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Failed to load file';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Edit file storage file
         */
        editFileStorageFile: function(filename) {
            if (!filename) {
                this.showAlert('Please select a file first.', 'Error', 'error');
                return;
            }
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=file-storage.php&action=view&file=${encodeURIComponent(filename)}`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        this.showFileStorageModal(filename, response.data, true);
                    } else {
                        this.showAlert('Failed to load file: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Failed to load file';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Show file storage modal
         */
        showFileStorageModal: function(filename, data, isEdit) {
            const modal = $('#file-storage-modal');
            const title = $('#file-storage-modal-title');
            const content = $('#file-storage-modal-content');
            const saveBtn = $('#file-storage-modal-save');
            
            title.text(isEdit ? `Edit: ${filename}` : `View: ${filename}`);
            saveBtn.toggle(isEdit);
            
            if (isEdit) {
                // Edit mode - show editable JSON
                const textarea = $('<textarea>')
                    .attr('id', 'file-storage-edit-content')
                    .css({
                        width: '100%',
                        height: '400px',
                        fontFamily: 'monospace',
                        fontSize: '0.9rem',
                        padding: '0.5rem',
                        border: '1px solid var(--border-color)',
                        borderRadius: '4px'
                    })
                    .val(JSON.stringify(data, null, 2));
                content.html(textarea);
                
                // Save handler
                saveBtn.off('click').on('click', () => {
                    try {
                        const editedData = JSON.parse($('#file-storage-edit-content').val());
                        this.saveFileStorageFile(filename, editedData);
                    } catch (e) {
                        this.showAlert('Invalid JSON: ' + e.message, 'Error', 'error');
                    }
                });
            } else {
                // View mode - show formatted JSON
                const pre = $('<pre>')
                    .css({
                        maxHeight: '500px',
                        overflow: 'auto',
                        padding: '1rem',
                        background: 'var(--bg-secondary)',
                        borderRadius: '4px',
                        fontFamily: 'monospace',
                        fontSize: '0.9rem',
                        whiteSpace: 'pre-wrap',
                        wordWrap: 'break-word'
                    })
                    .text(JSON.stringify(data, null, 2));
                content.html(pre);
            }
            
            modal.show();
        },
        
        /**
         * Save file storage file
         */
        saveFileStorageFile: function(filename, data) {
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=file-storage.php&action=edit`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    filename: filename,
                    data: data
                }),
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert('File saved successfully.', 'Success', 'success');
                        $('#file-storage-modal').hide();
                        this.loadFileStorageList();
                    } else {
                        this.showAlert('Failed to save file: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Failed to save file';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Delete file storage file
         */
        deleteFileStorageFile: function(filename) {
            if (!filename) {
                this.showAlert('Please select a file first.', 'Error', 'error');
                return;
            }
            
            this.showConfirm(
                `Are you sure you want to delete "${filename}"? This action cannot be undone.`,
                'Delete File'
            ).then((confirmed) => {
                if (confirmed) {
                    $.ajax({
                        url: `${this.config.apiBase}/proxy.php?path=file-storage.php&action=delete&file=${encodeURIComponent(filename)}`,
                        method: 'DELETE',
                        success: (response) => {
                            if (response && response.success) {
                                this.showAlert('File deleted successfully.', 'Success', 'success');
                                this.selectedFileStorageFile = null;
                                $('#view-file-storage-btn, #edit-file-storage-btn, #delete-file-storage-btn').hide();
                                this.loadFileStorageList();
                            } else {
                                this.showAlert('Failed to delete file: ' + (response.error || 'Unknown error'), 'Error', 'error');
                            }
                        },
                        error: (xhr) => {
                            const errorMsg = xhr.responseJSON?.error || 'Failed to delete file';
                            this.showAlert(errorMsg, 'Error', 'error');
                        }
                    });
                }
            });
        },
        
        /**
         * Initialize Mail System
         */
        initMailSystem: function() {
            this.currentMailFolder = 'inbox';
            this.currentMailId = null;
            this.currentMailThreadId = null;
            
            // Compose button
            $('#compose-mail-btn').on('click', () => {
                this.openComposeMail();
            });
            
            // Folder navigation
            $('.mail-folder').on('click', (e) => {
                const folder = $(e.target).closest('.mail-folder').data('folder');
                if (folder) {
                    this.switchMailFolder(folder);
                }
            });
            
            // Back to list button
            $('#back-to-list-btn').on('click', () => {
                this.showMailList();
            });
            
            // Compose mail modal handlers
            $('#compose-mail-close').on('click', () => {
                $('#compose-mail-modal').removeClass('active');
            });
            
            $('#send-mail-btn').on('click', () => {
                this.sendMail();
            });
            
            $('#save-draft-btn').on('click', () => {
                this.saveMailDraft();
            });
            
            // Mail viewer actions
            $('#star-mail-btn').on('click', () => {
                if (this.currentMailId) {
                    this.toggleMailStar(this.currentMailId);
                }
            });
            
            $('#reply-mail-btn').on('click', () => {
                if (this.currentMailId) {
                    this.replyToMail(this.currentMailId);
                }
            });
            
            $('#delete-mail-btn').on('click', () => {
                if (this.currentMailId) {
                    this.deleteMail(this.currentMailId);
                }
            });
            
            // Close compose modal on backdrop click
            $('#compose-mail-modal').on('click', (e) => {
                if ($(e.target).is('#compose-mail-modal')) {
                    $('#compose-mail-modal').removeClass('active');
                }
            });
            
            // File upload handling
            $('#mail-attachments-input').on('change', (e) => {
                this.handleMailAttachmentUpload(e.target.files);
            });
            
            // Update unread count periodically
            setInterval(() => {
                if (this.currentView === 'mail') {
                    this.updateUnreadMailCount();
                }
            }, 30000); // Every 30 seconds
            
            // Initialize mail attachments array
            this.mailAttachments = [];
        },
        
        /**
         * Switch mail folder
         */
        switchMailFolder: function(folder) {
            this.currentMailFolder = folder;
            $('.mail-folder').removeClass('active');
            $(`.mail-folder[data-folder="${folder}"]`).addClass('active');
            this.loadMailFolder(folder);
        },
        
        /**
         * Load mail for a folder
         */
        loadMailFolder: function(folder) {
            const container = $('#mail-list-container');
            container.html('<div class="loading-messages">Loading mail...</div>');
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=mail.php&action=${folder}`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        this.renderMailList(response.messages || [], folder);
                    } else {
                        container.html('<div class="no-messages">Failed to load mail.</div>');
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load mail:', xhr);
                    container.html('<div class="no-messages">Failed to load mail. Please try again.</div>');
                }
            });
        },
        
        /**
         * Render mail list
         */
        renderMailList: function(messages, folder) {
            const container = $('#mail-list-container');
            container.empty();
            
            if (messages.length === 0) {
                container.html('<div class="no-messages">No mail in this folder.</div>');
                return;
            }
            
            const list = $('<div>').addClass('mail-list');
            
            messages.forEach((mail) => {
                const isUnread = mail.read_at === null && folder === 'inbox';
                const starredClass = mail.is_starred ? 'starred' : '';
                const unreadClass = isUnread ? 'unread' : '';
                
                const mailItem = $(`
                    <div class="mail-item ${starredClass} ${unreadClass}" data-mail-id="${mail.id}">
                        <div class="mail-item-checkbox">
                            <input type="checkbox" class="mail-checkbox" data-mail-id="${mail.id}">
                        </div>
                        <div class="mail-item-star">
                            <i class="fas fa-star ${mail.is_starred ? 'active' : ''}"></i>
                        </div>
                        <div class="mail-item-content">
                            <div class="mail-item-header">
                                <span class="mail-item-from">${this.escapeHtml(folder === 'sent' ? mail.to_user : mail.from_user)}</span>
                                <span class="mail-item-date">${this.formatTime(mail.created_at)}</span>
                            </div>
                            <div class="mail-item-subject">${this.escapeHtml(mail.subject)}</div>
                            ${mail.has_attachments ? '<span class="mail-attachment-icon"><i class="fas fa-paperclip"></i></span>' : ''}
                        </div>
                    </div>
                `);
                
                mailItem.on('click', () => {
                    this.viewMail(mail.id);
                });
                
                list.append(mailItem);
            });
            
            container.html(list);
        },
        
        /**
         * View a mail message
         */
        viewMail: function(mailId) {
            this.currentMailId = mailId;
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=mail.php&action=view&id=${mailId}`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success && response.message) {
                        // Include attachments in message object
                        response.message.attachments = response.attachments || [];
                        this.renderMailViewer(response.message);
                        this.currentMailThreadId = response.message.thread_id;
                    } else {
                        this.showAlert('Failed to load mail message.', 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load mail:', xhr);
                    this.showAlert('Failed to load mail message.', 'Error', 'error');
                }
            });
        },
        
        /**
         * Render mail viewer
         */
        renderMailViewer: function(mail) {
            $('#mail-list-container').hide();
            $('#mail-viewer-container').show();
            
            const content = $('#mail-viewer-content');
            
            // Decode message body
            let messageBody = '';
            try {
                const decoded = decodeURIComponent(atob(mail.cipher_blob).replace(/\+/g, '%20'));
                messageBody = this.escapeHtml(decoded);
            } catch (e) {
                messageBody = 'Error decoding message.';
            }
            
            // Update star button
            const starIcon = $('#star-mail-btn i');
            starIcon.removeClass('far fas');
            starIcon.addClass(mail.is_starred ? 'fas' : 'far');
            
            // Render attachments if available
            let attachmentsHtml = '';
            if (mail.attachments && mail.attachments.length > 0) {
                attachmentsHtml = '<div class="mail-viewer-attachments"><h4>Attachments:</h4><ul>';
                mail.attachments.forEach((attachment) => {
                    const sizeKB = (attachment.file_size / 1024).toFixed(1);
                    attachmentsHtml += `
                        <li class="attachment-item-viewer">
                            <i class="fas fa-paperclip"></i>
                            <a href="${this.config.apiBase}/proxy.php?path=mail.php&action=download-attachment&id=${attachment.id}" 
                               target="_blank" class="attachment-link">
                                ${this.escapeHtml(attachment.filename)}
                            </a>
                            <span class="attachment-size">(${sizeKB} KB)</span>
                        </li>
                    `;
                });
                attachmentsHtml += '</ul></div>';
            }
            
            const html = `
                <div class="mail-viewer-header-info">
                    <div class="mail-viewer-subject">${this.escapeHtml(mail.subject)}</div>
                    <div class="mail-viewer-meta">
                        <div class="mail-viewer-from">
                            <strong>From:</strong> ${this.escapeHtml(mail.from_user)}
                        </div>
                        <div class="mail-viewer-to">
                            <strong>To:</strong> ${this.escapeHtml(mail.to_user)}
                        </div>
                        ${mail.cc_users && mail.cc_users.length > 0 ? `
                            <div class="mail-viewer-cc">
                                <strong>CC:</strong> ${mail.cc_users.map(u => this.escapeHtml(u)).join(', ')}
                            </div>
                        ` : ''}
                        <div class="mail-viewer-date">
                            <strong>Date:</strong> ${this.formatTime(mail.created_at)}
                        </div>
                    </div>
                </div>
                <div class="mail-viewer-body">
                    ${messageBody.replace(/\n/g, '<br>')}
                </div>
                ${attachmentsHtml}
            `;
            
            content.html(html);
        },
        
        /**
         * Show mail list (hide viewer)
         */
        showMailList: function() {
            $('#mail-viewer-container').hide();
            $('#mail-list-container').show();
            this.currentMailId = null;
        },
        
        /**
         * Open compose mail modal
         */
        openComposeMail: function(replyToId = null, threadId = null) {
            $('#compose-mail-form')[0].reset();
            $('#mail-reply-to-id').val(replyToId || '');
            $('#mail-thread-id').val(threadId || '');
            $('#mail-draft-id').val('');
            
            if (replyToId) {
                $('#compose-mail-title').text('Reply to Mail');
            } else {
                $('#compose-mail-title').text('Compose Mail');
            }
            
            $('#compose-mail-modal').addClass('active');
            $('#mail-to-input').focus();
        },
        
        /**
         * Reply to mail
         */
        replyToMail: function(mailId) {
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=mail.php&action=view&id=${mailId}`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success && response.message) {
                        const mail = response.message;
                        const replySubject = mail.subject.startsWith('Re: ') ? mail.subject : `Re: ${mail.subject}`;
                        
                        $('#mail-to-input').val(mail.from_user);
                        $('#mail-subject-input').val(replySubject);
                        $('#mail-reply-to-id').val(mailId);
                        $('#mail-thread-id').val(mail.thread_id || mailId);
                        
                        $('#compose-mail-title').text('Reply to Mail');
                        $('#compose-mail-modal').addClass('active');
                        $('#mail-body-input').focus();
                    }
                },
                error: (xhr) => {
                    this.showAlert('Failed to load mail for reply.', 'Error', 'error');
                }
            });
        },
        
        /**
         * Send mail
         */
        sendMail: function() {
            const toUser = $('#mail-to-input').val().trim();
            const subject = $('#mail-subject-input').val().trim();
            const body = $('#mail-body-input').val().trim();
            const ccUsers = $('#mail-cc-input').val().trim().split(',').map(u => u.trim()).filter(u => u);
            const bccUsers = $('#mail-bcc-input').val().trim().split(',').map(u => u.trim()).filter(u => u);
            const replyToId = $('#mail-reply-to-id').val() || null;
            const threadId = $('#mail-thread-id').val() || null;
            const draftId = $('#mail-draft-id').val() || null;
            
            if (!toUser || !subject || !body) {
                this.showAlert('Please fill in all required fields (To, Subject, Message).', 'Error', 'error');
                return;
            }
            
            // Encode message body
            const cipherBlob = btoa(encodeURIComponent(body));
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=mail.php&action=send`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    to_user: toUser,
                    subject: subject,
                    cipher_blob: cipherBlob,
                    cc_users: ccUsers,
                    bcc_users: bccUsers,
                    reply_to_id: replyToId ? parseInt(replyToId) : null,
                    thread_id: threadId ? parseInt(threadId) : null
                }),
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert('Mail sent successfully!', 'Success', 'success');
                        $('#compose-mail-modal').removeClass('active');
                        $('#compose-mail-form')[0].reset();
                        
                        // Reload current folder
                        this.loadMailFolder(this.currentMailFolder);
                        this.updateUnreadMailCount();
                    } else {
                        this.showAlert('Failed to send mail: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || xhr.responseJSON?.message || 'Failed to send mail';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Save mail draft
         */
        saveMailDraft: function() {
            const toUser = $('#mail-to-input').val().trim();
            const subject = $('#mail-subject-input').val().trim();
            const body = $('#mail-body-input').val().trim();
            const draftId = $('#mail-draft-id').val() || null;
            
            if (!subject && !body) {
                this.showAlert('Draft must have at least a subject or message body.', 'Error', 'error');
                return;
            }
            
            // Encode message body
            const cipherBlob = btoa(encodeURIComponent(body || ''));
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=mail.php&action=save-draft`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    to_user: toUser,
                    subject: subject,
                    cipher_blob: cipherBlob,
                    draft_id: draftId ? parseInt(draftId) : null
                }),
                success: (response) => {
                    if (response && response.success) {
                        $('#mail-draft-id').val(response.draft_id);
                        this.showAlert('Draft saved successfully!', 'Success', 'success');
                    } else {
                        this.showAlert('Failed to save draft: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || xhr.responseJSON?.message || 'Failed to save draft';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Toggle mail star
         */
        toggleMailStar: function(mailId) {
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=mail.php&action=star`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    mail_id: mailId
                }),
                success: (response) => {
                    if (response && response.success) {
                        // Reload current view
                        if ($('#mail-viewer-container').is(':visible')) {
                            this.viewMail(mailId);
                        } else {
                            this.loadMailFolder(this.currentMailFolder);
                        }
                    }
                },
                error: (xhr) => {
                    this.showAlert('Failed to toggle star.', 'Error', 'error');
                }
            });
        },
        
        /**
         * Delete mail
         */
        deleteMail: function(mailId) {
            this.showConfirm('Are you sure you want to delete this mail?', 'Delete Mail').then((confirmed) => {
                if (!confirmed) return;
                
                $.ajax({
                    url: `${this.config.apiBase}/proxy.php?path=mail.php&action=delete`,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        mail_id: mailId
                    }),
                    success: (response) => {
                        if (response && response.success) {
                            this.showAlert('Mail deleted successfully.', 'Success', 'success');
                            this.showMailList();
                            this.loadMailFolder(this.currentMailFolder);
                            this.updateUnreadMailCount();
                        } else {
                            this.showAlert('Failed to delete mail: ' + (response.error || 'Unknown error'), 'Error', 'error');
                        }
                    },
                    error: (xhr) => {
                        const errorMsg = xhr.responseJSON?.error || xhr.responseJSON?.message || 'Failed to delete mail';
                        this.showAlert(errorMsg, 'Error', 'error');
                    }
                });
            });
        },
        
        /**
         * Update unread mail count
         */
        updateUnreadMailCount: function() {
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=mail.php&action=unread-count`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        const count = response.count || 0;
                        const badge = $('#mail-badge');
                        const inboxBadge = $('#inbox-badge');
                        
                        if (count > 0) {
                            badge.text(count).show();
                            inboxBadge.text(count).show();
                        } else {
                            badge.hide();
                            inboxBadge.hide();
                        }
                    }
                },
                error: (xhr) => {
                    // Silently fail - don't show error for badge updates
                    console.error('Failed to update unread mail count:', xhr);
                }
            });
        },
        
        /**
         * Initialize Settings System
         */
        initSettingsSystem: function() {
            // Settings tab switching
            $('.settings-tab-btn').on('click', (e) => {
                const tab = $(e.target).closest('.settings-tab-btn').data('tab');
                this.switchSettingsTab(tab);
            });
            
            // Color picker synchronization
            $(document).on('input', '.color-picker', function() {
                const hexValue = $(this).val();
                $(this).siblings('.color-hex').val(hexValue);
            });
            
            $(document).on('input', '.color-hex', function() {
                let hexValue = $(this).val();
                if (!hexValue.startsWith('#')) {
                    hexValue = '#' + hexValue;
                }
                if (/^#[0-9A-Fa-f]{6}$/.test(hexValue)) {
                    $(this).siblings('.color-picker').val(hexValue);
                    $(this).val(hexValue);
                }
            });
            
            // Theme change handler
            $('#settings-theme').on('change', () => {
                const theme = $('#settings-theme').val();
                this.toggleCustomThemeBuilder(theme);
                // Apply theme immediately for preview
                this.applyTheme(theme);
            });
            
            // Custom theme color synchronization
            $(document).on('input', '#custom-primary-color, #custom-background-color, #custom-surface-color, #custom-text-color, #custom-border-color, #custom-success-color, #custom-warning-color, #custom-error-color', function() {
                const hexValue = $(this).val();
                $(this).siblings('.color-hex').val(hexValue);
            });
            
            $(document).on('input', '#custom-primary-color-hex, #custom-background-color-hex, #custom-surface-color-hex, #custom-text-color-hex, #custom-border-color-hex, #custom-success-color-hex, #custom-warning-color-hex, #custom-error-color-hex', function() {
                let hexValue = $(this).val();
                if (!hexValue.startsWith('#')) {
                    hexValue = '#' + hexValue;
                }
                if (/^#[0-9A-Fa-f]{6}$/.test(hexValue)) {
                    $(this).siblings('.color-picker').val(hexValue);
                    $(this).val(hexValue);
                }
            });
            
            // Preview custom theme
            $('#preview-custom-theme').on('click', () => {
                this.previewCustomTheme();
            });
            
            // Avatar type change handler
            $('input[name="avatar-type"]').on('change', () => {
                const avatarType = $('input[name="avatar-type"]:checked').val();
                const gravatarEmail = $('#gravatar-email').val().trim();
                
                if (avatarType === 'gravatar') {
                    $('#gravatar-email').show();
                    // Update preview with current email
                    this.updateAvatarPreview({ avatar_type: 'gravatar', gravatar_email: gravatarEmail });
                } else {
                    $('#gravatar-email').hide();
                    // Update preview for default or gallery
                    if (avatarType === 'gallery') {
                        $('#avatar-gallery-container').show();
                        // Preview will update when image is selected
                    } else {
                        $('#avatar-gallery-container').hide();
                        this.updateAvatarPreview({ avatar_type: 'default' });
                    }
                }
                if (avatarType === 'gallery') {
                    $('#avatar-gallery-container').show();
                } else {
                    $('#avatar-gallery-container').hide();
                }
            });
            
            // Gravatar email change handler
            $('#gravatar-email').on('input', () => {
                const avatarType = $('input[name="avatar-type"]:checked').val();
                if (avatarType === 'gravatar') {
                    const gravatarEmail = $('#gravatar-email').val().trim();
                    this.updateAvatarPreview({ avatar_type: 'gravatar', gravatar_email: gravatarEmail });
                }
            });
            
            // Avatar upload handler
            $('#upload-avatar-btn').on('click', () => {
                $('#avatar-upload-input').click();
            });
            
            $('#avatar-upload-input').on('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    this.uploadAvatarImage(file);
                }
            });
            
            // Save handlers
            $('#settings-profile-save').on('click', () => {
                this.saveProfileSettings();
            });
            
            $('#settings-appearance-save').on('click', () => {
                this.saveAppearanceSettings();
            });
            
            $('#settings-notifications-save').on('click', () => {
                this.saveNotificationSettings();
            });
            
            $('#settings-chat-save').on('click', () => {
                this.saveChatSettings();
            });
            
            // Gallery upload handler
            $('#gallery-upload-btn').on('click', () => {
                $('#gallery-upload-input').click();
            });
            
            $('#gallery-upload-input').on('change', (e) => {
                const files = Array.from(e.target.files);
                if (files.length > 0) {
                    files.forEach(file => {
                        this.uploadGalleryImage(file);
                    });
                }
            });
            
            // Gallery image modal handlers
            $('#gallery-image-modal-close, #gallery-image-cancel-btn').on('click', () => {
                this.closeGalleryImageModal();
            });
            
            $('#gallery-image-save-btn').on('click', () => {
                this.saveGalleryImageChanges();
            });
            
            $('#gallery-image-delete-btn').on('click', () => {
                this.deleteGalleryImageFromModal();
            });
            
            // Close modal on background click
            $('#gallery-image-modal').on('click', (e) => {
                if ($(e.target).is('#gallery-image-modal')) {
                    this.closeGalleryImageModal();
                }
            });
        },
        
        /**
         * Switch settings tab
         */
        switchSettingsTab: function(tab) {
            $('.settings-tab-btn').removeClass('active');
            $(`.settings-tab-btn[data-tab="${tab}"]`).addClass('active');
            $('.settings-tab-pane').removeClass('active');
            $(`#settings-tab-${tab}`).addClass('active');
            
            // Load gallery when Gallery tab is opened
            if (tab === 'gallery') {
                this.loadGallery();
            }
        },
        
        /**
         * Load gallery for Gallery tab
         */
        loadGallery: function() {
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=avatars.php&action=gallery`,
                method: 'GET',
                success: (response) => {
                    console.log('Gallery API response:', response);
                    if (response && response.success && response.gallery) {
                        console.log('Rendering gallery with', response.gallery.length, 'images');
                        this.renderGallery(response.gallery);
                    } else {
                        console.error('Invalid gallery response:', response);
                        $('#gallery-grid').html('<p class="text-error">Invalid response from server.</p>');
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load gallery:', xhr);
                    $('#gallery-grid').html('<p class="text-error">Failed to load gallery. Please try again.</p>');
                }
            });
        },
        
        /**
         * Delete gallery image
         */
        deleteGalleryImage: function(imageId) {
            if (!confirm('Are you sure you want to delete this image?')) {
                return;
            }
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=avatars.php&action=delete`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ image_id: imageId }),
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert('Image deleted successfully', 'Success', 'success');
                        this.loadGallery(); // Reload gallery
                        this.loadAvatarGallery(); // Reload avatar gallery if open
                    } else {
                        this.showAlert('Failed to delete image: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Failed to delete image';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Load user settings
         */
        loadSettings: function() {
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=settings.php&action=get`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success && response.settings) {
                        this.renderSettings(response.settings);
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load settings:', xhr);
                }
            });
            
            // Also load profile data
            this.loadProfileForSettings();
        },
        
        /**
         * Load profile data for settings
         */
        loadProfileForSettings: function() {
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=profile.php&action=view&user_handle=${encodeURIComponent(this.config.userHandle)}`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success && response.profile) {
                        $('#settings-display-name').val(response.profile.display_name || '');
                        $('#settings-bio').val(response.profile.bio || '');
                        $('#settings-status-message').val(response.profile.status_message || '');
                        
                        // Load avatar settings (this will update the preview)
                        this.loadAvatarSettings(response.profile);
                        
                        // Also update the user profile cache
                        this.userProfileCache[this.config.userHandle] = response.profile;
                        
                        // Update profile button avatar in header
                        this.updateProfileButtonAvatar(response.profile);
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load profile:', xhr);
                }
            });
            
            // Load gallery
            this.loadAvatarGallery();
        },
        
        /**
         * Load avatar settings
         */
        loadAvatarSettings: function(profile) {
            const avatarType = profile.avatar_type || 'default';
            $('input[name="avatar-type"][value="' + avatarType + '"]').prop('checked', true);
            
            if (avatarType === 'gravatar') {
                $('#gravatar-email').val(profile.gravatar_email || '').show();
            } else {
                $('#gravatar-email').hide();
            }
            
            if (avatarType === 'gallery') {
                $('#avatar-gallery-container').show();
                // Store the current avatar_data (image ID) so it's preserved when gallery loads
                if (profile.avatar_data) {
                    $('#avatar-gallery-grid').data('selected-image-id', parseInt(profile.avatar_data));
                    console.log('Loaded avatar_data from profile:', profile.avatar_data);
                }
                // Reload gallery to ensure it shows the correct selection
                this.loadAvatarGallery();
            } else {
                $('#avatar-gallery-container').hide();
            }
            
            // Update preview - ensure we have the latest profile data
            console.log('Loading avatar settings:', { avatarType, avatar_data: profile.avatar_data, gravatar_email: profile.gravatar_email, gravatar_url: profile.gravatar_url });
            this.updateAvatarPreview(profile);
        },
        
        /**
         * Load avatar gallery
         */
        loadAvatarGallery: function() {
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=avatars.php&action=gallery`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success && response.gallery) {
                        this.renderAvatarGallery(response.gallery);
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load gallery:', xhr);
                }
            });
        },
        
        /**
         * Render avatar gallery
         */
        renderAvatarGallery: function(gallery) {
            const container = $('#avatar-gallery-grid');
            container.empty();
            
            // Clear any previously selected image ID
            container.removeData('selected-image-id');
            
            if (gallery.length === 0) {
                container.html('<p class="text-muted">No images in gallery. Upload an image to use as avatar.</p>');
                return;
            }
            
            // Find currently selected avatar (if any)
            // First check if we have a stored selected-image-id from loadAvatarSettings
            let currentAvatarId = container.data('selected-image-id');
            if (!currentAvatarId) {
                // Fallback: check is_avatar flag from server
                gallery.forEach((image) => {
                    if (image.is_avatar) {
                        currentAvatarId = image.id;
                    }
                });
            }
            
            // Store the currentAvatarId in container data for saveAvatarSettings to use
            if (currentAvatarId) {
                container.data('selected-image-id', currentAvatarId);
            }
            
            gallery.forEach((image) => {
                // Validate image object
                if (!image || !image.id) {
                    console.error('Invalid image object in avatar gallery:', image);
                    return;
                }
                
                // Use direct endpoint to serve images (not through proxy - serves binary data)
                const imageUrl = `/iChat/api/gallery-image.php?id=${image.id}${image.has_thumbnail_data ? '&thumb=1' : ''}`;
                
                // Check if this image matches the current avatar (by ID or is_avatar flag)
                const isSelected = (currentAvatarId && image.id == currentAvatarId) || image.is_avatar;
                const img = $('<img>')
                    .attr('src', imageUrl)
                    .attr('alt', image.filename || 'Gallery image')
                    .addClass('avatar-gallery-item')
                    .data('image-id', image.id)
                    .css({
                        width: '80px',
                        height: '80px',
                        objectFit: 'cover',
                        cursor: 'pointer',
                        border: isSelected ? '3px solid var(--blizzard-blue)' : '1px solid var(--border-color)',
                        borderRadius: '4px',
                        margin: '0.25rem',
                        display: 'block'
                    })
                    .on('error', function() {
                        console.error('Failed to load avatar gallery image:', imageUrl, 'for image ID:', image.id);
                        $(this).replaceWith(`<div style="width: 80px; height: 80px; border: 1px solid var(--border-color); border-radius: 4px; display: flex; align-items: center; justify-content: center; background: var(--ghost-white); color: var(--text-medium); font-size: 0.7rem; text-align: center; padding: 0.25rem;" class="avatar-gallery-item" data-image-id="${image.id}">${image.filename || 'Image'}</div>`);
                    })
                    .on('click', () => {
                        $('.avatar-gallery-item').css('border', '1px solid var(--border-color)');
                        $(img).css('border', '3px solid var(--blizzard-blue)');
                        $('input[name="avatar-type"][value="gallery"]').prop('checked', true);
                        // Update preview with gallery image
                        const previewUrl = `/iChat/api/gallery-image.php?id=${image.id}`;
                        $('#avatar-preview').attr('src', previewUrl + '&_t=' + Date.now());
                        console.log('Selected gallery image:', image.id, 'Preview URL:', previewUrl);
                        // Store selected image ID for saving - use both data attribute and a class marker
                        container.data('selected-image-id', image.id);
                        // Also mark the image element itself for easier retrieval
                        $('.avatar-gallery-item').removeClass('selected-avatar-image');
                        $(img).addClass('selected-avatar-image');
                        console.log('Stored selected-image-id:', container.data('selected-image-id'));
                    });
                
                container.append(img);
            });
        },
        
        /**
         * Render gallery in Gallery tab
         */
        renderGallery: function(gallery) {
            const container = $('#gallery-grid');
            container.empty();
            
            if (!Array.isArray(gallery)) {
                console.error('Gallery is not an array:', gallery);
                container.html('<p class="text-error">Invalid gallery data format.</p>');
                return;
            }
            
            if (gallery.length === 0) {
                container.html('<p class="text-muted">No images in your gallery. Click "Upload Image" to add some!</p>');
                return;
            }
            
            console.log('Rendering', gallery.length, 'gallery images');
            
            gallery.forEach((image) => {
                // Validate image object
                if (!image || !image.id) {
                    console.error('Invalid image object:', image);
                    return;
                }
                
                // Use direct endpoint to serve images (not through proxy - serves binary data)
                const imageUrl = `/iChat/api/gallery-image.php?id=${image.id}${image.has_thumbnail_data ? '&thumb=1' : ''}`;
                const fullImageUrl = `/iChat/api/gallery-image.php?id=${image.id}`;
                
                const galleryItem = $('<div>')
                    .addClass('gallery-item')
                    .css({
                        position: 'relative',
                        display: 'inline-block',
                        margin: '0.5rem',
                        cursor: 'pointer'
                    });
                
                const img = $('<img>')
                    .attr('src', imageUrl)
                    .attr('alt', image.filename || 'Gallery image')
                    .css({
                        width: '150px',
                        height: '150px',
                        objectFit: 'cover',
                        border: '1px solid var(--border-color)',
                        borderRadius: '4px',
                        display: 'block'
                    })
                    .on('error', function() {
                        console.error('Failed to load image:', imageUrl, 'for image ID:', image.id);
                        $(this).replaceWith(`<div style="width: 150px; height: 150px; border: 1px solid var(--border-color); border-radius: 4px; display: flex; align-items: center; justify-content: center; background: var(--ghost-white); color: var(--text-medium); font-size: 0.8rem; text-align: center; padding: 0.5rem;">Failed to load<br>${image.filename || 'Image'}</div>`);
                    })
                    .on('click', () => {
                        // Open full-size image in modal
                        this.openGalleryImageModal(image);
                    });
                
                const deleteBtn = $('<button>')
                    .addClass('btn-error btn-sm')
                    .html('<i class="fas fa-trash"></i>')
                    .css({
                        position: 'absolute',
                        top: '5px',
                        right: '5px',
                        zIndex: 10
                    })
                    .on('click', (e) => {
                        e.stopPropagation();
                        this.deleteGalleryImage(image.id);
                    });
                
                galleryItem.append(img).append(deleteBtn);
                container.append(galleryItem);
            });
        },
        
        /**
         * Open gallery image modal for viewing/editing
         */
        openGalleryImageModal: function(image) {
            if (!image || !image.id) {
                console.error('Invalid image data for modal');
                return;
            }
            
            // Store current image data
            this.currentGalleryImage = image;
            
            // Set image display
            const imageUrl = `/iChat/api/gallery-image.php?id=${image.id}`;
            $('#gallery-image-display').attr('src', imageUrl);
            
            // Set form values
            $('#gallery-image-filename').val(image.filename || '');
            $('#gallery-image-is-public').prop('checked', image.is_public === true || image.is_public === 1);
            $('#gallery-image-is-avatar').prop('checked', image.is_avatar === true || image.is_avatar === 1);
            
            // Set title
            $('#gallery-image-title').text(image.filename || 'Gallery Image');
            
            // Show modal
            $('#gallery-image-modal').addClass('active');
        },
        
        /**
         * Close gallery image modal
         */
        closeGalleryImageModal: function() {
            $('#gallery-image-modal').removeClass('active');
            this.currentGalleryImage = null;
        },
        
        /**
         * Save gallery image changes
         */
        saveGalleryImageChanges: function() {
            if (!this.currentGalleryImage || !this.currentGalleryImage.id) {
                console.error('No image selected for editing');
                return;
            }
            
            const imageId = this.currentGalleryImage.id;
            const filename = $('#gallery-image-filename').val().trim();
            const isPublic = $('#gallery-image-is-public').is(':checked');
            const isAvatar = $('#gallery-image-is-avatar').is(':checked');
            
            if (!filename) {
                this.showAlert('Filename cannot be empty', 'Error');
                return;
            }
            
            $.ajax({
                url: this.config.apiBase + '/proxy.php?path=avatars.php&action=update',
                method: 'POST',
                data: JSON.stringify({
                    image_id: imageId,
                    filename: filename,
                    is_public: isPublic,
                    is_avatar: isAvatar
                }),
                contentType: 'application/json',
                dataType: 'json',
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert('Image updated successfully', 'Success');
                        this.closeGalleryImageModal();
                        // Reload gallery
                        this.loadGallery();
                        // If avatar was changed, reload avatar gallery and update preview
                        this.loadAvatarGallery();
                        if (isAvatar) {
                            // Reload profile to update avatar preview
                            this.loadSettings();
                        }
                    } else {
                        this.showAlert(response.error || 'Failed to update image', 'Error');
                    }
                },
                error: (xhr) => {
                    console.error('Failed to update gallery image:', xhr);
                    const errorMsg = xhr.responseJSON?.error || 'Failed to update image. Please try again.';
                    this.showAlert(errorMsg, 'Error');
                }
            });
        },
        
        /**
         * Delete gallery image from modal
         */
        deleteGalleryImageFromModal: function() {
            if (!this.currentGalleryImage || !this.currentGalleryImage.id) {
                console.error('No image selected for deletion');
                return;
            }
            
            const imageId = this.currentGalleryImage.id;
            const filename = this.currentGalleryImage.filename || 'this image';
            
            this.showConfirm(
                `Are you sure you want to delete "${filename}"? This action cannot be undone.`,
                'Delete Image',
                () => {
                    this.deleteGalleryImage(imageId);
                    this.closeGalleryImageModal();
                }
            );
        },
        
        /**
         * Update profile button avatar in header
         */
        updateProfileButtonAvatar: function() {
            const profileBtn = $('#my-profile-btn');
            if (profileBtn.length === 0) {
                return; // Button doesn't exist (guest user)
            }
            
            // Get current user profile
            const userHandle = this.config.userHandle;
            if (!userHandle) {
                return;
            }
            
            // Get avatar URL
            const avatarUrl = this.getAvatarUrlForUser(userHandle, null, 32);
            
            // Check if user has custom avatar (not default)
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=settings.php&action=get`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success && response.settings) {
                        const profile = response.settings;
                        const avatarType = profile.avatar_type || 'default';
                        const hasCustomAvatar = (avatarType !== 'default' && profile.avatar_data) || 
                                              (avatarType === 'gravatar' && profile.gravatar_email);
                        
                        if (hasCustomAvatar) {
                            // Show avatar image
                            profileBtn.attr('data-has-avatar', 'true');
                            profileBtn.html(`<img src="${avatarUrl}&_t=${Date.now()}" alt="Profile" class="profile-btn-avatar">`);
                        } else {
                            // Show emoji
                            profileBtn.attr('data-has-avatar', 'false');
                            profileBtn.html('👤');
                        }
                    }
                },
                error: () => {
                    // On error, default to emoji
                    profileBtn.attr('data-has-avatar', 'false');
                    profileBtn.html('👤');
                }
            });
        },
        
        /**
         * Update profile button avatar in header
         */
        updateProfileButtonAvatar: function(profile) {
            const profileBtn = $('#my-profile-btn');
            if (profileBtn.length === 0) {
                return; // Button doesn't exist (guest user)
            }
            
            // Get current user handle
            const userHandle = this.config.userHandle;
            if (!userHandle) {
                return;
            }
            
            // If profile not provided, fetch it
            if (!profile) {
                $.ajax({
                    url: `${this.config.apiBase}/proxy.php?path=profile.php&action=view&user_handle=${encodeURIComponent(userHandle)}`,
                    method: 'GET',
                    success: (response) => {
                        if (response && response.success && response.profile) {
                            this.updateProfileButtonAvatar(response.profile);
                        } else {
                            // Default to emoji if no profile
                            profileBtn.attr('data-has-avatar', 'false');
                            profileBtn.html('👤');
                        }
                    },
                    error: () => {
                        // On error, default to emoji
                        profileBtn.attr('data-has-avatar', 'false');
                        profileBtn.html('👤');
                    }
                });
                return;
            }
            
            // Check if user has custom avatar (not default)
            const avatarType = profile.avatar_type || 'default';
            const hasCustomAvatar = (avatarType !== 'default' && profile.avatar_data) || 
                                  (avatarType === 'gravatar' && profile.gravatar_email);
            
            if (hasCustomAvatar) {
                // Get avatar URL (32px for header button)
                const avatarUrl = this.getAvatarUrlForUser(userHandle, 32);
                // Show avatar image
                profileBtn.attr('data-has-avatar', 'true');
                profileBtn.html(`<img src="${avatarUrl}&_t=${Date.now()}" alt="Profile" class="profile-btn-avatar">`);
            } else {
                // Show emoji for default avatar
                profileBtn.attr('data-has-avatar', 'false');
                profileBtn.html('👤');
            }
        },
        
        /**
         * Update avatar preview
         */
        updateAvatarPreview: function(profile) {
            const preview = $('#avatar-preview');
            if (preview.length === 0) {
                console.warn('Avatar preview element not found');
                return;
            }
            
            const avatarType = profile.avatar_type || 'default';
            let avatarUrl = '/iChat/images/default-avatar.png';
            
            if (avatarType === 'default') {
                avatarUrl = '/iChat/images/default-avatar.png';
            } else if (avatarType === 'gravatar') {
                // Always use server-computed Gravatar URL (includes correct SHA256 hash)
                if (profile.gravatar_url) {
                    avatarUrl = profile.gravatar_url;
                    console.log('Using server-computed Gravatar URL:', avatarUrl);
                } else {
                    // Fallback: if server didn't provide URL, use default
                    // (This shouldn't happen if profile was loaded correctly)
                    avatarUrl = '/iChat/images/default-avatar.png';
                    console.warn('Gravatar URL not found in profile, using default');
                }
            } else if (avatarType === 'gallery') {
                // Gallery images are served from database via PHP endpoint (direct, not proxy)
                if (profile.avatar_data) {
                    // avatar_data contains gallery image ID
                    avatarUrl = `/iChat/api/gallery-image.php?id=${profile.avatar_data}`;
                } else if (profile.avatar_path) {
                    // Fallback to file path if still using old system
                    avatarUrl = '/' + profile.avatar_path;
                }
            }
            
            // Check if avatar is cached in database (served from PHP)
            if (profile.avatar_cached && profile.avatar_url) {
                avatarUrl = profile.avatar_url;
                console.log('Using cached avatar from database:', avatarUrl);
            }
            
            console.log('Updating avatar preview with URL:', avatarUrl);
            // Force image reload by adding timestamp or changing src
            preview.attr('src', avatarUrl + (avatarUrl.includes('?') ? '&' : '?') + '_t=' + Date.now());
        },
        
        /**
         * Get avatar URL for a user handle (with caching)
         */
        getAvatarUrlForUser: function(userHandle, size = 50) {
            if (!userHandle) {
                return '/iChat/api/avatar-image.php?user=default&size=' + size;
            }
            
            // Always use the PHP endpoint which handles caching and thumbnails
            // The endpoint will serve thumbnails for sizes <= 48px, full size for larger
            return '/iChat/api/avatar-image.php?user=' + encodeURIComponent(userHandle) + '&size=' + size;
        },
        
        /**
         * SHA256 hash function for Gravatar using Web Crypto API
         * Gravatar now uses SHA256 (64 chars) instead of MD5 (32 chars)
         */
        sha256: async function(string) {
            // Use Web Crypto API for proper SHA256 hashing
            if (window.crypto && window.crypto.subtle) {
                const encoder = new TextEncoder();
                const data = encoder.encode(string);
                const hashBuffer = await crypto.subtle.digest('SHA-256', data);
                const hashArray = Array.from(new Uint8Array(hashBuffer));
                return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
            }
            // Fallback: return empty string if Web Crypto API not available
            // In this case, we should rely on server-computed hash
            console.warn('Web Crypto API not available, falling back to server-computed hash');
            return '';
        },
        
        /**
         * Get avatar URL from profile data
         * Primary: PHP endpoint (checks database cache first)
         * Fallback: Direct Gravatar URL or gallery endpoint
         * 
         * @param {string} userHandle User handle
         * @param {object} profile Profile data
         * @param {number} size Avatar size in pixels (default: 128)
         */
        getAvatarUrl: function(userHandle, profile, size = 128) {
            if (!profile) {
                return '/iChat/api/avatar-image.php?user=' + encodeURIComponent(userHandle || '') + '&size=' + size;
            }
            
            // Check if avatar is cached in database (served from PHP)
            if (profile.avatar_cached && profile.avatar_url) {
                // Update URL with size parameter for thumbnails
                const baseUrl = profile.avatar_url.split('&size=')[0].split('?size=')[0];
                return baseUrl + (baseUrl.includes('?') ? '&' : '?') + 'size=' + size;
            }
            
            const avatarType = profile.avatar_type || 'default';
            
            if (avatarType === 'default') {
                return '/iChat/api/avatar-image.php?user=' + encodeURIComponent(userHandle) + '&size=' + size;
            } else if (avatarType === 'gravatar' && profile.gravatar_email) {
                // Use PHP endpoint which will cache Gravatar and serve thumbnails
                return '/iChat/api/avatar-image.php?user=' + encodeURIComponent(userHandle) + '&size=' + size;
            } else if (avatarType === 'gallery') {
                // Gallery images served from database via PHP endpoint (direct, not proxy)
                if (profile.avatar_data) {
                    // For thumbnails, use avatar-image.php endpoint which will handle caching
                    if (size <= 48) {
                        return '/iChat/api/avatar-image.php?user=' + encodeURIComponent(userHandle) + '&size=' + size;
                    }
                    return '/iChat/api/gallery-image.php?id=' + encodeURIComponent(profile.avatar_data);
                } else if (profile.avatar_path) {
                    // Fallback to file path if still using old system
                    return '/' + profile.avatar_path;
                }
            }
            
            return '/iChat/api/avatar-image.php?user=' + encodeURIComponent(userHandle) + '&size=' + size;
        },
        
        /**
         * Render settings
         */
        renderSettings: function(settings) {
            // Appearance settings
            $('#settings-chat-text-color').val(settings.chat_text_color || '#000000');
            $('#settings-chat-text-color-hex').val(settings.chat_text_color || '#000000');
            $('#settings-chat-name-color').val(settings.chat_name_color || '#0070ff');
            $('#settings-chat-name-color-hex').val(settings.chat_name_color || '#0070ff');
            $('#settings-font-size').val(settings.font_size || 'medium');
            $('#settings-theme').val(settings.theme || 'default');
            $('#settings-compact-mode').prop('checked', settings.compact_mode || false);
            
            // Load custom theme colors if available
            if (settings.custom_theme_colors) {
                try {
                    const customColors = typeof settings.custom_theme_colors === 'string' 
                        ? JSON.parse(settings.custom_theme_colors) 
                        : settings.custom_theme_colors;
                    
                    if (customColors.primary) {
                        $('#custom-primary-color').val(customColors.primary);
                        $('#custom-primary-color-hex').val(customColors.primary);
                    }
                    if (customColors.background) {
                        $('#custom-background-color').val(customColors.background);
                        $('#custom-background-color-hex').val(customColors.background);
                    }
                    if (customColors.surface) {
                        $('#custom-surface-color').val(customColors.surface);
                        $('#custom-surface-color-hex').val(customColors.surface);
                    }
                    if (customColors.text) {
                        $('#custom-text-color').val(customColors.text);
                        $('#custom-text-color-hex').val(customColors.text);
                    }
                    if (customColors.border) {
                        $('#custom-border-color').val(customColors.border);
                        $('#custom-border-color-hex').val(customColors.border);
                    }
                    if (customColors.success) {
                        $('#custom-success-color').val(customColors.success);
                        $('#custom-success-color-hex').val(customColors.success);
                    }
                    if (customColors.warning) {
                        $('#custom-warning-color').val(customColors.warning);
                        $('#custom-warning-color-hex').val(customColors.warning);
                    }
                    if (customColors.error) {
                        $('#custom-error-color').val(customColors.error);
                        $('#custom-error-color-hex').val(customColors.error);
                    }
                } catch (e) {
                    console.error('Failed to parse custom theme colors:', e);
                }
            }
            
            // Show/hide custom theme builder based on theme selection
            this.toggleCustomThemeBuilder(settings.theme || 'default');
            
            // Notification settings
            $('#settings-show-timestamps').prop('checked', settings.show_timestamps !== false);
            $('#settings-sound-notifications').prop('checked', settings.sound_notifications !== false);
            $('#settings-desktop-notifications').prop('checked', settings.desktop_notifications || false);
            
            // Chat settings
            $('#settings-auto-scroll').prop('checked', settings.auto_scroll !== false);
            $('#settings-word-filter-enabled').prop('checked', settings.word_filter_enabled !== false);
            $('#settings-language').val(settings.language || 'en');
            $('#settings-timezone').val(settings.timezone || 'UTC');
            
            // Apply settings immediately
            this.applyAppearanceSettings(settings);
        },
        
        /**
         * Toggle custom theme builder visibility
         */
        toggleCustomThemeBuilder: function(theme) {
            if (theme === 'custom') {
                $('#custom-theme-builder').show();
            } else {
                $('#custom-theme-builder').hide();
            }
        },
        
        /**
         * Save profile settings
         */
        saveProfileSettings: function() {
            const displayName = $('#settings-display-name').val().trim();
            const bio = $('#settings-bio').val().trim();
            const statusMessage = $('#settings-status-message').val().trim();
            
            // Save profile data
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=profile.php&action=update`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    display_name: displayName,
                    bio: bio,
                    status_message: statusMessage
                }),
                success: (response) => {
                    if (response && response.success) {
                        // Save avatar settings separately
                        this.saveAvatarSettings();
                    } else {
                        this.showAlert('Failed to update profile: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Failed to update profile';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Save avatar settings
         */
        saveAvatarSettings: function() {
            const avatarType = $('input[name="avatar-type"]:checked').val();
            const gravatarEmail = $('#gravatar-email').val().trim();
            
            // Get selected gallery image ID - check multiple methods
            let imageId = null;
            if (avatarType === 'gallery') {
                // Method 1: Check data attribute on container
                imageId = $('#avatar-gallery-grid').data('selected-image-id');
                console.log('Method 1 - Container data attribute:', imageId);
                
                // Method 2: Check for selected class marker
                if (!imageId) {
                    const selectedByClass = $('.avatar-gallery-item.selected-avatar-image');
                    if (selectedByClass.length > 0) {
                        imageId = selectedByClass.data('image-id');
                        console.log('Method 2 - Selected class marker:', imageId);
                    }
                }
                
                // Method 3: Check border style (fallback)
                if (!imageId) {
                    const selectedImage = $('.avatar-gallery-item').filter((i, el) => {
                        const border = $(el).css('border');
                        return border && border.includes('3px');
                    });
                    if (selectedImage.length > 0) {
                        imageId = selectedImage.data('image-id');
                        console.log('Method 3 - Border style:', imageId);
                    }
                }
                
                // Method 4: Check if any image has is_avatar flag (from server)
                if (!imageId) {
                    const avatarImage = $('.avatar-gallery-item').filter((i, el) => {
                        return $(el).data('is-avatar') === true || $(el).data('is-avatar') === 1;
                    });
                    if (avatarImage.length > 0) {
                        imageId = avatarImage.first().data('image-id');
                        console.log('Method 4 - is_avatar flag:', imageId);
                    }
                }
                
                console.log('Final selected imageId:', imageId);
            }
            
            // Validate required fields
            if (avatarType === 'gravatar' && !gravatarEmail) {
                this.showAlert('Please enter your Gravatar email address', 'Error', 'error');
                return;
            }
            
            if (avatarType === 'gallery' && !imageId) {
                this.showAlert('Please select an image from your gallery', 'Error', 'error');
                return;
            }
            
            const avatarData = {
                avatar_type: avatarType,
                gravatar_email: avatarType === 'gravatar' ? gravatarEmail : null,
                image_id: avatarType === 'gallery' && imageId ? imageId : null,
            };
            
            console.log('Saving avatar settings:', avatarData);
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=avatars.php&action=set-avatar`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(avatarData),
                success: (response) => {
                    if (response && response.success) {
                        // Clear selected image ID after successful save
                        $('#avatar-gallery-grid').removeData('selected-image-id');
                        // Reload profile data to get updated avatar settings
                        this.loadProfileForSettings();
                        // Reload avatar gallery to show updated selection
                        this.loadAvatarGallery();
                        // Update profile button avatar after a short delay to ensure profile is loaded
                        setTimeout(() => {
                            this.updateProfileButtonAvatar();
                        }, 500);
                        this.showAlert('Avatar updated successfully!', 'Success', 'success');
                    } else {
                        this.showAlert('Failed to update avatar: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Failed to update avatar';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Upload image to gallery (used by both avatar and gallery tabs)
         */
        uploadGalleryImage: function(file) {
            const formData = new FormData();
            formData.append('image', file);
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=avatars.php&action=upload`,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert('Image uploaded successfully!', 'Success', 'success');
                        this.loadGallery(); // Reload gallery tab
                        this.loadAvatarGallery(); // Reload avatar gallery if open
                    } else {
                        this.showAlert('Failed to upload image: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Failed to upload image';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Upload avatar image (for Profile tab avatar selection)
         */
        uploadAvatarImage: function(file) {
            const formData = new FormData();
            formData.append('image', file);
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=avatars.php&action=upload`,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert('Image uploaded successfully!', 'Success', 'success');
                        this.loadAvatarGallery(); // Refresh gallery
                        // Auto-select gallery option
                        $('input[name="avatar-type"][value="gallery"]').prop('checked', true);
                        $('#avatar-gallery-container').show();
                    } else {
                        this.showAlert('Failed to upload image: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Failed to upload image';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Save appearance settings
         */
        saveAppearanceSettings: function() {
            const theme = $('#settings-theme').val();
            const settings = {
                chat_text_color: $('#settings-chat-text-color').val(),
                chat_name_color: $('#settings-chat-name-color').val(),
                font_size: $('#settings-font-size').val(),
                theme: theme,
                compact_mode: $('#settings-compact-mode').is(':checked')
            };
            
            // Include custom theme colors if custom theme is selected
            if (theme === 'custom') {
                settings.custom_theme_colors = {
                    primary: $('#custom-primary-color').val(),
                    background: $('#custom-background-color').val(),
                    surface: $('#custom-surface-color').val(),
                    text: $('#custom-text-color').val(),
                    border: $('#custom-border-color').val(),
                    success: $('#custom-success-color').val(),
                    warning: $('#custom-warning-color').val(),
                    error: $('#custom-error-color').val()
                };
            }
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=settings.php&action=update`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ settings: settings }),
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert('Appearance settings saved!', 'Success', 'success');
                        // Apply settings immediately
                        this.applyAppearanceSettings(response.settings);
                    } else {
                        this.showAlert('Failed to save settings: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Failed to save settings';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Apply theme to page
         */
        applyTheme: function(theme) {
            // Remove all theme classes
            $('body').removeClass('theme-default theme-dark theme-colorful theme-custom');
            
            if (theme === 'default') {
                // Default theme - no class needed, uses root CSS variables
                return;
            } else if (theme === 'dark' || theme === 'colorful') {
                $('body').addClass(`theme-${theme}`);
            } else if (theme === 'custom') {
                $('body').addClass('theme-custom');
                // Custom theme colors will be applied via applyCustomThemeColors
            }
        },
        
        /**
         * Preview custom theme
         */
        previewCustomTheme: function() {
            const customColors = {
                primary: $('#custom-primary-color').val(),
                background: $('#custom-background-color').val(),
                surface: $('#custom-surface-color').val(),
                text: $('#custom-text-color').val(),
                border: $('#custom-border-color').val(),
                success: $('#custom-success-color').val(),
                warning: $('#custom-warning-color').val(),
                error: $('#custom-error-color').val()
            };
            
            this.applyCustomThemeColors(customColors);
            this.showAlert('Theme preview applied! Save to make it permanent.', 'Preview', 'info');
        },
        
        /**
         * Apply custom theme colors
         */
        applyCustomThemeColors: function(colors) {
            if (!colors) return;
            
            // Calculate darker and lighter variants
            const primaryDark = this.darkenColor(colors.primary, 0.2);
            const primaryLight = this.lightenColor(colors.primary, 0.2);
            
            // Apply CSS variables
            document.documentElement.style.setProperty('--blizzard-blue', colors.primary);
            document.documentElement.style.setProperty('--blizzard-blue-dark', primaryDark);
            document.documentElement.style.setProperty('--blizzard-blue-light', primaryLight);
            document.documentElement.style.setProperty('--ghost-white', colors.background);
            document.documentElement.style.setProperty('--surface-white', colors.surface);
            document.documentElement.style.setProperty('--text-dark', colors.text);
            document.documentElement.style.setProperty('--border-color', colors.border);
            document.documentElement.style.setProperty('--success-color', colors.success);
            document.documentElement.style.setProperty('--warning-color', colors.warning);
            document.documentElement.style.setProperty('--error-color', colors.error);
        },
        
        /**
         * Darken a hex color
         */
        darkenColor: function(hex, percent) {
            const num = parseInt(hex.replace('#', ''), 16);
            const r = Math.max(0, Math.floor((num >> 16) * (1 - percent)));
            const g = Math.max(0, Math.floor(((num >> 8) & 0x00FF) * (1 - percent)));
            const b = Math.max(0, Math.floor((num & 0x0000FF) * (1 - percent)));
            return '#' + ((r << 16) | (g << 8) | b).toString(16).padStart(6, '0');
        },
        
        /**
         * Lighten a hex color
         */
        lightenColor: function(hex, percent) {
            const num = parseInt(hex.replace('#', ''), 16);
            const r = Math.min(255, Math.floor((num >> 16) + (255 - (num >> 16)) * percent));
            const g = Math.min(255, Math.floor(((num >> 8) & 0x00FF) + (255 - ((num >> 8) & 0x00FF)) * percent));
            const b = Math.min(255, Math.floor((num & 0x0000FF) + (255 - (num & 0x0000FF)) * percent));
            return '#' + ((r << 16) | (g << 8) | b).toString(16).padStart(6, '0');
        },
        
        /**
         * Save notification settings
         */
        saveNotificationSettings: function() {
            const settings = {
                show_timestamps: $('#settings-show-timestamps').is(':checked'),
                sound_notifications: $('#settings-sound-notifications').is(':checked'),
                desktop_notifications: $('#settings-desktop-notifications').is(':checked')
            };
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=settings.php&action=update`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ settings: settings }),
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert('Notification settings saved!', 'Success', 'success');
                    } else {
                        this.showAlert('Failed to save settings: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Failed to save settings';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Save chat settings
         */
        saveChatSettings: function() {
            const settings = {
                auto_scroll: $('#settings-auto-scroll').is(':checked'),
                word_filter_enabled: $('#settings-word-filter-enabled').is(':checked'),
                language: $('#settings-language').val(),
                timezone: $('#settings-timezone').val()
            };
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=settings.php&action=update`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ settings: settings }),
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert('Chat preferences saved!', 'Success', 'success');
                        this.autoScrollEnabled = settings.auto_scroll;
                    } else {
                        this.showAlert('Failed to save settings: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Failed to save settings';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Apply appearance settings to chat
         */
        applyAppearanceSettings: function(settings) {
            // Store settings for use in message rendering
            this.userSettings = settings;
            
            // Apply theme
            this.applyTheme(settings.theme || 'default');
            
            // Apply custom theme colors if custom theme is selected
            if (settings.theme === 'custom' && settings.custom_theme_colors) {
                try {
                    const customColors = typeof settings.custom_theme_colors === 'string' 
                        ? JSON.parse(settings.custom_theme_colors) 
                        : settings.custom_theme_colors;
                    this.applyCustomThemeColors(customColors);
                } catch (e) {
                    console.error('Failed to apply custom theme colors:', e);
                }
            }
            
            // Apply CSS variables or inline styles for chat colors
            if (settings.chat_text_color) {
                document.documentElement.style.setProperty('--user-chat-text-color', settings.chat_text_color);
            }
            if (settings.chat_name_color) {
                document.documentElement.style.setProperty('--user-chat-name-color', settings.chat_name_color);
            }
        },
        
        /**
         * Get user's name color from settings
         */
        getUserColor: function(userHandle) {
            if (userHandle === this.config.userHandle && this.userSettings && this.userSettings.chat_name_color) {
                return this.userSettings.chat_name_color;
            }
            return '#0070ff'; // Default blizzard blue
        },
        
        /**
         * Get user's text color from settings
         */
        getUserTextColor: function(userHandle) {
            if (userHandle === this.config.userHandle && this.userSettings && this.userSettings.chat_text_color) {
                return this.userSettings.chat_text_color;
            }
            return '#000000'; // Default black
        },
        
        /**
         * Load user theme on page load
         */
        loadUserTheme: function() {
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=settings.php&action=get`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success && response.settings) {
                        this.applyAppearanceSettings(response.settings);
                    }
                },
                error: (xhr) => {
                    // Silently fail - use default theme
                    console.error('Failed to load theme:', xhr);
                }
            });
        },
        
        /**
         * Word Filter Management (Admin)
         */
        loadWordFilters: function() {
            const status = $('#word-filter-status-filter').val() || '';
            const severity = $('#word-filter-severity-filter').val() || '';
            const search = $('#word-filter-search').val() || '';
            
            let url = `${this.config.apiBase}/proxy.php?path=word-filter.php&action=list`;
            if (status) url += `&status=${status}`;
            if (severity) url += `&severity=${severity}`;
            
            $.ajax({
                url: url,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        let filters = response.filters || [];
                        
                        // Apply search filter
                        if (search) {
                            const searchLower = search.toLowerCase();
                            filters = filters.filter(f => 
                                (f.word_pattern && f.word_pattern.toLowerCase().includes(searchLower)) ||
                                (f.filter_id && f.filter_id.toLowerCase().includes(searchLower))
                            );
                        }
                        
                        this.renderWordFilters(filters);
                    } else {
                        console.error('Word filters API error:', response);
                        $('#word-filters-list').html('<p class="error">Failed to load word filters: ' + this.escapeHtml(response.error || 'Unknown error') + '</p>');
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load word filters:', xhr);
                    const errorMsg = xhr.responseJSON?.error || xhr.responseJSON?.message || 'Failed to load word filters';
                    $('#word-filters-list').html('<p class="error">' + this.escapeHtml(errorMsg) + '</p>');
                }
            });
        },
        
        renderWordFilters: function(filters) {
            const $list = $('#word-filters-list');
            if (filters.length === 0) {
                $list.html('<p>No word filters found</p>');
                return;
            }
            
            // Sort alphabetically by filter_id (or word_pattern if filter_id is null)
            filters.sort((a, b) => {
                const aId = (a.filter_id || a.word_pattern || '').toLowerCase();
                const bId = (b.filter_id || b.word_pattern || '').toLowerCase();
                return aId.localeCompare(bId);
            });
            
            let html = '<table class="word-filters-table" style="width: 100%; border-collapse: collapse;">';
            html += '<thead><tr><th>ID</th><th>Pattern</th><th>Replacement</th><th>Severity</th><th>Tags</th><th>Regex</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
            
            filters.forEach(filter => {
                const tags = filter.tags ? (Array.isArray(filter.tags) ? filter.tags.join(', ') : filter.tags) : '';
                html += `<tr>
                    <td>${this.escapeHtml(filter.filter_id || filter.id)}</td>
                    <td>${this.escapeHtml(filter.word_pattern || '')}</td>
                    <td>${this.escapeHtml(filter.replacement || '*')}</td>
                    <td>${filter.severity || 2}</td>
                    <td>${this.escapeHtml(tags)}</td>
                    <td>${filter.is_regex ? 'Yes' : 'No'}</td>
                    <td>${filter.is_active ? 'Yes' : 'No'}</td>
                    <td>
                        <button class="btn-small edit-word-filter" data-id="${filter.id}">Edit</button>
                        <button class="btn-small delete-word-filter" data-id="${filter.id}">Delete</button>
                    </td>
                </tr>`;
            });
            
            html += '</tbody></table>';
            $list.html(html);
        },
        
        /**
         * Open word filter modal for add/edit
         */
        openWordFilterModal: function(filterId) {
            const $modal = $('#word-filter-modal');
            const $title = $('#word-filter-modal-title');
            const $form = $('#word-filter-form');
            
            if (filterId) {
                // Edit mode - load filter data
                $title.text('Edit Word Filter');
                $.ajax({
                    url: `${this.config.apiBase}/proxy.php?path=word-filter.php&action=get&id=${filterId}`,
                    method: 'GET',
                    success: (response) => {
                        if (response && response.success && response.filter) {
                            const filter = response.filter;
                            $('#word-filter-id').val(filter.id);
                            $('#modal-word-pattern').val(filter.word_pattern || '');
                            $('#modal-replacement').val(filter.replacement || '*');
                            $('#modal-severity').val(filter.severity || 2);
                            $('#modal-tags').val(filter.tags ? (Array.isArray(filter.tags) ? filter.tags.join(', ') : filter.tags) : '');
                            $('#modal-exceptions').val(filter.exceptions ? (Array.isArray(filter.exceptions) ? filter.exceptions.join(', ') : filter.exceptions) : '');
                            $('#modal-is-regex').prop('checked', filter.is_regex || false);
                            $('#modal-is-active').prop('checked', filter.is_active !== false);
                            $modal.addClass('active');
                        } else {
                            this.showAlert('Failed to load filter data', 'Error', 'error');
                        }
                    },
                    error: (xhr) => {
                        const errorMsg = xhr.responseJSON?.error || 'Failed to load filter data';
                        this.showAlert(errorMsg, 'Error', 'error');
                    }
                });
            } else {
                // Add mode - clear form
                $title.text('Add New Word Filter');
                $form[0].reset();
                $('#word-filter-id').val('');
                $('#modal-replacement').val('*');
                $('#modal-severity').val(2);
                $('#modal-is-active').prop('checked', true);
                $modal.addClass('active');
            }
        },
        
        /**
         * Close word filter modal
         */
        closeWordFilterModal: function() {
            $('#word-filter-modal').removeClass('active');
            $('#word-filter-form')[0].reset();
        },
        
        /**
         * Save word filter (add or update)
         */
        saveWordFilter: function() {
            const filterId = $('#word-filter-id').val();
            const wordPattern = $('#modal-word-pattern').val().trim();
            const replacement = $('#modal-replacement').val().trim() || '*';
            const severity = parseInt($('#modal-severity').val()) || 2;
            const tagsStr = $('#modal-tags').val().trim();
            const exceptionsStr = $('#modal-exceptions').val().trim();
            const isRegex = $('#modal-is-regex').is(':checked');
            const isActive = $('#modal-is-active').is(':checked');
            
            if (!wordPattern) {
                this.showAlert('Word pattern is required', 'Error', 'error');
                return;
            }
            
            if (severity < 1 || severity > 4) {
                this.showAlert('Severity must be between 1 and 4', 'Error', 'error');
                return;
            }
            
            // Parse tags and exceptions
            const tags = tagsStr ? tagsStr.split(',').map(t => t.trim()).filter(t => t) : null;
            const exceptions = exceptionsStr ? exceptionsStr.split(',').map(e => e.trim()).filter(e => e) : null;
            
            const action = filterId ? 'update' : 'add';
            const data = {
                word_pattern: wordPattern,
                replacement: replacement,
                severity: severity,
                tags: tags,
                exceptions: exceptions,
                is_regex: isRegex,
                is_active: isActive
            };
            
            if (filterId) {
                data.id = parseInt(filterId);
            }
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=word-filter.php&action=${action}`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(data),
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert(filterId ? 'Word filter updated successfully' : 'Word filter added successfully', 'Success', 'success');
                        this.closeWordFilterModal();
                        this.loadWordFilters();
                    } else {
                        this.showAlert('Failed to save filter: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Failed to save filter';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Delete word filter
         */
        deleteWordFilter: function(filterId) {
            if (!filterId) {
                this.showAlert('Filter ID is required', 'Error', 'error');
                return;
            }
            
            this.showConfirm(
                'Are you sure you want to delete this word filter?',
                'Delete Word Filter',
                () => {
                    $.ajax({
                        url: `${this.config.apiBase}/proxy.php?path=word-filter.php&action=delete`,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ id: filterId }),
                        success: (response) => {
                            if (response && response.success) {
                                this.showAlert('Word filter deleted successfully', 'Success', 'success');
                                this.loadWordFilters();
                            } else {
                                this.showAlert('Failed to delete filter: ' + (response.error || 'Unknown error'), 'Error', 'error');
                            }
                        },
                        error: (xhr) => {
                            const errorMsg = xhr.responseJSON?.error || 'Failed to delete filter';
                            this.showAlert(errorMsg, 'Error', 'error');
                        }
                    });
                }
            );
        },
        
        /**
         * Word Filter Requests (Admin & Moderator)
         */
        loadWordFilterRequests: function() {
            const status = $('#word-filter-request-status-filter').val() || '';
            let url = `${this.config.apiBase}/proxy.php?path=word-filter.php&action=request-list`;
            if (status) url += `&status=${status}`;
            
            $.ajax({
                url: url,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        this.renderWordFilterRequests(response.requests || []);
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load word filter requests:', xhr);
                }
            });
        },
        
        loadModeratorWordFilterRequests: function() {
            // Load requests submitted by current moderator
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=word-filter.php&action=request-list`,
                method: 'GET',
                success: (response) => {
                    if (response && response.success) {
                        const userHandle = this.config.userHandle;
                        const myRequests = (response.requests || []).filter(r => r.requester_handle === userHandle);
                        this.renderModeratorWordFilterRequests(myRequests);
                    }
                },
                error: (xhr) => {
                    console.error('Failed to load word filter requests:', xhr);
                }
            });
        },
        
        renderWordFilterRequests: function(requests) {
            const $list = $('#word-filter-requests-list');
            if (requests.length === 0) {
                $list.html('<p>No word filter requests found</p>');
                return;
            }
            
            let html = '<div class="word-filter-requests-grid">';
            requests.forEach(request => {
                const tags = request.tags ? (Array.isArray(request.tags) ? request.tags.join(', ') : request.tags) : '';
                html += `<div class="word-filter-request-card" style="border: 1px solid var(--border-color); padding: 1rem; margin-bottom: 1rem; border-radius: 4px;">
                    <h4>${this.escapeHtml(request.request_type.toUpperCase())} Request #${request.id}</h4>
                    <p><strong>Requester:</strong> ${this.escapeHtml(request.requester_handle)}</p>
                    <p><strong>Status:</strong> <span class="status-${request.status}">${this.escapeHtml(request.status)}</span></p>
                    ${request.word_pattern ? `<p><strong>Pattern:</strong> ${this.escapeHtml(request.word_pattern)}</p>` : ''}
                    ${request.severity ? `<p><strong>Severity:</strong> ${request.severity}</p>` : ''}
                    ${tags ? `<p><strong>Tags:</strong> ${this.escapeHtml(tags)}</p>` : ''}
                    <p><strong>Justification:</strong> ${this.escapeHtml(request.justification)}</p>
                    ${request.reviewed_by ? `<p><strong>Reviewed by:</strong> ${this.escapeHtml(request.reviewed_by)}</p>` : ''}
                    ${request.review_notes ? `<p><strong>Review Notes:</strong> ${this.escapeHtml(request.review_notes)}</p>` : ''}
                    ${request.status === 'pending' ? `
                        <div style="margin-top: 1rem;">
                            <button class="btn-primary approve-word-filter-request" data-id="${request.id}">Approve</button>
                            <button class="btn-secondary deny-word-filter-request" data-id="${request.id}">Deny</button>
                        </div>
                    ` : ''}
                </div>`;
            });
            html += '</div>';
            $list.html(html);
        },
        
        renderModeratorWordFilterRequests: function(requests) {
            const $list = $('#moderator-word-filter-requests-list');
            if (requests.length === 0) {
                $list.html('<p>No word filter requests submitted yet</p>');
                return;
            }
            
            let html = '<div class="word-filter-requests-grid">';
            requests.forEach(request => {
                html += `<div class="word-filter-request-card" style="border: 1px solid var(--border-color); padding: 1rem; margin-bottom: 1rem; border-radius: 4px;">
                    <h4>${this.escapeHtml(request.request_type.toUpperCase())} Request #${request.id}</h4>
                    <p><strong>Status:</strong> <span class="status-${request.status}">${this.escapeHtml(request.status)}</span></p>
                    ${request.word_pattern ? `<p><strong>Pattern:</strong> ${this.escapeHtml(request.word_pattern)}</p>` : ''}
                    <p><strong>Justification:</strong> ${this.escapeHtml(request.justification)}</p>
                    ${request.review_notes ? `<p><strong>Admin Notes:</strong> ${this.escapeHtml(request.review_notes)}</p>` : ''}
                    <p><small>Submitted: ${new Date(request.created_at).toLocaleString()}</small></p>
                </div>`;
            });
            html += '</div>';
            $list.html(html);
        },
        
        showWordFilterRequestModal: function() {
            $('#word-filter-request-modal').addClass('active');
        },
        
        closeWordFilterRequestModal: function() {
            $('#word-filter-request-modal').removeClass('active');
            $('#word-filter-request-form')[0].reset();
            $('#word-filter-request-filter-id-group').hide();
        },
        
        submitWordFilterRequest: function() {
            const requestType = $('#word-filter-request-type').val();
            const filterId = $('#word-filter-request-filter-id').val();
            const wordPattern = $('#word-filter-request-pattern').val();
            const replacement = $('#word-filter-request-replacement').val() || '*';
            const severity = parseInt($('#word-filter-request-severity').val()) || 2;
            const tagsStr = $('#word-filter-request-tags').val();
            const isRegex = $('#word-filter-request-is-regex').is(':checked');
            const justification = $('#word-filter-request-justification').val();
            
            if (!requestType || !justification) {
                this.showAlert('Request type and justification are required', 'Error', 'error');
                return;
            }
            
            if (requestType !== 'remove' && !wordPattern) {
                this.showAlert('Word pattern is required for add/edit requests', 'Error', 'error');
                return;
            }
            
            if ((requestType === 'edit' || requestType === 'remove') && !filterId) {
                this.showAlert('Filter ID is required for edit/remove requests', 'Error', 'error');
                return;
            }
            
            const tags = tagsStr ? tagsStr.split(',').map(t => t.trim()).filter(t => t) : null;
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=word-filter.php&action=request-create`,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    request_type: requestType,
                    filter_id: filterId || null,
                    word_pattern: wordPattern || null,
                    replacement: replacement,
                    severity: severity,
                    tags: tags,
                    is_regex: isRegex,
                    justification: justification
                }),
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert('Word filter request submitted successfully!', 'Success', 'success');
                        this.closeWordFilterRequestModal();
                        this.loadModeratorWordFilterRequests();
                    } else {
                        this.showAlert('Failed to submit request: ' + (response.error || 'Unknown error'), 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Failed to submit request';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        approveWordFilterRequest: function(requestId) {
            this.showConfirm(
                'Are you sure you want to approve this word filter request?',
                'Approve Request',
                () => {
                    $.ajax({
                        url: `${this.config.apiBase}/proxy.php?path=word-filter.php&action=request-approve`,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ request_id: requestId }),
                        success: (response) => {
                            if (response && response.success) {
                                this.showAlert('Request approved and applied!', 'Success', 'success');
                                this.loadWordFilterRequests();
                                this.loadWordFilters();
                            } else {
                                this.showAlert('Failed to approve request: ' + (response.error || 'Unknown error'), 'Error', 'error');
                            }
                        },
                        error: (xhr) => {
                            const errorMsg = xhr.responseJSON?.error || 'Failed to approve request';
                            this.showAlert(errorMsg, 'Error', 'error');
                        }
                    });
                }
            );
        },
        
        denyWordFilterRequest: function(requestId) {
            this.showPrompt(
                'Enter reason for denial (optional):',
                'Deny Request',
                (reviewNotes) => {
                    $.ajax({
                        url: `${this.config.apiBase}/proxy.php?path=word-filter.php&action=request-deny`,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ 
                            request_id: requestId,
                            review_notes: reviewNotes || ''
                        }),
                        success: (response) => {
                            if (response && response.success) {
                                this.showAlert('Request denied', 'Success', 'success');
                                this.loadWordFilterRequests();
                            } else {
                                this.showAlert('Failed to deny request: ' + (response.error || 'Unknown error'), 'Error', 'error');
                            }
                        },
                        error: (xhr) => {
                            const errorMsg = xhr.responseJSON?.error || 'Failed to deny request';
                            this.showAlert(errorMsg, 'Error', 'error');
                        }
                    });
                }
            );
        },
        
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
        
        /**
         * Load WebSocket server management page
         */
        loadWebSocketManagement: function() {
            // Clear any existing interval first
            if (this.websocketStatusInterval) {
                clearInterval(this.websocketStatusInterval);
                this.websocketStatusInterval = null;
            }
            
            this.loadWebSocketStatus();
            this.loadPythonServerStatus();
            this.loadWebSocketLogs();
            
            // Set up auto-refresh for status (only when on admin/websocket tab)
            this.websocketStatusInterval = setInterval(() => {
                // Only refresh if we're still on the admin view and websocket tab
                if (this.currentView === 'admin') {
                    const activeTab = $('.admin-subtab-btn.active').data('tab');
                    if (activeTab === 'websocket') {
                        this.loadWebSocketStatus();
                        this.loadPythonServerStatus();
                        this.loadWebSocketLogs();
                    } else {
                        // Not on websocket tab anymore, clear interval
                        clearInterval(this.websocketStatusInterval);
                        this.websocketStatusInterval = null;
                    }
                } else {
                    // Not on admin view anymore, clear interval
                    clearInterval(this.websocketStatusInterval);
                    this.websocketStatusInterval = null;
                }
            }, 10000); // Refresh every 10 seconds (reduced frequency)
        },
        
        /**
         * Load WebSocket server status
         */
        loadWebSocketStatus: function() {
            console.log('[WS] Loading WebSocket status...');
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=websocket-admin.php&action=status`,
                method: 'GET',
                timeout: 5000, // 5 second timeout
                success: (response) => {
                    console.log('[WS] Status response:', response);
                    if (response && response.success) {
                        this.renderWebSocketStatus(response);
                    } else {
                        console.error('Failed to load WebSocket status:', response);
                        $('#websocket-status-text').text('Error loading status');
                        $('#websocket-status-light').removeClass('running stopped').addClass('error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('[WS] Status AJAX error:', status, error, xhr);
                    if (status === 'timeout') {
                        console.warn('[WS] Status check timed out');
                        $('#websocket-status-text').text('Status check timed out');
                        $('#websocket-status-light').removeClass('running stopped').addClass('error');
                    } else {
                        console.error('[WS] Error loading status:', error);
                        $('#websocket-status-text').text('Error loading status');
                        $('#websocket-status-light').removeClass('running stopped').addClass('error');
                    }
                }
            });
        },
        
        /**
         * Render WebSocket server status
         */
        renderWebSocketStatus: function(response) {
            const statusLight = $('#websocket-status-light');
            const statusText = $('#websocket-status-text');
            
            statusLight.removeClass('running stopped error');
            
            if (response.running || response.status === 'running') {
                statusLight.addClass('running');
                statusText.text('Server is running');
                
                $('#websocket-port').text(response.port || '-');
                $('#websocket-host').text(response.host || '-');
                $('#websocket-pid').text(response.pid || '-');
                $('#websocket-uptime').text(response.uptime || 'Unknown');
                $('#websocket-connected-users').text(response.connected_users || 0);
                $('#websocket-connections').text(response.connections || 0);
                $('#websocket-active-rooms').text(response.active_rooms || 0);
                
                // Display connected users if available
                if (response.users && response.users.length > 0) {
                    const usersList = $('#websocket-users-list');
                    usersList.empty();
                    response.users.forEach((user) => {
                        usersList.append(`<span class="websocket-user-badge">${this.escapeHtml(user)}</span>`);
                    });
                    $('#websocket-users-section').show();
                } else {
                    $('#websocket-users-section').hide();
                }
                
                // Display active rooms if available
                if (response.rooms && Object.keys(response.rooms).length > 0) {
                    const roomsList = $('#websocket-rooms-list');
                    roomsList.empty();
                    Object.entries(response.rooms).forEach(([roomId, count]) => {
                        roomsList.append(`<div class="websocket-room-item"><span class="room-name">${this.escapeHtml(roomId)}</span><span class="room-count">${count} ${count === 1 ? 'user' : 'users'}</span></div>`);
                    });
                    $('#websocket-rooms-section').show();
                } else {
                    $('#websocket-rooms-section').hide();
                }
                
                // Enable/disable buttons based on status
                $('#websocket-start-btn').prop('disabled', true);
                $('#websocket-stop-btn').prop('disabled', false);
                $('#websocket-restart-btn').prop('disabled', false);
            } else {
                statusLight.addClass('stopped');
                statusText.text('Server is stopped');
                
                $('#websocket-port').text(response.port || '-');
                $('#websocket-host').text(response.host || '-');
                $('#websocket-pid').text('-');
                $('#websocket-uptime').text('-');
                $('#websocket-connected-users').text('0');
                $('#websocket-connections').text('0');
                $('#websocket-active-rooms').text('0');
                $('#websocket-users-section').hide();
                $('#websocket-rooms-section').hide();
                
                // Enable/disable buttons based on status
                $('#websocket-start-btn').prop('disabled', false);
                $('#websocket-stop-btn').prop('disabled', true);
                $('#websocket-restart-btn').prop('disabled', false);
            }
        },
        
        /**
         * Load WebSocket server logs
         */
        loadWebSocketLogs: function() {
            const lines = $('#websocket-logs-lines').val() || 100;
            const server = $('#websocket-logs-server').val() || 'node';
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=websocket-admin.php&action=logs&lines=${lines}&server=${server}`,
                method: 'GET',
                timeout: 5000, // 5 second timeout
                success: (response) => {
                    if (response && response.success) {
                        this.renderWebSocketLogs(response.logs || []);
                    } else {
                        console.error('Failed to load WebSocket logs:', response);
                        $('#websocket-logs-container').html('<div class="error-message">Failed to load logs</div>');
                    }
                },
                error: (xhr, status, error) => {
                    if (status === 'timeout') {
                        console.warn('[WS] Logs check timed out');
                        $('#websocket-logs-container').html('<div class="error-message">Request timed out</div>');
                    } else {
                        console.error('Error loading WebSocket logs:', error);
                        $('#websocket-logs-container').html('<div class="error-message">Error loading logs</div>');
                    }
                }
            });
        },
        
        /**
         * Render WebSocket server logs
         */
        renderWebSocketLogs: function(logs) {
            const container = $('#websocket-logs-container');
            
            if (logs.length === 0) {
                container.html('<div class="no-messages">No logs available</div>');
                return;
            }
            
            let html = '<div class="logs-content">';
            logs.forEach((line) => {
                const escapedLine = this.escapeHtml(line);
                // Color code different log levels
                let className = 'log-line';
                if (line.includes('[ERROR]') || line.includes('Error')) {
                    className += ' log-error';
                } else if (line.includes('[WARN]') || line.includes('Warning')) {
                    className += ' log-warning';
                } else if (line.includes('[INFO]') || line.includes('INFO')) {
                    className += ' log-info';
                } else if (line.includes('[WS]')) {
                    className += ' log-websocket';
                }
                
                html += `<div class="${className}">${escapedLine}</div>`;
            });
            html += '</div>';
            
            container.html(html);
            
            // Auto-scroll to bottom
            const logsContent = container.find('.logs-content');
            if (logsContent.length > 0) {
                logsContent.scrollTop(logsContent[0].scrollHeight);
            }
        },
        
        /**
         * Start WebSocket server
         */
        startWebSocketServer: function() {
            if (!confirm('Start WebSocket server?')) {
                return;
            }
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=websocket-admin.php&action=start`,
                method: 'POST',
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert(response.message || 'WebSocket server started successfully', 'Success', 'success');
                        setTimeout(() => {
                            this.loadWebSocketStatus();
                        }, 2000);
                    } else {
                        this.showAlert(response.message || 'Failed to start WebSocket server', 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Error starting WebSocket server';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Stop WebSocket server
         */
        stopWebSocketServer: function() {
            if (!confirm('Stop WebSocket server? All connected clients will be disconnected.')) {
                return;
            }
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=websocket-admin.php&action=stop`,
                method: 'POST',
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert(response.message || 'WebSocket server stopped successfully', 'Success', 'success');
                        setTimeout(() => {
                            this.loadWebSocketStatus();
                        }, 1000);
                    } else {
                        this.showAlert(response.message || 'Failed to stop WebSocket server', 'Error', 'error');
                    }
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Error stopping WebSocket server';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Restart WebSocket server with polling
         */
        restartWebSocketServer: function() {
            if (!confirm('Restart WebSocket server? All connected clients will be disconnected.')) {
                return;
            }
            
            // Close existing WebSocket connection before restart
            if (this.webSocket && this.webSocket.readyState === WebSocket.OPEN) {
                console.log('[WS] Closing WebSocket connection before restart');
                this.webSocket.close();
                this.webSocket = null;
            }
            
            // Stop monitoring intervals
            if (this.websocketStatusInterval) {
                clearInterval(this.websocketStatusInterval);
                this.websocketStatusInterval = null;
            }
            
            // Update GUI to show waiting state
            $('#websocket-status-text').text('Restarting... Please wait');
            $('#websocket-status-light').removeClass('running stopped error').addClass('stopped');
            $('#websocket-pid').text('-');
            $('#websocket-uptime').text('-');
            $('#websocket-connected-users').text('-');
            $('#websocket-active-rooms').text('-');
            
            // Show loading indicator
            this.showLoading();
            
            // Trigger restart (quick, non-blocking)
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=websocket-admin.php&action=restart`,
                method: 'POST',
                timeout: 10000, // 10 second timeout
                success: (response) => {
                    console.log('[WS] Restart initiated:', response);
                    if (response && response.success) {
                        // Start polling for new process
                        this.pollForRestartCompletion(0);
                    } else {
                        this.hideLoading();
                        this.showAlert(response.message || 'Failed to initiate restart', 'Error', 'error');
                        // Restart monitoring
                        this.loadWebSocketManagement();
                    }
                },
                error: (xhr, status, error) => {
                    this.hideLoading();
                    console.error('[WS] Restart error:', status, error, xhr);
                    const errorMsg = xhr.responseJSON?.error || 'Error initiating restart';
                    this.showAlert(errorMsg, 'Error', 'error');
                    // Restart monitoring
                    this.loadWebSocketManagement();
                }
            });
        },
        
        /**
         * Poll for WebSocket server restart completion
         */
        pollForRestartCompletion: function(attempt) {
            const maxAttempts = 6; // 6 attempts = 30 seconds total (5 seconds each)
            const pollInterval = 5000; // 5 seconds
            
            if (attempt >= maxAttempts) {
                // Timeout - server didn't start
                this.hideLoading();
                $('#websocket-status-text').text('Restart timeout - server may not have started');
                $('#websocket-status-light').removeClass('running stopped').addClass('error');
                this.showAlert('Server restart timed out after 30 seconds. Please check logs and try manually starting the server.', 'Error', 'error');
                // Restart monitoring - this will detect if server is actually running
                this.loadWebSocketManagement();
                return;
            }
            
            // Update status message
            const remainingSeconds = (maxAttempts - attempt) * 5;
            $('#websocket-status-text').text(`Restarting... Waiting for server to start (${remainingSeconds}s remaining)`);
            
            // Check if server is running (uses same detection logic as status check)
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=websocket-admin.php&action=status`,
                method: 'GET',
                timeout: 5000,
                success: (response) => {
                    console.log(`[WS] Poll attempt ${attempt + 1}/${maxAttempts}:`, response);
                    
                    // Check if server is running (same logic as status check - detects by port)
                    if (response && response.success && response.running && response.pid) {
                        // Server is running! Update GUI and reconnect
                        console.log(`[WS] Server restarted successfully! New PID: ${response.pid}, Port: ${response.port}`);
                        this.hideLoading();
                        $('#websocket-status-text').text('Server is running');
                        $('#websocket-status-light').removeClass('stopped error').addClass('running');
                        
                        // Update status display
                        this.renderWebSocketStatus(response);
                        
                        // Reconnect WebSocket (use actual port server is running on)
                        setTimeout(() => {
                            console.log(`[WS] Reconnecting WebSocket after restart on port ${response.port}...`);
                            // Update config with actual port if different
                            if (response.port && this.config.websocket) {
                                this.config.websocket.port = response.port;
                            }
                            this.connectWebSocket();
                        }, 1000);
                        
                        // Restart monitoring
                        this.loadWebSocketManagement();
                    } else {
                        // Server not running yet, poll again
                        setTimeout(() => {
                            this.pollForRestartCompletion(attempt + 1);
                        }, pollInterval);
                    }
                },
                error: (xhr, status, error) => {
                    console.error(`[WS] Poll attempt ${attempt + 1} error:`, status, error);
                    // Continue polling even on error (might be temporary)
                    setTimeout(() => {
                        this.pollForRestartCompletion(attempt + 1);
                    }, pollInterval);
                }
            });
        },
        
        /**
         * Clear WebSocket logs
         */
        clearWebSocketLogs: function() {
            if (!confirm('Clear WebSocket server logs? This cannot be undone.')) {
                return;
            }
            
            // Clear log file
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=websocket-admin.php&action=logs&clear=1`,
                method: 'POST',
                success: () => {
                    this.showAlert('Logs cleared successfully', 'Success', 'success');
                    this.loadWebSocketLogs();
                },
                error: (xhr) => {
                    const errorMsg = xhr.responseJSON?.error || 'Error clearing logs';
                    this.showAlert(errorMsg, 'Error', 'error');
                }
            });
        },
        
        /**
         * Load Python server status
         */
        loadPythonServerStatus: function() {
            console.log('[Python WS] Loading Python server status...');
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=websocket-admin.php&action=python-status`,
                method: 'GET',
                timeout: 5000,
                success: (response) => {
                    console.log('[Python WS] Status response:', response);
                    if (response && response.success) {
                        this.renderPythonServerStatus(response);
                    } else {
                        console.error('Failed to load Python server status:', response);
                        $('#python-status-text').text('Error loading status');
                        $('#python-status-light').removeClass('running stopped').addClass('error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('[Python WS] Status AJAX error:', status, error, xhr);
                    if (status === 'timeout') {
                        $('#python-status-text').text('Status check timed out');
                        $('#python-status-light').removeClass('running stopped').addClass('error');
                    } else {
                        $('#python-status-text').text('Error loading status');
                        $('#python-status-light').removeClass('running stopped').addClass('error');
                    }
                }
            });
        },
        
        /**
         * Render Python server status
         */
        renderPythonServerStatus: function(response) {
            const statusLight = $('#python-status-light');
            const statusText = $('#python-status-text');

            statusLight.removeClass('running stopped error');

            if (response.running || response.status === 'running') {
                statusLight.addClass('running');
                statusText.text('Python Server is running');

                // Extract stats from response (same structure as Node.js)
                const stats = response.stats || {};
                const pythonServer = stats.python_server || {};
                const nodeServer = stats.node_server || {};

                $('#python-port').text(response.port || pythonServer.port || '-');
                $('#python-pid').text(response.pid || pythonServer.pid || '-');
                $('#python-uptime').text(response.uptime || pythonServer.uptime || 'Unknown');
                $('#python-node-restarts').text(response.node_restarts || pythonServer.node_restarts || nodeServer.restart_count || 0);

                $('#python-start-btn').prop('disabled', true);
                $('#python-stop-btn').prop('disabled', false);
                $('#python-restart-btn').prop('disabled', false);
            } else {
                statusLight.addClass('stopped');
                statusText.text('Python Server is stopped');

                $('#python-port').text(response.port || '-');
                $('#python-pid').text('-');
                $('#python-uptime').text('-');
                $('#python-node-restarts').text('-');

                $('#python-start-btn').prop('disabled', false);
                $('#python-stop-btn').prop('disabled', true);
                $('#python-restart-btn').prop('disabled', false);
            }
        },
        
        /**
         * Start Python server
         */
        startPythonServer: function() {
            if (!confirm('Start Python server?')) {
                return;
            }
            
            $('#python-status-text').text('Starting...');
            $('#python-start-btn').prop('disabled', true);
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=websocket-admin.php&action=python-start`,
                method: 'POST',
                timeout: 10000, // 10 second timeout
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert(response.message || 'Python server started successfully', 'Success', 'success');
                        setTimeout(() => {
                            this.loadPythonServerStatus();
                        }, 2000);
                    } else {
                        $('#python-start-btn').prop('disabled', false);
                        this.showAlert(response.message || 'Failed to start Python server', 'Error', 'error');
                        this.loadPythonServerStatus();
                    }
                },
                error: (xhr, status, error) => {
                    $('#python-start-btn').prop('disabled', false);
                    console.error('[Python WS] Start error:', status, error, xhr);
                    let errorMsg = 'Error starting Python server';
                    if (status === 'timeout') {
                        errorMsg = 'Request timed out - server may still be starting. Check logs.';
                    } else if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg = xhr.responseJSON.error;
                    }
                    this.showAlert(errorMsg, 'Error', 'error');
                    this.loadPythonServerStatus();
                }
            });
        },
        
        /**
         * Stop Python server
         */
        stopPythonServer: function() {
            if (!confirm('Stop Python server? This will also stop the Node.js server.')) {
                return;
            }
            
            $('#python-status-text').text('Stopping...');
            $('#python-stop-btn').prop('disabled', true);
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=websocket-admin.php&action=python-stop`,
                method: 'POST',
                timeout: 10000,
                success: (response) => {
                    if (response && response.success) {
                        this.showAlert(response.message || 'Python server stopped successfully', 'Success', 'success');
                        setTimeout(() => {
                            this.loadPythonServerStatus();
                            this.loadWebSocketStatus(); // Also refresh Node.js status
                        }, 1000);
                    } else {
                        $('#python-stop-btn').prop('disabled', false);
                        this.showAlert(response.message || 'Failed to stop Python server', 'Error', 'error');
                        this.loadPythonServerStatus();
                    }
                },
                error: (xhr) => {
                    $('#python-stop-btn').prop('disabled', false);
                    const errorMsg = xhr.responseJSON?.error || 'Error stopping Python server';
                    this.showAlert(errorMsg, 'Error', 'error');
                    this.loadPythonServerStatus();
                }
            });
        },
        
        /**
         * Restart Python server
         */
        restartPythonServer: function() {
            if (!confirm('Restart Python server? This will also restart the Node.js server.')) {
                return;
            }
            
            $('#python-status-text').text('Restarting... Please wait');
            $('#python-restart-btn').prop('disabled', true);
            this.showLoading();
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=websocket-admin.php&action=python-restart`,
                method: 'POST',
                timeout: 10000,
                success: (response) => {
                    console.log('[Python WS] Restart initiated:', response);
                    if (response && response.success) {
                        if (response.polling_required) {
                            this.pollForPythonRestartCompletion(0);
                        } else {
                            this.hideLoading();
                            $('#python-restart-btn').prop('disabled', false);
                            this.showAlert(response.message || 'Python server restarted successfully', 'Success', 'success');
                            setTimeout(() => {
                                this.loadPythonServerStatus();
                                this.loadWebSocketStatus();
                            }, 2000);
                        }
                    } else {
                        this.hideLoading();
                        $('#python-restart-btn').prop('disabled', false);
                        this.showAlert(response.message || 'Failed to restart Python server', 'Error', 'error');
                        this.loadPythonServerStatus();
                    }
                },
                error: (xhr, status, error) => {
                    this.hideLoading();
                    $('#python-restart-btn').prop('disabled', false);
                    console.error('[Python WS] Restart error:', status, error, xhr);
                    let errorMsg = 'Error restarting Python server';
                    if (status === 'timeout') {
                        errorMsg = 'Request timed out - server may still be restarting. Check logs.';
                    } else if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg = xhr.responseJSON.error;
                    }
                    this.showAlert(errorMsg, 'Error', 'error');
                    this.loadPythonServerStatus();
                }
            });
        },
        
        /**
         * Poll for Python server restart completion
         */
        pollForPythonRestartCompletion: function(attempt) {
            const maxAttempts = 6; // 6 attempts = 30 seconds total
            const pollInterval = 5000; // 5 seconds
            
            if (attempt >= maxAttempts) {
                this.hideLoading();
                $('#python-restart-btn').prop('disabled', false);
                $('#python-status-text').text('Restart timeout - server may not have started');
                $('#python-status-light').removeClass('running stopped').addClass('error');
                this.showAlert('Server restart timed out after 30 seconds. Please check logs.', 'Error', 'error');
                this.loadPythonServerStatus();
                return;
            }
            
            const remainingSeconds = (maxAttempts - attempt) * 5;
            $('#python-status-text').text(`Restarting... Waiting for server to start (${remainingSeconds}s remaining)`);
            
            $.ajax({
                url: `${this.config.apiBase}/proxy.php?path=websocket-admin.php&action=python-status`,
                method: 'GET',
                timeout: 5000,
                success: (response) => {
                    if (response && response.success && response.running && response.pid) {
                        this.hideLoading();
                        $('#python-restart-btn').prop('disabled', false);
                        $('#python-status-text').text('Python Server is running');
                        this.showAlert('Python server restarted successfully', 'Success', 'success');
                        this.loadPythonServerStatus();
                        this.loadWebSocketStatus();
                    } else {
                        setTimeout(() => {
                            this.pollForPythonRestartCompletion(attempt + 1);
                        }, pollInterval);
                    }
                },
                error: () => {
                    setTimeout(() => {
                        this.pollForPythonRestartCompletion(attempt + 1);
                    }, pollInterval);
                }
            });
        }
    };
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        App.init();
    });
    
    // Expose App globally for debugging
    window.SentinelApp = App;
})();

