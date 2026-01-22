<?php
/**
 * Tool: Import massivo Aziende (Tenants) da elenco-completo-verificato.csv
 *
 * - Dry-run di default (non scrive DB)
 * - Apply solo con ?apply=1 (web) o --apply (CLI)
 * - CSV atteso (header):
 *   Denominazione,Provincia,Settore,Partita IVA,Sede legale,Note
 *
 * Regole:
 * - Idempotente: salta duplicati per Partita IVA (deleted_at IS NULL)
 * - Denominazione tenant: usa "Note" se presente (è spesso la ragione sociale completa), altrimenti "Denominazione"
 * - Settore: mappa FOOD -> alimentare, SANITA'/SANITA -> sanita, ALTRO -> altro
 * - Sede legale: richiede formato "... , 12345 Comune (PR)". Se manca CAP -> skipped_invalid (serve per i campi obbligatori).
 * - Crea anche cartella root tenant (files) via cnx_ensure_tenant_root_folder().
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

function cnx_is_valid_utf8(string $s): bool { return preg_match('//u', $s) === 1; }
function cnx_to_utf8(string $s): string {
    if (cnx_is_valid_utf8($s)) return $s;
    if (function_exists('iconv')) {
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
        if (is_string($converted) && $converted !== '' && cnx_is_valid_utf8($converted)) return $converted;
    }
    return $s;
}
function cnx_norm_str(?string $s): string {
    $s = cnx_to_utf8((string)$s);
    $s = str_replace(["\u{2019}", "\u{2018}", '’'], ["'", "'", "'"], $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim((string)$s);
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

function cnx_normalize_sector(string $raw): ?string {
    $s = strtoupper(cnx_norm_str($raw));
    if ($s === 'FOOD') return 'alimentare';
    if ($s === "SANITA'" || $s === 'SANITA') return 'sanita';
    if ($s === 'ALTRO') return 'altro';
    return null;
}

/**
 * Parse "Via X 10, 90100 Comune (PR)".
 * Requires CAP; if missing -> ok=false.
 */
function cnx_parse_sede_legale(string $full): array {
    $full = cnx_norm_str($full);
    if ($full === '') return ['ok' => false, 'error' => 'Sede legale vuota'];

    $parts = array_map('trim', explode(',', $full));
    if (count($parts) < 2) {
        return ['ok' => false, 'error' => 'Sede legale: formato non valido (manca virgola prima del CAP)'];
    }
    $last = cnx_norm_str((string)array_pop($parts)); // ideally "CAP Comune (PR)"
    $addrLine = cnx_norm_str(implode(',', $parts));

    // Support: "12345 Comune (PR)"
    if (!preg_match('/^(\d{5})\s+(.+?)\s*\(([A-Z]{2})\)$/u', $last, $m)) {
        // Sometimes missing CAP: "Comune (PR)" -> report clearly
        if (preg_match('/^(.+?)\s*\(([A-Z]{2})\)$/u', $last)) {
            return ['ok' => false, 'error' => 'Sede legale: CAP mancante (richiesto)'];
        }
        return ['ok' => false, 'error' => 'Sede legale: CAP/Comune/Provincia non riconosciuti'];
    }

    $cap = $m[1];
    $comune = cnx_norm_str($m[2]);
    $prov = strtoupper(cnx_norm_str($m[3]));

    // Split indirizzo vs civico from addrLine (best-effort)
    $tokens = preg_split('/\s+/', $addrLine);
    $tokens = $tokens ?: [];
    $civico = '';
    if (!empty($tokens)) {
        $lastTok = (string)end($tokens);
        $lastTokNorm = strtolower($lastTok);
        if ($lastTokNorm === 'snc' || preg_match('/\d/', $lastTok)) {
            $civico = $lastTok;
            array_pop($tokens);
        }
    }
    $indirizzo = cnx_norm_str(implode(' ', $tokens));
    if ($indirizzo === '') {
        $indirizzo = $addrLine;
        $civico = $civico !== '' ? $civico : 'snc';
    }
    if ($civico === '') $civico = 'snc';

    return [
        'ok' => true,
        'data' => [
            'indirizzo' => $indirizzo,
            'civico' => $civico,
            'cap' => $cap,
            'comune' => $comune,
            'provincia' => $prov,
        ],
    ];
}

function cnx_write_reports(array $report, string $baseName): array {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . '_reports';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $jsonPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.json';
    @file_put_contents($jsonPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $csvPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.csv';
    $fp = @fopen($csvPath, 'wb');
    if ($fp) {
        fputcsv($fp, ['row', 'denominazione', 'ragione_sociale', 'partita_iva', 'settore_raw', 'settore_mapped', 'result', 'tenant_id', 'message']);
        foreach (($report['rows'] ?? []) as $r) {
            fputcsv($fp, [
                $r['row'] ?? '',
                $r['denominazione'] ?? '',
                $r['ragione_sociale'] ?? '',
                $r['partita_iva'] ?? '',
                $r['settore_raw'] ?? '',
                $r['settore_mapped'] ?? '',
                $r['result'] ?? '',
                $r['tenant_id'] ?? '',
                $r['message'] ?? '',
            ]);
        }
        fclose($fp);
    }
    return ['json' => $jsonPath, 'csv' => $csvPath];
}

// Bootstrap
if (is_cli()) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/CollaboraNexio/tools/import_tenants_from_elenco_verificato_csv.php';
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
    if (!$auth->checkAuth()) { header('Location: /CollaboraNexio/index.php'); exit; }
    $u = $auth->getCurrentUser();
    if (!$u || ($u['role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        echo '<h1>403</h1><p>Solo super_admin.</p>';
        exit;
    }
    $_SESSION['user_id'] = (int)($u['id'] ?? 0);
    $apply = (($_GET['apply'] ?? '') === '1');
    $filePath = $_GET['file'] ?? null;
}

if (!$filePath) {
    $filePath = '\\\\truenas\\Share_Data\\File di Elaborazione\\elenco-completo-verificato.csv';
}

if ($apply && is_cli()) {
    if (!$userIdForCli) {
        fwrite(STDERR, "ERROR: --user-id obbligatorio in --apply (serve per creare cartelle root tenant)\n");
        exit(2);
    }
    $_SESSION['user_id'] = (int)$userIdForCli;
}

$locationsStorageAvailable = cnx_table_exists($db, 'tenant_locations');
$tenantsCols = cnx_get_table_columns($db, 'tenants');
$pdo = $db->getConnection();
$tenantsHasCode = in_array('code', $tenantsCols, true); // detected, but we won't set it (can be unique and collide)

$report = [
    'apply' => $apply,
    'file' => $filePath,
    'created' => 0,
    'skipped_duplicate' => 0,
    'skipped_invalid' => 0,
    'failed' => 0,
    'rows' => [],
];

if (!file_exists($filePath)) {
    $report['failed'] = 1;
    $report['rows'][] = ['row' => 0, 'result' => 'fail', 'message' => 'File non trovato: ' . $filePath];
} else {
    $fp = fopen($filePath, 'rb');
    if (!$fp) {
        $report['failed'] = 1;
        $report['rows'][] = ['row' => 0, 'result' => 'fail', 'message' => 'Impossibile aprire file CSV'];
    } else {
        $header = fgetcsv($fp, 0, ',', '"');
        $header = is_array($header) ? array_map('cnx_norm_str', $header) : [];
        $idx = [];
        foreach ($header as $i => $name) $idx[strtoupper($name)] = $i;

        $required = ['DENOMINAZIONE', 'SETTORE', 'PARTITA IVA', 'SEDE LEGALE', 'NOTE'];
        foreach ($required as $col) {
            if (!array_key_exists($col, $idx)) {
                $report['failed'] = 1;
                $report['rows'][] = ['row' => 0, 'result' => 'fail', 'message' => "Header mancante: {$col}"];
            }
        }

        if (!$report['failed']) {
            $rowNum = 1;
            while (($row = fgetcsv($fp, 0, ',', '"')) !== false) {
                $rowNum++;
                if (!is_array($row) || count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;

                $den = cnx_norm_str((string)($row[$idx['DENOMINAZIONE']] ?? ''));
                $note = cnx_norm_str((string)($row[$idx['NOTE']] ?? ''));
                // Prefer full legal name from Note, but avoid "Sede operativa: ..." notes.
                $ragione = ($note !== '' && stripos($note, 'sede operativa') === false) ? $note : $den;

                $pivaRaw = cnx_norm_str((string)($row[$idx['PARTITA IVA']] ?? ''));
                $pivaDigits = preg_replace('/[^0-9]/', '', $pivaRaw);

                $settoreRaw = cnx_norm_str((string)($row[$idx['SETTORE']] ?? ''));
                $settoreMapped = cnx_normalize_sector($settoreRaw) ?? 'altro';

                $sedeRaw = cnx_norm_str((string)($row[$idx['SEDE LEGALE']] ?? ''));

                // CAP override (user-provided): NEW LIFE - Piana degli Albanesi (PA) -> 90037
                // Source row can be missing CAP (required by validateSedeLegale).
                if ($pivaDigits === '06270470823' && !preg_match('/\b\d{5}\b/', $sedeRaw)) {
                    // If format "... , Comune (PR)" then inject CAP before Comune
                    $parts = array_map('trim', explode(',', $sedeRaw));
                    if (count($parts) >= 2) {
                        $last = trim((string)array_pop($parts)); // "Piana degli Albanesi (PA)"
                        $addr = trim(implode(', ', $parts));
                        if ($addr !== '' && $last !== '') {
                            $sedeRaw = $addr . ', 90037 ' . $last;
                        }
                    }
                }

                if ($pivaDigits === '' || strlen($pivaDigits) !== 11) {
                    $report['skipped_invalid']++;
                    $report['rows'][] = [
                        'row' => $rowNum,
                        'denominazione' => $den,
                        'ragione_sociale' => $ragione,
                        'partita_iva' => $pivaDigits,
                        'settore_raw' => $settoreRaw,
                        'settore_mapped' => $settoreMapped,
                        'result' => 'skipped_invalid',
                        'tenant_id' => null,
                        'message' => 'Partita IVA mancante/non valida (11 cifre richieste)',
                    ];
                    continue;
                }

                // Idempotency check by Partita IVA (also catches already-imported tenants)
                $exists = $db->fetchOne(
                    "SELECT id, deleted_at FROM tenants WHERE partita_iva = ? LIMIT 1",
                    [$pivaDigits]
                );
                if ($exists && !empty($exists['id']) && empty($exists['deleted_at'])) {
                    $report['skipped_duplicate']++;
                    $report['rows'][] = [
                        'row' => $rowNum,
                        'denominazione' => $den,
                        'ragione_sociale' => $ragione,
                        'partita_iva' => $pivaDigits,
                        'settore_raw' => $settoreRaw,
                        'settore_mapped' => $settoreMapped,
                        'result' => 'skipped_duplicate',
                        'tenant_id' => (int)$exists['id'],
                        'message' => 'Tenant già presente (Partita IVA)',
                    ];
                    continue;
                }
                if ($exists && !empty($exists['id']) && !empty($exists['deleted_at'])) {
                    $report['skipped_duplicate']++;
                    $report['rows'][] = [
                        'row' => $rowNum,
                        'denominazione' => $den,
                        'ragione_sociale' => $ragione,
                        'partita_iva' => $pivaDigits,
                        'settore_raw' => $settoreRaw,
                        'settore_mapped' => $settoreMapped,
                        'result' => 'skipped_duplicate',
                        'tenant_id' => (int)$exists['id'],
                        'message' => 'Tenant esistente ma soft-deleted (ripristino non automatico)',
                    ];
                    continue;
                }

                $parsed = cnx_parse_sede_legale($sedeRaw);
                if (!($parsed['ok'] ?? false)) {
                    $report['skipped_invalid']++;
                    $report['rows'][] = [
                        'row' => $rowNum,
                        'denominazione' => $den,
                        'ragione_sociale' => $ragione,
                        'partita_iva' => $pivaDigits,
                        'settore_raw' => $settoreRaw,
                        'settore_mapped' => $settoreMapped,
                        'result' => 'skipped_invalid',
                        'tenant_id' => null,
                        'message' => (string)($parsed['error'] ?? 'Sede legale non valida'),
                    ];
                    continue;
                }

                $sede = (array)$parsed['data'];
                $sedeErrors = validateSedeLegale($sede);
                if (!empty($sedeErrors)) {
                    $report['skipped_invalid']++;
                    $report['rows'][] = [
                        'row' => $rowNum,
                        'denominazione' => $den,
                        'ragione_sociale' => $ragione,
                        'partita_iva' => $pivaDigits,
                        'settore_raw' => $settoreRaw,
                        'settore_mapped' => $settoreMapped,
                        'result' => 'skipped_invalid',
                        'tenant_id' => null,
                        'message' => implode('; ', $sedeErrors),
                    ];
                    continue;
                }

                if (!$apply) {
                    $report['created']++;
                    $report['rows'][] = [
                        'row' => $rowNum,
                        'denominazione' => $den,
                        'ragione_sociale' => $ragione,
                        'partita_iva' => $pivaDigits,
                        'settore_raw' => $settoreRaw,
                        'settore_mapped' => $settoreMapped,
                        'result' => 'dry_run_would_create',
                        'tenant_id' => null,
                        'message' => 'OK (dry-run)',
                    ];
                    continue;
                }

                try {
                    $db->beginTransaction();

                    $tenantData = [
                        'name' => $ragione,
                        'denominazione' => $ragione,
                        'partita_iva' => $pivaDigits,
                        'status' => 'active',
                        'settore_merceologico' => $settoreMapped,
                        'sede_legale_indirizzo' => $sede['indirizzo'],
                        'sede_legale_civico' => $sede['civico'],
                        'sede_legale_cap' => $sede['cap'],
                        'sede_legale_comune' => $sede['comune'],
                        'sede_legale_provincia' => $sede['provincia'],
                        // IMPORTANT: do NOT set tenants.code here (often UNIQUE, can collide with earlier imports)
                    ];

                    $tenantData = cnx_filter_table_data($db, 'tenants', $tenantData);
                    if (empty($tenantData)) {
                        throw new Exception('Nessun campo valido per tabella tenants (schema drift?)');
                    }

                    // Insert via PDO directly to preserve the real SQL error message
                    $fields = array_keys($tenantData);
                    $placeholders = array_map(fn($f) => ':' . $f, $fields);
                    $sql = "INSERT INTO `tenants` (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                    $stmt = $pdo->prepare($sql);
                    foreach ($tenantData as $k => $v) {
                        $stmt->bindValue(':' . $k, $v);
                    }
                    $stmt->execute();
                    $tenantId = (int)$pdo->lastInsertId();

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
                            $lFields = array_keys($loc);
                            $lPh = array_map(fn($f) => ':' . $f, $lFields);
                            $lSql = "INSERT INTO `tenant_locations` (`" . implode('`, `', $lFields) . "`) VALUES (" . implode(', ', $lPh) . ")";
                            $lStmt = $pdo->prepare($lSql);
                            foreach ($loc as $k => $v) {
                                $lStmt->bindValue(':' . $k, $v);
                            }
                            $lStmt->execute();
                        }
                    }

                    $db->commit();
                    cnx_ensure_tenant_root_folder($db, (int)$tenantId, $ragione);

                    $report['created']++;
                    $report['rows'][] = [
                        'row' => $rowNum,
                        'denominazione' => $den,
                        'ragione_sociale' => $ragione,
                        'partita_iva' => $pivaDigits,
                        'settore_raw' => $settoreRaw,
                        'settore_mapped' => $settoreMapped,
                        'result' => 'created',
                        'tenant_id' => (int)$tenantId,
                        'message' => 'Creato',
                    ];
                } catch (Throwable $e) {
                    try { $db->rollback(); } catch (Throwable $ignored) {}
                    $report['failed']++;
                    $report['rows'][] = [
                        'row' => $rowNum,
                        'denominazione' => $den,
                        'ragione_sociale' => $ragione,
                        'partita_iva' => $pivaDigits,
                        'settore_raw' => $settoreRaw,
                        'settore_mapped' => $settoreMapped,
                        'result' => 'failed',
                        'tenant_id' => null,
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        fclose($fp);
    }
}

$baseName = 'tenants_import_elenco_verificato_' . date('Ymd_His');
$paths = cnx_write_reports($report, $baseName);
$report['report_files'] = $paths;

if (is_cli()) {
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo '<h2>Import Tenants — Elenco Completo Verificato</h2>';
echo '<p><strong>Modalità:</strong> ' . ($apply ? 'APPLY' : 'DRY-RUN') . '</p>';
echo '<p><strong>File:</strong> ' . h($filePath) . '</p>';
echo '<pre style="white-space:pre-wrap;">' . h(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';

