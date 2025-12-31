<?php
/**
 * RBAC API Endpoint
 * 
 * Handles Role-Based Access Control operations:
 * - View permissions
 * - Update role permissions
 * - Protect/unprotect permissions with owner password
 * - View permission change history
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Services\RBACService;
use iChat\Services\AuditService;
use iChat\Database;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// SECURITY: Standardized session handling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

// Get current user
$authService = new AuthService();
$currentUser = $authService->getCurrentUser();
$userRole = $currentUser['role'] ?? 'guest';
$userId = $currentUser['id'] ?? null;
$username = $currentUser['username'] ?? $currentUser['user_handle'] ?? 'guest';
$userHandle = $currentUser['user_handle'] ?? $currentUser['username'] ?? 'guest';

// Initialize services
$rbacService = new RBACService();
$auditService = new AuditService();

// Check authorization - only Trusted Admins and Owners can manage RBAC
if (!in_array($userRole, ['trusted_admin', 'owner'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden - Trusted Admin or Owner access required']);
    exit;
}

try {
    // Check if RBAC tables exist
    try {
        $testQuery = Database::queryOne('SELECT COUNT(*) as count FROM rbac_permissions LIMIT 1');
    } catch (\Exception $e) {
        // Tables don't exist - patch 027 needs to be applied
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => 'RBAC system not initialized. Please apply patch 027 (Add RBAC System) from the Admin Dashboard -> System -> Patches section.',
            'patch_required' => '027'
        ]);
        exit;
    }

    switch ($action) {
        case 'permissions':
            // Get all permissions grouped by category
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'GET method required']);
                exit;
            }

            $permissions = $rbacService->getAllPermissionsGrouped();
            
            // Check if permissions are empty (patch might not have seeded data)
            if (empty($permissions) || (is_array($permissions) && count($permissions) === 0)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'No permissions found. The RBAC tables exist but are empty. Please check if patch 027 seeded the initial permissions correctly.',
                    'permissions' => []
                ]);
                exit;
            }
            
            echo json_encode([
                'success' => true,
                'permissions' => $permissions
            ]);
            break;

        case 'role-permissions':
            // Get permissions for a specific role
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'GET method required']);
                exit;
            }

            $role = $security->sanitizeInput($_GET['role'] ?? '');
            
            if (empty($role) || !in_array($role, ['guest', 'user', 'moderator', 'administrator', 'trusted_admin', 'owner'], true)) {
                throw new \InvalidArgumentException('Invalid role');
            }

            $permissions = $rbacService->getRolePermissions($role);
            
            // Check which permissions are owner-protected
            $permissionRepo = new \iChat\Repositories\PermissionRepository();
            foreach ($permissions as &$perm) {
                $protection = $permissionRepo->getOwnerProtection($perm['permission_id'], $role);
                $perm['owner_protected'] = $protection !== null;
                if ($protection !== null) {
                    $perm['protected_by'] = $protection['protected_by_username'] ?? null;
                    $perm['protected_at'] = $protection['password_generated_at'] ?? null;
                }
            }
            unset($perm);

            echo json_encode([
                'success' => true,
                'role' => $role,
                'permissions' => $permissions
            ]);
            break;

        case 'update-permission':
            // Update a role permission
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
            }

            // SECURITY: Secure JSON parsing with error checking
            $rawInput = file_get_contents('php://input');
            if ($rawInput === false) {
                throw new \InvalidArgumentException('Failed to read request body');
            }
            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input: ' . json_last_error_msg());
            }

            $role = $security->sanitizeInput($input['role'] ?? '');
            $permissionId = isset($input['permission_id']) ? (int)$input['permission_id'] : 0;
            $allowed = isset($input['allowed']) ? (bool)$input['allowed'] : false;
            $password = $input['password'] ?? null; // Owner password if permission is protected

            if (empty($role) || !in_array($role, ['guest', 'user', 'moderator', 'administrator', 'trusted_admin'], true)) {
                throw new \InvalidArgumentException('Invalid role - cannot modify owner permissions');
            }

            if ($permissionId <= 0) {
                throw new \InvalidArgumentException('Invalid permission ID');
            }

            // Update permission
            $result = $rbacService->updateRolePermission(
                $role,
                $permissionId,
                $allowed,
                $userId,
                $username,
                $password
            );

            if ($result['success']) {
                // Log admin change (use userHandle for audit logging)
                try {
                    $auditService->logAdminChange(
                        $userHandle,
                        $userId,
                        'rbac_permission_change',
                        'permission',
                        (string)$permissionId,
                        ['role' => $role, 'old_value' => !$allowed],
                        ['role' => $role, 'new_value' => $allowed]
                    );
                } catch (\Exception $e) {
                    // Log audit error but don't fail the permission update
                    error_log("RBAC API: Failed to log admin change: " . $e->getMessage());
                }

                echo json_encode($result);
            } else {
                http_response_code(400);
                echo json_encode($result);
            }
            break;

        case 'protect-permission':
            // Protect a permission with owner password (Owner only)
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
            }

            if ($userRole !== 'owner') {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - Owner access required']);
                exit;
            }

            // SECURITY: Secure JSON parsing with error checking
            $rawInput = file_get_contents('php://input');
            if ($rawInput === false) {
                throw new \InvalidArgumentException('Failed to read request body');
            }
            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input: ' . json_last_error_msg());
            }

            $permissionId = isset($input['permission_id']) ? (int)$input['permission_id'] : 0;
            $role = $security->sanitizeInput($input['role'] ?? '');

            if ($permissionId <= 0) {
                throw new \InvalidArgumentException('Invalid permission ID');
            }

            if (empty($role) || !in_array($role, ['guest', 'user', 'moderator', 'administrator', 'trusted_admin'], true)) {
                throw new \InvalidArgumentException('Invalid role');
            }

            // Generate password
            $password = $rbacService->generateOwnerPassword(16);

            // Protect permission
            $result = $rbacService->protectPermission(
                $permissionId,
                $role,
                $password,
                $userId,
                $username
            );

            if ($result['success']) {
                // Log admin change (use userHandle for audit logging)
                try {
                    $auditService->logAdminChange(
                        $userHandle,
                        $userId,
                        'rbac_permission_protect',
                        'permission',
                        (string)$permissionId,
                        [],
                        ['role' => $role, 'protected' => true]
                    );
                } catch (\Exception $e) {
                    // Log audit error but don't fail the permission update
                    error_log("RBAC API: Failed to log admin change: " . $e->getMessage());
                }

                echo json_encode($result);
            } else {
                http_response_code(400);
                echo json_encode($result);
            }
            break;

        case 'unprotect-permission':
            // Remove owner protection from a permission (Owner only)
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
            }

            if ($userRole !== 'owner') {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - Owner access required']);
                exit;
            }

            // SECURITY: Secure JSON parsing with error checking
            $rawInput = file_get_contents('php://input');
            if ($rawInput === false) {
                throw new \InvalidArgumentException('Failed to read request body');
            }
            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input: ' . json_last_error_msg());
            }

            $permissionId = isset($input['permission_id']) ? (int)$input['permission_id'] : 0;
            $role = $security->sanitizeInput($input['role'] ?? '');
            $password = $input['password'] ?? null; // Owner password required to unprotect

            if ($permissionId <= 0) {
                throw new \InvalidArgumentException('Invalid permission ID');
            }

            if (empty($role) || !in_array($role, ['guest', 'user', 'moderator', 'administrator', 'trusted_admin'], true)) {
                throw new \InvalidArgumentException('Invalid role');
            }

            // Unprotect permission
            $result = $rbacService->unprotectPermission($permissionId, $role, $password, $userId, $userHandle);

            if ($result['success']) {
                // Log admin change (use userHandle for audit logging)
                try {
                    $auditService->logAdminChange(
                        $userHandle,
                        $userId,
                        'rbac_permission_unprotect',
                        'permission',
                        (string)$permissionId,
                        ['role' => $role, 'protected' => true],
                        ['role' => $role, 'protected' => false]
                    );
                } catch (\Exception $e) {
                    // Log audit error but don't fail the permission update
                    error_log("RBAC API: Failed to log admin change: " . $e->getMessage());
                }

                echo json_encode($result);
            } else {
                http_response_code(400);
                echo json_encode($result);
            }
            break;

        case 'history':
            // Get permission change history
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'GET method required']);
                exit;
            }

            $permissionId = isset($_GET['permission_id']) ? (int)$_GET['permission_id'] : null;
            $role = $security->sanitizeInput($_GET['role'] ?? null);
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

            $history = $rbacService->getPermissionChangeHistory($permissionId, $role, $limit);

            echo json_encode([
                'success' => true,
                'history' => $history
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    error_log('RBAC API error: ' . $e->getMessage());
    error_log('RBAC API error trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Request failed',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

