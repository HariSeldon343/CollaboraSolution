<?php
/**
 * API Endpoint: List Tenant Roles (Ruoli Aziendali)
 * Retrieves list of custom business roles for a tenant with user counts
 *
 * @method GET
 * @param int tenant_id (optional) - Target tenant ID (super_admin can query any, others restricted to own)
 * @param bool include_inactive (optional) - Include inactive roles (default: false)
 *
 * @response {
 *   success: true,
 *   data: {
 *     roles: [{id, name, code, description, color, icon, sort_order, is_active, user_count, created_at}],
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

    // Verify CSRF token
    verifyApiCsrfToken();

    // Get database instance
    $db = Database::getInstance();

    // Determine target tenant_id
    $targetTenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;

    // If no tenant_id specified, use session company filter (for super_admin) or current tenant
    if ($targetTenantId <= 0) {
        if ($isSuperAdmin && isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null) {
            $targetTenantId = (int)$_SESSION['company_filter_id'];
        } else {
            $targetTenantId = $currentTenantId;
        }
    }

    // Validate tenant_id is provided
    if ($targetTenantId <= 0) {
        api_error('tenant_id richiesto', 400);
    }

    // Authorization check: only super_admin can query other tenants
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
            api_error('Accesso negato al tenant richiesto', 403);
        }
    }

    // Only admin, manager, super_admin can view tenant roles configuration
    if (!in_array($currentUserRole, ['admin', 'manager', 'super_admin']) && !$isSuperAdmin) {
        api_error('Permessi insufficienti per visualizzare i ruoli aziendali', 403);
    }

    // Schema drift: optional management permissions column (migration 59)
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

    // Verify tenant exists and get has_custom_roles flag
    $tenant = $db->fetchOne(
        "SELECT id, name, has_custom_roles, tenant_role_assignment_roles" . ($hasManagePermCol ? ", tenant_role_management_roles" : "") . "
         FROM tenants
         WHERE id = ?
           AND deleted_at IS NULL",
        [$targetTenantId]
    );

    if (!$tenant) {
        api_error('Azienda non trovata', 404);
    }

    // Build query for roles
    $includeInactive = isset($_GET['include_inactive']) &&
                       filter_var($_GET['include_inactive'], FILTER_VALIDATE_BOOLEAN);

    $whereConditions = [
        'tr.tenant_id = ?',
        'tr.deleted_at IS NULL'
    ];
    $params = [$targetTenantId];

    if (!$includeInactive) {
        $whereConditions[] = 'tr.is_active = 1';
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Query roles with user count from user_tenant_access
    $query = "
        SELECT
            tr.id,
            tr.name,
            tr.code,
            tr.description,
            tr.color,
            tr.icon,
            tr.sort_order,
            tr.is_active,
            tr.created_at,
            tr.updated_at,
            (
                SELECT COUNT(DISTINCT uta.user_id)
                FROM user_tenant_access uta
                WHERE uta.tenant_role_id = tr.id
                  AND uta.deleted_at IS NULL
            ) AS user_count
        FROM tenant_roles tr
        WHERE {$whereClause}
        ORDER BY tr.sort_order ASC, tr.name ASC
    ";

    $roles = $db->fetchAll($query, $params);

    // Format response data
    $formattedRoles = [];
    foreach ($roles as $role) {
        $formattedRoles[] = [
            'id' => (int)$role['id'],
            'name' => $role['name'],
            'code' => $role['code'],
            'description' => $role['description'],
            'color' => $role['color'] ?? '#6366f1',
            'icon' => $role['icon'],
            'sort_order' => (int)$role['sort_order'],
            'is_active' => (bool)$role['is_active'],
            'user_count' => (int)$role['user_count'],
            'created_at' => $role['created_at'],
            'updated_at' => $role['updated_at']
        ];
    }

    // Determine if current user can assign business roles for this tenant
    // Default: only super_admin can assign. Super admin can enable other system roles per-tenant.
    $canAssignBusinessRoles = $isSuperAdmin;
    if (!$canAssignBusinessRoles) {
        $raw = $tenant['tenant_role_assignment_roles'] ?? null;
        $allowedRoles = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $allowedRoles = array_map('strval', $decoded);
            }
        }
        $canAssignBusinessRoles = in_array($currentUserRole, $allowedRoles, true);
    }

    // Determine if current user can manage tenant roles (create/update/delete) for this tenant
    // Default: only super_admin. Super admin can enable other system roles per-tenant.
    $canManageTenantRoles = $isSuperAdmin;
    if (!$canManageTenantRoles) {
        $allowedRoles = [];
        if ($hasManagePermCol) {
            $raw = $tenant['tenant_role_management_roles'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $allowedRoles = array_map('strval', $decoded);
                }
            }
        }
        $canManageTenantRoles = in_array($currentUserRole, $allowedRoles, true);
    }

    // Success response
    api_success([
        'roles' => $formattedRoles,
        'tenant_has_custom_roles' => (bool)$tenant['has_custom_roles'],
        'can_assign_custom_roles' => (bool)$canAssignBusinessRoles,
        'can_manage_custom_roles' => (bool)$canManageTenantRoles,
        'storage_available_management_roles' => (bool)$hasManagePermCol,
        'tenant_role_management_roles' => $hasManagePermCol ? ($tenant['tenant_role_management_roles'] ?? null) : null,
        'tenant_id' => (int)$targetTenantId,
        'tenant_name' => $tenant['name'],
        'total_roles' => count($formattedRoles)
    ], 'Ruoli aziendali recuperati con successo');

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log('[Tenant Roles List] PDO Error: ' . $e->getMessage());

    // Return user-friendly error
    api_error('Errore database', 500);

} catch (Throwable $e) {
    // Log the error
    error_log('[Tenant Roles List] Error: ' . $e->getMessage());

    // Return generic error
    api_error('Errore interno del server', 500);
}
