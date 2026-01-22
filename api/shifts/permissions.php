<?php
/**
 * API Endpoint: Shift Permissions (Permessi Turni)
 *
 * Per-tenant settings to define which system roles can:
 * - manage shifts (create/update/delete, manage shift types)
 * - approve shift change requests
 *
 * super_admin is always allowed (not stored).
 *
 * GET  action=get    -> returns permissions + computed flags for current user
 * POST action=save   -> saves permissions (admin/manager/super_admin)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api_auth.php';
initializeApiEnvironment();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function cnx_is_super_admin(string $currentUserRole): bool {
    if ($currentUserRole === 'super_admin') return true;
    return (($_SESSION['role'] ?? '') === 'super_admin') || (($_SESSION['user_role'] ?? '') === 'super_admin');
}

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/shift_permissions_helper.php';

    verifyApiAuthentication();
    $userInfo = getApiUserInfo();
    $currentUserId = (int)($userInfo['user_id'] ?? 0);
    $currentUserRole = (string)($userInfo['role'] ?? 'user');
    $currentTenantId = (int)($userInfo['tenant_id'] ?? 0);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $isSuperAdmin = cnx_is_super_admin($currentUserRole);
    if ($isSuperAdmin) {
        $currentUserRole = 'super_admin';
    }

    // Parse request
    $method = $_SERVER['REQUEST_METHOD'];
    $rawBody = cnx_get_raw_request_body();
    $data = json_decode($rawBody, true) ?: [];
    $action = $data['action'] ?? $_GET['action'] ?? 'get';

    // CSRF required for both get/save to avoid leaking config cross-site
    verifyApiCsrfToken();

    $db = Database::getInstance();

    // Feature-detect columns (avoid SQL errors on older schemas)
    $hasShiftPermCol = $db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'tenants'
           AND COLUMN_NAME = 'shift_permissions'"
    );

    // Determine tenant
    $targetTenantId = (int)($data['tenant_id'] ?? $_GET['tenant_id'] ?? 0);
    if ($targetTenantId <= 0) {
        if ($isSuperAdmin && isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null) {
            $targetTenantId = (int)$_SESSION['company_filter_id'];
        } else {
            $targetTenantId = $currentTenantId;
        }
    }

    if ($targetTenantId <= 0) {
        api_error('Seleziona un\'azienda per gestire i permessi turni', 400);
    }

    // Authorization: allow super_admin always, others only within accessible tenant
    if (!$isSuperAdmin && $targetTenantId !== $currentTenantId) {
        $utaHasDeletedAt = $db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_tenant_access'
               AND COLUMN_NAME = 'deleted_at'
             LIMIT 1"
        );
        $utaWhere = $utaHasDeletedAt ? " AND deleted_at IS NULL" : "";
        $hasAccess = $db->fetchOne(
            "SELECT 1 FROM user_tenant_access
             WHERE user_id = ? AND tenant_id = ?" . $utaWhere . "
             LIMIT 1",
            [$currentUserId, $targetTenantId]
        );
        if (!$hasAccess) {
            api_error('Accesso negato al tenant richiesto', 403);
        }
    }

    $tenantSelect = $hasShiftPermCol
        ? "SELECT id, name, shift_permissions FROM tenants WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        : "SELECT id, name FROM tenants WHERE id = ? AND deleted_at IS NULL LIMIT 1";
    $tenant = $db->fetchOne($tenantSelect, [$targetTenantId]);
    if (!$tenant) {
        api_error('Azienda non trovata', 404);
    }

    $perms = $hasShiftPermCol ? cnx_parse_shift_permissions($tenant['shift_permissions'] ?? null) : cnx_default_shift_permissions();
    $canManage = $isSuperAdmin || in_array($currentUserRole, $perms['can_manage_shifts_roles'], true);
    $canApprove = $isSuperAdmin || in_array($currentUserRole, $perms['can_approve_shift_requests_roles'], true);

    if ($action === 'get') {
        api_success([
            'tenant_id' => $targetTenantId,
            'tenant_name' => $tenant['name'] ?? '',
            'permissions' => $perms,
            'storage_available' => (bool)$hasShiftPermCol,
            'computed' => [
                'can_manage_shifts' => $canManage,
                'can_approve_shift_requests' => $canApprove,
                'is_super_admin' => $isSuperAdmin,
                'current_role' => $currentUserRole,
            ],
        ], 'Permessi turni recuperati');
    }

    if ($action !== 'save') {
        api_error('Azione non valida', 400);
    }

    if ($method !== 'POST') {
        api_error('Metodo non supportato', 405);
    }

    // Only super_admin/admin/manager can change settings
    if (!$isSuperAdmin && !in_array($currentUserRole, ['admin', 'manager'], true)) {
        api_error('Permessi insufficienti', 403);
    }

    if (!$hasShiftPermCol) {
        api_error('Migrazione non applicata: shift_permissions assente su tenants', 409);
    }

    $newManage = cnx_normalize_shift_roles((array)($data['can_manage_shifts_roles'] ?? []));
    $newApprove = cnx_normalize_shift_roles((array)($data['can_approve_shift_requests_roles'] ?? []));

    $newPerms = [
        'can_manage_shifts_roles' => $newManage,
        'can_approve_shift_requests_roles' => $newApprove,
    ];

    $db->update('tenants', [
        'shift_permissions' => json_encode($newPerms, JSON_UNESCAPED_UNICODE),
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['id' => $targetTenantId]);

    api_success([
        'tenant_id' => $targetTenantId,
        'permissions' => $newPerms,
        'storage_available' => true,
    ], 'Permessi turni salvati');

} catch (Throwable $e) {
    error_log('[Shift Permissions API] Error: ' . $e->getMessage());
    api_error('Errore interno del server', 500);
}

