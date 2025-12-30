/**
 * Sentinel Chat Platform - Audit Logs Admin UI
 * 
 * Frontend for the searchable audit logs dashboard in the admin panel.
 * Provides filtering, searching, pagination, and export functionality.
 */

(function() {
    'use strict';

    const AuditLogsAdmin = {
        currentPage: 1,
        currentLimit: 100,
        currentFilters: {},
        totalCount: 0,
        
        /**
         * Initialize audit logs admin
         */
        init() {
            // Load logs when tab is opened
            $(document).on('click', '.admin-subtab-btn[data-tab="audit-logs"]', () => {
                // Auto-load logs when tab is opened (with empty filters to show all)
                this.currentPage = 1;
                this.currentFilters = {};
                // Load logs immediately without waiting for search button
                setTimeout(() => this.loadLogs(), 100);
            });
            
            // Also load logs when admin tab is switched to audit-logs
            $(document).on('click', '.admin-tab-btn[data-tab="audit-logs"]', () => {
                this.currentPage = 1;
                this.currentFilters = {};
                setTimeout(() => this.loadLogs(), 100);
            });
            
            // Auto-load on page load if audit logs tab is active
            if ($('.admin-subtab-btn[data-tab="audit-logs"]').hasClass('active') || 
                $('#admin-tab-audit-logs').hasClass('active')) {
                setTimeout(() => this.loadLogs(), 500);
            }
            
            // Search button
            $(document).on('click', '#audit-search-btn', () => {
                this.currentPage = 1;
                this.loadLogs();
            });
            
            // Clear filters
            $(document).on('click', '#audit-clear-filters-btn', () => {
                this.clearFilters();
            });
            
            // Export buttons
            $(document).on('click', '#audit-export-json-btn', () => this.exportLogs('json'));
            $(document).on('click', '#audit-export-csv-btn', () => this.exportLogs('csv'));
            $(document).on('click', '#audit-export-pdf-btn', () => this.exportLogs('pdf'));
            
            // Pagination
            $(document).on('click', '#audit-prev-page', () => {
                if (this.currentPage > 1) {
                    this.currentPage--;
                    this.loadLogs();
                }
            });
            
            $(document).on('click', '#audit-next-page', () => {
                const maxPage = Math.ceil(this.totalCount / this.currentLimit);
                if (this.currentPage < maxPage) {
                    this.currentPage++;
                    this.loadLogs();
                }
            });
            
            // Limit change
            $(document).on('change', '#audit-limit-select', () => {
                this.currentLimit = parseInt($('#audit-limit-select').val());
                this.currentPage = 1;
                this.loadLogs();
            });
            
            // Enter key on search input
            $(document).on('keypress', '#audit-search-input', (e) => {
                if (e.which === 13) {
                    this.currentPage = 1;
                    this.loadLogs();
                }
            });
            
            // Row click to show details
            $(document).on('click', '.audit-log-row', (e) => {
                const logId = $(e.currentTarget).data('log-id');
                if (logId) {
                    this.showLogDetails(logId);
                }
            });
            
            // Close detail modal
            $(document).on('click', '#audit-log-detail-close, #audit-log-detail-close-btn', () => {
                $('#audit-log-detail-modal').hide();
            });
        },
        
        /**
         * Get current filters from UI
         */
        getFilters() {
            const filters = {};
            
            const searchTerm = $('#audit-search-input').val().trim();
            if (searchTerm) {
                filters['search_term'] = searchTerm;
            }
            
            const userHandle = $('#audit-user-filter').val().trim();
            if (userHandle) {
                filters['user_handle'] = userHandle;
            }
            
            const actionType = $('#audit-action-filter').val();
            if (actionType) {
                filters['action_type'] = actionType;
            }
            
            const category = $('#audit-category-filter').val();
            if (category) {
                filters['action_category'] = category;
            }
            
            const startDate = $('#audit-start-date').val();
            if (startDate) {
                filters['start_date'] = startDate + ' 00:00:00';
            }
            
            const endDate = $('#audit-end-date').val();
            if (endDate) {
                filters['end_date'] = endDate + ' 23:59:59';
            }
            
            const success = $('#audit-success-filter').val();
            if (success !== '') {
                filters['success'] = success === '1';
            }
            
            return filters;
        },
        
        /**
         * Clear all filters
         */
        clearFilters() {
            $('#audit-search-input').val('');
            $('#audit-user-filter').val('');
            $('#audit-action-filter').val('');
            $('#audit-category-filter').val('');
            $('#audit-start-date').val('');
            $('#audit-end-date').val('');
            $('#audit-success-filter').val('');
            this.currentPage = 1;
            this.loadLogs();
        },
        
        /**
         * Load audit logs
         */
        async loadLogs() {
            const filters = this.getFilters();
            const offset = (this.currentPage - 1) * this.currentLimit;
            
            const params = new URLSearchParams({
                action: 'list',
                limit: this.currentLimit,
                offset: offset,
                ...filters
            });
            
            try {
                window.App.showLoadingNotification('Loading audit logs...');
                
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=audit.php&${params.toString()}`);
                const result = await response.json();
                
                if (result.success) {
                    this.totalCount = result.total || 0;
                    this.renderLogs(result.logs || []);
                    this.updatePagination();
                    this.updateTotalCount();
                } else {
                    window.App.showAlert(result.error || 'Failed to load audit logs', 'Error', 'error');
                }
            } catch (e) {
                console.error('Failed to load audit logs:', e);
                window.App.showAlert('Failed to load audit logs', 'Error', 'error');
            } finally {
                window.App.hideLoadingNotification();
            }
        },
        
        /**
         * Render audit logs table
         */
        renderLogs(logs) {
            const tbody = $('#audit-logs-tbody');
            tbody.empty();
            
            if (logs.length === 0) {
                tbody.html(`
                    <tr>
                        <td colspan="9" style="padding: 2rem; text-align: center; color: var(--text-medium);">
                            No audit logs found matching your filters.
                        </td>
                    </tr>
                `);
                return;
            }
            
            logs.forEach((log) => {
                const successIcon = log.success ? 
                    '<i class="fas fa-check-circle" style="color: var(--success-color);"></i>' : 
                    '<i class="fas fa-times-circle" style="color: var(--error-color);"></i>';
                
                const row = $(`
                    <tr class="audit-log-row" data-log-id="${log.id}" style="border-bottom: 1px solid var(--border-color-light); cursor: pointer; transition: background 0.2s;">
                        <td style="padding: 0.75rem; font-family: monospace; font-size: 0.85rem;">${this.escapeHtml(String(log.id).substring(0, 8))}</td>
                        <td style="padding: 0.75rem; font-size: 0.9rem;">${this.formatTimestamp(log.timestamp)}</td>
                        <td style="padding: 0.75rem;">
                            <div style="font-weight: 500;">${this.escapeHtml(log.user_handle || 'N/A')}</div>
                            ${log.user_id ? `<div style="font-size: 0.75rem; color: var(--text-medium);">ID: ${log.user_id}</div>` : ''}
                        </td>
                        <td style="padding: 0.75rem;">
                            <span style="font-weight: 500;">${this.escapeHtml(log.action_type || 'N/A')}</span>
                        </td>
                        <td style="padding: 0.75rem;">
                            <span class="audit-category-badge" style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background: var(--ghost-white);">
                                ${this.escapeHtml(log.action_category || 'other')}
                            </span>
                        </td>
                        <td style="padding: 0.75rem; font-size: 0.85rem;">
                            ${log.resource_type ? `${this.escapeHtml(log.resource_type)}/${this.escapeHtml(String(log.resource_id || '').substring(0, 10))}` : 'N/A'}
                        </td>
                        <td style="padding: 0.75rem; font-family: monospace; font-size: 0.85rem;">
                            ${this.escapeHtml(log.ip_address || 'N/A')}
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            ${successIcon}
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            <button class="btn-secondary btn-sm" onclick="event.stopPropagation(); window.AuditLogsAdmin.showLogDetails(${log.id})">
                                <i class="fas fa-eye"></i> View
                            </button>
                        </td>
                    </tr>
                `);
                
                row.hover(
                    function() { $(this).css('background', 'var(--ghost-white)'); },
                    function() { $(this).css('background', ''); }
                );
                
                tbody.append(row);
            });
        },
        
        /**
         * Show log details in modal
         */
        async showLogDetails(logId) {
            const modal = $('#audit-log-detail-modal');
            const content = $('#audit-log-detail-content');
            
            modal.show();
            content.html('<div class="loading-messages">Loading log details...</div>');
            
            try {
                const filters = this.getFilters();
                const params = new URLSearchParams({
                    action: 'list',
                    limit: 1,
                    offset: 0,
                    ...filters
                });
                
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=audit.php&${params.toString()}`);
                const result = await response.json();
                
                if (result.success && result.logs && result.logs.length > 0) {
                    const log = result.logs.find(l => l.id == logId) || result.logs[0];
                    this.renderLogDetails(log, content);
                } else {
                    // Try to get by ID directly
                    const directResponse = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=audit.php&action=list&limit=1000`);
                    const directResult = await directResponse.json();
                    if (directResult.success) {
                        const log = directResult.logs.find(l => l.id == logId);
                        if (log) {
                            this.renderLogDetails(log, content);
                        } else {
                            content.html('<p>Log entry not found.</p>');
                        }
                    }
                }
            } catch (e) {
                console.error('Failed to load log details:', e);
                content.html('<p>Failed to load log details.</p>');
            }
        },
        
        /**
         * Render log details
         */
        renderLogDetails(log, container) {
            const details = `
                <div class="audit-log-details" style="display: grid; grid-template-columns: 150px 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div style="font-weight: 600; color: var(--text-medium);">ID:</div>
                    <div>${this.escapeHtml(String(log.id))}</div>
                    
                    <div style="font-weight: 600; color: var(--text-medium);">Timestamp:</div>
                    <div>${this.formatTimestamp(log.timestamp)}</div>
                    
                    <div style="font-weight: 600; color: var(--text-medium);">User Handle:</div>
                    <div>${this.escapeHtml(log.user_handle || 'N/A')}</div>
                    
                    ${log.user_id ? `
                        <div style="font-weight: 600; color: var(--text-medium);">User ID:</div>
                        <div>${log.user_id}</div>
                    ` : ''}
                    
                    <div style="font-weight: 600; color: var(--text-medium);">Action Type:</div>
                    <div><strong>${this.escapeHtml(log.action_type || 'N/A')}</strong></div>
                    
                    <div style="font-weight: 600; color: var(--text-medium);">Category:</div>
                    <div>${this.escapeHtml(log.action_category || 'other')}</div>
                    
                    ${log.resource_type ? `
                        <div style="font-weight: 600; color: var(--text-medium);">Resource Type:</div>
                        <div>${this.escapeHtml(log.resource_type)}</div>
                    ` : ''}
                    
                    ${log.resource_id ? `
                        <div style="font-weight: 600; color: var(--text-medium);">Resource ID:</div>
                        <div>${this.escapeHtml(String(log.resource_id))}</div>
                    ` : ''}
                    
                    ${log.ip_address ? `
                        <div style="font-weight: 600; color: var(--text-medium);">IP Address:</div>
                        <div style="font-family: monospace;">${this.escapeHtml(log.ip_address)}</div>
                    ` : ''}
                    
                    ${log.user_agent ? `
                        <div style="font-weight: 600; color: var(--text-medium);">User Agent:</div>
                        <div style="font-size: 0.9rem; word-break: break-all;">${this.escapeHtml(log.user_agent)}</div>
                    ` : ''}
                    
                    ${log.session_id ? `
                        <div style="font-weight: 600; color: var(--text-medium);">Session ID:</div>
                        <div style="font-family: monospace; font-size: 0.85rem;">${this.escapeHtml(log.session_id)}</div>
                    ` : ''}
                    
                    <div style="font-weight: 600; color: var(--text-medium);">Success:</div>
                    <div>${log.success ? '<span style="color: var(--success-color);">✓ Yes</span>' : '<span style="color: var(--error-color);">✗ No</span>'}</div>
                    
                    ${log.error_message ? `
                        <div style="font-weight: 600; color: var(--text-medium);">Error Message:</div>
                        <div style="color: var(--error-color);">${this.escapeHtml(log.error_message)}</div>
                    ` : ''}
                </div>
                
                ${log.before_value ? `
                    <div style="margin-top: 1.5rem;">
                        <h4 style="margin-bottom: 0.5rem;">Before Value:</h4>
                        <pre style="background: var(--ghost-white); padding: 1rem; border-radius: 4px; overflow-x: auto; font-size: 0.85rem;">${this.escapeHtml(JSON.stringify(log.before_value, null, 2))}</pre>
                    </div>
                ` : ''}
                
                ${log.after_value ? `
                    <div style="margin-top: 1.5rem;">
                        <h4 style="margin-bottom: 0.5rem;">After Value:</h4>
                        <pre style="background: var(--ghost-white); padding: 1rem; border-radius: 4px; overflow-x: auto; font-size: 0.85rem;">${this.escapeHtml(JSON.stringify(log.after_value, null, 2))}</pre>
                    </div>
                ` : ''}
                
                ${log.metadata ? `
                    <div style="margin-top: 1.5rem;">
                        <h4 style="margin-bottom: 0.5rem;">Metadata:</h4>
                        <pre style="background: var(--ghost-white); padding: 1rem; border-radius: 4px; overflow-x: auto; font-size: 0.85rem;">${this.escapeHtml(JSON.stringify(log.metadata, null, 2))}</pre>
                    </div>
                ` : ''}
            `;
            
            container.html(details);
        },
        
        /**
         * Update pagination controls
         */
        updatePagination() {
            const maxPage = Math.ceil(this.totalCount / this.currentLimit);
            $('#audit-page-info').text(`Page ${this.currentPage} of ${maxPage || 1}`);
            $('#audit-prev-page').prop('disabled', this.currentPage <= 1);
            $('#audit-next-page').prop('disabled', this.currentPage >= maxPage || maxPage === 0);
        },
        
        /**
         * Update total count display
         */
        updateTotalCount() {
            $('#audit-total-count').text(`Total: ${this.totalCount.toLocaleString()} logs`);
        },
        
        /**
         * Export logs
         */
        exportLogs(format) {
            const filters = this.getFilters();
            const params = new URLSearchParams({
                action: 'export',
                format: format,
                ...filters
            });
            
            // Open in new window to trigger download
            window.open(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=audit.php&${params.toString()}`, '_blank');
        },
        
        /**
         * Format timestamp
         */
        formatTimestamp(timestamp) {
            if (!timestamp) return 'N/A';
            const date = new Date(timestamp);
            return date.toLocaleString();
        },
        
        /**
         * Escape HTML
         */
        escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        },
        
        /**
         * Load retention policies
         */
        async loadRetentionPolicies() {
            try {
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=audit.php&action=retention-policies`);
                const result = await response.json();
                
                if (result.success) {
                    this.renderRetentionPolicies(result.policies || []);
                } else {
                    $('#retention-policies-list').html('<p style="color: var(--error-color);">Failed to load retention policies.</p>');
                }
            } catch (e) {
                console.error('Failed to load retention policies:', e);
                $('#retention-policies-list').html('<p style="color: var(--error-color);">Failed to load retention policies.</p>');
            }
        },
        
        /**
         * Render retention policies
         */
        renderRetentionPolicies(policies) {
            const container = $('#retention-policies-list');
            
            if (policies.length === 0) {
                container.html('<p>No retention policies found.</p>');
                return;
            }
            
            let html = '<table class="retention-policies-table" style="width: 100%; border-collapse: collapse; margin-top: 1rem;">';
            html += '<thead><tr style="background: var(--ghost-white); border-bottom: 2px solid var(--border-color);">';
            html += '<th style="padding: 0.75rem; text-align: left;">Policy Name</th>';
            html += '<th style="padding: 0.75rem; text-align: left;">Category</th>';
            html += '<th style="padding: 0.75rem; text-align: left;">Action Type</th>';
            html += '<th style="padding: 0.75rem; text-align: center;">Retention (Days)</th>';
            html += '<th style="padding: 0.75rem; text-align: center;">Auto Purge</th>';
            html += '<th style="padding: 0.75rem; text-align: center;">Legal Hold</th>';
            html += '<th style="padding: 0.75rem; text-align: center;">Actions</th>';
            html += '</tr></thead><tbody>';
            
            policies.forEach((policy) => {
                html += `
                    <tr style="border-bottom: 1px solid var(--border-color-light);">
                        <td style="padding: 0.75rem; font-weight: 500;">${this.escapeHtml(policy.policy_name || 'N/A')}</td>
                        <td style="padding: 0.75rem;">${this.escapeHtml(policy.action_category || 'all')}</td>
                        <td style="padding: 0.75rem;">${this.escapeHtml(policy.action_type || 'all')}</td>
                        <td style="padding: 0.75rem; text-align: center;">
                            <input type="number" class="retention-days-input" data-policy-id="${policy.id}" 
                                   value="${policy.retention_days}" min="1" max="36500" 
                                   style="width: 80px; padding: 0.25rem; border: 1px solid var(--border-color); border-radius: 4px;">
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            <input type="checkbox" class="auto-purge-checkbox" data-policy-id="${policy.id}" 
                                   ${policy.auto_purge ? 'checked' : ''}>
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            <input type="checkbox" class="legal-hold-checkbox" data-policy-id="${policy.id}" 
                                   ${policy.legal_hold ? 'checked' : ''}>
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            <button class="btn-secondary btn-sm save-policy-btn" data-policy-id="${policy.id}">
                                <i class="fas fa-save"></i> Save
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            container.html(html);
            
            // Attach event handlers
            $('.save-policy-btn').on('click', (e) => {
                const policyId = $(e.currentTarget).data('policy-id');
                this.saveRetentionPolicy(policyId);
            });
        },
        
        /**
         * Save retention policy
         */
        async saveRetentionPolicy(policyId) {
            const retentionDays = parseInt($(`.retention-days-input[data-policy-id="${policyId}"]`).val());
            const autoPurge = $(`.auto-purge-checkbox[data-policy-id="${policyId}"]`).is(':checked');
            const legalHold = $(`.legal-hold-checkbox[data-policy-id="${policyId}"]`).is(':checked');
            
            if (isNaN(retentionDays) || retentionDays < 1) {
                window.App.showAlert('Invalid retention days value', 'Error', 'error');
                return;
            }
            
            try {
                window.App.showLoadingNotification('Saving retention policy...');
                
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=audit.php&action=retention-policies`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        id: policyId,
                        retention_days: retentionDays,
                        auto_purge: autoPurge,
                        legal_hold: legalHold,
                    }),
                });
                
                const result = await response.json();
                
                if (result.success) {
                    window.App.showAlert('Retention policy updated successfully', 'Success', 'success');
                    this.loadRetentionPolicies(); // Refresh list
                } else {
                    window.App.showAlert(result.message || 'Failed to update retention policy', 'Error', 'error');
                }
            } catch (e) {
                console.error('Failed to save retention policy:', e);
                window.App.showAlert('Failed to save retention policy', 'Error', 'error');
            } finally {
                window.App.hideLoadingNotification();
            }
        },
        
        /**
         * Purge old logs manually
         */
        async purgeLogsNow() {
            if (!confirm('Are you sure you want to purge old audit logs based on retention policies? This action cannot be undone.')) {
                return;
            }
            
            try {
                window.App.showLoadingNotification('Purging old logs...');
                
                const response = await fetch(`${window.SENTINEL_CONFIG?.apiBase || ''}/proxy.php?path=audit.php&action=purge-logs`, {
                    method: 'POST',
                });
                
                const result = await response.json();
                
                if (result.success) {
                    window.App.showAlert(
                        `Successfully purged ${result.purged_count || 0} old audit log entries`,
                        'Success',
                        'success'
                    );
                    this.loadLogs(); // Refresh log list
                } else {
                    window.App.showAlert(result.message || 'Failed to purge logs', 'Error', 'error');
                }
            } catch (e) {
                console.error('Failed to purge logs:', e);
                window.App.showAlert('Failed to purge logs', 'Error', 'error');
            } finally {
                window.App.hideLoadingNotification();
            }
        }
    };
    
    // Export to global scope
    window.AuditLogsAdmin = AuditLogsAdmin;
    
    // Auto-initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            AuditLogsAdmin.init();
            
            // Load retention policies when tab is opened
            $(document).on('click', '.admin-subtab-btn[data-tab="audit-logs"]', () => {
                AuditLogsAdmin.loadRetentionPolicies();
            });
            
            // Refresh retention policies button
            $(document).on('click', '#refresh-retention-policies-btn', () => {
                AuditLogsAdmin.loadRetentionPolicies();
            });
            
            // Purge logs now button
            $(document).on('click', '#purge-logs-now-btn', () => {
                AuditLogsAdmin.purgeLogsNow();
            });
        });
    } else {
        AuditLogsAdmin.init();
        
        // Load retention policies when tab is opened
        $(document).on('click', '.admin-subtab-btn[data-tab="audit-logs"]', () => {
            AuditLogsAdmin.loadRetentionPolicies();
        });
        
        // Refresh retention policies button
        $(document).on('click', '#refresh-retention-policies-btn', () => {
            AuditLogsAdmin.loadRetentionPolicies();
        });
        
        // Purge logs now button
        $(document).on('click', '#purge-logs-now-btn', () => {
            AuditLogsAdmin.purgeLogsNow();
        });
    }
})();

