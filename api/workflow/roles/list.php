<?php
/**
 * Workflow Roles List API - NORMALIZED VERSION
 * Returns all users of tenant with workflow role indicators
 *
 * Endpoint: GET /api/workflow/roles/list.php
 *
 * Query Parameters:
 * - tenant_id (optional): Tenant to query (validated via user_tenant_access)
 *                         If absent, defaults to session tenant
 *
 * Response Structure (FIXED - Always Same Keys):
 * {
 *   "success": true,
 *   "data": {
 *     "available_users": [
 *       {
 *         "id": 19,
 *         "name": "Antonio Amodeo",
 *         "email": "a.oedoma@gmail.com",
 *         "system_role": "super_admin",
 *         "is_validator": true,
 *         "is_approver": false
 *       }
 *     ],
 *     "current": {
 *       "validators": [19],
 *       "approvers": [32]
 *     }
 *   },
 *   "message": "Ruoli caricati con successo"
 * }
 *
 * Security:
 * - CSRF token validated (mandatory)
 * - Multi-tenant isolation enforced
 * - Super Admin can query any tenant
 * - Regular users validated via user_tenant_access
 *
 * Pattern: LEFT JOIN to show ALL users with role indicators
 * NO exclusions (no NOT IN patterns)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_auth.php';
require_once __DIR__ . '/../../../includes/db.php';

// Initialize API environment
initializeApiEnvironment();

// No-cache headers (prevents stale 403/500 errors from browser cache)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// CRITICAL: Verify authentication IMMEDIATELY after initialization
verifyApiAuthentication();

// Get user info
$userInfo = getApiUserInfo();

// Verify CSRF token (MANDATORY - all API calls require CSRF)
verifyApiCsrfToken();

try {
    $db = Database::getInstance();

    // Extract user details
    $userId = (int)($userInfo['id'] ?? $userInfo['user_id'] ?? 0);
    $userRole = (string)($userInfo['role'] ?? 'user');
    // BUG-144 FIX: Removed active_tenant_id fallback (column does not exist)
    $sessionTenantId = (int)($userInfo['tenant_id'] ?? 0);

    // Only manager/admin/super_admin can view/modify workflow role config
    if (!in_array($userRole, ['manager', 'admin', 'super_admin'], true)) {
        api_error('Non autorizzato', 403);
    }

    // Parse tenant_id parameter (optional)
    $requestedTenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : null;
    $companyFilterTenantId = (int)($_SESSION['company_filter_id'] ?? 0);

    // Determine target tenant with security validation
    $tenantId = null;

    if ($requestedTenantId !== null) {
        // User requested specific tenant - validate access
        if ($userRole === 'super_admin') {
            // Super Admin: bypass tenant isolation
            $tenantId = $requestedTenantId;
        } else {
            // Manager/Admin: allow session tenant, otherwise validate via access tables
            if ($requestedTenantId === $sessionTenantId && $sessionTenantId > 0) {
                $tenantId = $requestedTenantId;
            } else {
                $hasAccess = false;

                // 1) user_tenant_access (multi-tenant memberships)
                $uta = $db->fetchOne(
                    "SELECT 1 as ok
                     FROM user_tenant_access
                     WHERE user_id = ?
                       AND tenant_id = ?
                       AND deleted_at IS NULL
                     LIMIT 1",
                    [$userId, $requestedTenantId]
                );
                if ($uta) $hasAccess = true;

                // BUG-144 FIX: Removed user_companies table check (table does not exist)
                // Access is already checked via user_tenant_access table above

                if ($hasAccess) {
                    $tenantId = $requestedTenantId;
                } else {
                    api_error('Non hai accesso a questo tenant', 403);
                }
            }
        }
    } else {
        // No tenant_id parameter - fallback to company_filter, then session
        if ($companyFilterTenantId > 0) {
            $tenantId = $companyFilterTenantId;
        } else {
            $tenantId = $sessionTenantId;
        }
    }

    // Validate tenant_id is valid
    if ($tenantId <= 0) {
        api_error('Tenant non valido', 400);
    }

    // BUG-144b FIX: Query to return ALL users with role indicators
    // Removed: user_companies join (table doesn't exist)
    // Removed: u.active_tenant_id (column doesn't exist)
    // Multi-tenant via u.tenant_id OR user_tenant_access
    // BUG-149a+149d FIX: Admin AND Manager are DEFAULT validators/approvers
    // - If user has ANY workflow_roles record (even soft-deleted), they're NOT default
    // - Only users with NO record at all get default role (based on system role)
    $sql = "SELECT DISTINCT
        u.id,
        u.name,
        u.email,
        u.role AS system_role,
        -- Role indicators (boolean flags)
        -- BUG-147b FIX: Only show users with ACTIVE workflow roles (add is_active = 1 filter)
        -- BUG-149a FIX: Removed deleted_at IS NULL from subquery - ANY record means explicitly configured
        -- BUG-149d FIX: Admin AND Manager are default validators/approvers
        MAX(CASE
            WHEN wr.workflow_role = 'validator' AND wr.is_active = 1 THEN 1
            WHEN u.role IN ('admin', 'manager') AND NOT EXISTS (
                SELECT 1 FROM workflow_roles wr_v
                WHERE wr_v.user_id = u.id
                  AND wr_v.tenant_id = ?
                  AND wr_v.workflow_role = 'validator'
            ) THEN 1
            ELSE 0
        END) AS is_validator,
        MAX(CASE
            WHEN wr.workflow_role = 'approver' AND wr.is_active = 1 THEN 1
            WHEN u.role IN ('admin', 'manager') AND NOT EXISTS (
                SELECT 1 FROM workflow_roles wr_a
                WHERE wr_a.user_id = u.id
                  AND wr_a.tenant_id = ?
                  AND wr_a.workflow_role = 'approver'
            ) THEN 1
            ELSE 0
        END) AS is_approver,
        -- Role IDs (comma-separated, for removal operations)
        GROUP_CONCAT(
            CASE WHEN wr.workflow_role = 'validator' AND wr.is_active = 1 THEN wr.id END
        ) AS validator_role_ids,
        GROUP_CONCAT(
            CASE WHEN wr.workflow_role = 'approver' AND wr.is_active = 1 THEN wr.id END
        ) AS approver_role_ids
    FROM users u
    LEFT JOIN user_tenant_access uta ON u.id = uta.user_id
        AND uta.tenant_id = ?
        AND uta.deleted_at IS NULL
    LEFT JOIN workflow_roles wr ON wr.user_id = u.id
        AND wr.tenant_id = ?
        AND wr.deleted_at IS NULL
    WHERE u.deleted_at IS NULL
      AND u.is_active = 1
      AND (u.tenant_id = ? OR uta.user_id IS NOT NULL)
    GROUP BY u.id, u.name, u.email, u.role
    ORDER BY u.name ASC";

    // Execute query
    // BUG-144b FIX: Reduced to 3 placeholders (removed user_companies and active_tenant_id)
    // BUG-148d FIX: Now 5 placeholders (added 2 for admin/manager default subqueries)
    $users = $db->fetchAll($sql, [$tenantId, $tenantId, $tenantId, $tenantId, $tenantId]);

    // Handle empty result gracefully (still return success with empty arrays)
    if (empty($users)) {
        api_success([
            'available_users' => [],
            'current' => [
                'validators' => [],
                'approvers' => []
            ]
        ], 'Nessun utente trovato per questo tenant');
        exit;
    }

    // Build available_users array (all users with role indicators)
    $availableUsers = [];
    foreach ($users as $user) {
        $availableUsers[] = [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'system_role' => $user['system_role'],
            'is_validator' => (bool)$user['is_validator'],
            'is_approver' => (bool)$user['is_approver']
        ];
    }

    // Build current validators array (user IDs only)
    $currentValidators = [];
    foreach ($users as $user) {
        if ($user['is_validator']) {
            $currentValidators[] = (int)$user['id'];
        }
    }

    // Build current approvers array (user IDs only)
    $currentApprovers = [];
    foreach ($users as $user) {
        if ($user['is_approver']) {
            $currentApprovers[] = (int)$user['id'];
        }
    }

    // FIXED response structure (ALWAYS same keys)
    api_success([
        'available_users' => $availableUsers,
        'current' => [
            'validators' => $currentValidators,
            'approvers' => $currentApprovers
        ]
    ], 'Ruoli caricati con successo');

} catch (Exception $e) {
    // Log error with context
    error_log('[API Workflow Roles List] Error: ' . $e->getMessage());
    error_log('[API Workflow Roles List] User ID: ' . ($userId ?? 'unknown'));
    error_log('[API Workflow Roles List] Tenant ID: ' . ($tenantId ?? 'unknown'));

    // Return generic error (don't expose internal details)
    api_error('Errore durante il caricamento dei ruoli', 500);
}
