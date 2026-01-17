<?php
// GET: list tenant targets allowed for manual calendar event creation
require_once __DIR__ . '/_common.php';

try {
    $role = (string)($userInfo['role'] ?? 'user');
    $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    $primaryTenantId = (int)($userInfo['tenant_id'] ?? 0);

    if ($role === 'super_admin') {
        $rows = $db->fetchAll(
            "SELECT id, name, COALESCE(denominazione, name) AS denominazione
             FROM tenants
             WHERE deleted_at IS NULL
               AND status = 'active'
             ORDER BY COALESCE(denominazione, name) ASC"
        );
        api_success(['targets' => $rows ?: []]);
    }

    // Admin: only tenants they can access (primary + user_tenant_access), plus tenant 28
    $ids = [];
    if ($primaryTenantId > 0) $ids[] = $primaryTenantId;
    $ids[] = CNX_VENDOR_TENANT_ID;

    if ($userId > 0) {
        $utaHasDeletedAt = $db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_tenant_access'
               AND COLUMN_NAME = 'deleted_at'
             LIMIT 1"
        );
        $utaWhere = $utaHasDeletedAt ? " AND deleted_at IS NULL" : "";
        $uta = $db->fetchAll(
            "SELECT DISTINCT tenant_id FROM user_tenant_access WHERE user_id = ?" . $utaWhere,
            [$userId]
        );
        foreach ($uta ?: [] as $r) {
            $tid = (int)($r['tenant_id'] ?? 0);
            if ($tid > 0) $ids[] = $tid;
        }
    }
    $ids = array_values(array_unique(array_filter($ids, static fn($v) => $v > 0)));
    if (empty($ids)) {
        api_success(['targets' => []]);
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->fetchAll(
        "SELECT id, name, COALESCE(denominazione, name) AS denominazione
         FROM tenants
         WHERE deleted_at IS NULL
           AND status = 'active'
           AND id IN ($ph)
         ORDER BY COALESCE(denominazione, name) ASC",
        $ids
    );
    api_success(['targets' => $rows ?: []]);
} catch (Exception $e) {
    error_log('[CONSULTING_CAL_TARGETS] ' . $e->getMessage());
    api_error('Errore caricamento aziende calendario', 500);
}


