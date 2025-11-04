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
    
    // Load available users for tenant (for dropdowns)
    $availableUsers = [];
    if ($tenantId > 0) {
        $availableUsers = $db->fetchAll(
            "SELECT id, display_name AS name, email, role AS system_role
             FROM users
             WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL
             ORDER BY display_name ASC",
            [$tenantId]
        );
    }
    
    // Fetch current role holders if table exists
    $roles = [];
    $usersWithRoles = [];
    
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
        
        if ($tenantId > 0) {
            $usersWithRoles = $db->fetchAll(
                "SELECT wr.user_id, wr.role AS workflow_role, 
                        u.display_name AS user_name, u.email AS user_email, u.role AS system_role
                 FROM workflow_roles wr
                 JOIN users u ON u.id = wr.user_id
                 WHERE wr.tenant_id = ? AND u.deleted_at IS NULL
                 ORDER BY u.display_name ASC",
                [$tenantId]
            );
            
            // Also provide compact roles vector for current state
            $roles = array_map(function($r) {
                return [
                    'user_id' => (int)$r['user_id'],
                    'role' => $r['workflow_role']
                ];
            }, $usersWithRoles);
        }
    } catch (Exception $e) {
        // Ignore table errors; return empty roles
        $roles = [];
        $usersWithRoles = [];
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

