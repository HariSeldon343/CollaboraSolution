<?php
/**
 * Seed Consulting Activity Types (base catalog) for tenant 28 (S.CO)
 * - Idempotent by name
 * - Supports CLI + Web (super_admin)
 *
 * CLI:
 *   php tools/seed_consulting_activity_types_base.php --apply --user-id 19
 *
 * Web:
 *   /CollaboraNexio/tools/seed_consulting_activity_types_base.php?apply=1
 */

declare(strict_types=1);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_cli(): bool { return (PHP_SAPI === 'cli'); }

function cnx_arg(array $argv, string $name): ?string {
    $n = count($argv);
    for ($i = 0; $i < $n; $i++) {
        $a = (string)$argv[$i];
        if (strpos($a, $name . '=') === 0) return substr($a, strlen($name) + 1);
        if ($a === $name) {
            if (($i + 1) < $n) {
                $next = (string)$argv[$i + 1];
                if ($next !== '' && $next[0] !== '-') return $next;
            }
            return '1';
        }
    }
    return null;
}

if (is_cli()) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/CollaboraNexio/tools/seed_consulting_activity_types_base.php';
}

require_once __DIR__ . '/../includes/session_init.php';
require_once __DIR__ . '/../includes/db.php';

$db = Database::getInstance();

$apply = false;
$userIdForCli = null;

if (is_cli()) {
    global $argv;
    $apply = (cnx_arg($argv, '--apply') !== null);
    $userIdForCli = cnx_arg($argv, '--user-id');
} else {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    require_once __DIR__ . '/../includes/auth_simple.php';
    $auth = new Auth();
    if (!$auth->checkAuth()) {
        header('Location: /CollaboraNexio/index.php');
        exit;
    }
    $u = $auth->getCurrentUser();
    if (!$u || ($u['role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        echo '<h1>403</h1><p>Solo super_admin.</p>';
        exit;
    }
    $_SESSION['user_id'] = (int)($u['id'] ?? 0);
    $apply = (($_GET['apply'] ?? '') === '1');
}

if ($apply && is_cli()) {
    if (!$userIdForCli) {
        fwrite(STDERR, "ERROR: --user-id obbligatorio in --apply\n");
        exit(2);
    }
    $_SESSION['user_id'] = (int)$userIdForCli;
}

$vendorTenantId = 28;
$names = [
    '9001',
    '22000/5',
    'EMAS 14001',
    '50001',
    'SA 8000',
    '45001',
    'PdR 125',
    '37001',
    '14001',
    '9001/HACCP',
    'BRC/IFS',
    '10891',
    'ACCRED',
    '16634',
    'BRC/BROKERS',
    'DPO',
    '17020',
    'ODV',
    '13485',
    'PRIVACY',
    'GDP',
    'CE',
];

$report = [
    'apply' => $apply,
    'inserted' => 0,
    'skipped_existing' => 0,
    'missing_table' => false,
];

$hasTypes = $db->fetchOne("SHOW TABLES LIKE 'consulting_activity_types'");
if (!$hasTypes) {
    $report['missing_table'] = true;
    if (is_cli()) {
        fwrite(STDERR, "ERROR: tabella consulting_activity_types mancante (migrazione 35)\n");
        exit(2);
    }
    echo '<p>Tabella consulting_activity_types mancante (migrazione 35).</p>';
    exit;
}

foreach ($names as $nm) {
    $nm = trim((string)$nm);
    if ($nm === '') continue;
    $e = $db->fetchOne(
        "SELECT id FROM consulting_activity_types WHERE tenant_id = ? AND deleted_at IS NULL AND name = ? LIMIT 1",
        [$vendorTenantId, $nm]
    );
    if ($e) {
        $report['skipped_existing']++;
        continue;
    }
    if (!$apply) continue;

    $id = $db->insert('consulting_activity_types', [
        'tenant_id' => $vendorTenantId,
        'name' => $nm,
        'weight_factor' => 1.0,
        'call_every_days_default' => 7,
        'call_duration_minutes_default' => 30,
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    if ($id) $report['inserted']++;
}

if (is_cli()) {
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo '<h2>Seed catalogo attività (tenant 28)</h2>';
echo '<p><strong>Modalità:</strong> ' . ($apply ? 'APPLY' : 'DRY-RUN') . '</p>';
echo '<pre style="white-space:pre-wrap;">' . h(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';

