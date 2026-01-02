/**
 * Sentinel Chat Platform - Web Terminal Admin UI
 * 
 * Provides a web-based terminal interface for executing system commands.
 * All commands are logged for security auditing.
 */

(function() {
    'use strict';
    
    const TerminalAdmin = {
        commandHistory: [],
        historyIndex: -1,
        currentCwd: null,
        
        /**
         * Initialize terminal admin UI
         */
        init: function() {
            // Only initialize if we're on the terminal tab
            if (!$('#admin-tab-terminal').length) {
                return;
            }
            
            // Bind event handlers
            this.bindEvents();
            
            // Load current working directory
            this.loadCwd();
        },
        
        /**
         * Bind event handlers
         */
        bindEvents: function() {
            const $input = $('#terminal-input');
            const $output = $('#terminal-output');
            
            // Command input
            $input.on('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.executeCommand();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    this.navigateHistory(-1);
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    this.navigateHistory(1);
                }
            });
            
            // Clear terminal
            $('#terminal-clear-btn').on('click', () => {
                this.clearTerminal();
            });
            
            // Reset session
            $('#terminal-reset-btn').on('click', () => {
                if (confirm('Reset terminal session? This will clear history and reset working directory.')) {
                    this.resetSession();
                }
            });
            
            // Focus input when tab is activated
            $(document).on('click', '.admin-subtab-btn[data-tab="terminal"]', () => {
                setTimeout(() => {
                    $input.focus();
                }, 100);
            });
        },
        
        /**
         * Execute command
         */
        executeCommand: function() {
            const $input = $('#terminal-input');
            const $output = $('#terminal-output');
            const command = $input.val().trim();
            
            if (!command) {
                return;
            }
            
            // Add to history
            this.commandHistory.push(command);
            this.historyIndex = this.commandHistory.length;
            
            // Display command
            this.appendOutput(`$ ${command}`, '#00ff00');
            
            // Clear input
            $input.val('');
            
            // Show executing indicator
            this.appendOutput('Executing...', '#888');
            
            // Execute via API
            $.ajax({
                url: '/iChat/api/proxy.php?path=terminal&action=execute',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    command: command,
                    working_dir: this.currentCwd
                }),
                dataType: 'json',
                success: (response) => {
                    // Remove "Executing..." line
                    this.removeLastLine();
                    
                    if (response.success) {
                        // Show output
                        if (response.output) {
                            this.appendOutput(response.output, '#fff');
                        }
                        
                        // Update working directory if changed
                        if (response.cwd) {
                            this.currentCwd = response.cwd;
                            this.updateCwdDisplay();
                        }
                        
                        // Show exit code if non-zero
                        if (response.exit_code !== 0) {
                            this.appendOutput(`Exit code: ${response.exit_code}`, '#ff6b6b');
                        }
                    } else {
                        // Show error - display actual output if available, otherwise show error message
                        if (response.output && response.output.trim()) {
                            // Show the actual command output (which may contain the error message)
                            this.appendOutput(response.output, '#ff6b6b');
                        } else {
                            // No output, show generic error
                            this.appendOutput(`Error: ${response.error || 'Command failed'}`, '#ff6b6b');
                        }
                        if (response.exit_code !== undefined && response.exit_code !== 0) {
                            this.appendOutput(`Exit code: ${response.exit_code}`, '#ff6b6b');
                        }
                    }
                    
                    // Add prompt
                    this.appendPrompt();
                    
                    // Scroll to bottom
                    this.scrollToBottom();
                },
                error: (xhr, status, error) => {
                    // Remove "Executing..." line
                    this.removeLastLine();
                    
                    console.error('Error executing command:', error);
                    const errorMsg = xhr.responseJSON?.error || error || 'Failed to execute command';
                    this.appendOutput(`Error: ${errorMsg}`, '#ff6b6b');
                    this.appendPrompt();
                    this.scrollToBottom();
                }
            });
        },
        
        /**
         * Append output to terminal
         */
        appendOutput: function(text, color = '#fff') {
            const $output = $('#terminal-output');
            const $line = $('<div>').css('color', color).text(text);
            $output.append($line);
        },
        
        /**
         * Append prompt
         */
        appendPrompt: function() {
            const cwd = this.currentCwd ? this.getShortPath(this.currentCwd) : '~';
            this.appendOutput(`[${cwd}] $`, '#00ff00');
        },
        
        /**
         * Remove last line (for "Executing..." indicator)
         */
        removeLastLine: function() {
            const $output = $('#terminal-output');
            $output.children().last().remove();
        },
        
        /**
         * Clear terminal
         */
        clearTerminal: function() {
            $('#terminal-output').empty();
            this.appendPrompt();
            this.scrollToBottom();
        },
        
        /**
         * Reset session
         */
        resetSession: function() {
            this.commandHistory = [];
            this.historyIndex = -1;
            this.currentCwd = null;
            this.clearTerminal();
            this.loadCwd();
        },
        
        /**
         * Navigate command history
         */
        navigateHistory: function(direction) {
            if (this.commandHistory.length === 0) {
                return;
            }
            
            this.historyIndex += direction;
            
            if (this.historyIndex < 0) {
                this.historyIndex = 0;
            } else if (this.historyIndex >= this.commandHistory.length) {
                this.historyIndex = this.commandHistory.length;
                $('#terminal-input').val('');
                return;
            }
            
            if (this.historyIndex < this.commandHistory.length) {
                $('#terminal-input').val(this.commandHistory[this.historyIndex]);
            }
        },
        
        /**
         * Load current working directory
         */
        loadCwd: function() {
            $.ajax({
                url: '/iChat/api/proxy.php?path=terminal&action=cwd',
                method: 'GET',
                dataType: 'json',
                success: (response) => {
                    if (response.success && response.cwd) {
                        this.currentCwd = response.cwd;
                        this.updateCwdDisplay();
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error loading CWD:', error);
                }
            });
        },
        
        /**
         * Update CWD display
         */
        updateCwdDisplay: function() {
            const shortPath = this.currentCwd ? this.getShortPath(this.currentCwd) : '-';
            $('#terminal-cwd').text(shortPath);
        },
        
        /**
         * Get short path for display
         */
        getShortPath: function(fullPath) {
            // Get relative path from project root
            const projectRoot = '/iChat';
            if (fullPath.indexOf(projectRoot) !== -1) {
                return fullPath.substring(fullPath.indexOf(projectRoot));
            }
            
            // If path is too long, show last part
            if (fullPath.length > 50) {
                const parts = fullPath.split(/[\\\/]/);
                return '...' + parts.slice(-3).join('/');
            }
            
            return fullPath;
        },
        
        /**
         * Scroll terminal to bottom
         */
        scrollToBottom: function() {
            const $container = $('#terminal-container');
            $container.scrollTop($container[0].scrollHeight);
        }
    };
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        TerminalAdmin.init();
        
        // Re-initialize when terminal tab is activated
        $(document).on('click', '.admin-subtab-btn[data-tab="terminal"]', function() {
            setTimeout(() => {
                $('#terminal-input').focus();
                if (TerminalAdmin.currentCwd === null) {
                    TerminalAdmin.loadCwd();
                }
            }, 100);
        });
    });
    
    // Export for global access
    window.TerminalAdmin = TerminalAdmin;
})();

