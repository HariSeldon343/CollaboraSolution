<?php
/**
 * Verify Work Shifts (Turni) schema alignment.
 *
 * - Non-destructive read-only checks against information_schema.
 * - Shows missing tables/columns and highlights potential drift.
 *
 * Access:
 * - Web: super_admin only
 * - CLI: allowed
 */
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

if (!$isCli) {
    require_once __DIR__ . '/../includes/session_init.php';
    require_once __DIR__ . '/../includes/auth_simple.php';
    $auth = new Auth();
    if (!$auth->checkAuth()) {
        http_response_code(401);
        echo "Unauthorized";
        exit;
    }
    $u = $auth->getCurrentUser();
    if (($u['role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        echo "Forbidden: super_admin required";
        exit;
    }
}

$db = Database::getInstance();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function tableExists(Database $db, string $table): bool {
    $r = $db->fetchOne(
        "SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1",
        [$table]
    );
    return (bool)$r;
}

function getColumns(Database $db, string $table): array {
    $rows = $db->fetchAll(
        "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION",
        [$table]
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[(string)$r['COLUMN_NAME']] = $r;
    }
    return $out;
}

$required = [
    'shift_types' => [
        'tenant_id','name','code','description','start_time','end_time','duration_minutes',
        'color','icon','sort_order','is_active','deleted_at','created_at','updated_at','created_by'
    ],
    'work_shifts' => [
        'tenant_id','shift_type_id','user_id','shift_date','start_time_override','end_time_override',
        'status','notes','user_notes','actual_start_time','actual_end_time','deleted_at','created_at','updated_at','created_by','updated_by'
    ],
    'shift_change_requests' => [
        'tenant_id','work_shift_id','requester_id','request_type','status','created_at','updated_at','deleted_at'
    ],
];

$html = [];
$html[] = "<h2>Verify Turni schema</h2>";
$html[] = "<div>DB: <code>" . h((string)($db->fetchOne("SELECT DATABASE() AS d")['d'] ?? '')) . "</code></div>";
$html[] = "<div style=\"margin-top:10px;\">Expected base migration: <code>database/migrations/22_work_shifts_feature.sql</code></div>";

$problems = 0;

foreach ($required as $table => $cols) {
    $html[] = "<hr><h3>Table: <code>" . h($table) . "</code></h3>";
    if (!tableExists($db, $table)) {
        $problems++;
        $html[] = "<div style=\"color:#b91c1c;\"><strong>MISSING TABLE</strong></div>";
        continue;
    }
    $dbCols = getColumns($db, $table);
    $missingCols = [];
    foreach ($cols as $c) {
        if (!isset($dbCols[$c])) $missingCols[] = $c;
    }
    if (!empty($missingCols)) {
        $problems++;
        $html[] = "<div style=\"color:#b91c1c;\"><strong>Missing columns:</strong> " . h(implode(', ', $missingCols)) . "</div>";
    } else {
        $html[] = "<div style=\"color:#166534;\"><strong>OK:</strong> required columns present</div>";
    }

    // Show a compact column summary
    $html[] = "<details style=\"margin-top:8px;\"><summary>Show columns</summary><pre style=\"white-space:pre-wrap;\">";
    foreach ($dbCols as $name => $r) {
        $html[] = h(sprintf(
            "%-24s %-12s %-24s nullable=%s",
            $name,
            $r['DATA_TYPE'] ?? '',
            $r['COLUMN_TYPE'] ?? '',
            $r['IS_NULLABLE'] ?? ''
        ));
    }
    $html[] = "</pre></details>";
}

// Quick sanity checks for other dependencies
$html[] = "<hr><h3>Other dependencies</h3>";
$uta = tableExists($db, 'user_tenant_access');
$tenants = tableExists($db, 'tenants');
$users = tableExists($db, 'users');
$html[] = "<div>tenants: " . ($tenants ? 'OK' : '<span style="color:#b91c1c;">MISSING</span>') . "</div>";
$html[] = "<div>users: " . ($users ? 'OK' : '<span style="color:#b91c1c;">MISSING</span>') . "</div>";
$html[] = "<div>user_tenant_access (cross-tenant access): " . ($uta ? 'OK' : '<span style="color:#b91c1c;">MISSING</span>') . "</div>";

$html[] = "<hr><h3>Summary</h3>";
if ($problems === 0) {
    $html[] = "<div style=\"color:#166534;\"><strong>Schema looks aligned for Turni.</strong></div>";
} else {
    $html[] = "<div style=\"color:#b91c1c;\"><strong>Found {$problems} schema problem(s).</strong> Apply migration 22 and re-check.</div>";
    $html[] = "<div>Tool: <code>/CollaboraNexio/tools/apply_migration_22_work_shifts_feature.php</code></div>";
}

echo $isCli ? strip_tags(implode("\n", $html)) : implode("\n", $html);

