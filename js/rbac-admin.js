/**
 * RBAC Admin JavaScript
 * 
 * Handles Role-Based Access Control management UI.
 * Allows Trusted Admins to set permissions for each role.
 * Owners can protect permissions with passwords.
 */

class RBACAdmin {
    constructor() {
        this.currentRole = 'user';
        this.permissions = {};
        this.permissionsByCategory = {};
        this.isOwner = window.SENTINEL_CONFIG?.userRole === 'owner';
        this.init();
    }

    init() {
        // Load permissions when RBAC tab is opened
        $(document).on('click', '.admin-subtab-btn[data-tab="rbac"]', () => {
            this.loadPermissions();
        });

        // Role selector change
        $(document).on('change', '#rbac-role-select', (e) => {
            this.currentRole = $(e.target).val();
            // Only load role permissions if permissions are already loaded
            if (this.permissionsByCategory && Object.keys(this.permissionsByCategory).length > 0) {
                this.loadRolePermissions();
            } else {
                // Load permissions first, then role permissions
                this.loadPermissions();
            }
        });

        // Refresh button
        $(document).on('click', '#rbac-refresh-btn', () => {
            // Reload everything
            this.loadPermissions();
        });

        // Permission toggle
        $(document).on('change', '.rbac-permission-toggle', (e) => {
            const $toggle = $(e.target);
            const permissionId = parseInt($toggle.data('permission-id'));
            const allowed = $toggle.is(':checked');
            this.updatePermission(permissionId, allowed, $toggle);
        });

        // Protect permission (Owner only)
        $(document).on('click', '.rbac-protect-btn', (e) => {
            const permissionId = parseInt($(e.target).data('permission-id'));
            this.protectPermission(permissionId);
        });

        // Unprotect permission (Owner only)
        $(document).on('click', '.rbac-unprotect-btn', (e) => {
            const permissionId = parseInt($(e.target).data('permission-id'));
            this.unprotectPermission(permissionId);
        });

        // History refresh
        $(document).on('click', '#rbac-history-refresh-btn', () => {
            this.loadHistory();
        });

        $(document).on('change', '#rbac-history-role-filter', () => {
            this.loadHistory();
        });
    }

    /**
     * Load all permissions grouped by category
     */
    async loadPermissions() {
        try {
            const response = await $.ajax({
                url: window.SENTINEL_CONFIG.apiBase + '/proxy.php',
                method: 'GET',
                data: {
                    path: 'rbac',
                    action: 'permissions'
                },
                // Ensure data is properly serialized for GET requests
                traditional: true
            });

            if (response.success) {
                this.permissionsByCategory = response.permissions;
                this.loadRolePermissions();
            } else {
                // Check if patch is required
                if (response.patch_required) {
                    this.showError(`RBAC system not initialized. Please apply patch ${response.patch_required} (Add RBAC System) from the Admin Dashboard -> System -> Patches section.`);
                } else {
                    this.showError('Failed to load permissions: ' + (response.error || 'Unknown error'));
                }
            }
        } catch (error) {
            console.error('Error loading permissions:', error);
            console.error('Error details:', error.responseJSON || error.responseText || error);
            
            // Check response for specific error types
            const response = error.responseJSON || {};
            
            if (response.patch_required) {
                this.showError(`RBAC system not initialized. Please apply patch ${response.patch_required} (Add RBAC System) from the Admin Dashboard -> System -> Patches section.`);
            } else if (response.error) {
                this.showError('Failed to load permissions: ' + response.error + (response.message ? ' - ' + response.message : ''));
            } else if (error.status === 403) {
                this.showError('Access denied. You must be a Trusted Admin or Owner to manage RBAC permissions.');
            } else if (error.status === 500) {
                this.showError('Server error: ' + (response.message || 'Please check the server logs for details.'));
            } else {
                this.showError('Failed to load permissions. Please check console for details. Status: ' + (error.status || 'unknown'));
            }
        }
    }

    /**
     * Load permissions for the selected role
     */
    async loadRolePermissions() {
        $('#rbac-status').text('Loading...').css('color', 'var(--text-medium)');

        try {
            // Ensure permissions are loaded first
            if (!this.permissionsByCategory || Object.keys(this.permissionsByCategory).length === 0) {
                await this.loadPermissions();
            }

            const response = await $.ajax({
                url: window.SENTINEL_CONFIG.apiBase + '/proxy.php',
                method: 'GET',
                data: {
                    path: 'rbac',
                    action: 'role-permissions',
                    role: this.currentRole
                },
                // Ensure data is properly serialized for GET requests
                traditional: true
            });

            if (response.success) {
                this.renderPermissions(response.permissions);
                $('#rbac-status').text('Loaded').css('color', 'var(--success-color)');
            } else {
                this.showError('Failed to load role permissions: ' + (response.error || 'Unknown error'));
                $('#rbac-status').text('Error').css('color', 'var(--error-color)');
            }
        } catch (error) {
            console.error('Error loading role permissions:', error);
            this.showError('Failed to load role permissions. Please check console for details.');
            $('#rbac-status').text('Error').css('color', 'var(--error-color)');
        }
    }

    /**
     * Render permissions grouped by category
     */
    renderPermissions(rolePermissions) {
        const container = $('#rbac-permissions-container');
        container.empty();

        if (!this.permissionsByCategory || Object.keys(this.permissionsByCategory).length === 0) {
            container.html('<div class="loading-messages">No permissions found. Please refresh.</div>');
            return;
        }

        // Create a map of permission_id -> role permission for quick lookup
        const rolePermMap = {};
        rolePermissions.forEach(rp => {
            rolePermMap[rp.permission_id] = rp;
        });

        // Render by category
        let html = '';
        const categories = Object.keys(this.permissionsByCategory).sort();

        categories.forEach(category => {
            const perms = this.permissionsByCategory[category];
            
            html += `
                <div class="rbac-category-section" style="margin-bottom: 2rem; padding: 1.5rem; background: var(--bg-secondary); border-radius: 8px; border: 1px solid var(--border-color);">
                    <h4 style="margin-bottom: 1rem; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-${this.getCategoryIcon(category)}"></i>
                        ${this.capitalize(category)}
                    </h4>
                    <div class="rbac-permissions-list" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem;">
            `;

            perms.forEach(perm => {
                const rolePerm = rolePermMap[perm.id] || { allowed: perm.default_value, owner_protected: false };
                const isProtected = rolePerm.owner_protected === true;
                const protectedBy = rolePerm.protected_by || null;
                const protectedAt = rolePerm.protected_at || null;

                html += `
                    <div class="rbac-permission-item" style="padding: 1rem; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 6px; ${isProtected ? 'border-left: 4px solid var(--warning-color);' : ''}">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: var(--text-primary);">${this.escapeHtml(perm.action)}</div>
                                <div style="font-size: 0.85rem; color: var(--text-medium); margin-top: 0.25rem;">${this.escapeHtml(perm.description || perm.permission_key)}</div>
                            </div>
                            <label class="rbac-toggle-switch" style="margin-left: 1rem;">
                                <input type="checkbox" 
                                       class="rbac-permission-toggle" 
                                       data-permission-id="${perm.id}"
                                       ${rolePerm.allowed ? 'checked' : ''}
                                       ${isProtected ? 'data-protected="true"' : ''}
                                       ${!this.isOwner && isProtected ? 'disabled title="Protected by Owner"' : ''}>
                                <span class="rbac-toggle-slider"></span>
                            </label>
                        </div>
                        ${isProtected ? `
                            <div style="margin-top: 0.5rem; padding: 0.5rem; background: var(--warning-color); color: white; border-radius: 4px; font-size: 0.85rem;">
                                <i class="fas fa-lock"></i> Protected by ${this.escapeHtml(protectedBy || 'Owner')}
                                ${protectedAt ? ` on ${new Date(protectedAt).toLocaleDateString()}` : ''}
                            </div>
                        ` : ''}
                        ${this.isOwner ? `
                            <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem;">
                                ${!isProtected ? `
                                    <button class="btn-secondary btn-sm rbac-protect-btn" data-permission-id="${perm.id}" style="font-size: 0.8rem; padding: 0.25rem 0.5rem;">
                                        <i class="fas fa-lock"></i> Protect
                                    </button>
                                ` : `
                                    <button class="btn-secondary btn-sm rbac-unprotect-btn" data-permission-id="${perm.id}" style="font-size: 0.8rem; padding: 0.25rem 0.5rem;">
                                        <i class="fas fa-unlock"></i> Unprotect
                                    </button>
                                `}
                            </div>
                        ` : ''}
                    </div>
                `;
            });

            html += `
                    </div>
                </div>
            `;
        });

        container.html(html);
    }

    /**
     * Update a permission
     */
    async updatePermission(permissionId, allowed, $toggle) {
        const isProtected = $toggle.data('protected') === true;
        let password = null;

        // If protected, prompt for password
        if (isProtected && !this.isOwner) {
            password = await this.promptForPassword();
            if (password === null) {
                // User cancelled - revert toggle
                $toggle.prop('checked', !allowed);
                return;
            }
        }

        // Disable toggle during update
        $toggle.prop('disabled', true);

        try {
            const response = await $.ajax({
                url: window.SENTINEL_CONFIG.apiBase + '/proxy.php?path=rbac&action=update-permission',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    role: this.currentRole,
                    permission_id: permissionId,
                    allowed: allowed,
                    password: password
                })
            });

            if (response.success) {
                this.showSuccess('Permission updated successfully');
                // Reload to get updated protection status
                this.loadRolePermissions();
            } else {
                if (response.error === 'owner_password_required') {
                    this.showError('This permission is protected by the Owner. Please enter the owner password to modify it.');
                    // Prompt for password and retry
                    password = await this.promptForPassword();
                    if (password !== null) {
                        $toggle.prop('disabled', false);
                        this.updatePermission(permissionId, allowed, $toggle);
                        return;
                    }
                } else if (response.error === 'invalid_password') {
                    this.showError('Invalid owner password. Permission change denied.');
                } else {
                    this.showError('Failed to update permission: ' + (response.error || 'Unknown error'));
                }
                // Revert toggle on error
                $toggle.prop('checked', !allowed);
            }
        } catch (error) {
            console.error('Error updating permission:', error);
            
            // Extract error message from jQuery error object
            let errorMessage = 'Failed to update permission.';
            if (error.responseJSON) {
                errorMessage = error.responseJSON.error || error.responseJSON.message || errorMessage;
            } else if (error.responseText) {
                try {
                    const errorData = JSON.parse(error.responseText);
                    errorMessage = errorData.error || errorData.message || errorMessage;
                } catch (e) {
                    errorMessage = error.responseText || errorMessage;
                }
            } else if (error.statusText) {
                errorMessage = `Request failed: ${error.statusText}`;
            } else if (error.message) {
                errorMessage = error.message;
            }
            
            this.showError(errorMessage);
            // Revert toggle on error
            $toggle.prop('checked', !allowed);
        } finally {
            $toggle.prop('disabled', false);
        }
    }

    /**
     * Protect a permission (Owner only)
     */
    async protectPermission(permissionId) {
        if (!this.isOwner) {
            this.showError('Only Owners can protect permissions');
            return;
        }

        const confirmed = await this.showConfirm(
            'Protect Permission',
            'This will generate a password that will be required to modify this permission. The password will be shown once - save it securely. Continue?'
        );

        if (!confirmed) {
            return;
        }

        try {
            const response = await $.ajax({
                url: window.SENTINEL_CONFIG.apiBase + '/proxy.php?path=rbac&action=protect-permission',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    permission_id: permissionId,
                    role: this.currentRole
                })
            });

            if (response.success) {
                // Show password to user (only shown once)
                await this.showPasswordModal(
                    'Permission Protected',
                    `Permission has been protected. Use this password to modify it in the future:`,
                    response.password
                );
                this.loadRolePermissions();
            } else {
                this.showError('Failed to protect permission: ' + (response.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error protecting permission:', error);
            this.showError('Failed to protect permission. Please check console for details.');
        }
    }

    /**
     * Unprotect a permission (Owner only)
     */
    async unprotectPermission(permissionId) {
        if (!this.isOwner) {
            this.showError('Only Owners can unprotect permissions');
            return;
        }

        const confirmed = await this.showConfirm(
            'Remove Protection',
            'This will remove the password protection from this permission. Continue?'
        );

        if (!confirmed) {
            return;
        }

        let password = await this.promptForPassword();
        if (password === null) {
            this.showError('Unprotection cancelled. Owner password not provided.');
            return;
        }

        try {
            const response = await $.ajax({
                url: window.SENTINEL_CONFIG.apiBase + '/proxy.php?path=rbac&action=unprotect-permission',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    permission_id: permissionId,
                    role: this.currentRole,
                    password: password
                })
            });

            if (response.success) {
                this.showSuccess('Protection removed successfully');
                this.loadRolePermissions();
            } else {
                this.showError('Failed to remove protection: ' + (response.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error unprotecting permission:', error);
            this.showError('Failed to remove protection. Please check console for details.');
        }
    }

    /**
     * Load permission change history
     */
    async loadHistory() {
        const roleFilter = $('#rbac-history-role-filter').val() || null;

        try {
            const response = await $.ajax({
                url: window.SENTINEL_CONFIG.apiBase + '/proxy.php',
                method: 'GET',
                data: {
                    path: 'rbac',
                    action: 'history',
                    role: roleFilter || '',
                    limit: 50
                },
                // Ensure data is properly serialized for GET requests
                traditional: true
            });

            if (response.success) {
                this.renderHistory(response.history);
            } else {
                this.showError('Failed to load history: ' + (response.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error loading history:', error);
            this.showError('Failed to load history. Please check console for details.');
        }
    }

    /**
     * Render permission change history
     */
    renderHistory(history) {
        const container = $('#rbac-history-list');
        
        if (!history || history.length === 0) {
            container.html('<div class="loading-messages">No permission changes found.</div>');
            return;
        }

        let html = `
            <table class="rbac-history-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: var(--ghost-white); border-bottom: 2px solid var(--border-color);">
                        <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Date</th>
                        <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Permission</th>
                        <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Role</th>
                        <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Changed By</th>
                        <th style="padding: 0.75rem; text-align: center; font-weight: 600;">Old Value</th>
                        <th style="padding: 0.75rem; text-align: center; font-weight: 600;">New Value</th>
                        <th style="padding: 0.75rem; text-align: center; font-weight: 600;">Password Verified</th>
                    </tr>
                </thead>
                <tbody>
        `;

        history.forEach(change => {
            const date = new Date(change.created_at).toLocaleString();
            html += `
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <td style="padding: 0.75rem;">${this.escapeHtml(date)}</td>
                    <td style="padding: 0.75rem;">
                        <div style="font-weight: 600;">${this.escapeHtml(change.action || change.permission_key)}</div>
                        <div style="font-size: 0.85rem; color: var(--text-medium);">${this.escapeHtml(change.category || '')}</div>
                    </td>
                    <td style="padding: 0.75rem;">
                        <span class="role-badge role-${this.escapeHtml(change.role)}">${this.capitalize(change.role)}</span>
                    </td>
                    <td style="padding: 0.75rem;">${this.escapeHtml(change.changed_by_username || 'system')}</td>
                    <td style="padding: 0.75rem; text-align: center;">
                        ${change.old_value === null ? '<span style="color: var(--text-medium);">-</span>' : (change.old_value ? '<span style="color: var(--success-color);">✓ Allow</span>' : '<span style="color: var(--error-color);">✗ Deny</span>')}
                    </td>
                    <td style="padding: 0.75rem; text-align: center;">
                        ${change.new_value ? '<span style="color: var(--success-color);">✓ Allow</span>' : '<span style="color: var(--error-color);">✗ Deny</span>'}
                    </td>
                    <td style="padding: 0.75rem; text-align: center;">
                        ${change.password_verified ? '<i class="fas fa-check" style="color: var(--success-color);"></i>' : '<i class="fas fa-times" style="color: var(--text-medium);"></i>'}
                    </td>
                </tr>
            `;
        });

        html += `
                </tbody>
            </table>
        `;

        container.html(html);
    }

    /**
     * Prompt for owner password
     */
    promptForPassword() {
        return new Promise((resolve) => {
            // Use the generic modal system
            $('#generic-modal-title').text('Owner Password Required');
            $('#generic-modal-message').text('This permission is protected by the Owner. Please enter the owner password to modify it:');
            $('#generic-modal-input-container').show();
            $('#generic-modal-input').show().val('').focus();
            $('#generic-modal-textarea').hide();
            $('#generic-modal').show();

            $('#generic-modal-confirm').off('click').on('click', () => {
                const password = $('#generic-modal-input').val();
                $('#generic-modal').hide();
                resolve(password || null);
            });

            $('#generic-modal-cancel, #generic-modal-close').off('click').on('click', () => {
                $('#generic-modal').hide();
                resolve(null);
            });
        });
    }

    /**
     * Show password modal (for displaying generated password)
     */
    showPasswordModal(title, message, password) {
        return new Promise((resolve) => {
            $('#generic-modal-title').text(title);
            $('#generic-modal-message').html(`${message}<br><br><strong style="font-family: monospace; font-size: 1.2rem; color: var(--primary-color); background: var(--bg-secondary); padding: 1rem; border-radius: 4px; display: block; text-align: center; margin-top: 1rem;">${this.escapeHtml(password)}</strong><br><br><em style="color: var(--warning-color);">⚠️ Save this password securely - it will not be shown again!</em>`);
            $('#generic-modal-input-container').hide();
            $('#generic-modal').show();

            $('#generic-modal-confirm').off('click').on('click', () => {
                $('#generic-modal').hide();
                resolve();
            });

            $('#generic-modal-cancel, #generic-modal-close').off('click').on('click', () => {
                $('#generic-modal').hide();
                resolve();
            });
        });
    }

    /**
     * Show confirmation dialog
     */
    showConfirm(title, message) {
        return new Promise((resolve) => {
            $('#generic-modal-title').text(title);
            $('#generic-modal-message').text(message);
            $('#generic-modal-input-container').hide();
            $('#generic-modal').show();

            $('#generic-modal-confirm').off('click').on('click', () => {
                $('#generic-modal').hide();
                resolve(true);
            });

            $('#generic-modal-cancel, #generic-modal-close').off('click').on('click', () => {
                $('#generic-modal').hide();
                resolve(false);
            });
        });
    }

    /**
     * Show success message
     */
    showSuccess(message) {
        // Use app.js notification system if available
        if (window.app && typeof window.app.showNotification === 'function') {
            window.app.showNotification(message, 'success');
        } else {
            alert('Success: ' + message);
        }
    }

    /**
     * Show error message
     */
    showError(message) {
        // Use app.js notification system if available
        if (window.app && typeof window.app.showNotification === 'function') {
            window.app.showNotification(message, 'error');
        } else {
            alert('Error: ' + message);
        }
    }

    /**
     * Get icon for category
     */
    getCategoryIcon(category) {
        const icons = {
            'see': 'eye',
            'say': 'comment',
            'do': 'cog',
            'link': 'link',
            'other': 'question-circle'
        };
        return icons[category] || 'question-circle';
    }

    /**
     * Capitalize first letter
     */
    capitalize(str) {
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    /**
     * Escape HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize when DOM is ready
$(document).ready(() => {
    window.rbacAdmin = new RBACAdmin();
});

