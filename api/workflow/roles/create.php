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
    $currentUserId = (int)$userInfo['id'];
    $currentRole = $userInfo['role'];

    // Only manager/admin/super_admin can modify roles
    if (!in_array($currentRole, ['manager', 'admin', 'super_admin'])) {
        api_error('Non autorizzato', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    // Accept both 'role' and 'workflow_role' for backward compatibility
    $role = $input['workflow_role'] ?? $input['role'] ?? '';
    $targetUserId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : 0;

    if (!in_array($role, ['validator','approver'], true)) {
        api_error('Ruolo non valido. Usa "validator" o "approver"', 400);
    }
    if ($targetUserId <= 0) {
        api_error('user_id richiesto e deve essere positivo', 400);
    }

    // Determine tenant
    if ($tenantId <= 0) {
        $tenantId = (int)($userInfo['tenant_id'] ?? 0);
    }
    if ($tenantId <= 0) {
        api_error('tenant_id richiesto', 400);
    }

    // Validate user exists and belongs to tenant
    $userCheck = $db->fetchOne(
        "SELECT u.id
         FROM users u
         INNER JOIN user_tenant_access uta ON u.id = uta.user_id
         WHERE u.id = ?
           AND uta.tenant_id = ?
           AND u.deleted_at IS NULL
           AND uta.deleted_at IS NULL",
        [$targetUserId, $tenantId]
    );

    if (!$userCheck) {
        api_error('Utente non trovato o non appartiene a questo tenant', 404);
    }

    // Authorization: admin must have access to tenant
    if ($currentRole === 'admin') {
        $hasAccess = $db->fetchOne(
            "SELECT 1 FROM user_tenant_access
             WHERE user_id = ?
               AND tenant_id = ?
               AND deleted_at IS NULL",
            [$currentUserId, $tenantId]
        );
        if (!$hasAccess) {
            api_error('Accesso negato al tenant', 403);
        }
    }

    // Check if workflow_roles table exists (should exist from migration)
    $tableCheck = $db->fetchOne(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = 'workflow_roles'"
    );

    if (!$tableCheck) {
        api_error('Tabella workflow_roles non trovata. Eseguire la migrazione.', 500);
    }

    // Upsert role using workflow_role column name
    $now = date('Y-m-d H:i:s');

    // Check if role already exists
    $existingRole = $db->fetchOne(
        "SELECT id FROM workflow_roles
         WHERE tenant_id = ?
           AND user_id = ?
           AND workflow_role = ?
           AND deleted_at IS NULL",
        [$tenantId, $targetUserId, $role]
    );

    if ($existingRole) {
        // Update timestamp
        $db->update('workflow_roles', [
            'updated_at' => $now
        ], [
            'id' => $existingRole['id']
        ]);
    } else {
        // Insert new role
        $db->insert('workflow_roles', [
            'tenant_id' => $tenantId,
            'user_id' => $targetUserId,
            'workflow_role' => $role,
            'assigned_by_user_id' => $currentUserId,
            'created_at' => $now,
            'updated_at' => $now
        ]);
    }

    api_success(null, 'Ruolo salvato con successo');

} catch (Exception $e) {
    error_log('[API Workflow Roles Create] Error: ' . $e->getMessage());
    error_log('[API Workflow Roles Create] User ID: ' . ($currentUserId ?? 'unknown'));
    error_log('[API Workflow Roles Create] Tenant ID: ' . ($tenantId ?? 'unknown'));
    api_error('Errore nel salvataggio del ruolo', 500);
}