/**
 * Sentinel Chat Platform - Chatbot Bot Admin UI
 * 
 * Handles all chatbot bot management UI interactions including:
 * - Bot status monitoring
 * - Bot user creation
 * - Dependency checking and installation
 * - Bot configuration
 * - Bot control (start/stop/restart)
 * - Log viewing
 */

(function() {
    'use strict';
    
    const ChatbotAdmin = {
        /**
         * Initialize chatbot admin UI
         */
        init: function() {
            // Only initialize if we're on the chatbot tab
            if (!$('#admin-tab-chatbot').length) {
                return;
            }
            
            // Bind event handlers
            this.bindEvents();
            
            // Load initial data
            this.loadStatus();
            this.checkUser();
            this.checkDependencies();
            this.loadConfig();
            this.loadLogs();
            
            // Auto-refresh status every 5 seconds
            setInterval(() => {
                if ($('#admin-tab-chatbot').hasClass('active')) {
                    this.loadStatus();
                }
            }, 5000);
        },
        
        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Bot control buttons
            $('#chatbot-start-btn').on('click', () => this.startBot());
            $('#chatbot-stop-btn').on('click', () => this.stopBot());
            $('#chatbot-restart-btn').on('click', () => this.restartBot());
            $('#chatbot-refresh-status-btn').on('click', () => {
                const $btn = $('#chatbot-refresh-status-btn');
                const originalHtml = $btn.html();
                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Refreshing...');
                this.loadStatus(() => {
                    // Re-enable button after status loads
                    $btn.prop('disabled', false).html(originalHtml);
                });
            });
            
            // Bot user creation
            $('#chatbot-create-user-btn').on('click', () => this.showCreateUserForm());
            $('#chatbot-cancel-user-form-btn').on('click', () => this.hideCreateUserForm());
            $('#chatbot-user-form').on('submit', (e) => {
                e.preventDefault();
                this.createUser();
            });
            
            // Bot settings
            $('#chatbot-settings-form').on('submit', (e) => {
                e.preventDefault();
                this.saveConfig();
            });
            
            // AI provider change
            $('#chatbot-ai-provider-input').on('change', () => this.updateProviderFields());
            
            // Dependencies
            $('#chatbot-install-deps-btn').on('click', () => this.installDependencies());
            
            // Logs
            $('#chatbot-logs-refresh-btn').on('click', () => this.loadLogs());
            $('#chatbot-logs-clear-btn').on('click', () => this.clearLogs());
            $('#chatbot-logs-lines').on('change', () => this.loadLogs());
        },
        
        /**
         * Load bot status
         */
        loadStatus: function(callback) {
            $.ajax({
                url: '/iChat/api/proxy.php',
                method: 'GET',
                data: {
                    path: 'chatbot-admin',
                    action: 'status'
                },
                dataType: 'json',
                success: (response) => {
                    console.log('Bot status response:', response); // Debug log
                    if (response.success) {
                        this.updateStatus(response.status, response.config);
                    } else {
                        this.showError('Failed to load bot status: ' + (response.error || 'Unknown error'));
                    }
                    if (callback && typeof callback === 'function') {
                        callback();
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error loading bot status:', error, xhr.responseText);
                    this.showError('Failed to load bot status: ' + (xhr.responseText || error));
                    if (callback && typeof callback === 'function') {
                        callback();
                    }
                }
            });
        },
        
        /**
         * Update status display
         */
        updateStatus: function(status, config) {
            console.log('Updating status display:', status, config); // Debug log
            const $light = $('#chatbot-status-light');
            const $text = $('#chatbot-status-text');
            
            if (!status) {
                console.error('Status object is null or undefined');
                return;
            }
            
            if (status.running) {
                $light.css('background', '#4caf50');
                $text.text('Running');
                $('#chatbot-start-btn').prop('disabled', true);
                $('#chatbot-stop-btn').prop('disabled', false);
                $('#chatbot-restart-btn').prop('disabled', false);
            } else {
                $light.css('background', '#ccc');
                $text.text('Stopped');
                $('#chatbot-start-btn').prop('disabled', false);
                $('#chatbot-stop-btn').prop('disabled', true);
                $('#chatbot-restart-btn').prop('disabled', true);
            }
            
            if (config) {
                $('#chatbot-handle').text(config.bot_handle || '-');
                $('#chatbot-room').text(config.default_room || '-');
            }
            
            $('#chatbot-pid').text(status.pid || '-');
            $('#chatbot-uptime').text(status.uptime || '-');
        },
        
        /**
         * Start bot
         */
        startBot: function() {
            if (!confirm('Start the chatbot bot?')) {
                return;
            }
            
            $('#chatbot-start-btn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Starting...');
            
            $.ajax({
                url: '/iChat/api/proxy.php',
                method: 'GET',
                data: {
                    path: 'chatbot-admin',
                    action: 'start'
                },
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        this.showSuccess('Bot started successfully');
                        this.loadStatus();
                    } else {
                        this.showError('Failed to start bot: ' + (response.error || 'Unknown error'));
                        $('#chatbot-start-btn').prop('disabled', false).html('<i class="fas fa-play"></i> Start Bot');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error starting bot:', error);
                    this.showError('Failed to start bot');
                    $('#chatbot-start-btn').prop('disabled', false).html('<i class="fas fa-play"></i> Start Bot');
                }
            });
        },
        
        /**
         * Stop bot
         */
        stopBot: function() {
            if (!confirm('Stop the chatbot bot?')) {
                return;
            }
            
            const $btn = $('#chatbot-stop-btn');
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Stopping...');
            
            $.ajax({
                url: '/iChat/api/proxy.php',
                method: 'GET',
                data: {
                    path: 'chatbot-admin',
                    action: 'stop'
                },
                dataType: 'json',
                success: (response) => {
                    console.log('Stop bot response:', response); // Debug log
                    if (response.success) {
                        this.showSuccess('Bot stopped successfully');
                        this.loadStatus(() => {
                            $btn.prop('disabled', false).html(originalHtml);
                        });
                    } else {
                        this.showError('Failed to stop bot: ' + (response.error || 'Unknown error'));
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error stopping bot:', error, xhr.responseText);
                    const errorMsg = xhr.responseJSON?.error || xhr.responseText || error;
                    this.showError('Failed to stop bot: ' + errorMsg);
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        },
        
        /**
         * Restart bot
         */
        restartBot: function() {
            if (!confirm('Restart the chatbot bot?')) {
                return;
            }
            
            const $btn = $('#chatbot-restart-btn');
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Restarting...');
            
            $.ajax({
                url: '/iChat/api/proxy.php',
                method: 'GET',
                data: {
                    path: 'chatbot-admin',
                    action: 'restart'
                },
                dataType: 'json',
                success: (response) => {
                    console.log('Restart bot response:', response); // Debug log
                    if (response.success) {
                        this.showSuccess('Bot restarted successfully');
                        // Wait a moment for bot to start, then refresh status
                        setTimeout(() => {
                            this.loadStatus(() => {
                                $btn.prop('disabled', false).html(originalHtml);
                            });
                        }, 2000);
                    } else {
                        this.showError('Failed to restart bot: ' + (response.error || 'Unknown error'));
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error restarting bot:', error, xhr.responseText);
                    const errorMsg = xhr.responseJSON?.error || xhr.responseText || error;
                    this.showError('Failed to restart bot: ' + errorMsg);
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        },
        
        /**
         * Check if bot user exists
         */
        checkUser: function() {
            $.ajax({
                url: '/iChat/api/proxy.php',
                method: 'GET',
                data: {
                    path: 'chatbot-admin',
                    action: 'check-user'
                },
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        if (response.exists) {
                            $('#chatbot-user-status').html(`
                                <div style="color: #4caf50; margin-bottom: 0.5rem;">
                                    <i class="fas fa-check-circle"></i> Bot user exists: <strong>${response.user.username}</strong> (${response.user.role})
                                </div>
                            `);
                            $('#chatbot-create-user-btn').hide();
                            $('#chatbot-create-user-form').hide();
                        } else {
                            $('#chatbot-user-status').html(`
                                <div style="color: #f44336; margin-bottom: 0.5rem;">
                                    <i class="fas fa-exclamation-circle"></i> Bot user does not exist. Create one to start the bot.
                                </div>
                            `);
                            $('#chatbot-create-user-btn').show();
                        }
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error checking bot user:', error);
                    $('#chatbot-user-status').html('<div class="loading-messages">Error checking bot user</div>');
                }
            });
        },
        
        /**
         * Show create user form
         */
        showCreateUserForm: function() {
            $('#chatbot-create-user-form').slideDown();
        },
        
        /**
         * Hide create user form
         */
        hideCreateUserForm: function() {
            $('#chatbot-create-user-form').slideUp();
            $('#chatbot-user-form')[0].reset();
        },
        
        /**
         * Create bot user
         */
        createUser: function() {
            const handle = $('#chatbot-handle-input').val().trim();
            const email = $('#chatbot-email-input').val().trim();
            const role = $('#chatbot-role-input').val();
            
            if (!handle || !email) {
                this.showError('Handle and email are required');
                return;
            }
            
            $.ajax({
                url: '/iChat/api/proxy.php?path=chatbot-admin&action=create-user',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    handle: handle,
                    email: email,
                    role: role
                }),
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        this.showSuccess('Bot user created successfully');
                        this.hideCreateUserForm();
                        this.checkUser();
                        this.loadConfig();
                    } else {
                        this.showError('Failed to create bot user: ' + (response.error || 'Unknown error'));
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error creating bot user:', error);
                    const errorMsg = xhr.responseJSON?.error || error;
                    this.showError('Failed to create bot user: ' + errorMsg);
                }
            });
        },
        
        /**
         * Check dependencies
         */
        checkDependencies: function() {
            $.ajax({
                url: '/iChat/api/proxy.php',
                method: 'GET',
                data: {
                    path: 'chatbot-admin',
                    action: 'check-dependencies'
                },
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        this.updateDependenciesStatus(response.dependencies);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error checking dependencies:', error);
                    $('#chatbot-dependencies-status').html('<div class="loading-messages">Error checking dependencies</div>');
                }
            });
        },
        
        /**
         * Update dependencies status display
         */
        updateDependenciesStatus: function(deps) {
            if (!deps.python || !deps.python.available) {
                $('#chatbot-dependencies-status').html(`
                    <div style="color: #f44336; margin-bottom: 0.5rem;">
                        <i class="fas fa-times-circle"></i> Python is not available. Please install Python 3.6+.
                    </div>
                `);
                $('#chatbot-install-deps-btn').hide();
                return;
            }
            
            if (deps.error) {
                $('#chatbot-dependencies-status').html(`
                    <div style="color: #f44336; margin-bottom: 0.5rem;">
                        <i class="fas fa-exclamation-circle"></i> ${deps.error}
                    </div>
                `);
                $('#chatbot-install-deps-btn').hide();
                return;
            }
            
            if (deps.installed) {
                $('#chatbot-dependencies-status').html(`
                    <div style="color: #4caf50; margin-bottom: 0.5rem;">
                        <i class="fas fa-check-circle"></i> All dependencies installed
                    </div>
                    <div style="font-size: 0.9rem; color: var(--text-medium);">
                        Python: ${deps.python.version}
                    </div>
                `);
                $('#chatbot-install-deps-btn').hide();
            } else {
                const missing = deps.dependencies.filter(d => !d.installed).map(d => d.name).join(', ');
                $('#chatbot-dependencies-status').html(`
                    <div style="color: #ff9800; margin-bottom: 0.5rem;">
                        <i class="fas fa-exclamation-triangle"></i> Missing dependencies: ${missing}
                    </div>
                    <div style="font-size: 0.9rem; color: var(--text-medium);">
                        Python: ${deps.python.version}
                    </div>
                `);
                $('#chatbot-install-deps-btn').show();
            }
        },
        
        /**
         * Install dependencies
         */
        installDependencies: function() {
            if (!confirm('Install missing Python dependencies? This may take a few moments.')) {
                return;
            }
            
            $('#chatbot-install-deps-btn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Installing...');
            
            $.ajax({
                url: '/iChat/api/proxy.php',
                method: 'GET',
                data: {
                    path: 'chatbot-admin',
                    action: 'install-dependencies'
                },
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        this.showSuccess('Dependencies installed successfully');
                        this.checkDependencies();
                    } else {
                        this.showError('Failed to install dependencies: ' + (response.error || 'Unknown error'));
                    }
                    $('#chatbot-install-deps-btn').prop('disabled', false).html('<i class="fas fa-download"></i> Install Dependencies');
                },
                error: (xhr, status, error) => {
                    console.error('Error installing dependencies:', error);
                    this.showError('Failed to install dependencies');
                    $('#chatbot-install-deps-btn').prop('disabled', false).html('<i class="fas fa-download"></i> Install Dependencies');
                }
            });
        },
        
        /**
         * Load bot configuration
         */
        loadConfig: function() {
            $.ajax({
                url: '/iChat/api/proxy.php',
                method: 'GET',
                data: {
                    path: 'chatbot-admin',
                    action: 'status'
                },
                dataType: 'json',
                success: (response) => {
                    if (response.success && response.config) {
                        const config = response.config;
                        $('#chatbot-display-name-input').val(config.bot_display_name || '');
                        $('#chatbot-default-room-input').val(config.default_room || 'lobby');
                        $('#chatbot-response-delay-input').val(config.response_delay || 2.0);
                        $('#chatbot-ai-provider-input').val(config.ai_provider || 'simple');
                        $('#chatbot-openai-key-input').val(config.openai_api_key || '');
                        $('#chatbot-ollama-url-input').val(config.ollama_url || 'http://localhost:11434');
                        $('#chatbot-ollama-model-input').val(config.ollama_model || 'llama2');
                        this.updateProviderFields();
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error loading config:', error);
                }
            });
        },
        
        /**
         * Update provider-specific fields visibility
         */
        updateProviderFields: function() {
            const provider = $('#chatbot-ai-provider-input').val();
            if (provider === 'openai') {
                $('#chatbot-openai-key-group').show();
                $('#chatbot-ollama-url-group').hide();
                $('#chatbot-ollama-model-group').hide();
            } else if (provider === 'ollama') {
                $('#chatbot-openai-key-group').hide();
                $('#chatbot-ollama-url-group').show();
                $('#chatbot-ollama-model-group').show();
            } else {
                $('#chatbot-openai-key-group').hide();
                $('#chatbot-ollama-url-group').hide();
                $('#chatbot-ollama-model-group').hide();
            }
        },
        
        /**
         * Save bot configuration
         */
        saveConfig: function() {
            const config = {
                display_name: $('#chatbot-display-name-input').val().trim(),
                default_room: $('#chatbot-default-room-input').val().trim(),
                response_delay: parseFloat($('#chatbot-response-delay-input').val()) || 2.0,
                ai_provider: $('#chatbot-ai-provider-input').val(),
                openai_api_key: $('#chatbot-openai-key-input').val().trim(),
                ollama_url: $('#chatbot-ollama-url-input').val().trim(),
                ollama_model: $('#chatbot-ollama-model-input').val().trim(),
            };
            
            $.ajax({
                url: '/iChat/api/proxy.php?path=chatbot-admin&action=save-config',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(config),
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        this.showSuccess('Configuration saved successfully');
                        this.loadStatus();
                    } else {
                        this.showError('Failed to save configuration: ' + (response.error || 'Unknown error'));
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error saving config:', error);
                    const errorMsg = xhr.responseJSON?.error || error;
                    this.showError('Failed to save configuration: ' + errorMsg);
                }
            });
        },
        
        /**
         * Load bot logs
         */
        loadLogs: function() {
            const lines = $('#chatbot-logs-lines').val() || 100;
            
            $.ajax({
                url: '/iChat/api/proxy.php',
                method: 'GET',
                data: {
                    path: 'chatbot-admin',
                    action: 'logs',
                    lines: lines
                },
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        $('#chatbot-logs-container').text(response.logs || 'No logs available');
                        // Auto-scroll to bottom
                        const $container = $('#chatbot-logs-container');
                        $container.scrollTop($container[0].scrollHeight);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error loading logs:', error);
                    $('#chatbot-logs-container').text('Error loading logs');
                }
            });
        },
        
        /**
         * Clear bot logs
         */
        clearLogs: function() {
            if (!confirm('Clear bot logs?')) {
                return;
            }
            
            $.ajax({
                url: '/iChat/api/proxy.php',
                method: 'GET',
                data: {
                    path: 'chatbot-admin',
                    action: 'clear-logs'
                },
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        this.showSuccess('Logs cleared');
                        this.loadLogs();
                    } else {
                        this.showError('Failed to clear logs');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error clearing logs:', error);
                    this.showError('Failed to clear logs');
                }
            });
        },
        
        /**
         * Show success message
         */
        showSuccess: function(message) {
            if (typeof App !== 'undefined' && App.showAlert) {
                App.showAlert(message, 'Success', 'success');
            } else {
                alert('Success: ' + message);
            }
        },
        
        /**
         * Show error message
         */
        showError: function(message) {
            if (typeof App !== 'undefined' && App.showAlert) {
                App.showAlert(message, 'Error', 'error');
            } else {
                alert('Error: ' + message);
            }
        }
    };
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        ChatbotAdmin.init();
        
        // Re-initialize when chatbot tab is activated
        $(document).on('click', '.admin-subtab-btn[data-tab="chatbot"]', function() {
            setTimeout(() => {
                ChatbotAdmin.loadStatus();
                ChatbotAdmin.checkUser();
                ChatbotAdmin.checkDependencies();
                ChatbotAdmin.loadConfig();
                ChatbotAdmin.loadLogs();
            }, 100);
        });
    });
    
    // Export for global access
    window.ChatbotAdmin = ChatbotAdmin;
})();

