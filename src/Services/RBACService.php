<?php
/**
 * RBAC Service
 * 
 * Provides Role-Based Access Control functionality.
 * Manages permissions, role assignments, and owner-protected permissions.
 */

declare(strict_types=1);

namespace iChat\Services;

use iChat\Repositories\PermissionRepository;
use iChat\Repositories\AuthRepository;

class RBACService
{
    private PermissionRepository $permissionRepo;
    private AuthRepository $authRepo;

    public function __construct()
    {
        $this->permissionRepo = new PermissionRepository();
        $this->authRepo = new AuthRepository();
    }

    /**
     * Check if a user has a specific permission
     * 
     * @param string|null $userRole User's role (null for guest)
     * @param string $permissionKey Permission key (e.g., "message.send")
     * @return bool True if user has permission, false otherwise
     */
    public function hasPermission(?string $userRole, string $permissionKey): bool
    {
        // Default to guest if no role provided
        $role = $userRole ?? 'guest';
        
        // Owners always have all permissions
        if ($role === 'owner') {
            return true;
        }

        return $this->permissionRepo->hasPermission($role, $permissionKey);
    }

    /**
     * Check if current user has permission
     * 
     * @param string $permissionKey Permission key
     * @return bool True if user has permission
     */
    public function currentUserHasPermission(string $permissionKey): bool
    {
        $authService = new AuthService();
        $user = $authService->getCurrentUser();
        $role = $user['role'] ?? 'guest';
        
        return $this->hasPermission($role, $permissionKey);
    }

    /**
     * Get all permissions grouped by category
     * 
     * @return array Permissions grouped by category
     */
    public function getAllPermissionsGrouped(): array
    {
        $permissions = $this->permissionRepo->getAllPermissions();
        $grouped = [];

        foreach ($permissions as $permission) {
            $category = $permission['category'] ?? 'other';
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $permission;
        }

        return $grouped;
    }

    /**
     * Get all permissions for a role
     * 
     * @param string $role Role name
     * @return array Array of role permissions
     */
    public function getRolePermissions(string $role): array
    {
        return $this->permissionRepo->getRolePermissions($role);
    }

    /**
     * Update role permission
     * 
     * @param string $role Role name
     * @param int $permissionId Permission ID
     * @param bool $allowed Whether permission is allowed
     * @param int|null $userId User ID making the change
     * @param string|null $username Username making the change
     * @param string|null $password Owner password if permission is protected
     * @return array Result with success status and message
     */
    public function updateRolePermission(
        string $role,
        int $permissionId,
        bool $allowed,
        ?int $userId = null,
        ?string $username = null,
        ?string $password = null
    ): array {
        // Check if permission is owner-protected
        $protection = $this->permissionRepo->getOwnerProtection($permissionId, $role);
        
        if ($protection !== null) {
            // Permission is protected - require password
            if (empty($password)) {
                return [
                    'success' => false,
                    'error' => 'owner_password_required',
                    'message' => 'This permission is protected by the Owner and requires a password to modify.'
                ];
            }

            // Verify password
            if (!password_verify($password, $protection['password_hash'])) {
                return [
                    'success' => false,
                    'error' => 'invalid_password',
                    'message' => 'Invalid owner password. Permission change denied.'
                ];
            }
        }

        // Get current permission value for logging
        $permission = $this->permissionRepo->getPermissionByKey(
            $this->getPermissionKeyById($permissionId)
        );
        
        $currentPermissions = $this->permissionRepo->getRolePermissions($role);
        $oldValue = null;
        foreach ($currentPermissions as $rp) {
            if ($rp['permission_id'] == $permissionId) {
                $oldValue = (bool)$rp['allowed'];
                break;
            }
        }

        // Update permission
        $success = $this->permissionRepo->updateRolePermission(
            $role,
            $permissionId,
            $allowed,
            $userId,
            $username
        );

        if ($success) {
            // Log the change
            $this->permissionRepo->logPermissionChange(
                $permissionId,
                $role,
                $oldValue,
                $allowed,
                $userId,
                $username ?? 'system',
                null,
                $protection !== null
            );

            return [
                'success' => true,
                'message' => 'Permission updated successfully'
            ];
        }

        return [
            'success' => false,
            'error' => 'update_failed',
            'message' => 'Failed to update permission'
        ];
    }

    /**
     * Protect a permission with owner password
     * 
     * @param int $permissionId Permission ID
     * @param string $role Role name
     * @param string $password Password to protect the permission
     * @param int|null $userId Owner user ID
     * @param string|null $username Owner username
     * @return array Result with success status, message, and generated password
     */
    public function protectPermission(
        int $permissionId,
        string $role,
        string $password,
        ?int $userId = null,
        ?string $username = null
    ): array {
        // Generate password hash
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $success = $this->permissionRepo->protectPermission(
            $permissionId,
            $role,
            $passwordHash,
            $userId,
            $username
        );

        if ($success) {
            return [
                'success' => true,
                'message' => 'Permission protected successfully',
                'password' => $password // Return password for display (only generated once)
            ];
        }

        return [
            'success' => false,
            'error' => 'protection_failed',
            'message' => 'Failed to protect permission'
        ];
    }

    /**
     * Generate a secure random password for owner protection
     * 
     * @param int $length Password length (default 16)
     * @return string Generated password
     */
    public function generateOwnerPassword(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $max = strlen($chars) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }

        return $password;
    }

    /**
     * Remove owner protection from a permission
     * 
     * @param int $permissionId Permission ID
     * @param string $role Role name
     * @param string|null $password Owner password required to unprotect
     * @param int|null $userId User ID making the change
     * @param string|null $username Username making the change
     * @return array Result with success status and message
     */
    public function unprotectPermission(
        int $permissionId,
        string $role,
        ?string $password = null,
        ?int $userId = null,
        ?string $username = null
    ): array {
        // Check if permission is protected
        $protection = $this->permissionRepo->getOwnerProtection($permissionId, $role);
        
        if ($protection === null) {
            return [
                'success' => false,
                'error' => 'not_protected',
                'message' => 'Permission is not protected.'
            ];
        }

        // Password is required to unprotect
        if (empty($password)) {
            return [
                'success' => false,
                'error' => 'owner_password_required',
                'message' => 'This permission is protected by the Owner and requires a password to unprotect.'
            ];
        }

        // Verify password
        if (!password_verify($password, $protection['password_hash'])) {
            return [
                'success' => false,
                'error' => 'invalid_password',
                'message' => 'Invalid owner password. Unprotection denied.'
            ];
        }

        // Remove protection
        $success = $this->permissionRepo->unprotectPermission($permissionId, $role);

        if ($success) {
            // Log the change
            $this->permissionRepo->logPermissionChange(
                $permissionId,
                $role,
                true, // Old value was protected
                false, // New value is not protected
                $userId,
                $username ?? 'system',
                'Permission unprotected by owner',
                true // Password was verified
            );
            
            return [
                'success' => true,
                'message' => 'Protection removed successfully.'
            ];
        }

        return [
            'success' => false,
            'error' => 'unprotect_failed',
            'message' => 'Failed to remove protection.'
        ];
    }

    /**
     * Get permission change history
     * 
     * @param int|null $permissionId Optional permission ID filter
     * @param string|null $role Optional role filter
     * @param int $limit Maximum records
     * @return array Permission change history
     */
    public function getPermissionChangeHistory(
        ?int $permissionId = null,
        ?string $role = null,
        int $limit = 100
    ): array {
        return $this->permissionRepo->getPermissionChangeHistory($permissionId, $role, $limit);
    }

    /**
     * Get permission key by ID (helper method)
     * 
     * @param int $permissionId Permission ID
     * @return string Permission key
     */
    private function getPermissionKeyById(int $permissionId): string
    {
        $permissions = $this->permissionRepo->getAllPermissions();
        foreach ($permissions as $perm) {
            if ($perm['id'] == $permissionId) {
                return $perm['permission_key'];
            }
        }
        return '';
    }

    /**
     * Check if user can manage RBAC
     * 
     * @param string|null $userRole User's role
     * @return bool True if user can manage RBAC
     */
    public function canManageRBAC(?string $userRole): bool
    {
        return $this->hasPermission($userRole, 'rbac.manage');
    }

    /**
     * Check if user can view RBAC
     * 
     * @param string|null $userRole User's role
     * @return bool True if user can view RBAC
     */
    public function canViewRBAC(?string $userRole): bool
    {
        return $this->hasPermission($userRole, 'rbac.view');
    }
}

