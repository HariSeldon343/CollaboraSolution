<?php
/**
 * Common bootstrap/helpers for Compliance (Leva 2/3/4)
 *
 * - Auth: includes/api_auth.php (JSON-only responses)
 * - DB: includes/db.php
 * - Optional tenant 28 gate: includes/tenant28_access_check.php
 *
 * IMPORTANT:
 * - This file does NOT enforce tenant 28 by default because some endpoints are for the client tenant dashboard.
 * - Use cnx_compliance_require_tenant28_planning() in endpoints that are only callable from planning (tenant 28 tools).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/tenant28_access_check.php';

initializeApiEnvironment();
verifyApiAuthentication();

$userInfo = getApiUserInfo();
$db = Database::getInstance();

/**
 * Enforce CSRF for write operations.
 */
function cnx_compliance_require_csrf_for_write(): void {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        verifyApiCsrfToken(true);
    }
}

/**
 * Enforce tenant 28 gate (for endpoints triggered from planning).
 */
function cnx_compliance_require_tenant28_planning(array $userInfo): void {
    $t28 = cnxCheckTenant28Access($userInfo);
    if (!$t28['ok']) {
        api_error($t28['reason'], 403);
    }
}

/**
 * Resolve target tenant id for client-tenant scoped operations.
 * - Prefer explicit ?tenant_id=
 * - else CompanyFilter (company_filter_id)
 * - else user's primary tenant
 */
function cnx_compliance_resolve_target_tenant_id(array $userInfo): int {
    $tid = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
    if ($tid <= 0) {
        $tid = (int)($_SESSION['company_filter_id'] ?? 0);
    }
    if ($tid <= 0) {
        $tid = (int)($userInfo['tenant_id'] ?? 0);
    }
    return $tid;
}

/**
 * Multi-tenant authorization: does current user have access to $tenantId?
 * - super_admin: always yes
 * - own primary tenant: yes
 * - admin/manager: must have user_tenant_access membership (schema drift safe for deleted_at)
 * - user: only own primary tenant
 */
function cnx_compliance_user_has_access_to_tenant(Database $db, array $userInfo, int $tenantId): bool {
    $tenantId = (int)$tenantId;
    if ($tenantId <= 0) return false;

    $role = (string)($userInfo['role'] ?? 'user');
    if ($role === 'super_admin') return true;

    $primaryTenantId = (int)($userInfo['tenant_id'] ?? 0);
    if ($primaryTenantId > 0 && $tenantId === $primaryTenantId) return true;

    // Only admin/manager can use cross-tenant membership
    if (!in_array($role, ['admin', 'manager'], true)) {
        return false;
    }

    $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    if ($userId <= 0) return false;

    try {
        $utaHasDeletedAt = $db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_tenant_access'
               AND COLUMN_NAME = 'deleted_at'
             LIMIT 1"
        );
        $utaWhere = $utaHasDeletedAt ? " AND deleted_at IS NULL" : "";
        $has = $db->fetchOne(
            "SELECT 1
             FROM user_tenant_access
             WHERE user_id = ?
               AND tenant_id = ?" . $utaWhere . "
             LIMIT 1",
            [$userId, $tenantId]
        );
        return (bool)$has;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Schema-drift safe module availability for compliance tables.
 *
 * @param string[] $requiredTables
 * @param ?string $migrationHint
 * @return array{ok:bool,missing?:string[],migration?:string}
 */
function cnx_compliance_check_tables(Database $db, array $requiredTables, ?string $migrationHint = null): array {
    $missing = [];
    foreach ($requiredTables as $t) {
        try {
            // Avoid SHOW TABLES because some MySQL/MariaDB variants don't support it reliably
            // with prepared statements (and Database::fetchOne() uses prepare/execute).
            $has = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                 LIMIT 1",
                [$t]
            );
        } catch (Throwable $e) {
            $has = false;
        }
        if (!$has) $missing[] = $t;
    }
    if (!empty($missing)) {
        $out = [
            'ok' => false,
            'missing' => $missing,
        ];
        if ($migrationHint) {
            $out['migration'] = $migrationHint;
        }
        return $out;
    }
    return ['ok' => true];
}

