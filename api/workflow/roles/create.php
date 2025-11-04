<?php
/**
 * Workflow Roles - Assign a role (validator/approver) to a user for a tenant
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_auth.php';
require_once __DIR__ . '/../../../includes/db.php';

initializeApiEnvironment();
verifyApiAuthentication();
verifyApiCsrfToken();

try {
    $db = Database::getInstance();
    $userInfo = getApiUserInfo();
    $currentUserId = (int)$userInfo['user_id'];
    $currentRole = $userInfo['role'];

    // Only manager/admin/super_admin can modify roles
    if (!in_array($currentRole, ['manager', 'admin', 'super_admin'])) {
        apiError('Non autorizzato', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $role = $input['role'] ?? '';
    $targetUserId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : 0;

    if (!in_array($role, ['validator','approver'], true)) {
        apiError('Ruolo non valido', 400);
    }
    if ($targetUserId <= 0) {
        apiError('user_id richiesto', 400);
    }

    // Determine tenant
    if ($tenantId <= 0) {
        $tenantId = (int)($userInfo['tenant_id'] ?? 0);
    }
    if ($tenantId <= 0) {
        apiError('tenant_id richiesto', 400);
    }

    // Authorization: admin must have access to tenant
    if ($currentRole === 'admin') {
        $hasAccess = $db->fetchOne(
            "SELECT 1 FROM user_tenant_access WHERE user_id = ? AND tenant_id = ?",
            [$currentUserId, $tenantId]
        );
        if (!$hasAccess) {
            apiError('Accesso negato al tenant', 403);
        }
    }

    // Ensure table exists
    $db->query("CREATE TABLE IF NOT EXISTS workflow_roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        user_id INT NOT NULL,
        role ENUM('validator','approver') NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uniq_role (tenant_id, user_id, role),
        INDEX (tenant_id), INDEX (user_id), INDEX (role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Upsert role
    $now = date('Y-m-d H:i:s');
    // Try insert; if duplicate, update timestamp
    try {
        $db->insert('workflow_roles', [
            'tenant_id' => $tenantId,
            'user_id' => $targetUserId,
            'role' => $role,
            'created_at' => $now,
            'updated_at' => $now
        ]);
    } catch (Exception $e) {
        // If duplicate, update updated_at
        $db->update('workflow_roles', [
            'updated_at' => $now
        ], [
            'tenant_id' => $tenantId,
            'user_id' => $targetUserId,
            'role' => $role
        ]);
    }

    apiSuccess(null, 'Ruolo salvato');

} catch (Exception $e) {
    logApiError('WorkflowRolesCreate', $e);
    apiError('Errore nel salvataggio del ruolo', 500);
}