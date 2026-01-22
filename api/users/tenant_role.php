<?php
/**
 * API Endpoint: Get/Set User Tenant Role (Ruolo Aziendale per-azienda)
 *
 * This endpoint manages the per-tenant business role assignment by updating:
 * - user_tenant_access.tenant_role_id (legacy "primary" role) for a given (user_id, tenant_id)
 * - user_tenant_roles (multi-role memberships) when available
 *
 * Methods:
 * - GET  /api/users/tenant_role.php?user_id={id}&tenant_id={id}
 * - POST /api/users/tenant_role.php
 *   Preferred Body: {user_id, tenant_id, tenant_role_ids: int[]}
 *   Legacy Body:    {user_id, tenant_id, tenant_role_id|null|-1}
 *
 * Auth:
 * - Admin / Super Admin only
 * - Admin can operate only within tenants they can access (primary + user_tenant_access)
 *
 * CSRF: Required
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api_auth.php';
initializeApiEnvironment();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

verifyApiAuthentication();
verifyApiCsrfToken();
// Permission is checked per-tenant below (default: super_admin only).

require_once __DIR__ . '/../../includes/db.php';
$db = Database::getInstance();

function cnx_int($v): int {
    return (int)($v ?? 0);
}

function cnx_isSuperAdmin(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return (($_SESSION['role'] ?? '') === 'super_admin' || ($_SESSION['user_role'] ?? '') === 'super_admin');
}

function cnx_userHasTenantAccess(Database $db, int $userId, int $primaryTenantId, int $targetTenantId): bool {
    if ($primaryTenantId === $targetTenantId) {
        return true;
    }
    $row = $db->fetchOne(
        "SELECT 1
         FROM user_tenant_access
         WHERE user_id = ?
           AND tenant_id = ?
           AND deleted_at IS NULL
         LIMIT 1",
        [$userId, $targetTenantId]
    );
    return !empty($row);
}

function cnx_canAssignBusinessRoleForTenant(Database $db, int $targetTenantId, string $currentRole, bool $isSuperAdmin): bool {
    if ($isSuperAdmin) return true;
    // Default deny; allow only if tenant enables the current role.
    $tenant = $db->fetchOne(
        "SELECT tenant_role_assignment_roles
         FROM tenants
         WHERE id = ?
           AND deleted_at IS NULL
         LIMIT 1",
        [$targetTenantId]
    );
    if (!$tenant) return false;
    $raw = $tenant['tenant_role_assignment_roles'] ?? null;
    if (!is_string($raw) || $raw === '') return false;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return false;
    $allowed = array_map('strval', $decoded);
    return in_array($currentRole, $allowed, true);
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $userInfo = getApiUserInfo();
    $currentUserId = cnx_int($userInfo['user_id'] ?? 0);
    $currentRole = (string)($userInfo['role'] ?? 'user');
    $currentTenantId = cnx_int($userInfo['tenant_id'] ?? 0);
    $isSuperAdmin = ($currentRole === 'super_admin') || cnx_isSuperAdmin();

    if ($method === 'GET') {
        $targetUserId = cnx_int($_GET['user_id'] ?? 0);
        $targetTenantId = cnx_int($_GET['tenant_id'] ?? 0);

        if ($targetUserId <= 0 || $targetTenantId <= 0) {
            api_error('user_id e tenant_id richiesti', 400);
        }

        if (!$isSuperAdmin) {
            if (!cnx_userHasTenantAccess($db, $currentUserId, $currentTenantId, $targetTenantId)) {
                api_error('Accesso negato al tenant richiesto', 403);
            }
        }

        $tenant = $db->fetchOne(
            "SELECT id, has_custom_roles
             FROM tenants
             WHERE id = ?
               AND deleted_at IS NULL
             LIMIT 1",
            [$targetTenantId]
        );
        if (!$tenant) {
            api_error('Azienda non trovata', 404);
        }

        // Can assign?
        $canAssign = cnx_canAssignBusinessRoleForTenant($db, $targetTenantId, $currentRole, $isSuperAdmin);

        // Detect membership table (multi roles)
        $hasMembershipTable = false;
        try {
            $ok = $db->fetchOne(
                "SELECT 1
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'user_tenant_roles'
                 LIMIT 1"
            );
            $hasMembershipTable = !empty($ok);
        } catch (Exception $e) {
            $hasMembershipTable = false;
        }

        // Detect columns on user_tenant_access (schema drift safe)
        $utaHasDeletedAt = false;
        $utaHasTenantRoleId = false;
        try {
            $rows = $db->fetchAll(
                "SELECT COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'user_tenant_access'
                   AND COLUMN_NAME IN ('deleted_at', 'tenant_role_id')"
            );
            $names = array_map(static fn($r) => (string)$r['COLUMN_NAME'], $rows);
            $utaHasDeletedAt = in_array('deleted_at', $names, true);
            $utaHasTenantRoleId = in_array('tenant_role_id', $names, true);
        } catch (Exception $e) {
            // ignore
        }

        $tenantRoleIds = [];

        if ($hasMembershipTable) {
            $rows = $db->fetchAll(
                "SELECT tenant_role_id
                 FROM user_tenant_roles
                 WHERE user_id = ?
                   AND tenant_id = ?
                   AND deleted_at IS NULL",
                [$targetUserId, $targetTenantId]
            );
            foreach ($rows as $r) {
                $rid = (int)($r['tenant_role_id'] ?? 0);
                if ($rid > 0) $tenantRoleIds[] = $rid;
            }
            $tenantRoleIds = array_values(array_unique($tenantRoleIds));
        } elseif ($utaHasTenantRoleId) {
            $utaWhere = $utaHasDeletedAt ? " AND deleted_at IS NULL" : "";
            $uta = $db->fetchOne(
                "SELECT tenant_role_id
                 FROM user_tenant_access
                 WHERE user_id = ?
                   AND tenant_id = ?" . $utaWhere . "
                 LIMIT 1",
                [$targetUserId, $targetTenantId]
            );
            if ($uta && array_key_exists('tenant_role_id', $uta) && $uta['tenant_role_id'] !== null) {
                $rid = (int)$uta['tenant_role_id'];
                if ($rid > 0) $tenantRoleIds = [$rid];
            }
        }

        // Backward compatible single role field (primary role)
        $tenantRoleId = !empty($tenantRoleIds) ? (int)$tenantRoleIds[0] : null;

        // Load roles details (multi)
        $tenantRoles = [];
        if (!empty($tenantRoleIds)) {
            $ph = implode(',', array_fill(0, count($tenantRoleIds), '?'));
            $rows = $db->fetchAll(
                "SELECT id, name, code, color, icon
                 FROM tenant_roles
                 WHERE tenant_id = ?
                   AND deleted_at IS NULL
                   AND id IN ($ph)",
                array_merge([$targetTenantId], $tenantRoleIds)
            );
            foreach ($rows as $tr) {
                $tenantRoles[] = [
                    'id' => (int)$tr['id'],
                    'name' => (string)$tr['name'],
                    'code' => (string)($tr['code'] ?? ''),
                    'color' => (string)($tr['color'] ?? '#6366f1'),
                    'icon' => $tr['icon'] ?? null
                ];
            }
        }

        api_success([
            'user_id' => $targetUserId,
            'tenant_id' => $targetTenantId,
            'tenant_has_custom_roles' => (bool)$tenant['has_custom_roles'],
            'can_assign_custom_roles' => (bool)$canAssign,
            // Backward compatible
            'tenant_role_id' => $tenantRoleId,
            'tenant_role' => !empty($tenantRoles) ? $tenantRoles[0] : null,
            // Multi-role
            'tenant_role_ids' => $tenantRoleIds,
            'tenant_roles' => $tenantRoles
        ], 'Ruolo aziendale recuperato');
    }

    if ($method !== 'POST') {
        api_error('Metodo non consentito', 405);
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!$input || !is_array($input)) {
        $input = $_POST ?: [];
    }

    $targetUserId = cnx_int($input['user_id'] ?? 0);
    $targetTenantId = cnx_int($input['tenant_id'] ?? 0);
    $tenantRoleIdsRaw = $input['tenant_role_ids'] ?? null; // preferred (multi)
    $tenantRoleIdRaw = $input['tenant_role_id'] ?? null;   // legacy (single)

    if ($targetUserId <= 0 || $targetTenantId <= 0) {
        api_error('user_id e tenant_id richiesti', 400);
    }

    if (!$isSuperAdmin) {
        if (!cnx_userHasTenantAccess($db, $currentUserId, $currentTenantId, $targetTenantId)) {
            api_error('Accesso negato al tenant richiesto', 403);
        }
    }

    $tenant = $db->fetchOne(
        "SELECT id, has_custom_roles
         FROM tenants
         WHERE id = ?
           AND deleted_at IS NULL
         LIMIT 1",
        [$targetTenantId]
    );
    if (!$tenant) {
        api_error('Azienda non trovata', 404);
    }

    // Permission check (default deny except super_admin)
    if (!cnx_canAssignBusinessRoleForTenant($db, $targetTenantId, $currentRole, $isSuperAdmin)) {
        api_error('Permessi insufficienti per assegnare ruoli aziendali in questa azienda', 403);
    }

    // Normalize input to tenantRoleIds[] (multi) - accept legacy single too
    $tenantRoleIds = [];
    if (is_array($tenantRoleIdsRaw)) {
        foreach ($tenantRoleIdsRaw as $v) {
            $rid = (int)$v;
            if ($rid > 0) $tenantRoleIds[] = $rid;
        }
    } elseif (is_string($tenantRoleIdsRaw)) {
        // allow comma-separated string "1,2"
        $parts = array_filter(array_map('trim', explode(',', $tenantRoleIdsRaw)));
        foreach ($parts as $p) {
            $rid = (int)$p;
            if ($rid > 0) $tenantRoleIds[] = $rid;
        }
    } elseif ($tenantRoleIdRaw !== null) {
        $tmp = (int)$tenantRoleIdRaw;
        if ($tmp > 0) $tenantRoleIds = [$tmp];
    }
    $tenantRoleIds = array_values(array_unique($tenantRoleIds));

    $remove = empty($tenantRoleIds);

    if (!$remove) {
        // Validate roles belong to tenant and are active
        $ph = implode(',', array_fill(0, count($tenantRoleIds), '?'));
        $rows = $db->fetchAll(
            "SELECT id
             FROM tenant_roles
             WHERE tenant_id = ?
               AND is_active = 1
               AND deleted_at IS NULL
               AND id IN ($ph)",
            array_merge([$targetTenantId], $tenantRoleIds)
        );
        $validIds = array_map(static fn($r) => (int)$r['id'], $rows);
        sort($validIds);
        $expected = $tenantRoleIds;
        sort($expected);
        if ($validIds !== $expected) {
            api_error('Uno o più ruoli aziendali non sono validi o non appartengono a questa azienda', 400, [
                'requested' => $tenantRoleIds,
                'valid' => $validIds
            ]);
        }
    }

    // Detect optional columns on user_tenant_access
    $utaHasGrantedBy = false;
    $utaHasGrantedAt = false;
    $utaHasDeletedAt = false;
    $utaHasTenantRoleId = false;
    try {
        $rows = $db->fetchAll(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_tenant_access'
               AND COLUMN_NAME IN ('granted_by', 'granted_at', 'deleted_at', 'tenant_role_id')"
        );
        $names = array_map(static fn($r) => (string)$r['COLUMN_NAME'], $rows);
        $utaHasGrantedBy = in_array('granted_by', $names, true);
        $utaHasGrantedAt = in_array('granted_at', $names, true);
        $utaHasDeletedAt = in_array('deleted_at', $names, true);
        $utaHasTenantRoleId = in_array('tenant_role_id', $names, true);
    } catch (Exception $e) {
        // ignore
    }

    if (!$utaHasTenantRoleId) {
        api_error('Funzionalità Ruolo Aziendale non disponibile (schema user_tenant_access senza tenant_role_id)', 500);
    }

    $db->beginTransaction();
    try {
        // Detect membership table (multi roles)
        $hasMembershipTable = false;
        try {
            $ok = $db->fetchOne(
                "SELECT 1
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'user_tenant_roles'
                 LIMIT 1"
            );
            $hasMembershipTable = !empty($ok);
        } catch (Exception $e) {
            $hasMembershipTable = false;
        }

        // Ensure user exists
        $user = $db->fetchOne(
            "SELECT id, deleted_at
             FROM users
             WHERE id = ?
             LIMIT 1",
            [$targetUserId]
        );
        if (!$user || !empty($user['deleted_at'])) {
            $db->rollback();
            api_error('Utente non trovato o eliminato', 404);
        }

        $utaNotDeleted = $utaHasDeletedAt ? " AND deleted_at IS NULL" : "";
        $existingUta = $db->fetchOne(
            "SELECT id
             FROM user_tenant_access
             WHERE user_id = ?
               AND tenant_id = ?{$utaNotDeleted}
             LIMIT 1",
            [$targetUserId, $targetTenantId]
        );

        $now = date('Y-m-d H:i:s');
        // Store first selected role (or null) as legacy "primary" role
        $primaryRoleId = $remove ? null : (int)$tenantRoleIds[0];
        if ($existingUta && !empty($existingUta['id'])) {
            $update = ['tenant_role_id' => $primaryRoleId];
            if ($utaHasDeletedAt) {
                $update['deleted_at'] = null;
            }
            $db->update('user_tenant_access', $update, ['id' => (int)$existingUta['id']]);
        } else {
            $cols = ['user_id', 'tenant_id'];
            $vals = [$targetUserId, $targetTenantId];

            if ($utaHasGrantedAt) {
                $cols[] = 'granted_at';
                $vals[] = $now;
            }
            if ($utaHasGrantedBy) {
                $cols[] = 'granted_by';
                $vals[] = $currentUserId;
            }
            if ($utaHasTenantRoleId) {
                $cols[] = 'tenant_role_id';
                $vals[] = $primaryRoleId;
            }
            if ($utaHasDeletedAt) {
                $cols[] = 'deleted_at';
                $vals[] = null;
            }

            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO user_tenant_access (" . implode(',', $cols) . ") VALUES ($placeholders)";
            $db->query($sql, $vals);
        }

        // Update multi-role memberships (best-effort; if table missing we rely on primary only)
        if ($hasMembershipTable) {
            // Soft-delete existing memberships
            $db->query(
                "UPDATE user_tenant_roles
                 SET deleted_at = NOW(), updated_at = NOW()
                 WHERE user_id = ?
                   AND tenant_id = ?
                   AND deleted_at IS NULL",
                [$targetUserId, $targetTenantId]
            );

            if (!$remove) {
                foreach ($tenantRoleIds as $rid) {
                    $db->query(
                        "INSERT INTO user_tenant_roles (tenant_id, user_id, tenant_role_id, created_at, updated_at)
                         VALUES (?, ?, ?, NOW(), NOW())",
                        [$targetTenantId, $targetUserId, (int)$rid]
                    );
                }
            }
        }

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        throw $e;
    }

    api_success([
        'user_id' => $targetUserId,
        'tenant_id' => $targetTenantId,
        // Backward compatible single role
        'tenant_role_id' => $remove ? null : (int)$tenantRoleIds[0],
        // Multi-role
        'tenant_role_ids' => $remove ? [] : $tenantRoleIds
    ], $remove ? 'Ruoli aziendali rimossi' : 'Ruoli aziendali aggiornati');

} catch (Exception $e) {
    logApiError('users/tenant_role', $e);
    api_error('Errore durante l\'aggiornamento del ruolo aziendale', 500);
}

