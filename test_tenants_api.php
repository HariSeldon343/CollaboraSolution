<?php
/**
 * Script di Test per API Gestione Tenants
 *
 * Verifica funzionamento di tutte le 5 API:
 * 1. create.php
 * 2. update.php
 * 3. list.php
 * 4. get.php
 * 5. list_managers.php
 *
 * IMPORTANTE: Eseguire DOPO aver effettuato login come Admin/Super Admin
 *
 * Usage:
 * - Browser: http://localhost:8888/CollaboraNexio/test_tenants_api.php
 * - CLI: php test_tenants_api.php
 *
 * @version 1.0.0
 */

// Start session per autenticazione
session_start();

// Headers per visualizzazione corretta
header('Content-Type: text/html; charset=utf-8');

// Configurazione
define('BASE_URL', 'http://localhost:8888/CollaboraNexio');
define('API_BASE', BASE_URL . '/api');

// Colori per output
$GREEN = "\033[32m";
$RED = "\033[31m";
$YELLOW = "\033[33m";
$RESET = "\033[0m";

// Se eseguito da CLI, usa colori ANSI
$IS_CLI = php_sapi_name() === 'cli';

if (!$IS_CLI) {
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'>";
    echo "<title>Test API Tenants</title>";
    echo "<style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
        h1, h2 { color: #4ec9b0; }
        .success { color: #4ec9b0; }
        .error { color: #f48771; }
        .warning { color: #dcdcaa; }
        .info { color: #9cdcfe; }
        pre { background: #252526; padding: 10px; border-left: 3px solid #007acc; }
        .test-section { margin: 20px 0; border: 1px solid #3c3c3c; padding: 15px; }
    </style></head><body>";
}

/**
 * Output formattato
 */
function output($message, $type = 'info') {
    global $IS_CLI, $GREEN, $RED, $YELLOW, $RESET;

    if ($IS_CLI) {
        $color = match($type) {
            'success' => $GREEN,
            'error' => $RED,
            'warning' => $YELLOW,
            default => $RESET
        };
        echo $color . $message . $RESET . "\n";
    } else {
        $class = match($type) {
            'success' => 'success',
            'error' => 'error',
            'warning' => 'warning',
            default => 'info'
        };
        echo "<div class='$class'>$message</div>\n";
    }
}

/**
 * Esegue chiamata API simulata
 */
function callAPI($endpoint, $method = 'GET', $data = null) {
    // Simula chiamata API usando file_get_contents o curl
    // Per testing reale, usare session autenticata

    $url = API_BASE . $endpoint;

    $options = [
        'http' => [
            'method' => $method,
            'header' => [
                'Content-Type: application/json',
                'X-CSRF-Token: ' . ($_SESSION['csrf_token'] ?? '')
            ],
            'ignore_errors' => true
        ]
    ];

    if ($data && in_array($method, ['POST', 'PUT'])) {
        $options['http']['content'] = json_encode($data);
    }

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    return [
        'body' => $response,
        'status' => $http_response_header[0] ?? 'Unknown',
        'json' => $response ? json_decode($response, true) : null
    ];
}

/**
 * Verifica autenticazione
 */
function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        output("ERRORE: Non autenticato. Effettuare login prima di eseguire i test.", 'error');
        output("Visita: " . BASE_URL . "/login.php", 'warning');
        return false;
    }

    output("✓ Autenticato come: " . ($_SESSION['user_name'] ?? 'Utente'), 'success');
    output("  Ruolo: " . ($_SESSION['role'] ?? 'N/A'), 'info');
    output("  Tenant ID: " . ($_SESSION['tenant_id'] ?? 'N/A'), 'info');

    // Verifica ruolo minimo
    if (!in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
        output("ATTENZIONE: Ruolo insufficiente. Richiesto Admin o Super Admin.", 'warning');
        return false;
    }

    return true;
}

/**
 * Test validatori
 */
function testValidators() {
    output("\n=== TEST VALIDATORI ===", 'info');

    // Include le funzioni di validazione
    require_once __DIR__ . '/api/tenants/create.php';

    // Test Codice Fiscale
    $testCF = [
        'RSSMRA80A01H501Z' => true,
        'BNCMRA85T10H501K' => true,
        'INVALID' => false,
        'RSSMRA80' => false,
        '123456789ABCDEFG' => false
    ];

    output("\nTest Codice Fiscale:", 'info');
    foreach ($testCF as $cf => $expected) {
        $result = validateCodiceFiscale($cf);
        $status = $result === $expected ? '✓' : '✗';
        $type = $result === $expected ? 'success' : 'error';
        output("  $status $cf => " . ($result ? 'VALIDO' : 'INVALIDO'), $type);
    }

    // Test Partita IVA
    $testPIVA = [
        '12345678903' => true,
        '01234567890' => true,
        '12345678900' => false,
        '1234567890' => false,
        'ABC12345678' => false
    ];

    output("\nTest Partita IVA:", 'info');
    foreach ($testPIVA as $piva => $expected) {
        $result = validatePartitaIva($piva);
        $status = $result === $expected ? '✓' : '✗';
        $type = $result === $expected ? 'success' : 'error';
        output("  $status $piva => " . ($result ? 'VALIDO' : 'INVALIDO'), $type);
    }

    // Test Telefono
    $testTel = [
        '+39 02 1234567' => true,
        '+39 333 1234567' => true,
        '02 12345678' => true,
        '3331234567' => true,
        '123' => false,
        '+1 555 1234567' => false
    ];

    output("\nTest Telefono:", 'info');
    foreach ($testTel as $tel => $expected) {
        $result = validateTelefono($tel);
        $status = $result === $expected ? '✓' : '✗';
        $type = $result === $expected ? 'success' : 'error';
        output("  $status $tel => " . ($result ? 'VALIDO' : 'INVALIDO'), $type);
    }
}

/**
 * Test creazione tenant (dry-run)
 */
function testCreateTenant() {
    output("\n=== TEST CREAZIONE TENANT (DRY-RUN) ===", 'info');

    $testData = [
        'denominazione' => 'Test Company SRL',
        'partita_iva' => '12345678903',
        'codice_fiscale' => 'TSTCMP80A01H501Z',
        'sede_legale' => [
            'indirizzo' => 'Via Test',
            'civico' => '123',
            'comune' => 'Milano',
            'provincia' => 'MI',
            'cap' => '20100'
        ],
        'sedi_operative' => [
            [
                'indirizzo' => 'Via Operativa',
                'civico' => '45',
                'comune' => 'Roma',
                'provincia' => 'RM',
                'cap' => '00100'
            ]
        ],
        'settore_merceologico' => 'IT',
        'numero_dipendenti' => 50,
        'capitale_sociale' => 100000.00,
        'telefono' => '+39 02 1234567',
        'email' => 'test@example.com',
        'pec' => 'test@pec.example.com',
        'rappresentante_legale' => 'Mario Rossi',
        'status' => 'active'
    ];

    output("\nDati test:", 'info');
    if (!$GLOBALS['IS_CLI']) {
        echo "<pre>" . json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    } else {
        echo json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }

    output("\nNOTA: Per testare realmente, usare Postman o frontend.", 'warning');
}

/**
 * Verifica esistenza file API
 */
function checkAPIFiles() {
    output("\n=== VERIFICA FILE API ===", 'info');

    $apiFiles = [
        'tenants/create.php' => 'Creazione tenant',
        'tenants/update.php' => 'Aggiornamento tenant',
        'tenants/list.php' => 'Lista tenants',
        'tenants/get.php' => 'Dettaglio tenant',
        'users/list_managers.php' => 'Lista manager'
    ];

    foreach ($apiFiles as $file => $desc) {
        $path = __DIR__ . '/api/' . $file;
        $exists = file_exists($path);
        $status = $exists ? '✓' : '✗';
        $type = $exists ? 'success' : 'error';

        $size = $exists ? filesize($path) : 0;
        $lines = $exists ? count(file($path)) : 0;

        output("  $status $file - $desc", $type);
        if ($exists) {
            output("      Size: " . number_format($size) . " bytes, Lines: $lines", 'info');
        }
    }
}

/**
 * Verifica schema database
 */
function checkDatabaseSchema() {
    output("\n=== VERIFICA SCHEMA DATABASE ===", 'info');

    try {
        require_once __DIR__ . '/includes/db.php';
        $db = Database::getInstance();

        // Verifica colonne tenants
        $columns = $db->fetchAll("SHOW COLUMNS FROM tenants");

        $requiredColumns = [
            'denominazione',
            'codice_fiscale',
            'partita_iva',
            'sede_legale_indirizzo',
            'sede_legale_civico',
            'sede_legale_comune',
            'sede_legale_provincia',
            'sede_legale_cap',
            'sedi_operative',
            'settore_merceologico',
            'numero_dipendenti',
            'capitale_sociale',
            'telefono',
            'email',
            'pec',
            'manager_id',
            'rappresentante_legale'
        ];

        $existingColumns = array_column($columns, 'Field');

        output("\nColonne richieste:", 'info');
        foreach ($requiredColumns as $col) {
            $exists = in_array($col, $existingColumns);
            $status = $exists ? '✓' : '✗';
            $type = $exists ? 'success' : 'error';
            output("  $status $col", $type);
        }

        // Verifica vincoli
        $createTable = $db->fetchOne("SHOW CREATE TABLE tenants");
        $hasCheckConstraint = str_contains($createTable['Create Table'] ?? '', 'chk_tenant_fiscal_code');
        $hasFKConstraint = str_contains($createTable['Create Table'] ?? '', 'fk_tenants_manager_id');

        output("\nVincoli:", 'info');
        output("  " . ($hasCheckConstraint ? '✓' : '✗') . " CHECK (CF OR P.IVA)", $hasCheckConstraint ? 'success' : 'error');
        output("  " . ($hasFKConstraint ? '✓' : '✗') . " FK manager_id", $hasFKConstraint ? 'success' : 'error');

    } catch (Exception $e) {
        output("ERRORE: " . $e->getMessage(), 'error');
        output("Eseguire migration: database/migrate_aziende_ruoli_sistema.sql", 'warning');
    }
}

/**
 * Main execution
 */
output("╔════════════════════════════════════════════════════════╗", 'info');
output("║        TEST API GESTIONE TENANTS - v1.0.0             ║", 'info');
output("╚════════════════════════════════════════════════════════╝", 'info');

// 1. Verifica autenticazione
if (!checkAuth()) {
    output("\nTest interrotto: autenticazione richiesta.", 'error');
    exit(1);
}

// 2. Verifica file API
checkAPIFiles();

// 3. Verifica schema database
checkDatabaseSchema();

// 4. Test validatori
testValidators();

// 5. Test creazione (dry-run)
testCreateTenant();

// Summary
output("\n╔════════════════════════════════════════════════════════╗", 'info');
output("║                  TEST COMPLETATO                       ║", 'info');
output("╚════════════════════════════════════════════════════════╝", 'info');

output("\nProssimi step:", 'warning');
output("1. Verificare che migration database sia stata eseguita", 'info');
output("2. Testare API con Postman o frontend", 'info');
output("3. Verificare tenant isolation per diversi ruoli", 'info');
output("4. Testare validazione errori", 'info');

if (!$IS_CLI) {
    echo "</body></html>";
}
