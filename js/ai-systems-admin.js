/**
 * Sentinel Chat Platform - AI Systems Admin UI
 * 
 * Front end for managing AI-powered features in the admin panel.
 */

(function() {
    'use strict';

    const AISystemsAdmin = {
        /**
         * Initialize AI Systems admin
         */
        init() {
            // Load configurations when AI Systems tab is opened
            $(document).on('click', '.admin-category-btn[data-category="ai-systems"]', () => {
                this.loadConfigurations();
            });
            
            // Load specific tab content
            $(document).on('click', '.admin-subtab-btn[data-tab^="ai-"]', (e) => {
                const tab = $(e.target).closest('.admin-subtab-btn').data('tab');
                this.loadTabContent(tab);
            });
            
            // Test moderation button
            $(document).on('click', '#test-moderation-btn', () => {
                this.showTestModerationDialog();
            });
            
            // Refresh moderation logs
            $(document).on('click', '#refresh-moderation-logs-btn', () => {
                this.loadModerationLogs();
            });
            
            // Save configuration
            $(document).on('click', '.ai-config-save-btn', (e) => {
                const systemName = $(e.target).data('system');
                this.saveConfiguration(systemName);
            });
        },

        /**
         * Load all AI system configurations
         */
        async loadConfigurations() {
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=ai-systems.php&action=config`);
                const result = await response.json();
                
                if (result.success && result.configs) {
                    this.renderConfigurations(result.configs);
                }
            } catch (e) {
                console.error('Failed to load AI configurations:', e);
                window.App.showAlert('Failed to load AI configurations', 'Error', 'error');
            }
        },

        /**
         * Render AI system configurations
         */
        renderConfigurations(configs) {
            const container = $('#ai-systems-config-list');
            if (!container.length) return;
            
            container.empty();
            
            configs.forEach((config) => {
                const configCard = $(`
                    <div class="ai-config-card" data-system="${this.escapeHtml(config.system_name)}">
                        <div class="ai-config-header">
                            <h4>${this.escapeHtml(this.formatSystemName(config.system_name))}</h4>
                            <label class="ai-toggle-switch">
                                <input type="checkbox" class="ai-enabled-toggle" ${config.enabled ? 'checked' : ''} data-system="${this.escapeHtml(config.system_name)}">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="ai-config-body">
                            <div class="form-group">
                                <label>Provider:</label>
                                <select class="ai-provider-select" data-system="${this.escapeHtml(config.system_name)}">
                                    <option value="">None (Local)</option>
                                    <option value="openai" ${config.provider === 'openai' ? 'selected' : ''}>OpenAI</option>
                                    <option value="anthropic" ${config.provider === 'anthropic' ? 'selected' : ''}>Anthropic (Claude)</option>
                                    <option value="local" ${config.provider === 'local' ? 'selected' : ''}>Local ML</option>
                                    <option value="custom" ${config.provider === 'custom' ? 'selected' : ''}>Custom API</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Model Name:</label>
                                <input type="text" class="ai-model-input" data-system="${this.escapeHtml(config.system_name)}" value="${this.escapeHtml(config.model_name || '')}" placeholder="e.g., gpt-4, claude-3, local-model">
                            </div>
                            <div class="form-group">
                                <label>API Key:</label>
                                <input type="password" class="ai-api-key-input" data-system="${this.escapeHtml(config.system_name)}" placeholder="Enter API key (encrypted)">
                            </div>
                            <div class="form-group">
                                <label>Additional Config (JSON):</label>
                                <textarea class="ai-config-json" data-system="${this.escapeHtml(config.system_name)}" rows="3" placeholder='{"key": "value"}'>${this.escapeHtml(config.config_json ? JSON.stringify(JSON.parse(config.config_json), null, 2) : '')}</textarea>
                            </div>
                            <button class="btn-primary ai-config-save-btn" data-system="${this.escapeHtml(config.system_name)}">Save Configuration</button>
                        </div>
                    </div>
                `);
                
                container.append(configCard);
            });
            
            // Setup toggle handlers
            $('.ai-enabled-toggle').on('change', (e) => {
                const systemName = $(e.target).data('system');
                this.toggleSystem(systemName, $(e.target).is(':checked'));
            });
        },

        /**
         * Load tab content
         */
        async loadTabContent(tab) {
            switch (tab) {
                case 'ai-config':
                    await this.loadConfigurations();
                    break;
                case 'ai-moderation':
                    await this.loadModerationLogs();
                    break;
                case 'ai-smart-replies':
                    await this.loadSmartRepliesConfig();
                    break;
                case 'ai-summarization':
                    await this.loadSummarizationConfig();
                    break;
                case 'ai-bot':
                    await this.loadBotConfig();
                    break;
            }
        },

        /**
         * Load moderation logs
         */
        async loadModerationLogs() {
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=ai-systems.php&action=moderation-logs&limit=50`);
                const result = await response.json();
                
                if (result.success && result.logs) {
                    this.renderModerationLogs(result.logs);
                }
            } catch (e) {
                console.error('Failed to load moderation logs:', e);
            }
        },

        /**
         * Render moderation logs
         */
        renderModerationLogs(logs) {
            const container = $('#moderation-logs-list');
            if (!container.length) return;
            
            if (logs.length === 0) {
                container.html('<p>No moderation logs found.</p>');
                return;
            }
            
            const logsHtml = logs.map((log) => {
                const actionClass = {
                    'flag': 'mod-action-flag',
                    'warn': 'mod-action-warn',
                    'hide': 'mod-action-hide',
                    'delete': 'mod-action-delete',
                    'none': 'mod-action-none',
                }[log.moderation_action] || '';
                
                return `
                    <div class="moderation-log-item ${actionClass}">
                        <div class="mod-log-header">
                            <span class="mod-log-user">${this.escapeHtml(log.user_handle)}</span>
                            <span class="mod-log-time">${this.formatTime(log.created_at)}</span>
                            <span class="mod-log-action ${actionClass}">${this.escapeHtml(log.moderation_action)}</span>
                        </div>
                        <div class="mod-log-content">
                            <div class="mod-log-message">${this.escapeHtml(log.message_content.substring(0, 200))}${log.message_content.length > 200 ? '...' : ''}</div>
                            ${log.flagged_words ? `<div class="mod-log-flagged">Flagged: ${this.escapeHtml(log.flagged_words)}</div>` : ''}
                            ${log.toxicity_score !== null ? `<div class="mod-log-score">Toxicity Score: ${(parseFloat(log.toxicity_score) * 100).toFixed(1)}%</div>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
            
            container.html(logsHtml);
        },

        /**
         * Show test moderation dialog
         */
        async showTestModerationDialog() {
            const testMessage = await window.App.showPrompt(
                'Enter a message to test AI moderation:',
                'Test Moderation',
                '',
                true
            );
            
            if (testMessage === null) return;
            
            // Simulate flagged words
            const flaggedWords = ['test', 'word'];
            
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=ai-systems.php&action=test-moderation`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        message: testMessage,
                        flagged_words: flaggedWords,
                    }),
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const action = result.result.action || 'none';
                    const score = (result.result.score || 0) * 100;
                    
                    window.App.showAlert(
                        `Moderation Result: ${action.toUpperCase()}\nToxicity Score: ${score.toFixed(1)}%`,
                        'Moderation Test',
                        action === 'delete' ? 'error' : action === 'hide' ? 'warning' : 'info'
                    );
                }
            } catch (e) {
                console.error('Failed to test moderation:', e);
                window.App.showAlert('Failed to test moderation', 'Error', 'error');
            }
        },

        /**
         * Toggle AI system
         */
        async toggleSystem(systemName, enabled) {
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=ai-systems.php&action=config`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        system_name: systemName,
                        enabled: enabled,
                    }),
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    // Revert toggle
                    $(`.ai-enabled-toggle[data-system="${systemName}"]`).prop('checked', !enabled);
                    window.App.showAlert('Failed to update system', 'Error', 'error');
                }
            } catch (e) {
                console.error('Failed to toggle system:', e);
                $(`.ai-enabled-toggle[data-system="${systemName}"]`).prop('checked', !enabled);
                window.App.showAlert('Failed to update system', 'Error', 'error');
            }
        },

        /**
         * Save configuration
         */
        async saveConfiguration(systemName) {
            const card = $(`.ai-config-card[data-system="${systemName}"]`);
            const enabled = card.find('.ai-enabled-toggle').is(':checked');
            const provider = card.find('.ai-provider-select').val();
            const modelName = card.find('.ai-model-input').val();
            const apiKey = card.find('.ai-api-key-input').val();
            let configJson = null;
            
            try {
                const jsonText = card.find('.ai-config-json').val();
                if (jsonText.trim()) {
                    configJson = JSON.parse(jsonText);
                }
            } catch (e) {
                window.App.showAlert('Invalid JSON in Additional Config', 'Error', 'error');
                return;
            }
            
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=ai-systems.php&action=config`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        system_name: systemName,
                        enabled: enabled,
                        provider: provider,
                        model_name: modelName,
                        api_key: apiKey || null, // Only send if provided
                        config_json: configJson,
                    }),
                });
                
                const result = await response.json();
                
                if (result.success) {
                    window.App.showAlert('Configuration saved successfully', 'Success', 'success');
                    // Clear API key field for security
                    card.find('.ai-api-key-input').val('');
                } else {
                    window.App.showAlert(result.error || 'Failed to save configuration', 'Error', 'error');
                }
            } catch (e) {
                console.error('Failed to save configuration:', e);
                window.App.showAlert('Failed to save configuration', 'Error', 'error');
            }
        },

        /**
         * Load smart replies config
         */
        async loadSmartRepliesConfig() {
            const container = $('#smart-replies-config');
            if (!container.length) return;
            
            try {
                // Get smart_replies system config
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=ai-systems.php&action=config&system=smart_replies`);
                const result = await response.json();
                
                const config = result.config || {
                    system_name: 'smart_replies',
                    enabled: false,
                    provider: null,
                    model_name: null,
                    config_json: null,
                };
                
                container.html(`
                    <div class="ai-config-form">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="smart-replies-enabled" ${config.enabled ? 'checked' : ''}>
                                Enable Smart Replies
                            </label>
                            <p class="form-help">When enabled, AI will suggest contextual reply options based on conversation history.</p>
                        </div>
                        <div class="form-group">
                            <label>Max Suggestions:</label>
                            <input type="number" id="smart-replies-max" class="form-control" value="${this.getConfigValue(config.config_json, 'max_suggestions', 3)}" min="1" max="10">
                            <p class="form-help">Maximum number of reply suggestions to show (1-10).</p>
                        </div>
                        <div class="form-group">
                            <label>Context Window (messages):</label>
                            <input type="number" id="smart-replies-context" class="form-control" value="${this.getConfigValue(config.config_json, 'context_window', 10)}" min="1" max="50">
                            <p class="form-help">Number of recent messages to use as context for generating suggestions.</p>
                        </div>
                        <div class="form-group">
                            <label>Cache Duration (minutes):</label>
                            <input type="number" id="smart-replies-cache" class="form-control" value="${this.getConfigValue(config.config_json, 'cache_duration', 5)}" min="1" max="60">
                            <p class="form-help">How long to cache suggestions before regenerating.</p>
                        </div>
                        <button class="btn-primary" id="save-smart-replies-btn">
                            <i class="fas fa-save"></i> Save Configuration
                        </button>
                    </div>
                `);
                
                // Setup save handler
                $('#save-smart-replies-btn').on('click', () => this.saveSmartRepliesConfig());
            } catch (e) {
                console.error('Failed to load smart replies config:', e);
                container.html('<p class="error">Failed to load configuration.</p>');
            }
        },

        /**
         * Load summarization config
         */
        async loadSummarizationConfig() {
            const container = $('#summarization-config');
            if (!container.length) return;
            
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=ai-systems.php&action=config&system=summarization`);
                const result = await response.json();
                
                const config = result.config || {
                    system_name: 'summarization',
                    enabled: false,
                    provider: null,
                    model_name: null,
                    config_json: null,
                };
                
                container.html(`
                    <div class="ai-config-form">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="summarization-enabled" ${config.enabled ? 'checked' : ''}>
                                Enable Thread Summarization
                            </label>
                            <p class="form-help">When enabled, AI will automatically summarize long conversation threads.</p>
                        </div>
                        <div class="form-group">
                            <label>Auto-summarize threshold (messages):</label>
                            <input type="number" id="summarization-threshold" class="form-control" value="${this.getConfigValue(config.config_json, 'threshold', 50)}" min="10" max="500">
                            <p class="form-help">Automatically summarize threads with this many messages or more.</p>
                        </div>
                        <div class="form-group">
                            <label>Summary length (words):</label>
                            <input type="number" id="summarization-length" class="form-control" value="${this.getConfigValue(config.config_json, 'summary_length', 100)}" min="50" max="500">
                            <p class="form-help">Target length for generated summaries.</p>
                        </div>
                        <div class="form-group">
                            <label>Include key points:</label>
                            <input type="number" id="summarization-key-points" class="form-control" value="${this.getConfigValue(config.config_json, 'key_points', 5)}" min="0" max="20">
                            <p class="form-help">Number of key points to extract (0 to disable).</p>
                        </div>
                        <button class="btn-primary" id="save-summarization-btn">
                            <i class="fas fa-save"></i> Save Configuration
                        </button>
                    </div>
                `);
                
                $('#save-summarization-btn').on('click', () => this.saveSummarizationConfig());
            } catch (e) {
                console.error('Failed to load summarization config:', e);
                container.html('<p class="error">Failed to load configuration.</p>');
            }
        },

        /**
         * Load bot config
         */
        async loadBotConfig() {
            const configContainer = $('#bot-config');
            const remindersContainer = $('#bot-reminders-list');
            const pollsContainer = $('#bot-polls-list');
            
            try {
                // Load bot configuration
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=ai-systems.php&action=config&system=bot`);
                const result = await response.json();
                
                const config = result.config || {
                    system_name: 'bot',
                    enabled: false,
                    provider: null,
                    model_name: null,
                    config_json: null,
                };
                
                configContainer.html(`
                    <div class="ai-config-form">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="bot-enabled" ${config.enabled ? 'checked' : ''}>
                                Enable AI Bot
                            </label>
                            <p class="form-help">When enabled, the AI bot can create reminders and polls in chat rooms.</p>
                        </div>
                        <div class="form-group">
                            <label>Bot Handle:</label>
                            <input type="text" id="bot-handle" class="form-control" value="${this.getConfigValue(config.config_json, 'bot_handle', '@assistant')}" placeholder="@assistant">
                            <p class="form-help">The handle/username for the bot (e.g., @assistant, @bot).</p>
                        </div>
                        <div class="form-group">
                            <label>Max Reminders per User:</label>
                            <input type="number" id="bot-max-reminders" class="form-control" value="${this.getConfigValue(config.config_json, 'max_reminders_per_user', 10)}" min="1" max="100">
                            <p class="form-help">Maximum number of active reminders a user can have.</p>
                        </div>
                        <div class="form-group">
                            <label>Max Polls per Room:</label>
                            <input type="number" id="bot-max-polls" class="form-control" value="${this.getConfigValue(config.config_json, 'max_polls_per_room', 5)}" min="1" max="20">
                            <p class="form-help">Maximum number of active polls per room.</p>
                        </div>
                        <button class="btn-primary" id="save-bot-config-btn">
                            <i class="fas fa-save"></i> Save Configuration
                        </button>
                    </div>
                `);
                
                $('#save-bot-config-btn').on('click', () => this.saveBotConfig());
                
                // Load reminders and polls (placeholder for now)
                remindersContainer.html('<p class="info">No active reminders. Users can create reminders using bot commands in chat.</p>');
                pollsContainer.html('<p class="info">No active polls. Users can create polls using bot commands in chat.</p>');
            } catch (e) {
                console.error('Failed to load bot config:', e);
                configContainer.html('<p class="error">Failed to load configuration.</p>');
            }
        },
        
        /**
         * Get config value from JSON
         */
        getConfigValue(configJson, key, defaultValue) {
            if (!configJson) return defaultValue;
            try {
                const config = typeof configJson === 'string' ? JSON.parse(configJson) : configJson;
                return config[key] !== undefined ? config[key] : defaultValue;
            } catch (e) {
                return defaultValue;
            }
        },
        
        /**
         * Save smart replies config
         */
        async saveSmartRepliesConfig() {
            const enabled = $('#smart-replies-enabled').is(':checked');
            const configJson = {
                max_suggestions: parseInt($('#smart-replies-max').val()) || 3,
                context_window: parseInt($('#smart-replies-context').val()) || 10,
                cache_duration: parseInt($('#smart-replies-cache').val()) || 5,
            };
            
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=ai-systems.php&action=config`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        system_name: 'smart_replies',
                        enabled: enabled,
                        config_json: configJson,
                    }),
                });
                
                const result = await response.json();
                if (result.success) {
                    window.App.showAlert('Smart Replies configuration saved', 'Success', 'success');
                } else {
                    window.App.showAlert(result.error || 'Failed to save', 'Error', 'error');
                }
            } catch (e) {
                console.error('Failed to save smart replies config:', e);
                window.App.showAlert('Failed to save configuration', 'Error', 'error');
            }
        },
        
        /**
         * Save summarization config
         */
        async saveSummarizationConfig() {
            const enabled = $('#summarization-enabled').is(':checked');
            const configJson = {
                threshold: parseInt($('#summarization-threshold').val()) || 50,
                summary_length: parseInt($('#summarization-length').val()) || 100,
                key_points: parseInt($('#summarization-key-points').val()) || 5,
            };
            
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=ai-systems.php&action=config`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        system_name: 'summarization',
                        enabled: enabled,
                        config_json: configJson,
                    }),
                });
                
                const result = await response.json();
                if (result.success) {
                    window.App.showAlert('Summarization configuration saved', 'Success', 'success');
                } else {
                    window.App.showAlert(result.error || 'Failed to save', 'Error', 'error');
                }
            } catch (e) {
                console.error('Failed to save summarization config:', e);
                window.App.showAlert('Failed to save configuration', 'Error', 'error');
            }
        },
        
        /**
         * Save bot config
         */
        async saveBotConfig() {
            const enabled = $('#bot-enabled').is(':checked');
            const configJson = {
                bot_handle: $('#bot-handle').val() || '@assistant',
                max_reminders_per_user: parseInt($('#bot-max-reminders').val()) || 10,
                max_polls_per_room: parseInt($('#bot-max-polls').val()) || 5,
            };
            
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=ai-systems.php&action=config`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        system_name: 'bot',
                        enabled: enabled,
                        config_json: configJson,
                    }),
                });
                
                const result = await response.json();
                if (result.success) {
                    window.App.showAlert('Bot configuration saved', 'Success', 'success');
                } else {
                    window.App.showAlert(result.error || 'Failed to save', 'Error', 'error');
                }
            } catch (e) {
                console.error('Failed to save bot config:', e);
                window.App.showAlert('Failed to save configuration', 'Error', 'error');
            }
        },

        /**
         * Format system name for display
         */
        formatSystemName(name) {
            return name.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
        },

        /**
         * Format time
         */
        formatTime(timestamp) {
            if (!timestamp) return '';
            const date = new Date(timestamp);
            return date.toLocaleString();
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
    window.AISystemsAdmin = AISystemsAdmin;

    // Auto-initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => AISystemsAdmin.init());
    } else {
        AISystemsAdmin.init();
    }
})();

