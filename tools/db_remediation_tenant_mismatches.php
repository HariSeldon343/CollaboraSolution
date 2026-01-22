<?php
/**
 * Tool: DB remediation for tenant mismatches (SAFE)
 *
 * Strategy:
 * - Do NOT rewrite tenant_id of existing records.
 * - Populate user_tenant_access (idempotent) so users have access to tenants where they already created/own records.
 * - Fix objectively broken reference: events.calendar_id cross-tenant => set to NULL.
 * - Ensure page_visibility_settings has global rows for 'turni' (admin/manager/user), idempotent.
 *
 * Security:
 * - super_admin only
 * - CSRF required for APPLY
 */

declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../includes/session_init.php';
require_once __DIR__ . '/../includes/auth_simple.php';
require_once __DIR__ . '/../includes/db.php';

$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: /CollaboraNexio/index.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    header('Location: /CollaboraNexio/index.php');
    exit;
}

if (($currentUser['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$csrfToken = $auth->generateCSRFToken();

$db = Database::getInstance();
$conn = $db->getConnection();

function cnx_h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function cnx_hasColumn(PDO $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function cnx_tableExists(PDO $conn, string $table): bool {
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND TABLE_TYPE = 'BASE TABLE'
        LIMIT 1
    ");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function cnx_utaActiveWhere(string $alias, bool $hasDeletedAt): string {
    if ($hasDeletedAt) {
        return "{$alias}.deleted_at IS NULL";
    }
    return "1=1";
}

/**
 * Build a unique set of (user_id, tenant_id) that should be present in user_tenant_access.
 * Excludes super_admin users.
 *
 * @return array<int, array{user_id:int, tenant_id:int, sources:array<string,bool>}>
 */
function cnx_collectUtaPairs(PDO $conn): array {
    $pairs = [];

    $sources = [
        'tickets.created_by' => "
            SELECT DISTINCT t.created_by AS user_id, t.tenant_id AS tenant_id
            FROM tickets t
            INNER JOIN users u ON u.id = t.created_by AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = t.tenant_id AND tn.deleted_at IS NULL
            WHERE u.role <> 'super_admin'
              AND u.tenant_id <> t.tenant_id
        ",
        'tasks.created_by' => "
            SELECT DISTINCT x.created_by AS user_id, x.tenant_id AS tenant_id
            FROM tasks x
            INNER JOIN users u ON u.id = x.created_by AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = x.tenant_id AND tn.deleted_at IS NULL
            WHERE x.created_by IS NOT NULL
              AND u.role <> 'super_admin'
              AND u.tenant_id <> x.tenant_id
        ",
        'tasks.assigned_to' => "
            SELECT DISTINCT x.assigned_to AS user_id, x.tenant_id AS tenant_id
            FROM tasks x
            INNER JOIN users u ON u.id = x.assigned_to AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = x.tenant_id AND tn.deleted_at IS NULL
            WHERE x.assigned_to IS NOT NULL
              AND u.role <> 'super_admin'
              AND u.tenant_id <> x.tenant_id
        ",
        'events.organizer_id' => "
            SELECT DISTINCT e.organizer_id AS user_id, e.tenant_id AS tenant_id
            FROM events e
            INNER JOIN users u ON u.id = e.organizer_id AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = e.tenant_id AND tn.deleted_at IS NULL
            WHERE u.role <> 'super_admin'
              AND u.tenant_id <> e.tenant_id
        ",
        'calendars.owner_id' => "
            SELECT DISTINCT c.owner_id AS user_id, c.tenant_id AS tenant_id
            FROM calendars c
            INNER JOIN users u ON u.id = c.owner_id AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = c.tenant_id AND tn.deleted_at IS NULL
            WHERE u.role <> 'super_admin'
              AND u.tenant_id <> c.tenant_id
        ",
    ];

    foreach ($sources as $sourceName => $sql) {
        $stmt = $conn->query($sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $userId = (int)($row['user_id'] ?? 0);
            $tenantId = (int)($row['tenant_id'] ?? 0);
            if ($userId <= 0 || $tenantId <= 0) {
                continue;
            }
            $key = $userId . ':' . $tenantId;
            if (!isset($pairs[$key])) {
                $pairs[$key] = [
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                    'sources' => [],
                ];
            }
            $pairs[$key]['sources'][$sourceName] = true;
        }
    }

    return array_values($pairs);
}

/**
 * Upsert a user_tenant_access row for (user_id, tenant_id) in an idempotent way.
 */
function cnx_utaEnsureAccess(PDO $conn, array $utaCols, int $userId, int $tenantId, int $actorId): array {
    $hasDeletedAt = !empty($utaCols['deleted_at']);
    $hasGrantedBy = !empty($utaCols['granted_by']);
    $hasGrantedAt = !empty($utaCols['granted_at']);
    $hasTenantRoleId = !empty($utaCols['tenant_role_id']);

    // 1) Active exists?
    $activeWhere = cnx_utaActiveWhere('uta', $hasDeletedAt);
    $stmt = $conn->prepare("
        SELECT id
        FROM user_tenant_access uta
        WHERE uta.user_id = ?
          AND uta.tenant_id = ?
          AND {$activeWhere}
        LIMIT 1
    ");
    $stmt->execute([$userId, $tenantId]);
    $existingId = $stmt->fetchColumn();
    if ($existingId !== false) {
        return ['action' => 'noop', 'id' => (int)$existingId];
    }

    // 2) Reactivate soft-deleted row if possible
    if ($hasDeletedAt) {
        $stmt = $conn->prepare("
            SELECT id
            FROM user_tenant_access
            WHERE user_id = ?
              AND tenant_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId, $tenantId]);
        $anyId = $stmt->fetchColumn();
        if ($anyId !== false) {
            $updates = ["deleted_at = NULL"];
            $params = [];
            if ($hasGrantedBy) {
                $updates[] = "granted_by = ?";
                $params[] = $actorId;
            }
            if ($hasGrantedAt) {
                $updates[] = "granted_at = NOW()";
            }

            $params[] = (int)$anyId;
            $sql = "UPDATE user_tenant_access SET " . implode(', ', $updates) . " WHERE id = ?";
            $u = $conn->prepare($sql);
            $u->execute($params);
            return ['action' => 'reactivate', 'id' => (int)$anyId];
        }
    }

    // 3) Insert new row
    $cols = ['user_id', 'tenant_id'];
    $placeholders = ['?', '?'];
    $values = [$userId, $tenantId];

    if ($hasTenantRoleId) {
        $cols[] = 'tenant_role_id';
        $placeholders[] = 'NULL';
    }
    if ($hasGrantedBy) {
        $cols[] = 'granted_by';
        $placeholders[] = '?';
        $values[] = $actorId;
    }
    if ($hasGrantedAt) {
        $cols[] = 'granted_at';
        $placeholders[] = 'NOW()';
    }
    if ($hasDeletedAt) {
        $cols[] = 'deleted_at';
        $placeholders[] = 'NULL';
    }

    $sql = "INSERT INTO user_tenant_access (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $ins = $conn->prepare($sql);
    $ins->execute($values);
    $id = (int)$conn->lastInsertId();

    return ['action' => 'insert', 'id' => $id];
}

function cnx_ensureTurniPageVisibility(PDO $conn): array {
    if (!cnx_tableExists($conn, 'page_visibility_settings')) {
        return ['table_exists' => false, 'inserted' => 0];
    }

    $hasDeletedAt = cnx_hasColumn($conn, 'page_visibility_settings', 'deleted_at');
    $hasDescription = cnx_hasColumn($conn, 'page_visibility_settings', 'description');
    $hasIsVisible = cnx_hasColumn($conn, 'page_visibility_settings', 'is_visible');

    $roles = ['admin', 'manager', 'user'];
    $inserted = 0;

    foreach ($roles as $role) {
        $whereDeleted = $hasDeletedAt ? "AND deleted_at IS NULL" : "";
        $stmt = $conn->prepare("
            SELECT 1
            FROM page_visibility_settings
            WHERE tenant_id IS NULL
              AND page_name = 'turni'
              AND role = ?
              {$whereDeleted}
            LIMIT 1
        ");
        $stmt->execute([$role]);
        if ($stmt->fetchColumn() !== false) {
            continue;
        }

        // Insert minimal compatible row
        $cols = ['tenant_id', 'page_name', 'role'];
        $vals = ['NULL', "'turni'", '?'];
        $params = [$role];

        if ($hasIsVisible) {
            $cols[] = 'is_visible';
            $vals[] = 'TRUE';
        }
        if ($hasDescription) {
            $cols[] = 'description';
            $vals[] = '?';
            $params[] = 'Turni - Default visibility';
        }
        // created_at may exist; if not, insert still ok if column has default
        if (cnx_hasColumn($conn, 'page_visibility_settings', 'created_at')) {
            $cols[] = 'created_at';
            $vals[] = 'NOW()';
        }

        $sql = "INSERT INTO page_visibility_settings (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        $ins = $conn->prepare($sql);
        $ins->execute($params);
        $inserted++;
    }

    return ['table_exists' => true, 'inserted' => $inserted];
}

function cnx_countEventsCalendarCrossTenant(PDO $conn): int {
    if (!cnx_tableExists($conn, 'events') || !cnx_tableExists($conn, 'calendars')) {
        return 0;
    }
    $stmt = $conn->query("
        SELECT COUNT(*) AS c
        FROM events e
        INNER JOIN calendars c ON c.id = e.calendar_id
        WHERE e.calendar_id IS NOT NULL
          AND c.tenant_id <> e.tenant_id
    ");
    return (int)$stmt->fetchColumn();
}

function cnx_applyFixEventsCalendarCrossTenant(PDO $conn): int {
    if (!cnx_tableExists($conn, 'events') || !cnx_tableExists($conn, 'calendars')) {
        return 0;
    }
    $stmt = $conn->prepare("
        UPDATE events e
        INNER JOIN calendars c ON c.id = e.calendar_id
        SET e.calendar_id = NULL
        WHERE e.calendar_id IS NOT NULL
          AND c.tenant_id <> e.tenant_id
    ");
    $stmt->execute();
    return (int)$stmt->rowCount();
}

function cnx_countMissingUtaForPairs(PDO $conn, bool $utaHasDeletedAt): int {
    if (!cnx_tableExists($conn, 'user_tenant_access')) {
        return 0;
    }
    $activeWhere = cnx_utaActiveWhere('uta', $utaHasDeletedAt);

    // Count distinct required pairs that currently do NOT have an active user_tenant_access row.
    $sql = "
        SELECT COUNT(*) AS c
        FROM (
            SELECT DISTINCT t.created_by AS user_id, t.tenant_id AS tenant_id
            FROM tickets t
            INNER JOIN users u ON u.id = t.created_by AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = t.tenant_id AND tn.deleted_at IS NULL
            WHERE u.role <> 'super_admin'
              AND u.tenant_id <> t.tenant_id
            UNION
            SELECT DISTINCT x.created_by AS user_id, x.tenant_id AS tenant_id
            FROM tasks x
            INNER JOIN users u ON u.id = x.created_by AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = x.tenant_id AND tn.deleted_at IS NULL
            WHERE x.created_by IS NOT NULL
              AND u.role <> 'super_admin'
              AND u.tenant_id <> x.tenant_id
            UNION
            SELECT DISTINCT x.assigned_to AS user_id, x.tenant_id AS tenant_id
            FROM tasks x
            INNER JOIN users u ON u.id = x.assigned_to AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = x.tenant_id AND tn.deleted_at IS NULL
            WHERE x.assigned_to IS NOT NULL
              AND u.role <> 'super_admin'
              AND u.tenant_id <> x.tenant_id
            UNION
            SELECT DISTINCT e.organizer_id AS user_id, e.tenant_id AS tenant_id
            FROM events e
            INNER JOIN users u ON u.id = e.organizer_id AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = e.tenant_id AND tn.deleted_at IS NULL
            WHERE u.role <> 'super_admin'
              AND u.tenant_id <> e.tenant_id
            UNION
            SELECT DISTINCT c.owner_id AS user_id, c.tenant_id AS tenant_id
            FROM calendars c
            INNER JOIN users u ON u.id = c.owner_id AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = c.tenant_id AND tn.deleted_at IS NULL
            WHERE u.role <> 'super_admin'
              AND u.tenant_id <> c.tenant_id
        ) need
        WHERE NOT EXISTS (
            SELECT 1
            FROM user_tenant_access uta
            WHERE uta.user_id = need.user_id
              AND uta.tenant_id = need.tenant_id
              AND {$activeWhere}
            LIMIT 1
        )
    ";
    $stmt = $conn->query($sql);
    return (int)$stmt->fetchColumn();
}

$utaTableExists = cnx_tableExists($conn, 'user_tenant_access');
$utaCols = [
    'deleted_at' => $utaTableExists ? cnx_hasColumn($conn, 'user_tenant_access', 'deleted_at') : false,
    'granted_by' => $utaTableExists ? cnx_hasColumn($conn, 'user_tenant_access', 'granted_by') : false,
    'granted_at' => $utaTableExists ? cnx_hasColumn($conn, 'user_tenant_access', 'granted_at') : false,
    'tenant_role_id' => $utaTableExists ? cnx_hasColumn($conn, 'user_tenant_access', 'tenant_role_id') : false,
];

$pairs = $utaTableExists ? cnx_collectUtaPairs($conn) : [];
$missingPairsCount = $utaTableExists ? cnx_countMissingUtaForPairs($conn, (bool)$utaCols['deleted_at']) : 0;
$eventsCalendarCrossTenantCount = cnx_countEventsCalendarCrossTenant($conn);

$applyResult = null;
$error = null;

$isApply = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (($_POST['action'] ?? '') === 'apply');
if ($isApply) {
    // CSRF
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!$auth->verifyCSRFToken($postedToken)) {
        $error = 'CSRF token non valido.';
    } else {
        $confirm = trim((string)($_POST['confirm'] ?? ''));
        if ($confirm !== 'APPLY') {
            $error = 'Conferma mancante. Digita APPLY per procedere.';
        } else {
            try {
                $conn->beginTransaction();

                $turniInsert = cnx_ensureTurniPageVisibility($conn);
                $eventsFixed = cnx_applyFixEventsCalendarCrossTenant($conn);

                $actions = [
                    'insert' => 0,
                    'reactivate' => 0,
                    'noop' => 0,
                ];

                $details = [];
                foreach ($pairs as $p) {
                    $userId = (int)$p['user_id'];
                    $tenantId = (int)$p['tenant_id'];
                    $res = cnx_utaEnsureAccess($conn, $utaCols, $userId, $tenantId, (int)$currentUser['id']);
                    $actions[$res['action']] = ($actions[$res['action']] ?? 0) + 1;
                    $details[] = [
                        'user_id' => $userId,
                        'tenant_id' => $tenantId,
                        'action' => $res['action'],
                        'id' => $res['id'] ?? null,
                    ];
                }

                $conn->commit();

                // Recompute post-state counters
                $missingPairsAfter = cnx_countMissingUtaForPairs($conn, (bool)$utaCols['deleted_at']);
                $eventsCalendarAfter = cnx_countEventsCalendarCrossTenant($conn);

                $applyResult = [
                    'turni' => $turniInsert,
                    'events_calendar_cross_tenant_fixed_rows' => $eventsFixed,
                    'uta_actions' => $actions,
                    'missing_pairs_before' => $missingPairsCount,
                    'missing_pairs_after' => $missingPairsAfter,
                    'events_calendar_cross_tenant_before' => $eventsCalendarCrossTenantCount,
                    'events_calendar_cross_tenant_after' => $eventsCalendarAfter,
                    'details' => $details,
                ];

                // Refresh dry-run numbers shown after apply
                $missingPairsCount = $missingPairsAfter;
                $eventsCalendarCrossTenantCount = $eventsCalendarAfter;

            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $error = 'Errore durante la remediation: ' . $e->getMessage();
            }
        }
    }
}

// Optional: enrich pairs for UI
$pairUsers = [];
if (!empty($pairs)) {
    $userIds = array_values(array_unique(array_map(static fn($p) => (int)$p['user_id'], $pairs)));
    $tenantIds = array_values(array_unique(array_map(static fn($p) => (int)$p['tenant_id'], $pairs)));
    if (!empty($userIds)) {
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $conn->prepare("SELECT id, email, name, role, tenant_id FROM users WHERE id IN ($in)");
        $stmt->execute($userIds);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pairUsers[(int)$r['id']] = $r;
        }
    }
    $pairTenants = [];
    if (!empty($tenantIds)) {
        $in = implode(',', array_fill(0, count($tenantIds), '?'));
        $stmt = $conn->prepare("SELECT id, COALESCE(denominazione, name) AS name FROM tenants WHERE id IN ($in)");
        $stmt->execute($tenantIds);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pairTenants[(int)$r['id']] = $r;
        }
    }
} else {
    $pairTenants = [];
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DB Remediation Tenant Mismatches - Nexio</title>
    <?php require_once __DIR__ . '/../includes/favicon.php'; ?>
    <link rel="stylesheet" href="/CollaboraNexio/assets/css/styles.css">
    <link rel="stylesheet" href="/CollaboraNexio/assets/css/sidebar-responsive.css">
    <style>
        .wrap { padding: 24px; max-width: 1200px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid var(--color-gray-200); border-radius: 12px; box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 18px; }
        .card-h { padding: 14px 16px; border-bottom: 1px solid var(--color-gray-200); display:flex; justify-content:space-between; gap:12px; align-items:center; }
        .card-b { padding: 16px; }
        .muted { color: var(--color-gray-600); font-size: 13px; }
        .pill { display:inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; border: 1px solid var(--color-gray-200); background: var(--color-gray-50); }
        .pill.warn { background: #fff7ed; border-color: #fed7aa; color: #9a3412; }
        .pill.ok { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
        table { width:100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #eef0f3; text-align:left; font-size: 13px; color:#111827; }
        th { background:#fafafa; font-weight:700; }
        .danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 10px 12px; border-radius: 10px; }
        .success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 10px 12px; border-radius: 10px; }
        .actions { display:flex; gap: 10px; align-items:center; flex-wrap: wrap; }
        input[type="text"] { padding: 10px 12px; border: 1px solid var(--color-gray-300); border-radius: 10px; }
        .btn { padding: 10px 14px; border-radius: 10px; border: 1px solid var(--color-gray-300); cursor: pointer; background: #fff; font-weight: 700; }
        .btn.primary { background: var(--color-primary); border-color: var(--color-primary); color: #fff; }
        .btn.primary:disabled { opacity: .6; cursor: not-allowed; }
        .kv { display:grid; grid-template-columns: 260px 1fr; gap: 10px; font-size: 13px; }
        .kv div { padding: 6px 0; border-bottom: 1px dashed #eef0f3; }
        .kv strong { color:#111827; }
    </style>
</head>
<body>
<div class="main-layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="header">
            <h1 class="page-title">DB Remediation (Tenant mismatches)</h1>
            <div class="flex items-center gap-4">
                <span class="text-sm text-muted"><?php echo cnx_h((string)($currentUser['email'] ?? '')); ?></span>
            </div>
        </div>

        <div class="wrap">
            <?php if ($error): ?>
                <div class="danger" style="margin-bottom: 16px;"><?php echo cnx_h($error); ?></div>
            <?php endif; ?>

            <?php if ($applyResult): ?>
                <div class="success" style="margin-bottom: 16px;">
                    Remediation completata. Missing UTA pairs: <?php echo (int)$applyResult['missing_pairs_before']; ?> → <?php echo (int)$applyResult['missing_pairs_after']; ?>.
                    Events calendar cross-tenant: <?php echo (int)$applyResult['events_calendar_cross_tenant_before']; ?> → <?php echo (int)$applyResult['events_calendar_cross_tenant_after']; ?>.
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-h">
                    <div>
                        <strong>Stato (dry-run)</strong>
                        <div class="muted">Nessuna modifica viene fatta finché non premi APPLY.</div>
                    </div>
                    <div class="actions">
                        <span class="pill <?php echo ($missingPairsCount > 0 || $eventsCalendarCrossTenantCount > 0) ? 'warn' : 'ok'; ?>">
                            <?php echo ($missingPairsCount > 0 || $eventsCalendarCrossTenantCount > 0) ? 'AZIONI NECESSARIE' : 'OK'; ?>
                        </span>
                    </div>
                </div>
                <div class="card-b">
                    <div class="kv">
                        <div><strong>DB</strong></div><div><?php echo cnx_h((string)$conn->query('SELECT DATABASE()')->fetchColumn()); ?></div>
                        <div><strong>user_tenant_access</strong></div><div><?php echo $utaTableExists ? 'presente' : 'MANCANTE'; ?></div>
                        <div><strong>Pairs richiesti (distinct)</strong></div><div><?php echo (int)count($pairs); ?></div>
                        <div><strong>Pairs mancanti (no active UTA)</strong></div><div><?php echo (int)$missingPairsCount; ?></div>
                        <div><strong>events.calendar_id cross-tenant</strong></div><div><?php echo (int)$eventsCalendarCrossTenantCount; ?></div>
                        <div><strong>UTA columns</strong></div>
                        <div class="muted">
                            deleted_at: <?php echo $utaCols['deleted_at'] ? 'yes' : 'no'; ?>,
                            granted_by: <?php echo $utaCols['granted_by'] ? 'yes' : 'no'; ?>,
                            granted_at: <?php echo $utaCols['granted_at'] ? 'yes' : 'no'; ?>,
                            tenant_role_id: <?php echo $utaCols['tenant_role_id'] ? 'yes' : 'no'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-h">
                    <div>
                        <strong>Apply (transazionale)</strong>
                        <div class="muted">Digita <strong>APPLY</strong> e conferma. In caso di errore: rollback.</div>
                    </div>
                </div>
                <div class="card-b">
                    <form method="POST">
                        <input type="hidden" name="action" value="apply">
                        <input type="hidden" name="csrf_token" value="<?php echo cnx_h($csrfToken); ?>">
                        <div class="actions">
                            <input type="text" name="confirm" placeholder="Digita APPLY per confermare" autocomplete="off">
                            <button class="btn primary" type="submit">APPLY remediation</button>
                        </div>
                        <div class="muted" style="margin-top:10px;">
                            Operazioni:\n
                            - Inserisce/riattiva righe in <code>user_tenant_access</code> per i pairs mancanti.\n
                            - Imposta <code>events.calendar_id = NULL</code> per eventi con calendario cross-tenant.\n
                            - Inserisce (se mancanti) le 3 righe globali di Page Visibility per <code>turni</code>.
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-h">
                    <div>
                        <strong>Dettaglio pairs (preview)</strong>
                        <div class="muted">Prime 200 righe.</div>
                    </div>
                </div>
                <div class="card-b" style="padding:0;">
                    <table>
                        <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Primary tenant</th>
                            <th>Target tenant</th>
                            <th>Sources</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php
                        $shown = 0;
                        foreach ($pairs as $p):
                            $shown++;
                            if ($shown > 200) break;
                            $u = $pairUsers[$p['user_id']] ?? null;
                            $t = $pairTenants[$p['tenant_id']] ?? null;
                            $sources = array_keys(array_filter($p['sources'] ?? []));
                            ?>
                            <tr>
                                <td><?php echo cnx_h(($u['email'] ?? ('user_id=' . (string)$p['user_id']))); ?></td>
                                <td><?php echo cnx_h((string)($u['role'] ?? '')); ?></td>
                                <td><?php echo cnx_h((string)($u['tenant_id'] ?? '')); ?></td>
                                <td><?php echo cnx_h((string)($t['name'] ?? ('tenant_id=' . (string)$p['tenant_id']))); ?></td>
                                <td class="muted"><?php echo cnx_h(implode(', ', $sources)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($applyResult): ?>
                <div class="card">
                    <div class="card-h">
                        <strong>Report apply (JSON)</strong>
                        <div class="muted">include conteggi e dettagli per pair.</div>
                    </div>
                    <div class="card-b">
                        <pre style="white-space: pre-wrap; font-size: 12px; background: #0b1220; color: #e5e7eb; padding: 12px; border-radius: 12px; overflow:auto;"><?php
                            echo cnx_h(json_encode($applyResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                        ?></pre>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>
</body>
</html>

