<?php
/**
 * Workflow Roles - Assign a role (validator/approver) to a user for a tenant
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/workflow_email_notifier.php';

initializeApiEnvironment();
verifyApiAuthentication();
verifyApiCsrfToken();

try {
    $db = Database::getInstance();
    $userInfo = getApiUserInfo();
    $currentUserId = (int)($userInfo['id'] ?? $userInfo['user_id'] ?? 0);
    $currentRole = (string)($userInfo['role'] ?? 'user');

    // Only manager/admin/super_admin can modify roles
    if (!in_array($currentRole, ['manager', 'admin', 'super_admin'])) {
        api_error('Non autorizzato', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    // Accept both 'role' and 'workflow_role' for backward compatibility
    $role = $input['workflow_role'] ?? $input['role'] ?? '';
    $targetUserId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $bulkUserIds = [];
    if (isset($input['user_ids']) && is_array($input['user_ids'])) {
        $bulkUserIds = array_values(array_filter(array_map('intval', $input['user_ids']), fn($v) => $v > 0));
    }
    $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : 0;

    if (!in_array($role, ['validator','approver'], true)) {
        api_error('Ruolo non valido. Usa "validator" o "approver"', 400);
    }
    // At least one target (single or bulk)
    if ($targetUserId <= 0 && empty($bulkUserIds)) {
        api_error('user_id o user_ids richiesti e devono essere positivi', 400);
    }

    // BUG-144b FIX: Determine tenant (removed active_tenant_id - column doesn't exist)
    if ($tenantId <= 0) {
        $tenantId = (int)($_SESSION['company_filter_id'] ?? 0);
    }
    if ($tenantId <= 0) {
        $tenantId = (int)($userInfo['tenant_id'] ?? 0);
    }
    // Fallback: resolve tenant from users table if API userInfo lacks tenant_id
    if ($tenantId <= 0 && $currentUserId > 0) {
        $u = $db->fetchOne("SELECT tenant_id FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1", [$currentUserId]);
        if ($u && isset($u['tenant_id'])) {
            $tenantId = (int)$u['tenant_id'];
        }
    }
    if ($tenantId <= 0) {
        api_error('tenant_id richiesto', 400);
    }

    // BUG-144b FIX: Authorization check (removed active_tenant_id and user_companies)
    if ($currentRole !== 'super_admin') {
        $sessionTenantId = (int)($userInfo['tenant_id'] ?? 0);
        if ($sessionTenantId <= 0 && $currentUserId > 0) {
            $u = $db->fetchOne("SELECT tenant_id FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1", [$currentUserId]);
            if ($u && isset($u['tenant_id'])) {
                $sessionTenantId = (int)$u['tenant_id'];
            }
        }
        if ($tenantId !== $sessionTenantId || $sessionTenantId <= 0) {
            $hasAccess = false;

            // Check user_tenant_access for multi-tenant membership
            $uta = $db->fetchOne(
                "SELECT 1 as ok
                 FROM user_tenant_access
                 WHERE user_id = ?
                   AND tenant_id = ?
                   AND deleted_at IS NULL
                 LIMIT 1",
                [$currentUserId, $tenantId]
            );
            if ($uta) $hasAccess = true;

            if (!$hasAccess) {
                api_error('Accesso negato al tenant', 403);
            }
        }
    }

    // BUG-144b FIX: Resolve target users (removed user_companies and active_tenant_id)
    $resolveTargets = function(array $ids) use ($db, $tenantId) {
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // Placeholder order: 1) uta.tenant_id, 2..n) IN (ids...), last) u.tenant_id
        $params = array_merge([$tenantId], $ids, [$tenantId]);
        return $db->fetchAll(
            "SELECT u.id, u.role
             FROM users u
             LEFT JOIN user_tenant_access uta ON u.id = uta.user_id
               AND uta.tenant_id = ?
               AND uta.deleted_at IS NULL
             WHERE u.id IN ($placeholders)
               AND u.deleted_at IS NULL
               AND (u.role = 'super_admin' OR u.tenant_id = ? OR uta.user_id IS NOT NULL)",
            $params
        );
    };

    $targets = [];
    if ($targetUserId > 0) {
        $targets = $resolveTargets([$targetUserId]);
    } else {
        $targets = $resolveTargets($bulkUserIds);
    }

    $requestedIds = $targetUserId > 0 ? [$targetUserId] : $bulkUserIds;
    $resolvedIds = array_map(fn($r) => (int)($r['id'] ?? 0), $targets);
    $missingIds = array_values(array_diff(array_map('intval', $requestedIds), $resolvedIds));

    if (empty($targets) || !empty($missingIds)) {
        error_log('[API Workflow Roles Create] Missing/invalid target users for tenant: ' . json_encode([
            'tenant_id' => $tenantId,
            'current_user_id' => $currentUserId,
            'current_role' => $currentRole,
            'workflow_role' => $role,
            'requested_ids' => $requestedIds,
            'resolved_ids' => $resolvedIds,
            'missing_ids' => $missingIds
        ]));
        api_error('Utente non trovato o non appartiene a questo tenant', 404);
    }

    // NOTE: access already validated above for manager/admin

    // Check if workflow_roles table exists (should exist from migration)
    $tableCheck = $db->fetchOne(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = 'workflow_roles'"
    );

    if (!$tableCheck) {
        api_error('Tabella workflow_roles non trovata. Eseguire la migrazione.', 500);
    }

    // Upsert role using workflow_role column name (single or bulk)
    $now = date('Y-m-d H:i:s');
    $newlyAssigned = [];

    try {
        $db->beginTransaction();

        $targetIds = ($targetUserId > 0) ? [$targetUserId] : $bulkUserIds;

        // Soft-delete roles NOT in list when bulk provided (to allow removal)
        // BUG-145a FIX: Changed $db->execute() to $db->query() - execute() method doesn't exist
        if (!empty($bulkUserIds)) {
            $placeholders = implode(',', array_fill(0, count($bulkUserIds), '?'));
            $params = array_merge([$tenantId, $role], $bulkUserIds);
            $db->query(
                "UPDATE workflow_roles
                 SET deleted_at = NOW(), updated_at = NOW()
                 WHERE tenant_id = ?
                   AND workflow_role = ?
                   AND deleted_at IS NULL
                   AND user_id NOT IN ($placeholders)",
                $params
            );
        }

        foreach ($targetIds as $uid) {
            $existingRole = $db->fetchOne(
                "SELECT id, deleted_at FROM workflow_roles
                 WHERE tenant_id = ?
                   AND user_id = ?
                   AND workflow_role = ?
                 ORDER BY id DESC
                 LIMIT 1",
                [$tenantId, $uid, $role]
            );

            if ($existingRole && empty($existingRole['deleted_at'])) {
                // Update timestamp and make sure role is active
                $db->update('workflow_roles', [
                    'updated_at' => $now,
                    'is_active' => 1
                ], [
                    'id' => $existingRole['id']
                ]);
            } elseif ($existingRole && !empty($existingRole['deleted_at'])) {
                // Reactivate soft-deleted (also ensure is_active=1)
                $db->update('workflow_roles', [
                    'deleted_at' => null,
                    'updated_at' => $now,
                    'is_active' => 1
                ], [
                    'id' => $existingRole['id']
                ]);
                $newlyAssigned[] = $uid;
            } else {
                // Insert new role (explicit is_active=1 to avoid null defaults)
                $db->insert('workflow_roles', [
                    'tenant_id' => $tenantId,
                    'user_id' => $uid,
                    'workflow_role' => $role,
                    'assigned_by_user_id' => $currentUserId,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
                $newlyAssigned[] = $uid;
            }
        }

        // Enforce at least one role for the role currently being updated
        $validatorCount = $db->fetchOne(
            "SELECT COUNT(*) as c FROM workflow_roles WHERE tenant_id = ? AND workflow_role = 'validator' AND deleted_at IS NULL",
            [$tenantId]
        );
        $approverCount = $db->fetchOne(
            "SELECT COUNT(*) as c FROM workflow_roles WHERE tenant_id = ? AND workflow_role = 'approver' AND deleted_at IS NULL",
            [$tenantId]
        );

        if ($role === 'validator' && (int)$validatorCount['c'] <= 0) {
            $db->rollBack();
            api_error('Deve esserci almeno un validatore per tenant', 400);
        }

        if ($role === 'approver' && (int)$approverCount['c'] <= 0) {
            $db->rollBack();
            api_error('Deve esserci almeno un approvatore per tenant', 400);
        }

        $db->commit();

        // Best-effort email notification (only for newly assigned)
        foreach ($newlyAssigned as $uid) {
            try {
                WorkflowEmailNotifier::notifyWorkflowRoleAssigned($uid, $role, $tenantId, $currentUserId);
            } catch (Throwable $e) {
                error_log('[API Workflow Roles Create] Email notify failed: ' . $e->getMessage());
            }
        }

        api_success([
            'role' => $role,
            'assigned' => $targetIds,
            'validator_count' => (int)$validatorCount['c'],
            'approver_count' => (int)$approverCount['c']
        ], 'Ruolo salvato con successo');

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

} catch (Throwable $e) {
    error_log('[API Workflow Roles Create] Error: ' . $e->getMessage());
    error_log('[API Workflow Roles Create] User ID: ' . ($currentUserId ?? 'unknown'));
    error_log('[API Workflow Roles Create] Tenant ID: ' . ($tenantId ?? 'unknown'));
    api_error('Errore nel salvataggio del ruolo', 500);
}