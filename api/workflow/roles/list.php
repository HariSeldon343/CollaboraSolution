<?php
/**
 * Workflow Roles - List users and current role holders
 * 
 * Returns available users for the tenant and current workflow role assignments
 * No CSRF required for GET requests (read-only operation)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_auth.php';
require_once __DIR__ . '/../../../includes/db.php';

initializeApiEnvironment();
verifyApiAuthentication();

try {
    $db = Database::getInstance();
    
    $userInfo = getApiUserInfo();
    $currentUserId = (int)$userInfo['user_id'];
    $currentRole = $userInfo['role'];
    $requestedTenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
    
    // Determine tenant scope
    $tenantId = 0;
    if ($currentRole === 'super_admin') {
        $tenantId = $requestedTenantId ?: ((int)($userInfo['tenant_id'] ?? 0));
    } else {
        $tenantId = (int)($userInfo['tenant_id'] ?? 0);
    }
    
    if ($tenantId <= 0 && $currentRole !== 'super_admin') {
        apiError('Tenant non valido', 400);
    }
    
    // BUG-062 FIX: Use LEFT JOIN to show ALL users with role indicators
    // This replaces the old pattern that used NOT IN and resulted in empty arrays
    // New pattern: Show all tenant users, indicate which roles they have
    $availableUsers = [];
    $usersWithRoles = [];
    $roles = [];

    if ($tenantId > 0) {
        try {
            // Ensure table exists (lightweight guard)
            $db->query("CREATE TABLE IF NOT EXISTS workflow_roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                user_id INT NOT NULL,
                role ENUM('validator','approver') NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX (tenant_id), INDEX (user_id), INDEX (role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // LEFT JOIN pattern: Return ALL users with role indicators
            // This ensures dropdown is always populated
            $allUsersWithRoles = $db->fetchAll(
                "SELECT DISTINCT
                    u.id,
                    u.display_name AS name,
                    u.email,
                    u.role AS system_role,
                    GROUP_CONCAT(
                        CASE WHEN wr.role = 'validator' THEN wr.id END
                    ) AS validator_role_ids,
                    GROUP_CONCAT(
                        CASE WHEN wr.role = 'approver' THEN wr.id END
                    ) AS approver_role_ids,
                    MAX(CASE WHEN wr.role = 'validator' THEN 1 ELSE 0 END) AS is_validator,
                    MAX(CASE WHEN wr.role = 'approver' THEN 1 ELSE 0 END) AS is_approver
                FROM users u
                LEFT JOIN workflow_roles wr ON wr.user_id = u.id
                    AND wr.tenant_id = ?
                WHERE u.tenant_id = ?
                  AND u.status = 'active'
                  AND u.deleted_at IS NULL
                GROUP BY u.id, u.display_name, u.email, u.role
                ORDER BY u.display_name ASC",
                [$tenantId, $tenantId]
            );

            // Format for available_users (all users with role indicators)
            $availableUsers = array_map(function($user) {
                return [
                    'id' => (int)$user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'system_role' => $user['system_role'],
                    'is_validator' => (bool)$user['is_validator'],
                    'is_approver' => (bool)$user['is_approver'],
                    'validator_role_ids' => $user['validator_role_ids'] ? explode(',', $user['validator_role_ids']) : [],
                    'approver_role_ids' => $user['approver_role_ids'] ? explode(',', $user['approver_role_ids']) : []
                ];
            }, $allUsersWithRoles);

            // Format for usersWithRoles (only users that have roles - for backward compatibility)
            $usersWithRoles = [];
            foreach ($allUsersWithRoles as $user) {
                if ($user['is_validator'] || $user['is_approver']) {
                    $usersWithRoles[] = [
                        'user_id' => (int)$user['id'],
                        'user_name' => $user['name'],
                        'user_email' => $user['email'],
                        'system_role' => $user['system_role'],
                        'is_validator' => (bool)$user['is_validator'],
                        'is_approver' => (bool)$user['is_approver']
                    ];
                }
            }

            // Compact roles vector for current state
            $roles = [];
            foreach ($allUsersWithRoles as $user) {
                if ($user['is_validator']) {
                    $roles[] = [
                        'user_id' => (int)$user['id'],
                        'role' => 'validator'
                    ];
                }
                if ($user['is_approver']) {
                    $roles[] = [
                        'user_id' => (int)$user['id'],
                        'role' => 'approver'
                    ];
                }
            }

        } catch (Exception $e) {
            // Ignore table errors; return empty arrays
            error_log('[WorkflowRolesList] Database error: ' . $e->getMessage());
            $availableUsers = [];
            $usersWithRoles = [];
            $roles = [];
        }
    }
    
    apiSuccess([
        'available_users' => $availableUsers,
        'roles' => $roles,
        'users' => array_map(function($r) {
            return [
                'user_id' => (int)$r['user_id'],
                'user_name' => $r['user_name'],
                'user_email' => $r['user_email'],
                'system_role' => $r['system_role'],
                'workflow_role' => $r['workflow_role']
            ];
        }, $usersWithRoles)
    ]);
    
} catch (Exception $e) {
    logApiError('WorkflowRolesList', $e);
    apiError('Errore nel caricamento dei ruoli', 500);
}

