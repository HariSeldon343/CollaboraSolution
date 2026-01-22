<?php
/**
 * Tool: DB integrity quick check (read-only)
 *
 * Purpose:
 * - Run a small subset of deterministic integrity checks (COUNT(*)) to spot common issues:
 *   - Orphan tenant references (users/tickets/tasks/events/files)
 *   - Tenant mismatches between records and owning users/calendars
 *   - Presence of users.home_city (migration 54)
 *
 * Notes:
 * - Uses the application DB connection (config.php + includes/db.php)
 * - Safe to run multiple times, no writes
 * - Web: super_admin only
 * - CLI: allowed
 */
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=utf-8');

$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/db.php';

    if (!$isCli) {
        require_once __DIR__ . '/../includes/session_init.php';
        require_once __DIR__ . '/../includes/auth_simple.php';
        $auth = new Auth();
        if (!$auth->checkAuth()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        $u = $auth->getCurrentUser();
        $role = (string)($u['role'] ?? '');
        if ($role !== 'super_admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden: super_admin required']);
            exit;
        }
    }

    $db = Database::getInstance();

    $tableExists = static function (string $table) use ($db): bool {
        try {
            return (bool)$db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND TABLE_TYPE = 'BASE TABLE'
                 LIMIT 1",
                [$table]
            );
        } catch (Throwable $e) {
            return false;
        }
    };

    $colExists = static function (string $table, string $col) use ($db): bool {
        try {
            return (bool)$db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                 LIMIT 1",
                [$table, $col]
            );
        } catch (Throwable $e) {
            return false;
        }
    };

    $dbName = '';
    $hostName = '';
    try { $dbName = (string)($db->fetchOne("SELECT DATABASE() AS db")['db'] ?? ''); } catch (Throwable $e) {}
    try { $hostName = (string)($db->fetchOne("SELECT @@hostname AS h")['h'] ?? ''); } catch (Throwable $e) {}

    $checks = [];
    $runCount = static function (string $name, string $sql) use ($db, &$checks): void {
        try {
            $row = $db->fetchOne($sql);
            $val = 0;
            if (is_array($row)) {
                if (array_key_exists('violations', $row)) $val = (int)$row['violations'];
                elseif (array_key_exists('count', $row)) $val = (int)$row['count'];
                else {
                    $first = array_values($row);
                    $val = isset($first[0]) ? (int)$first[0] : 0;
                }
            }
            $checks[$name] = $val;
        } catch (Throwable $e) {
            $checks[$name] = ['error' => $e->getMessage()];
        }
    };

    // users.home_city presence (migration 54)
    $checks['users.home_city_column'] = $colExists('users', 'home_city');
    // users professional profile columns (migration 58)
    $checks['users.job_title_column'] = $colExists('users', 'job_title');
    $checks['users.skills_text_column'] = $colExists('users', 'skills_text');
    $checks['users.certifications_text_column'] = $colExists('users', 'certifications_text');
    // tenants management permissions for tenant roles (migration 59)
    $checks['tenants.tenant_role_management_roles_column'] = $colExists('tenants', 'tenant_role_management_roles');

    // Orphan checks (only if tables exist)
    if ($tableExists('users') && $tableExists('tenants')) {
        $runCount('orphan_users_tenant', "SELECT COUNT(*) AS violations FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id WHERE t.id IS NULL");
    }
    if ($tableExists('tickets') && $tableExists('tenants')) {
        $runCount('orphan_tickets_tenant', "SELECT COUNT(*) AS violations FROM tickets x LEFT JOIN tenants t ON t.id = x.tenant_id WHERE t.id IS NULL");
    }
    if ($tableExists('tasks') && $tableExists('tenants')) {
        $runCount('orphan_tasks_tenant', "SELECT COUNT(*) AS violations FROM tasks x LEFT JOIN tenants t ON t.id = x.tenant_id WHERE t.id IS NULL");
    }
    if ($tableExists('events') && $tableExists('tenants')) {
        $runCount('orphan_events_tenant', "SELECT COUNT(*) AS violations FROM events x LEFT JOIN tenants t ON t.id = x.tenant_id WHERE t.id IS NULL");
    }
    if ($tableExists('files') && $tableExists('tenants')) {
        $runCount('orphan_files_tenant', "SELECT COUNT(*) AS violations FROM files x LEFT JOIN tenants t ON x.tenant_id = t.id WHERE x.tenant_id IS NOT NULL AND t.id IS NULL");
    }

    // Tenant consistency checks (only if relevant tables exist)
    if ($tableExists('tickets') && $tableExists('users')) {
        $runCount('tickets_created_by_tenant_mismatch', "SELECT COUNT(*) AS violations FROM tickets t JOIN users u ON u.id = t.created_by WHERE u.tenant_id <> t.tenant_id");
    }
    if ($tableExists('tasks') && $tableExists('users')) {
        $runCount('tasks_created_by_tenant_mismatch', "SELECT COUNT(*) AS violations FROM tasks x JOIN users u ON u.id = x.created_by WHERE x.created_by IS NOT NULL AND u.tenant_id <> x.tenant_id");
        $runCount('tasks_assigned_to_tenant_mismatch', "SELECT COUNT(*) AS violations FROM tasks x JOIN users u ON u.id = x.assigned_to WHERE x.assigned_to IS NOT NULL AND u.tenant_id <> x.tenant_id");
    }
    if ($tableExists('events') && $tableExists('users')) {
        $runCount('events_organizer_tenant_mismatch', "SELECT COUNT(*) AS violations FROM events e JOIN users u ON u.id = e.organizer_id WHERE u.tenant_id <> e.tenant_id");
    }
    if ($tableExists('events') && $tableExists('calendars')) {
        $runCount('events_calendar_tenant_mismatch', "SELECT COUNT(*) AS violations FROM events e JOIN calendars c ON c.id = e.calendar_id WHERE e.calendar_id IS NOT NULL AND c.tenant_id <> e.tenant_id");
    }
    if ($tableExists('calendars') && $tableExists('users')) {
        $runCount('calendars_owner_tenant_mismatch', "SELECT COUNT(*) AS violations FROM calendars c JOIN users u ON u.id = c.owner_id WHERE u.tenant_id <> c.tenant_id");
    }

    // In Nexio, cross-tenant records CAN be legitimate (e.g., S.CO consultants working on client tenants),
    // as long as user_tenant_access grants the access. Compute how many cross-tenant pairs are missing access.
    if ($tableExists('user_tenant_access') && $tableExists('users')) {
        $utaHasDeletedAt = $colExists('user_tenant_access', 'deleted_at');
        $utaActiveWhere = $utaHasDeletedAt ? " AND uta.deleted_at IS NULL" : "";

        // Align with remediation logic: consider only non-deleted users/tenants (when columns exist)
        $usersActiveWhere = $colExists('users', 'deleted_at') ? " AND u.deleted_at IS NULL" : "";
        $tenantsActiveWhere = ($tableExists('tenants') && $colExists('tenants', 'deleted_at')) ? " AND tn.deleted_at IS NULL" : "";

        $parts = [];
        if ($tableExists('tickets') && $tableExists('tenants')) {
            $parts[] = "
                SELECT t.created_by AS user_id, t.tenant_id AS tenant_id
                FROM tickets t
                JOIN users u ON u.id = t.created_by{$usersActiveWhere}
                JOIN tenants tn ON tn.id = t.tenant_id{$tenantsActiveWhere}
                WHERE t.created_by IS NOT NULL
                  AND u.role <> 'super_admin'
                  AND u.tenant_id <> t.tenant_id
            ";
        }
        if ($tableExists('tasks') && $tableExists('tenants')) {
            $parts[] = "
                SELECT x.created_by AS user_id, x.tenant_id AS tenant_id
                FROM tasks x
                JOIN users u ON u.id = x.created_by{$usersActiveWhere}
                JOIN tenants tn ON tn.id = x.tenant_id{$tenantsActiveWhere}
                WHERE x.created_by IS NOT NULL
                  AND u.role <> 'super_admin'
                  AND u.tenant_id <> x.tenant_id
            ";
            $parts[] = "
                SELECT x.assigned_to AS user_id, x.tenant_id AS tenant_id
                FROM tasks x
                JOIN users u ON u.id = x.assigned_to{$usersActiveWhere}
                JOIN tenants tn ON tn.id = x.tenant_id{$tenantsActiveWhere}
                WHERE x.assigned_to IS NOT NULL
                  AND u.role <> 'super_admin'
                  AND u.tenant_id <> x.tenant_id
            ";
        }
        if ($tableExists('events') && $tableExists('tenants')) {
            $parts[] = "
                SELECT e.organizer_id AS user_id, e.tenant_id AS tenant_id
                FROM events e
                JOIN users u ON u.id = e.organizer_id{$usersActiveWhere}
                JOIN tenants tn ON tn.id = e.tenant_id{$tenantsActiveWhere}
                WHERE e.organizer_id IS NOT NULL
                  AND u.role <> 'super_admin'
                  AND u.tenant_id <> e.tenant_id
            ";
        }
        if ($tableExists('calendars') && $tableExists('tenants')) {
            $parts[] = "
                SELECT c.owner_id AS user_id, c.tenant_id AS tenant_id
                FROM calendars c
                JOIN users u ON u.id = c.owner_id{$usersActiveWhere}
                JOIN tenants tn ON tn.id = c.tenant_id{$tenantsActiveWhere}
                WHERE c.owner_id IS NOT NULL
                  AND u.role <> 'super_admin'
                  AND u.tenant_id <> c.tenant_id
            ";
        }

        $unionPairsSql = '';
        if (!empty($parts)) {
            $unionPairsSql = "
                SELECT DISTINCT user_id, tenant_id
                FROM (
                    " . implode("\nUNION\n", $parts) . "
                ) z
                WHERE user_id IS NOT NULL AND tenant_id IS NOT NULL
            ";
        }

        if ($unionPairsSql !== '') {
            $runCount('cross_tenant_pairs_total', "SELECT COUNT(*) AS count FROM ({$unionPairsSql}) p");
            $runCount(
                'cross_tenant_pairs_missing_user_tenant_access',
                "SELECT COUNT(*) AS violations
                 FROM ({$unionPairsSql}) p
                 LEFT JOIN user_tenant_access uta
                   ON uta.user_id = p.user_id
                  AND uta.tenant_id = p.tenant_id{$utaActiveWhere}
                 WHERE uta.user_id IS NULL"
            );
        }
    }

    /* =========================================================
     * Consulting planning consistency checks (best-effort)
     * ========================================================= */
    if ($tableExists('consulting_plan_items')) {
        if ($colExists('consulting_plan_items', 'assignee_user_id') && $tableExists('users')) {
            $runCount(
                'consulting.orphan_plan_item_assignee_user',
                "SELECT COUNT(*) AS violations
                 FROM consulting_plan_items i
                 LEFT JOIN users u ON u.id = i.assignee_user_id
                 WHERE i.assignee_user_id IS NOT NULL
                   AND i.assignee_user_id <> 0
                   AND u.id IS NULL"
            );
        }
        if ($tableExists('consulting_plan_consultants') && $colExists('consulting_plan_items', 'assignee_user_id')) {
            $runCount(
                'consulting.assignee_not_in_selected_consultants',
                "SELECT COUNT(*) AS violations
                 FROM consulting_plan_items i
                 LEFT JOIN consulting_plan_consultants c
                   ON c.plan_id = i.plan_id AND c.user_id = i.assignee_user_id
                 WHERE i.assignee_user_id IS NOT NULL
                   AND i.assignee_user_id <> 0
                   AND c.user_id IS NULL"
            );
            $runCount(
                'consulting.duplicate_plan_consultants_pairs',
                "SELECT COUNT(*) AS violations
                 FROM (
                   SELECT plan_id, user_id, COUNT(*) AS cc
                   FROM consulting_plan_consultants
                   GROUP BY plan_id, user_id
                   HAVING cc > 1
                 ) x"
            );
        }
    }
    if ($tableExists('consulting_plan_task_links')) {
        if ($tableExists('tasks')) {
            $runCount(
                'consulting.orphan_plan_task_links_tasks',
                "SELECT COUNT(*) AS violations
                 FROM consulting_plan_task_links l
                 LEFT JOIN tasks t ON t.id = l.task_id
                 WHERE l.task_id IS NOT NULL
                   AND t.id IS NULL"
            );
        }
        if ($tableExists('consulting_plan_items')) {
            $runCount(
                'consulting.orphan_plan_task_links_items',
                "SELECT COUNT(*) AS violations
                 FROM consulting_plan_task_links l
                 LEFT JOIN consulting_plan_items i ON i.id = l.plan_item_id
                 WHERE l.plan_item_id IS NOT NULL
                   AND i.id IS NULL"
            );
        }
    }

    $ok = true;
    foreach ($checks as $k => $v) {
        if (is_array($v)) { $ok = false; break; }
        if ($k === 'users.home_city_column') { if (!$v) { $ok = false; break; } continue; }
        if ($k === 'tickets_created_by_tenant_mismatch'
            || $k === 'tasks_created_by_tenant_mismatch'
            || $k === 'tasks_assigned_to_tenant_mismatch'
            || $k === 'events_organizer_tenant_mismatch'
            || $k === 'calendars_owner_tenant_mismatch'
            || $k === 'cross_tenant_pairs_total') {
            // These can be legitimate in Nexio (consultants cross-tenant). Not considered fatal by itself.
            continue;
        }
        if (is_int($v) && $v !== 0) { $ok = false; break; }
    }
    // If we can compute missing access pairs, make that a hard failure (it causes RBAC issues).
    if (isset($checks['cross_tenant_pairs_missing_user_tenant_access']) && is_int($checks['cross_tenant_pairs_missing_user_tenant_access'])) {
        if ($checks['cross_tenant_pairs_missing_user_tenant_access'] !== 0) {
            $ok = false;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'db_name' => $dbName,
            'db_host' => $hostName,
            'ok' => $ok,
            'checks' => $checks,
            'interpretation' => [
                'tenant_mismatch_counts' => 'In Nexio possono essere legittimi (es. S.CO che lavora su tenant cliente). Il vero problema è se manca user_tenant_access: vedi cross_tenant_pairs_missing_user_tenant_access.',
            ],
            'note' => 'Questo è un quick-check. Per audit completo vedi database/diagnostics/db_integrity_checks.sql (richiede client SQL).',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

