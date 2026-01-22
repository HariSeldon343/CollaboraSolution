<?php
/**
 * API Endpoint: Delete Tenant Role (Ruolo Aziendale)
 * Soft deletes a custom business role for a tenant
 *
 * @method POST
 * @body {
 *   role_id: int (required - ID of role to delete)
 * }
 *
 * @note Users assigned to this role will have their tenant_role_id set to NULL via FK CASCADE
 * @note Automatically updates tenant.has_custom_roles = 0 if no more active roles remain
 *
 * @response {
 *   success: true,
 *   data: {
 *     deleted_role_id: int,
 *     affected_users: int,
 *     tenant_has_custom_roles: bool,
 *     tenant_id: int
 *   },
 *   message: string
 * }
 *
 * @version 1.0.0
 * @since 2025-12-15
 * @author CollaboraNexio Team
 */

declare(strict_types=1);

// Include centralized API authentication
require_once __DIR__ . '/../../includes/api_auth.php';

// Initialize API environment (session, headers, error handling)
initializeApiEnvironment();

// Disable caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Metodo non consentito', 405);
    }

    // Include required files
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/db.php';

    // Verify authentication
    verifyApiAuthentication();

    // Get current user info from session
    $userInfo = getApiUserInfo();
    $currentUserId = (int)($userInfo['user_id'] ?? 0);
    $currentUserRole = $userInfo['role'] ?? 'user';
    $currentTenantId = (int)($userInfo['tenant_id'] ?? 0);

    // Enhanced super_admin detection (BUG-146 pattern)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $isSuperAdmin = (
        ($currentUserRole === 'super_admin') ||
        (($_SESSION['role'] ?? '') === 'super_admin') ||
        (($_SESSION['user_role'] ?? '') === 'super_admin')
    );

    // Fast role gate (detailed per-tenant permission check happens after loading tenant)
    if (!in_array($currentUserRole, ['admin', 'manager', 'super_admin'], true) && !$isSuperAdmin) {
        api_error('Permessi insufficienti per eliminare ruoli aziendali', 403);
    }

    // Verify CSRF token
    verifyApiCsrfToken();

    // Get database instance
    $db = Database::getInstance();

    // Parse input (support both JSON and form-data)
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    // Validate role_id
    $roleId = isset($input['role_id']) ? (int)$input['role_id'] : 0;
    // Also accept 'id' for convenience
    if ($roleId <= 0 && isset($input['id'])) {
        $roleId = (int)$input['id'];
    }

    if ($roleId <= 0) {
        api_error('role_id richiesto', 400);
    }

    // Get existing role
    $existingRole = $db->fetchOne(
        "SELECT tr.*, t.name as tenant_name
         FROM tenant_roles tr
         JOIN tenants t ON t.id = tr.tenant_id
         WHERE tr.id = ?
           AND tr.deleted_at IS NULL",
        [$roleId]
    );

    if (!$existingRole) {
        api_error('Ruolo non trovato', 404);
    }

    $targetTenantId = (int)$existingRole['tenant_id'];

    // Tenant-role management permission (migration 59):
    // - super_admin always allowed
    // - admin/manager allowed only if enabled per-tenant via tenants.tenant_role_management_roles
    if (!$isSuperAdmin) {
        $hasManagePermCol = false;
        try {
            $col = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'tenants'
                   AND COLUMN_NAME = 'tenant_role_management_roles'
                 LIMIT 1"
            );
            $hasManagePermCol = ((int)($col['ok'] ?? 0) === 1);
        } catch (Exception $e) {
            $hasManagePermCol = false;
        }

        $allowed = [];
        if ($hasManagePermCol) {
            $t = $db->fetchOne(
                "SELECT tenant_role_management_roles
                 FROM tenants
                 WHERE id = ?
                   AND deleted_at IS NULL
                 LIMIT 1",
                [$targetTenantId]
            );
            $raw = $t['tenant_role_management_roles'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $allowed = array_map('strval', $decoded);
            }
        }

        if (!in_array($currentUserRole, $allowed, true)) {
            api_error('Non hai i permessi per eliminare ruoli aziendali (abilita i permessi in “Gestione Ruoli Aziendali”)', 403);
        }
    }

    // Authorization check: only super_admin can delete roles for other tenants
    if (!$isSuperAdmin && $targetTenantId !== $currentTenantId) {
        // Check user_tenant_access for multi-tenant membership
        $hasAccess = $db->fetchOne(
            "SELECT 1
             FROM user_tenant_access
             WHERE user_id = ?
               AND tenant_id = ?
               AND deleted_at IS NULL",
            [$currentUserId, $targetTenantId]
        );

        if (!$hasAccess) {
            api_error('Non puoi eliminare ruoli di altre aziende', 403);
        }

        // Even with access, must be admin role
        if ($currentUserRole !== 'admin') {
            api_error('Solo admin possono eliminare ruoli aziendali', 403);
        }
    }

    // Count users currently assigned to this role
    $affectedUsersResult = $db->fetchOne(
        "SELECT COUNT(DISTINCT user_id) as cnt
         FROM user_tenant_access
         WHERE tenant_role_id = ?
           AND deleted_at IS NULL",
        [$roleId]
    );
    $affectedUsers = (int)($affectedUsersResult['cnt'] ?? 0);

    // Begin transaction
    $db->beginTransaction();

    try {
        $now = date('Y-m-d H:i:s');

        // Store role info before deletion for audit
        $deletedRoleInfo = [
            'id' => $existingRole['id'],
            'name' => $existingRole['name'],
            'code' => $existingRole['code'],
            'tenant_id' => $targetTenantId,
            'affected_users' => $affectedUsers
        ];

        // Clear tenant_role_id from all user_tenant_access records
        // This is done explicitly rather than relying on FK ON DELETE SET NULL
        // because we're doing a soft delete, not a hard delete
        $db->query(
            "UPDATE user_tenant_access
             SET tenant_role_id = NULL,
                 updated_at = ?
             WHERE tenant_role_id = ?
               AND deleted_at IS NULL",
            [$now, $roleId]
        );

        // Soft delete the role
        $db->update('tenant_roles', [
            'deleted_at' => $now,
            'updated_at' => $now,
            'is_active' => 0
        ], [
            'id' => $roleId
        ]);

        // Count remaining active roles for this tenant
        $activeRolesCount = $db->fetchOne(
            "SELECT COUNT(*) as cnt
             FROM tenant_roles
             WHERE tenant_id = ?
               AND is_active = 1
               AND deleted_at IS NULL",
            [$targetTenantId]
        );

        $hasCustomRoles = (int)($activeRolesCount['cnt'] ?? 0) > 0;

        // Update tenant has_custom_roles flag
        $db->update('tenants', [
            'has_custom_roles' => $hasCustomRoles ? 1 : 0,
            'updated_at' => $now
        ], [
            'id' => $targetTenantId
        ]);

        $db->commit();

        // Audit log
        try {
            require_once __DIR__ . '/../../includes/audit_helper.php';
            AuditLogger::logDelete(
                $currentUserId,
                $targetTenantId,
                'tenant_role',
                $roleId,
                "Deleted tenant role: {$existingRole['name']} ({$existingRole['code']})",
                $deletedRoleInfo
            );
        } catch (Exception $e) {
            error_log('[AUDIT] Tenant role delete audit failed: ' . $e->getMessage());
        }

        // Success response
        api_success([
            'deleted_role_id' => (int)$roleId,
            'deleted_role_name' => $existingRole['name'],
            'deleted_role_code' => $existingRole['code'],
            'affected_users' => $affectedUsers,
            'tenant_has_custom_roles' => $hasCustomRoles,
            'remaining_active_roles' => (int)($activeRolesCount['cnt'] ?? 0),
            'tenant_id' => $targetTenantId
        ], 'Ruolo aziendale eliminato con successo');

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        throw $e;
    }

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log('[Tenant Roles Delete] PDO Error: ' . $e->getMessage());

    // Return user-friendly error
    api_error('Errore database', 500);

} catch (Throwable $e) {
    // Log the error
    error_log('[Tenant Roles Delete] Error: ' . $e->getMessage());

    // Return error message
    api_error($e->getMessage() ?: 'Errore interno del server', 500);
}
