<?php
/**
 * Tool: Import massivo Aziende (Tenants) da CSV
 *
 * - Dry-run di default (non scrive DB)
 * - Apply solo con ?apply=1 (web) o --apply (CLI)
 * - Crea tenant + sede legale + tenant_locations (se presente) + cartella root (files) per tenant
 * - Idempotente: salta duplicati per Partita IVA
 *
 * CSV atteso (header):
 * Denominazione,PartitaIVA,Sede legale completa,Settore Merceologico,Stato
 */

declare(strict_types=1);

// -------- helpers --------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_cli(): bool { return (PHP_SAPI === 'cli'); }

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

function cnx_arg(array $argv, string $name): ?string {
    $n = count($argv);
    for ($i = 0; $i < $n; $i++) {
        $a = (string)$argv[$i];
        if (strpos($a, $name . '=') === 0) return substr($a, strlen($name) + 1);
        if ($a === $name) {
            // support both "--flag" and "--key value"
            if (($i + 1) < $n) {
                $next = (string)$argv[$i + 1];
                if ($next !== '' && $next[0] !== '-') return $next;
            }
            return '1';
        }
    }
    return null;
}

function cnx_normalize_sector(string $raw): ?string {
    $s = strtoupper(trim($raw));
    $s = str_replace(["\u{2019}", "\u{2018}"], ["'", "'"], $s);
    $s = str_replace('’', "'", $s);
    $s = preg_replace('/\s+/', ' ', $s);

    // Mapping concordato
    if ($s === 'FOOD') return 'alimentare';
    if ($s === "SANITA'" || $s === 'SANITA') return 'sanita';
    if ($s === 'ALTRO') return 'altro';
    return null;
}

function cnx_normalize_status(string $raw): string {
    $s = strtolower(trim($raw));
    if ($s === 'attivo' || $s === 'active') return 'active';
    if ($s === 'inattivo' || $s === 'inactive') return 'inactive';
    if ($s === 'sospeso' || $s === 'suspended') return 'suspended';
    return 'active';
}

/**
 * Best-effort parser per "Sede legale completa":
 * "... <civico> <CAP> <Comune...> <PR>"
 */
function cnx_parse_sede_legale(string $full): array {
    $full = trim(preg_replace('/\s+/', ' ', $full));
    if ($full === '') return ['ok' => false, 'error' => 'Sede legale vuota'];

    $parts = preg_split('/\s+/', $full);
    if (!$parts || count($parts) < 5) {
        return ['ok' => false, 'error' => 'Sede legale troppo corta'];
    }

    $prov = strtoupper((string)end($parts));
    if (!preg_match('/^[A-Z]{2}$/', $prov)) {
        return ['ok' => false, 'error' => 'Provincia non riconosciuta (ultimo token non è 2 lettere)'];
    }

    // Find last CAP (5 digits) before province
    $capIdx = -1;
    for ($i = count($parts) - 2; $i >= 0; $i--) {
        if (preg_match('/^\d{5}$/', (string)$parts[$i])) {
            $capIdx = $i;
            break;
        }
    }
    if ($capIdx < 0) {
        return ['ok' => false, 'error' => 'CAP non trovato'];
    }
    $cap = (string)$parts[$capIdx];

    $civicoIdx = $capIdx - 1;
    if ($civicoIdx < 1) {
        return ['ok' => false, 'error' => 'Civico non trovato (token prima del CAP)'];
    }
    $civico = (string)$parts[$civicoIdx];
    // Accept numeric, snc, or numeric/alpha patterns (57/a)
    if (!preg_match('/^(\d+[A-Za-z]?|\d+\/[A-Za-z0-9]+|snc|SNC)$/', $civico)) {
        // Non-blocking: civico potrebbe contenere punti o simboli, ma lo accettiamo comunque
    }
    $civico = strtolower($civico) === 'snc' ? 'snc' : $civico;

    // Comune is tokens between CAP and province
    $comuneTokens = array_slice($parts, $capIdx + 1, (count($parts) - 1) - ($capIdx + 1));
    $comune = trim(implode(' ', $comuneTokens));
    if ($comune === '') {
        return ['ok' => false, 'error' => 'Comune non trovato'];
    }

    // Indirizzo is everything before civico
    $indirizzoTokens = array_slice($parts, 0, $civicoIdx);
    $indirizzo = trim(implode(' ', $indirizzoTokens));
    if ($indirizzo === '') {
        return ['ok' => false, 'error' => 'Indirizzo non trovato'];
    }

    return [
        'ok' => true,
        'data' => [
            'indirizzo' => $indirizzo,
            'civico' => $civico,
            'cap' => $cap,
            'comune' => $comune,
            'provincia' => $prov,
        ]
    ];
}

function cnx_write_report_files(array $report, string $baseName): array {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . '_reports';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $jsonPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.json';
    @file_put_contents($jsonPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $csvPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.csv';
    $fp = @fopen($csvPath, 'wb');
    if ($fp) {
        fputcsv($fp, ['row', 'denominazione', 'partita_iva', 'status', 'settore', 'result', 'tenant_id', 'message']);
        foreach (($report['rows'] ?? []) as $r) {
            fputcsv($fp, [
                $r['row'] ?? null,
                $r['denominazione'] ?? '',
                $r['partita_iva'] ?? '',
                $r['status'] ?? '',
                $r['settore'] ?? '',
                $r['result'] ?? '',
                $r['tenant_id'] ?? '',
                $r['message'] ?? '',
            ]);
        }
        fclose($fp);
    }

    return ['json' => $jsonPath, 'csv' => $csvPath];
}

// -------- bootstrap / auth --------
if (is_cli()) {
    // CLI safety defaults
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/CollaboraNexio/tools/import_tenants_from_csv.php';
}

require_once __DIR__ . '/../includes/session_init.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../api/tenants/tenant_validators.php';
require_once __DIR__ . '/../includes/tenant_folder_helper.php';

$db = Database::getInstance();

$apply = false;
$filePath = null;
$userIdForCli = null;

if (is_cli()) {
    global $argv;
    $apply = (cnx_arg($argv, '--apply') !== null);
    $filePath = cnx_arg($argv, '--file');
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
    $currentUser = $auth->getCurrentUser();
    $role = $currentUser['role'] ?? 'user';
    if ($role !== 'super_admin') {
        http_response_code(403);
        echo '<h1>403</h1><p>Solo super_admin può eseguire questo tool.</p>';
        exit;
    }

    $apply = (($_GET['apply'] ?? '') === '1');
    $filePath = isset($_GET['file']) ? trim((string)$_GET['file']) : null;
}

// Default path (best-effort on Windows). If not readable, user can upload or pass --file.
if (!$filePath) {
    $default = '\\\\TRUENAS\\Share_Data\\File di Elaborazione\\aziende_riepilogo.csv';
    if (@is_readable($default)) {
        $filePath = $default;
    }
}

// Resolve relative paths (CLI/web ?file=...) relative to project root
if ($filePath) {
    $isAbsWin = (bool)preg_match('/^[A-Za-z]:[\\\\\\/]/', $filePath);
    $isUnc = (strpos($filePath, '\\\\') === 0);
    $isAbsUnix = (strpos($filePath, '/') === 0);
    if (!$isAbsWin && !$isUnc && !$isAbsUnix) {
        $candidate = dirname(__DIR__) . DIRECTORY_SEPARATOR . $filePath;
        $real = @realpath($candidate);
        if ($real) {
            $filePath = $real;
        } else {
            $filePath = $candidate;
        }
    }
}

// Handle upload (web)
$uploadedTmp = null;
if (!is_cli() && !empty($_FILES['csv_file']['tmp_name'])) {
    $uploadedTmp = (string)$_FILES['csv_file']['tmp_name'];
    if (@is_readable($uploadedTmp)) {
        $filePath = $uploadedTmp;
    }
}

// CLI: require a user id for folder creation, and validate it's a super_admin
if (is_cli() && $apply) {
    if (!$userIdForCli) {
        fwrite(STDERR, "ERROR: --user-id è obbligatorio in modalità --apply (serve per creare cartelle tenant).\n");
        exit(2);
    }
    $_SESSION['user_id'] = (int)$userIdForCli;
    $u = $db->fetchOne('SELECT id, role FROM users WHERE id = ? AND deleted_at IS NULL', [(int)$userIdForCli]);
    if (!$u || (($u['role'] ?? '') !== 'super_admin')) {
        fwrite(STDERR, "ERROR: user_id non valido o non super_admin.\n");
        exit(2);
    }
    $_SESSION['role'] = 'super_admin';
}

if (!$filePath || !@is_readable($filePath)) {
    if (is_cli()) {
        fwrite(STDERR, "ERROR: file CSV non leggibile. Usa --file \"...\"\n");
        exit(2);
    }
    echo '<h2>Import Aziende da CSV</h2>';
    echo '<p>File CSV non leggibile. Carica un CSV o passa ?file=... (path sul server).</p>';
    echo '<form method="POST" enctype="multipart/form-data">';
    echo '<input type="file" name="csv_file" accept=".csv" required> ';
    echo '<label><input type="checkbox" name="apply" value="1"> Applica (scrive nel DB)</label> ';
    echo '<button type="submit">Esegui</button>';
    echo '</form>';
    exit;
}

// If web POST apply checkbox
if (!is_cli() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $apply = (($_POST['apply'] ?? '') === '1');
}

// -------- read & process CSV --------
$tenantCols = cnx_get_table_columns($db, 'tenants');
$tenantHasCf = in_array('codice_fiscale', $tenantCols, true);
$tenantHasPiva = in_array('partita_iva', $tenantCols, true);
$locationsStorageAvailable = cnx_table_exists($db, 'tenant_locations');

$report = [
    'meta' => [
        'apply' => $apply,
        'file' => $filePath,
        'generated_at' => date('c'),
        'locations_storage_available' => $locationsStorageAvailable,
    ],
    'counts' => [
        'total_rows' => 0,
        'created' => 0,
        'skipped_duplicate' => 0,
        'skipped_missing_piva' => 0,
        'failed_address_parse' => 0,
        'failed_validation' => 0,
        'failed_db' => 0,
    ],
    'rows' => [],
];

$fh = fopen($filePath, 'rb');
if (!$fh) {
    if (is_cli()) {
        fwrite(STDERR, "ERROR: impossibile aprire CSV.\n");
        exit(2);
    }
    echo '<p>Impossibile aprire CSV.</p>';
    exit;
}

// Handle UTF-8 BOM
$firstBytes = fread($fh, 3);
if ($firstBytes !== "\xEF\xBB\xBF") {
    rewind($fh);
}

$header = fgetcsv($fh);
$rowNum = 1;
if (!$header || count($header) < 3) {
    fclose($fh);
    if (is_cli()) {
        fwrite(STDERR, "ERROR: header CSV non valido.\n");
        exit(2);
    }
    echo '<p>Header CSV non valido.</p>';
    exit;
}

while (($row = fgetcsv($fh)) !== false) {
    $rowNum++;
    if ($row === [null] || $row === false) continue;
    if (count($row) === 0) continue;

    $report['counts']['total_rows']++;

    $den = trim((string)($row[0] ?? ''));
    $pivaRaw = trim((string)($row[1] ?? ''));
    $addrRaw = trim((string)($row[2] ?? ''));
    $sectorRaw = trim((string)($row[3] ?? ''));
    $statusRaw = trim((string)($row[4] ?? ''));

    $pivaUpper = strtoupper($pivaRaw);
    if ($pivaUpper === '' || $pivaUpper === 'DA_CERCARE') {
        $report['counts']['skipped_missing_piva']++;
        $report['rows'][] = [
            'row' => $rowNum,
            'denominazione' => $den,
            'partita_iva' => $pivaRaw,
            'status' => cnx_normalize_status($statusRaw),
            'settore' => cnx_normalize_sector($sectorRaw),
            'result' => 'skipped_missing_piva',
            'tenant_id' => null,
            'message' => 'Partita IVA mancante/DA_CERCARE',
        ];
        continue;
    }

    $pivaDigits = preg_replace('/[^0-9]/', '', $pivaRaw);
    if ($tenantHasPiva && !validatePartitaIva($pivaDigits)) {
        $report['counts']['failed_validation']++;
        $report['rows'][] = [
            'row' => $rowNum,
            'denominazione' => $den,
            'partita_iva' => $pivaRaw,
            'status' => cnx_normalize_status($statusRaw),
            'settore' => cnx_normalize_sector($sectorRaw),
            'result' => 'failed_validation',
            'tenant_id' => null,
            'message' => 'Partita IVA non valida (checksum)',
        ];
        continue;
    }

    $settore = cnx_normalize_sector($sectorRaw);
    if (!$settore) {
        $report['counts']['failed_validation']++;
        $report['rows'][] = [
            'row' => $rowNum,
            'denominazione' => $den,
            'partita_iva' => $pivaDigits,
            'status' => cnx_normalize_status($statusRaw),
            'settore' => null,
            'result' => 'failed_validation',
            'tenant_id' => null,
            'message' => "Settore merceologico non riconosciuto (attesi: FOOD, SANITA', ALTRO)",
        ];
        continue;
    }

    $parsed = cnx_parse_sede_legale($addrRaw);
    if (!($parsed['ok'] ?? false)) {
        $report['counts']['failed_address_parse']++;
        $report['rows'][] = [
            'row' => $rowNum,
            'denominazione' => $den,
            'partita_iva' => $pivaDigits,
            'status' => cnx_normalize_status($statusRaw),
            'settore' => $settore,
            'result' => 'failed_address_parse',
            'tenant_id' => null,
            'message' => (string)($parsed['error'] ?? 'Parse sede legale fallito'),
        ];
        continue;
    }

    $sede = (array)$parsed['data'];
    $sedeErrors = validateSedeLegale($sede);
    if (!empty($sedeErrors)) {
        $report['counts']['failed_validation']++;
        $report['rows'][] = [
            'row' => $rowNum,
            'denominazione' => $den,
            'partita_iva' => $pivaDigits,
            'status' => cnx_normalize_status($statusRaw),
            'settore' => $settore,
            'result' => 'failed_validation',
            'tenant_id' => null,
            'message' => 'Sede legale non valida: ' . implode('; ', $sedeErrors),
        ];
        continue;
    }

    // Dedupe by PIVA (and optionally CF if provided; CSV doesn't include CF)
    $dup = $db->fetchOne(
        "SELECT id, denominazione
         FROM tenants
         WHERE deleted_at IS NULL
           AND partita_iva = ?
         LIMIT 1",
        [$pivaDigits]
    );
    if ($dup && !empty($dup['id'])) {
        $report['counts']['skipped_duplicate']++;
        $report['rows'][] = [
            'row' => $rowNum,
            'denominazione' => $den,
            'partita_iva' => $pivaDigits,
            'status' => cnx_normalize_status($statusRaw),
            'settore' => $settore,
            'result' => 'skipped_duplicate',
            'tenant_id' => (int)$dup['id'],
            'message' => 'Duplicato: tenant già presente (PIVA)',
        ];
        continue;
    }

    $status = cnx_normalize_status($statusRaw);
    $denFinal = $den !== '' ? $den : ('Azienda ' . $pivaDigits);

    if (!$apply) {
        $report['rows'][] = [
            'row' => $rowNum,
            'denominazione' => $denFinal,
            'partita_iva' => $pivaDigits,
            'status' => $status,
            'settore' => $settore,
            'result' => 'would_create',
            'tenant_id' => null,
            'message' => 'Dry-run: verrebbe creato',
        ];
        continue;
    }

    // APPLY: insert tenant + location + root folder
    try {
        $db->beginTransaction();

        $tenantData = [
            'name' => $denFinal,
            'denominazione' => $denFinal,
            'status' => $status,
            'partita_iva' => $tenantHasPiva ? $pivaDigits : null,
            'codice_fiscale' => $tenantHasCf ? null : null,
            'settore_merceologico' => $settore,
            // legacy sede legale columns (best effort)
            'sede_legale_indirizzo' => $sede['indirizzo'],
            'sede_legale_civico' => $sede['civico'],
            'sede_legale_cap' => $sede['cap'],
            'sede_legale_comune' => $sede['comune'],
            'sede_legale_provincia' => $sede['provincia'],
        ];

        $tenantData = cnx_filter_table_data($db, 'tenants', $tenantData);
        if (empty($tenantData)) {
            throw new Exception('Schema tenants non allineato (nessuna colonna match)');
        }

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
            if (!empty($loc)) {
                $db->insert('tenant_locations', $loc);
            }
        }

        $db->commit();

        // Root folder (non-blocking but required): if it fails, report warning
        $folderMsg = 'OK';
        try {
            cnx_ensure_tenant_root_folder($db, (int)$tenantId, $denFinal);
        } catch (Throwable $e) {
            $folderMsg = 'WARN: cartella root non creata (' . $e->getMessage() . ')';
        }

        $report['counts']['created']++;
        $report['rows'][] = [
            'row' => $rowNum,
            'denominazione' => $denFinal,
            'partita_iva' => $pivaDigits,
            'status' => $status,
            'settore' => $settore,
            'result' => 'created',
            'tenant_id' => (int)$tenantId,
            'message' => $folderMsg,
        ];
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $ignored) {}
        $report['counts']['failed_db']++;
        $report['rows'][] = [
            'row' => $rowNum,
            'denominazione' => $denFinal,
            'partita_iva' => $pivaDigits,
            'status' => $status,
            'settore' => $settore,
            'result' => 'failed_db',
            'tenant_id' => null,
            'message' => 'DB error: ' . $e->getMessage(),
        ];
    }
}
fclose($fh);

$baseName = 'tenants_import_' . date('Ymd_His');
$files = cnx_write_report_files($report, $baseName);
$report['meta']['report_files'] = $files;

// -------- output --------
if (is_cli()) {
    fwrite(STDOUT, "Import tenants CSV\n");
    fwrite(STDOUT, "File: {$filePath}\n");
    fwrite(STDOUT, "Apply: " . ($apply ? 'YES' : 'NO (dry-run)') . "\n\n");
    foreach ($report['counts'] as $k => $v) {
        fwrite(STDOUT, str_pad($k, 22) . ": " . $v . "\n");
    }
    fwrite(STDOUT, "\nReport JSON: {$files['json']}\n");
    fwrite(STDOUT, "Report CSV : {$files['csv']}\n");
    exit(0);
}

echo '<h2>Import Aziende da CSV</h2>';
echo '<p><strong>Modalità:</strong> ' . ($apply ? 'APPLY (scrive nel DB)' : 'DRY-RUN (nessuna scrittura)') . '</p>';
echo '<p><strong>File:</strong> ' . h($filePath) . '</p>';
echo '<p><strong>Report:</strong> ' . h($files['json']) . ' / ' . h($files['csv']) . '</p>';

echo '<form method="POST" enctype="multipart/form-data" style="margin: 12px 0; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">';
echo '<div style="margin-bottom: 8px;"><input type="file" name="csv_file" accept=".csv"> <small>(opzionale: carica un CSV)</small></div>';
echo '<div style="margin-bottom: 8px;"><label><input type="checkbox" name="apply" value="1"> Applica (scrive nel DB)</label></div>';
echo '<button type="submit">Esegui</button>';
echo '</form>';

echo '<h3>Riepilogo</h3>';
echo '<ul>';
foreach ($report['counts'] as $k => $v) {
    echo '<li><strong>' . h($k) . ':</strong> ' . h((string)$v) . '</li>';
}
echo '</ul>';

echo '<h3>Dettaglio (prime 200 righe)</h3>';
echo '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
echo '<thead><tr>';
echo '<th>#</th><th>Denominazione</th><th>P.IVA</th><th>Settore</th><th>Status</th><th>Risultato</th><th>Tenant ID</th><th>Messaggio</th>';
echo '</tr></thead><tbody>';
$max = 200;
$i = 0;
foreach ($report['rows'] as $r) {
    $i++;
    if ($i > $max) break;
    echo '<tr>';
    echo '<td>' . h((string)($r['row'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['denominazione'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['partita_iva'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['settore'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['status'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['result'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['tenant_id'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['message'] ?? '')) . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';

