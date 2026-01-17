<?php
/**
 * Common bootstrap for Consulting Plans (Tenant 28 internal tools)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/tenant28_access_check.php';

initializeApiEnvironment();
verifyApiAuthentication();

$userInfo = getApiUserInfo();
$db = Database::getInstance();

// Enforce tenant 28 access (super_admin always ok; admin only if has tenant 28 access)
$t28 = cnxCheckTenant28Access($userInfo);
if (!$t28['ok']) {
    api_error($t28['reason'], 403);
}

// Schema-drift safety: if migration 34 hasn't been applied, fail gracefully (avoid PHP fatal/SQL errors)
try {
    $hasPlans = $db->fetchOne("SHOW TABLES LIKE 'consulting_plans'");
    $hasItems = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_items'");
    $hasLinks = $db->fetchOne("SHOW TABLES LIKE 'consulting_plan_task_links'");
    if (!$hasPlans || !$hasItems || !$hasLinks) {
        api_error(
            'Modulo pianificazione non inizializzato: applica la migrazione database 34 (consulting plans)',
            503,
            ['migration' => 'database/migrations/34_consulting_plans_tenant28.sql']
        );
    }
} catch (Exception $e) {
    // If SHOW TABLES fails, degrade with a generic message
    api_error('Database non disponibile per il modulo pianificazione', 503);
}

/**
 * Return list of client tenants the current user can manage in planning tool.
 * - super_admin: all active tenants except 28
 * - admin: only tenants from user_tenant_access + primary tenant, excluding 28
 *
 * @return array<int, array{id:int,name:string,denominazione:?string}>
 */
function cnx_consulting_allowed_clients(Database $db, array $userInfo): array {
    $role = (string)($userInfo['role'] ?? 'user');
    $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    $primaryTenantId = (int)($userInfo['tenant_id'] ?? 0);

    if ($role === 'super_admin') {
        $rows = $db->fetchAll(
            "SELECT id, name, COALESCE(denominazione, name) AS denominazione
             FROM tenants
             WHERE deleted_at IS NULL
               AND status = 'active'
               AND id <> ?
             ORDER BY COALESCE(denominazione, name) ASC",
            [CNX_VENDOR_TENANT_ID]
        );
        return array_map(static fn($r) => [
            'id' => (int)$r['id'],
            'name' => (string)$r['name'],
            'denominazione' => (string)($r['denominazione'] ?? $r['name'] ?? ''),
        ], $rows ?: []);
    }

    // Admin: only assigned companies (plus primary), excluding tenant 28
    $ids = [];
    if ($primaryTenantId > 0 && $primaryTenantId !== CNX_VENDOR_TENANT_ID) {
        $ids[] = $primaryTenantId;
    }

    if ($userId > 0) {
        $utaHasDeletedAt = $db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_tenant_access'
               AND COLUMN_NAME = 'deleted_at'
             LIMIT 1"
        );
        $utaWhere = $utaHasDeletedAt ? " AND uta.deleted_at IS NULL" : "";
        $rows = $db->fetchAll(
            "SELECT DISTINCT uta.tenant_id
             FROM user_tenant_access uta
             WHERE uta.user_id = ?" . $utaWhere,
            [$userId]
        );
        foreach ($rows ?: [] as $r) {
            $tid = (int)($r['tenant_id'] ?? 0);
            if ($tid > 0 && $tid !== CNX_VENDOR_TENANT_ID) {
                $ids[] = $tid;
            }
        }
    }

    $ids = array_values(array_unique(array_filter($ids, static fn($v) => $v > 0)));
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    array_unshift($params, CNX_VENDOR_TENANT_ID); // for safety if reused

    $rows = $db->fetchAll(
        "SELECT id, name, COALESCE(denominazione, name) AS denominazione
         FROM tenants
         WHERE deleted_at IS NULL
           AND status = 'active'
           AND id IN ($placeholders)
         ORDER BY COALESCE(denominazione, name) ASC",
        $ids
    );

    return array_map(static fn($r) => [
        'id' => (int)$r['id'],
        'name' => (string)$r['name'],
        'denominazione' => (string)($r['denominazione'] ?? $r['name'] ?? ''),
    ], $rows ?: []);
}

/**
 * Return whether the given client tenant_id is allowed for current user.
 */
function cnx_consulting_is_client_allowed(Database $db, array $userInfo, int $clientTenantId): bool {
    if ($clientTenantId <= 0 || $clientTenantId === CNX_VENDOR_TENANT_ID) {
        return false;
    }
    $role = (string)($userInfo['role'] ?? 'user');
    if ($role === 'super_admin') {
        return true;
    }
    foreach (cnx_consulting_allowed_clients($db, $userInfo) as $c) {
        if ((int)$c['id'] === $clientTenantId) return true;
    }
    return false;
}


