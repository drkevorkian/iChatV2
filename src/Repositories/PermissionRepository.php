<?php
/**
 * Permission Repository
 * 
 * Handles database operations for RBAC permissions.
 * Manages permission definitions, role assignments, and owner-protected permissions.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;

class PermissionRepository
{
    /**
     * Get all permission definitions
     * 
     * @return array Array of permission definitions
     */
    public function getAllPermissions(): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }

        try {
            $sql = 'SELECT * FROM rbac_permissions ORDER BY category, action';
            return Database::query($sql);
        } catch (\Exception $e) {
            error_log("PermissionRepository: Failed to get permissions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get permission by key
     * 
     * @param string $permissionKey Permission key (e.g., "message.send")
     * @return array|null Permission definition or null if not found
     */
    public function getPermissionByKey(string $permissionKey): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }

        try {
            $sql = 'SELECT * FROM rbac_permissions WHERE permission_key = :key LIMIT 1';
            return Database::queryOne($sql, [':key' => $permissionKey]);
        } catch (\Exception $e) {
            error_log("PermissionRepository: Failed to get permission by key: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all permissions for a specific role
     * 
     * @param string $role Role name (guest, user, moderator, administrator, trusted_admin, owner)
     * @return array Array of role permissions with permission details
     */
    public function getRolePermissions(string $role): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }

        try {
            $sql = 'SELECT 
                        rp.id,
                        rp.role,
                        rp.allowed,
                        rp.set_by_username,
                        rp.updated_at,
                        p.id as permission_id,
                        p.permission_key,
                        p.category,
                        p.action,
                        p.description,
                        p.default_value
                    FROM rbac_role_permissions rp
                    INNER JOIN rbac_permissions p ON rp.permission_id = p.id
                    WHERE rp.role = :role
                    ORDER BY p.category, p.action';
            
            return Database::query($sql, [':role' => $role]);
        } catch (\Exception $e) {
            error_log("PermissionRepository: Failed to get role permissions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if a role has a specific permission
     * 
     * @param string $role Role name
     * @param string $permissionKey Permission key
     * @return bool True if role has permission, false otherwise
     */
    public function hasPermission(string $role, string $permissionKey): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            // Owners always have all permissions
            if ($role === 'owner') {
                return true;
            }

            $sql = 'SELECT rp.allowed
                    FROM rbac_role_permissions rp
                    INNER JOIN rbac_permissions p ON rp.permission_id = p.id
                    WHERE rp.role = :role AND p.permission_key = :permission_key
                    LIMIT 1';
            
            $result = Database::queryOne($sql, [
                ':role' => $role,
                ':permission_key' => $permissionKey
            ]);

            return (bool)($result['allowed'] ?? false);
        } catch (\Exception $e) {
            error_log("PermissionRepository: Failed to check permission: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update role permission
     * 
     * @param string $role Role name
     * @param int $permissionId Permission ID
     * @param bool $allowed Whether permission is allowed
     * @param int|null $userId User ID who is making the change
     * @param string|null $username Username who is making the change
     * @return bool True on success, false on failure
     */
    public function updateRolePermission(
        string $role,
        int $permissionId,
        bool $allowed,
        ?int $userId = null,
        ?string $username = null
    ): bool {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            $sql = 'INSERT INTO rbac_role_permissions 
                    (role, permission_id, allowed, set_by_user_id, set_by_username, updated_at)
                    VALUES (:role, :permission_id, :allowed, :user_id, :username, NOW())
                    ON DUPLICATE KEY UPDATE
                    allowed = VALUES(allowed),
                    set_by_user_id = VALUES(set_by_user_id),
                    set_by_username = VALUES(set_by_username),
                    updated_at = NOW()';

            Database::execute($sql, [
                ':role' => $role,
                ':permission_id' => $permissionId,
                ':allowed' => $allowed ? 1 : 0,
                ':user_id' => $userId,
                ':username' => $username
            ]);

            return true;
        } catch (\Exception $e) {
            error_log("PermissionRepository: Failed to update role permission: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a permission is owner-protected
     * 
     * @param int $permissionId Permission ID
     * @param string $role Role name
     * @return array|null Owner protection record or null if not protected
     */
    public function getOwnerProtection(int $permissionId, string $role): ?array
    {
        if (!DatabaseHealth::isAvailable()) {
            return null;
        }

        // Check if the table exists first (patch 027 might not be applied)
        if (!$this->tableExists('rbac_owner_protected')) {
            // Table doesn't exist - patch 027 not applied or failed
            // Return null silently (no error logging needed)
            return null;
        }

        try {
            $sql = 'SELECT * FROM rbac_owner_protected 
                    WHERE permission_id = :permission_id AND role = :role
                    LIMIT 1';
            
            return Database::queryOne($sql, [
                ':permission_id' => $permissionId,
                ':role' => $role
            ]);
        } catch (\Exception $e) {
            // Only log if it's not a "table doesn't exist" error
            if (strpos($e->getMessage(), "doesn't exist") === false) {
                error_log("PermissionRepository: Failed to get owner protection: " . $e->getMessage());
            }
            return null;
        }
    }
    
    /**
     * Check if a database table exists
     * 
     * @param string $tableName Table name
     * @return bool True if table exists, false otherwise
     */
    private function tableExists(string $tableName): bool
    {
        try {
            $sql = 'SELECT COUNT(*) as count 
                    FROM information_schema.tables 
                    WHERE table_schema = DATABASE() 
                    AND table_name = :table_name';
            
            $result = Database::queryOne($sql, [':table_name' => $tableName]);
            return !empty($result) && ($result['count'] ?? 0) > 0;
        } catch (\Exception $e) {
            // If we can't check, assume it doesn't exist
            return false;
        }
    }

    /**
     * Protect a permission with owner password
     * 
     * @param int $permissionId Permission ID
     * @param string $role Role name
     * @param string $passwordHash Bcrypt hash of the password
     * @param int|null $userId Owner user ID
     * @param string|null $username Owner username
     * @return bool True on success, false on failure
     */
    public function protectPermission(
        int $permissionId,
        string $role,
        string $passwordHash,
        ?int $userId = null,
        ?string $username = null
    ): bool {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        // Check if the table exists first
        if (!$this->tableExists('rbac_owner_protected')) {
            error_log("PermissionRepository: Cannot protect permission - rbac_owner_protected table does not exist. Please apply patch 027.");
            return false;
        }

        try {
            $sql = 'INSERT INTO rbac_owner_protected 
                    (permission_id, role, password_hash, protected_by_user_id, protected_by_username, password_generated_at)
                    VALUES (:permission_id, :role, :password_hash, :user_id, :username, NOW())
                    ON DUPLICATE KEY UPDATE
                    password_hash = VALUES(password_hash),
                    protected_by_user_id = VALUES(protected_by_user_id),
                    protected_by_username = VALUES(protected_by_username),
                    password_generated_at = NOW(),
                    updated_at = NOW()';

            Database::execute($sql, [
                ':permission_id' => $permissionId,
                ':role' => $role,
                ':password_hash' => $passwordHash,
                ':user_id' => $userId,
                ':username' => $username
            ]);

            return true;
        } catch (\Exception $e) {
            // Only log if it's not a "table doesn't exist" error
            if (strpos($e->getMessage(), "doesn't exist") === false) {
                error_log("PermissionRepository: Failed to protect permission: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Remove owner protection from a permission
     * 
     * @param int $permissionId Permission ID
     * @param string $role Role name
     * @return bool True on success, false on failure
     */
    public function unprotectPermission(int $permissionId, string $role): bool
    {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        // Check if the table exists first
        if (!$this->tableExists('rbac_owner_protected')) {
            // Table doesn't exist - nothing to unprotect
            return true; // Return true since there's nothing to do
        }

        try {
            $sql = 'DELETE FROM rbac_owner_protected 
                    WHERE permission_id = :permission_id AND role = :role';

            Database::execute($sql, [
                ':permission_id' => $permissionId,
                ':role' => $role
            ]);

            return true;
        } catch (\Exception $e) {
            // Only log if it's not a "table doesn't exist" error
            if (strpos($e->getMessage(), "doesn't exist") === false) {
                error_log("PermissionRepository: Failed to unprotect permission: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Log permission change
     * 
     * @param int $permissionId Permission ID
     * @param string $role Role name
     * @param bool|null $oldValue Old permission value
     * @param bool $newValue New permission value
     * @param int|null $userId User ID making the change
     * @param string $username Username making the change
     * @param string|null $reason Reason for the change
     * @param bool $passwordVerified Whether owner password was verified
     * @return bool True on success, false on failure
     */
    public function logPermissionChange(
        int $permissionId,
        string $role,
        ?bool $oldValue,
        bool $newValue,
        ?int $userId,
        string $username,
        ?string $reason = null,
        bool $passwordVerified = false
    ): bool {
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            $sql = 'INSERT INTO rbac_permission_changes 
                    (permission_id, role, old_value, new_value, changed_by_user_id, changed_by_username, change_reason, password_verified)
                    VALUES (:permission_id, :role, :old_value, :new_value, :user_id, :username, :reason, :password_verified)';

            Database::execute($sql, [
                ':permission_id' => $permissionId,
                ':role' => $role,
                ':old_value' => $oldValue !== null ? ($oldValue ? 1 : 0) : null,
                ':new_value' => $newValue ? 1 : 0,
                ':user_id' => $userId,
                ':username' => $username,
                ':reason' => $reason,
                ':password_verified' => $passwordVerified ? 1 : 0
            ]);

            return true;
        } catch (\Exception $e) {
            error_log("PermissionRepository: Failed to log permission change: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get permission change history
     * 
     * @param int|null $permissionId Optional permission ID to filter by
     * @param string|null $role Optional role to filter by
     * @param int $limit Maximum number of records to return
     * @return array Array of permission change records
     */
    public function getPermissionChangeHistory(
        ?int $permissionId = null,
        ?string $role = null,
        int $limit = 100
    ): array {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }

        try {
            $sql = 'SELECT 
                        pc.*,
                        p.permission_key,
                        p.category,
                        p.action
                    FROM rbac_permission_changes pc
                    INNER JOIN rbac_permissions p ON pc.permission_id = p.id
                    WHERE 1=1';
            
            $params = [];
            
            if ($permissionId !== null) {
                $sql .= ' AND pc.permission_id = :permission_id';
                $params[':permission_id'] = $permissionId;
            }
            
            if ($role !== null) {
                $sql .= ' AND pc.role = :role';
                $params[':role'] = $role;
            }
            
            $sql .= ' ORDER BY pc.created_at DESC LIMIT :limit';
            $params[':limit'] = $limit;

            return Database::query($sql, $params);
        } catch (\Exception $e) {
            error_log("PermissionRepository: Failed to get permission change history: " . $e->getMessage());
            return [];
        }
    }
}

