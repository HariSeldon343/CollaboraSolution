<?php
/**
 * ZIP approval recipients resolver (folder ZIP download requests).
 *
 * Centralized helper to avoid duplicating logic across endpoints/tools.
 *
 * Rules:
 * - Prefer tenants.manager_id ONLY if it points to a real manager (users.role='manager'),
 *   has a non-empty email, and has access to the tenant (users.tenant_id==tenantId OR user_tenant_access exists).
 * - Otherwise fallback to all tenant managers:
 *   - managers linked via user_tenant_access for the tenant
 *   - plus any "mono-tenant" managers with users.tenant_id==tenantId
 * - Always exclude super_admin (implicitly by requiring role='manager')
 * - Always exclude deleted users (deleted_at IS NULL)
 * - Deduplicate by email (case-insensitive)
 *
 * @return array<int, array{email:string,name:string,user_id:int}>
 */
function cnx_resolve_tenant_manager_recipients(Database $db, int $tenantId, int $managerId, ?array &$diag = null): array {
    $tenantId = (int)$tenantId;
    $managerId = (int)$managerId;

    $diag = [
        'tenant_id' => $tenantId,
        'manager_id' => $managerId,
        'source' => 'none', // manager_id | tenant_managers | none
        'manager_id_checked' => ($managerId > 0),
        'manager_id_valid' => false,
        'manager_id_reason' => null,
    ];

    $dedupe = [];
    $out = [];

    $push = static function (array $row) use (&$out, &$dedupe): void {
        $email = trim((string)($row['email'] ?? ''));
        if ($email === '') return;
        $key = strtolower($email);
        if (isset($dedupe[$key])) return;
        $dedupe[$key] = true;
        $out[] = [
            'user_id' => (int)($row['user_id'] ?? $row['id'] ?? 0),
            'name' => (string)(($row['name'] ?? '') !== '' ? $row['name'] : 'Manager'),
            'email' => $email,
        ];
    };

    // Step A: prefer configured tenants.manager_id (but only if it really is a manager for this tenant)
    if ($managerId > 0) {
        $m = $db->fetchOne(
            "SELECT id, name, email, role, tenant_id
             FROM users
             WHERE id = ?
               AND deleted_at IS NULL
             LIMIT 1",
            [$managerId]
        );

        if (!$m) {
            $diag['manager_id_reason'] = 'manager_user_not_found';
        } else {
            $role = (string)($m['role'] ?? '');
            $email = trim((string)($m['email'] ?? ''));
            $mTenantId = (int)($m['tenant_id'] ?? 0);

            if ($role !== 'manager') {
                $diag['manager_id_reason'] = 'manager_id_not_manager_role';
            } elseif ($email === '') {
                $diag['manager_id_reason'] = 'manager_id_missing_email';
            } else {
                $hasAccess = false;
                if ($mTenantId === $tenantId) {
                    $hasAccess = true;
                } else {
                    $uta = $db->fetchOne(
                        "SELECT 1
                         FROM user_tenant_access
                         WHERE user_id = ?
                           AND tenant_id = ?
                         LIMIT 1",
                        [$managerId, $tenantId]
                    );
                    $hasAccess = (bool)$uta;
                }

                if (!$hasAccess) {
                    $diag['manager_id_reason'] = 'manager_id_no_tenant_access';
                } else {
                    $diag['manager_id_valid'] = true;
                    $diag['source'] = 'manager_id';
                    $push([
                        'user_id' => (int)$m['id'],
                        'name' => (string)($m['name'] ?? 'Manager'),
                        'email' => $email,
                    ]);
                    return $out;
                }
            }
        }
    }

    // Step B: fallback to all managers of the tenant (via user_tenant_access)
    $rows = $db->fetchAll(
        "SELECT u.id, u.name, u.email
         FROM user_tenant_access uta
         INNER JOIN users u ON u.id = uta.user_id
         WHERE uta.tenant_id = ?
           AND u.role = 'manager'
           AND u.deleted_at IS NULL",
        [$tenantId]
    );
    foreach ($rows as $r) {
        $push([
            'user_id' => (int)($r['id'] ?? 0),
            'name' => (string)($r['name'] ?? 'Manager'),
            'email' => (string)($r['email'] ?? ''),
        ]);
    }

    // Include mono-tenant managers (legacy pattern)
    $monoRows = $db->fetchAll(
        "SELECT id, name, email
         FROM users
         WHERE tenant_id = ?
           AND role = 'manager'
           AND deleted_at IS NULL",
        [$tenantId]
    );
    foreach ($monoRows as $r) {
        $push([
            'user_id' => (int)($r['id'] ?? 0),
            'name' => (string)($r['name'] ?? 'Manager'),
            'email' => (string)($r['email'] ?? ''),
        ]);
    }

    $diag['source'] = !empty($out) ? 'tenant_managers' : 'none';
    return $out;
}

