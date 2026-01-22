<?php
/**
 * Seed extra tenants requested (idempotent by Partita IVA) + ensure tenant root folder.
 *
 * NOTE: MEDTECH missing CAP in provided address; this tool will SKIP it unless CAP is provided.
 *
 * CLI:
 *   php tools/seed_extra_tenants_2026_01_03.php --apply --user-id 19
 *
 * Web (super_admin):
 *   /CollaboraNexio/tools/seed_extra_tenants_2026_01_03.php?apply=1
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

function cnx_table_exists(Database $db, string $table): bool {
    try {
        $row = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1",
            [$table]
        );
        return ((int)($row['ok'] ?? 0) === 1);
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_get_table_columns(Database $db, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $cols = [];
    try {
        $rows = $db->fetchAll(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?",
            [$table]
        );
        foreach ($rows as $r) {
            if (!empty($r['COLUMN_NAME'])) $cols[] = (string)$r['COLUMN_NAME'];
        }
    } catch (Throwable $e) {
        $cols = [];
    }
    $cache[$table] = $cols;
    return $cols;
}

function cnx_filter_table_data(Database $db, string $table, array $data): array {
    if (empty($data)) return [];
    $cols = cnx_get_table_columns($db, $table);
    if (empty($cols)) return [];
    $allowed = array_flip($cols);
    return array_intersect_key($data, $allowed);
}

/**
 * Parse "Via Liguria 45, 90144 Palermo (PA)" style.
 */
function parse_addr(string $s): array {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/', ' ', $s);
    // remove trailing dot
    $s = rtrim($s, '.');
    // Regex: street+number, CAP, Comune, (PR)
    if (preg_match('/^(.*?)\s+([0-9]+[A-Za-z0-9\/-]*)\s*,?\s*(\d{5})\s+(.+?)\s*\(([A-Z]{2})\)$/u', $s, $m)) {
        return [
            'ok' => true,
            'data' => [
                'indirizzo' => trim($m[1]),
                'civico' => trim($m[2]),
                'cap' => trim($m[3]),
                'comune' => trim($m[4]),
                'provincia' => strtoupper(trim($m[5])),
            ]
        ];
    }
    // Regex: street+number, Comune (PR) without CAP -> fail
    if (preg_match('/^(.*?)\s+([0-9]+[A-Za-z0-9\/-]*)\s*,?\s*(.+?)\s*\(([A-Z]{2})\)$/u', $s, $m)) {
        return ['ok' => false, 'error' => 'CAP mancante nella sede legale (serve 5 cifre)'];
    }
    return ['ok' => false, 'error' => 'Formato sede legale non riconosciuto'];
}

// Bootstrap
if (is_cli()) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/CollaboraNexio/tools/seed_extra_tenants_2026_01_03.php';
}
require_once __DIR__ . '/../includes/session_init.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../api/tenants/tenant_validators.php';
require_once __DIR__ . '/../includes/tenant_folder_helper.php';

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
    $apply = (($_GET['apply'] ?? '') === '1');
    $_SESSION['user_id'] = (int)($u['id'] ?? 0);
}

if (is_cli() && $apply) {
    if (!$userIdForCli) {
        fwrite(STDERR, "ERROR: --user-id obbligatorio in --apply (serve per creare cartelle tenant)\n");
        exit(2);
    }
    $_SESSION['user_id'] = (int)$userIdForCli;
}

$locationsStorageAvailable = cnx_table_exists($db, 'tenant_locations');

$tenants = [
    [
        'denominazione' => 'MEDTECH S.R.L.',
        'piva' => '05198180878',
        'sector' => 'sanita',
        'pec' => null,
        'sede' => 'Viale Alcide De Gasperi 187, 95127 Catania (CT)',
        'note' => 'dispositivi medici',
    ],
    [
        'denominazione' => 'ADI S.C.A.R.L.',
        'piva' => '07081900826',
        'sector' => 'sanita',
        'pec' => 'adiscarl@pec.it',
        'sede' => 'Via Liguria 45, 90144 Palermo (PA)',
        'note' => 'assistenza domiciliare',
    ],
    [
        'denominazione' => 'Consorzio Stabile Socio Sanitario Aretuseo (Con.So.Sar)',
        'piva' => '01799990898',
        'sector' => 'sanita',
        'pec' => 'consosar@pec.it',
        'sede' => 'Via San Metodio 26, 96100 Siracusa (SR)',
        'note' => null,
    ],
];

$report = [
    'apply' => $apply,
    'created' => [],
    'skipped_existing' => [],
    'skipped_invalid' => [],
    'failed_db' => [],
];

foreach ($tenants as $t) {
    $pivaDigits = preg_replace('/[^0-9]/', '', (string)$t['piva']);
    if (!validatePartitaIva($pivaDigits)) {
        $report['skipped_invalid'][] = ['piva' => $pivaDigits, 'name' => $t['denominazione'], 'reason' => 'PIVA non valida'];
        continue;
    }

    $exists = $db->fetchOne(
        "SELECT id FROM tenants WHERE deleted_at IS NULL AND partita_iva = ? LIMIT 1",
        [$pivaDigits]
    );
    if ($exists && !empty($exists['id'])) {
        $report['skipped_existing'][] = ['tenant_id' => (int)$exists['id'], 'piva' => $pivaDigits, 'name' => $t['denominazione']];
        continue;
    }

    $parsed = parse_addr((string)$t['sede']);
    if (!($parsed['ok'] ?? false)) {
        $report['skipped_invalid'][] = ['piva' => $pivaDigits, 'name' => $t['denominazione'], 'reason' => (string)($parsed['error'] ?? 'Sede non valida')];
        continue;
    }
    $sede = (array)$parsed['data'];
    $sedeErrors = validateSedeLegale($sede);
    if (!empty($sedeErrors)) {
        $report['skipped_invalid'][] = ['piva' => $pivaDigits, 'name' => $t['denominazione'], 'reason' => implode('; ', $sedeErrors)];
        continue;
    }

    if (!$apply) {
        $report['created'][] = ['tenant_id' => null, 'piva' => $pivaDigits, 'name' => $t['denominazione'], 'dry_run' => true];
        continue;
    }

    try {
        $db->beginTransaction();

        $tenantData = [
            'name' => (string)$t['denominazione'],
            'denominazione' => (string)$t['denominazione'],
            'partita_iva' => $pivaDigits,
            'status' => 'active',
            'settore_merceologico' => (string)$t['sector'],
            'pec' => $t['pec'] ? (string)$t['pec'] : null,
            // legacy sede legale cols
            'sede_legale_indirizzo' => $sede['indirizzo'],
            'sede_legale_civico' => $sede['civico'],
            'sede_legale_cap' => $sede['cap'],
            'sede_legale_comune' => $sede['comune'],
            'sede_legale_provincia' => $sede['provincia'],
        ];
        $tenantData = cnx_filter_table_data($db, 'tenants', $tenantData);
        $tenantId = $db->insert('tenants', $tenantData);

        if ($locationsStorageAvailable) {
            $loc = [
                'tenant_id' => $tenantId,
                'location_type' => 'sede_legale',
                'indirizzo' => $sede['indirizzo'],
                'civico' => $sede['civico'],
                'cap' => $sede['cap'],
                'comune' => $sede['comune'],
                'provincia' => $sede['provincia'],
                'is_primary' => 1,
                'is_active' => 1,
            ];
            $loc = cnx_filter_table_data($db, 'tenant_locations', $loc);
            if (!empty($loc)) $db->insert('tenant_locations', $loc);
        }

        $db->commit();

        // Ensure folder root
        cnx_ensure_tenant_root_folder($db, (int)$tenantId, (string)$t['denominazione']);

        $report['created'][] = ['tenant_id' => (int)$tenantId, 'piva' => $pivaDigits, 'name' => $t['denominazione']];
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $ignored) {}
        $report['failed_db'][] = ['piva' => $pivaDigits, 'name' => $t['denominazione'], 'error' => $e->getMessage()];
    }
}

if (is_cli()) {
    echo "Seed extra tenants 2026-01-03\n";
    echo "Apply: " . ($apply ? "YES\n" : "NO (dry-run)\n");
    echo "Created: " . count($report['created']) . "\n";
    echo "Skipped existing: " . count($report['skipped_existing']) . "\n";
    echo "Skipped invalid: " . count($report['skipped_invalid']) . "\n";
    echo "Failed DB: " . count($report['failed_db']) . "\n\n";
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo '<h2>Seed extra tenants (2026-01-03)</h2>';
echo '<p><strong>Modalità:</strong> ' . ($apply ? 'APPLY' : 'DRY-RUN') . '</p>';
echo '<pre style="white-space:pre-wrap;">' . h(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';

