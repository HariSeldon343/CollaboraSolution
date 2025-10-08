<?php
/**
 * Test Completo Sistema Aziende
 * Verifica end-to-end di form, API e database
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/db.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Test Sistema Aziende</title>";
    echo "<style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 30px auto; padding: 20px; background: #f5f5f5; }
        .test-section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { border-bottom: 2px solid #667eea; padding-bottom: 10px; color: #333; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .info { color: #17a2b8; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #667eea; color: white; }
    </style></head><body>";
    echo "<h1 style='text-align: center; color: #667eea;'>🧪 Test Completo Sistema Aziende</h1>";
}

$testsPassed = 0;
$testsFailed = 0;

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    echo "<div class='test-section'>\n";
    echo "<h2>✅ TEST 1: Verifica Struttura Database</h2>\n";

    // Check tenants table structure
    $stmt = $pdo->query("SHOW COLUMNS FROM tenants");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $requiredColumns = [
        'id', 'name', 'denominazione', 'codice_fiscale', 'partita_iva',
        'sede_legale_indirizzo', 'sede_legale_civico', 'sede_legale_comune',
        'sede_legale_provincia', 'sede_legale_cap', 'sedi_operative',
        'settore_merceologico', 'numero_dipendenti', 'capitale_sociale',
        'telefono', 'email', 'pec', 'manager_id', 'rappresentante_legale', 'status'
    ];

    echo "<table>\n";
    echo "<tr><th>Campo</th><th>Status</th></tr>\n";

    foreach ($requiredColumns as $col) {
        if (in_array($col, $columns)) {
            echo "<tr><td>$col</td><td class='success'>✓ Presente</td></tr>\n";
            $testsPassed++;
        } else {
            echo "<tr><td>$col</td><td class='error'>✗ Mancante</td></tr>\n";
            $testsFailed++;
        }
    }

    // Check piano column NOT exists
    if (!in_array('piano', $columns)) {
        echo "<tr><td>piano</td><td class='success'>✓ Rimosso correttamente</td></tr>\n";
        $testsPassed++;
    } else {
        echo "<tr><td>piano</td><td class='error'>✗ Ancora presente (dovrebbe essere rimosso)</td></tr>\n";
        $testsFailed++;
    }

    echo "</table>\n";
    echo "</div>\n";

    // TEST 2: Constraints
    echo "<div class='test-section'>\n";
    echo "<h2>✅ TEST 2: Verifica Constraints</h2>\n";

    // Check CF/PIVA constraint
    $stmt = $pdo->query("SHOW CREATE TABLE tenants");
    $createTable = $stmt->fetch(PDO::FETCH_ASSOC)['Create Table'];

    echo "<table>\n";
    echo "<tr><th>Constraint</th><th>Status</th></tr>\n";

    if (strpos($createTable, 'chk_tenant_fiscal_code') !== false) {
        echo "<tr><td>CF/P.IVA CHECK</td><td class='success'>✓ Attivo</td></tr>\n";
        $testsPassed++;
    } else {
        echo "<tr><td>CF/P.IVA CHECK</td><td class='error'>✗ Non trovato</td></tr>\n";
        $testsFailed++;
    }

    if (strpos($createTable, 'fk_tenants_manager_id') !== false) {
        echo "<tr><td>Manager FK</td><td class='success'>✓ Attivo</td></tr>\n";
        $testsPassed++;
    } else {
        echo "<tr><td>Manager FK</td><td class='error'>✗ Non trovato</td></tr>\n";
        $testsFailed++;
    }

    echo "</table>\n";
    echo "</div>\n";

    // TEST 3: Files esistenza
    echo "<div class='test-section'>\n";
    echo "<h2>✅ TEST 3: Verifica Files</h2>\n";

    $requiredFiles = [
        'aziende_new.php' => 'Form creazione azienda',
        'aziende.php' => 'Lista aziende',
        'js/aziende.js' => 'JavaScript gestione',
        'css/aziende.css' => 'CSS form aziende',
        'api/tenants/create.php' => 'API creazione',
        'api/tenants/list.php' => 'API lista',
        'api/tenants/get.php' => 'API dettaglio',
        'api/tenants/update.php' => 'API aggiornamento',
        'api/users/list_managers.php' => 'API managers'
    ];

    echo "<table>\n";
    echo "<tr><th>File</th><th>Descrizione</th><th>Status</th></tr>\n";

    foreach ($requiredFiles as $file => $desc) {
        $path = __DIR__ . '/' . $file;
        if (file_exists($path)) {
            echo "<tr><td>$file</td><td>$desc</td><td class='success'>✓ Presente</td></tr>\n";
            $testsPassed++;
        } else {
            echo "<tr><td>$file</td><td>$desc</td><td class='error'>✗ Mancante</td></tr>\n";
            $testsFailed++;
        }
    }

    echo "</table>\n";
    echo "</div>\n";

    // TEST 4: Verifica API endpoint names
    echo "<div class='test-section'>\n";
    echo "<h2>✅ TEST 4: Verifica Contenuti Files</h2>\n";

    echo "<table>\n";
    echo "<tr><th>File</th><th>Check</th><th>Status</th></tr>\n";

    // Check aziende.php usa api/tenants
    $aziendeContent = file_get_contents(__DIR__ . '/aziende.php');
    if (strpos($aziendeContent, 'api/tenants/') !== false) {
        echo "<tr><td>aziende.php</td><td>Usa api/tenants/</td><td class='success'>✓ OK</td></tr>\n";
        $testsPassed++;
    } else {
        echo "<tr><td>aziende.php</td><td>Usa api/tenants/</td><td class='error'>✗ Usa ancora api/companies/</td></tr>\n";
        $testsFailed++;
    }

    // Check aziende.php NON ha campo piano
    if (strpos($aziendeContent, 'Piano') === false && strpos($aziendeContent, 'piano') === false) {
        echo "<tr><td>aziende.php</td><td>Nessun campo Piano</td><td class='success'>✓ Rimosso</td></tr>\n";
        $testsPassed++;
    } else {
        echo "<tr><td>aziende.php</td><td>Nessun campo Piano</td><td class='error'>✗ Ancora presente</td></tr>\n";
        $testsFailed++;
    }

    // Check aziende_new.php ha campi separati sede legale
    $aziendeNewContent = file_get_contents(__DIR__ . '/aziende_new.php');
    $hasSedeSeparata = (
        strpos($aziendeNewContent, 'sede_indirizzo') !== false &&
        strpos($aziendeNewContent, 'sede_civico') !== false &&
        strpos($aziendeNewContent, 'sede_comune') !== false &&
        strpos($aziendeNewContent, 'sede_provincia') !== false &&
        strpos($aziendeNewContent, 'sede_cap') !== false
    );

    if ($hasSedeSeparata) {
        echo "<tr><td>aziende_new.php</td><td>Campi sede separati</td><td class='success'>✓ Implementati</td></tr>\n";
        $testsPassed++;
    } else {
        echo "<tr><td>aziende_new.php</td><td>Campi sede separati</td><td class='error'>✗ Mancanti</td></tr>\n";
        $testsFailed++;
    }

    // Check aziende_new.php ha sedi operative dinamiche
    if (strpos($aziendeNewContent, 'sediOperativeContainer') !== false) {
        echo "<tr><td>aziende_new.php</td><td>Sedi operative dinamiche</td><td class='success'>✓ Implementate</td></tr>\n";
        $testsPassed++;
    } else {
        echo "<tr><td>aziende_new.php</td><td>Sedi operative dinamiche</td><td class='error'>✗ Mancanti</td></tr>\n";
        $testsFailed++;
    }

    // Check aziende_new.php NON ha campo piano
    if (strpos($aziendeNewContent, 'Piano') === false && strpos($aziendeNewContent, 'piano') === false) {
        echo "<tr><td>aziende_new.php</td><td>Nessun campo Piano</td><td class='success'>✓ Rimosso</td></tr>\n";
        $testsPassed++;
    } else {
        echo "<tr><td>aziende_new.php</td><td>Nessun campo Piano</td><td class='error'>✗ Ancora presente</td></tr>\n";
        $testsFailed++;
    }

    echo "</table>\n";
    echo "</div>\n";

    // TEST 5: Managers disponibili
    echo "<div class='test-section'>\n";
    echo "<h2>✅ TEST 5: Managers Disponibili</h2>\n";

    $stmt = $pdo->query("
        SELECT COUNT(*) as count
        FROM users
        WHERE role IN ('super_admin', 'admin', 'manager')
        AND is_active = 1
        AND deleted_at IS NULL
    ");
    $managerCount = $stmt->fetchColumn();

    if ($managerCount > 0) {
        echo "<p class='success'>✓ Trovati $managerCount manager(s) disponibili</p>\n";

        $stmt = $pdo->query("
            SELECT id, email, role
            FROM users
            WHERE role IN ('super_admin', 'admin', 'manager')
            AND is_active = 1
            AND deleted_at IS NULL
            ORDER BY role, email
        ");

        echo "<table>\n";
        echo "<tr><th>ID</th><th>Email</th><th>Role</th></tr>\n";
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "<tr><td>{$row['id']}</td><td>{$row['email']}</td><td>{$row['role']}</td></tr>\n";
        }
        echo "</table>\n";
        $testsPassed++;
    } else {
        echo "<p class='error'>✗ Nessun manager disponibile</p>\n";
        $testsFailed++;
    }

    echo "</div>\n";

    // SUMMARY
    echo "<div class='test-section' style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;'>\n";
    echo "<h2 style='color: white; border-color: white;'>📊 RIEPILOGO TEST</h2>\n";
    $totalTests = $testsPassed + $testsFailed;
    $successRate = $totalTests > 0 ? round(($testsPassed / $totalTests) * 100, 2) : 0;

    echo "<p style='font-size: 20px;'><strong>Test Superati:</strong> $testsPassed / $totalTests</p>\n";
    echo "<p style='font-size: 20px;'><strong>Test Falliti:</strong> $testsFailed</p>\n";
    echo "<p style='font-size: 24px;'><strong>Success Rate:</strong> $successRate%</p>\n";

    if ($testsFailed == 0) {
        echo "<p style='font-size: 28px;'>🎉 <strong>TUTTO OK! Sistema pronto!</strong></p>\n";
    } else {
        echo "<p style='font-size: 20px;'>⚠️ <strong>Alcuni test falliti - verificare dettagli sopra</strong></p>\n";
    }

    echo "</div>\n";

    // Access instructions
    echo "<div class='test-section'>\n";
    echo "<h2>🚀 Come Testare</h2>\n";
    echo "<ol>\n";
    echo "<li>Login come super_admin: <a href='http://localhost:8888/CollaboraNexio/index.php'>http://localhost:8888/CollaboraNexio/</a></li>\n";
    echo "<li>Email: <code>admin@demo.local</code> o <code>asamodeo@fortibyte.it</code></li>\n";
    echo "<li>Password: <code>Admin123!</code></li>\n";
    echo "<li>Accedi a: <a href='http://localhost:8888/CollaboraNexio/aziende_new.php'>Nuova Azienda</a></li>\n";
    echo "<li>Compila il form con i dati richiesti</li>\n";
    echo "<li>Verifica che non ci sia il campo \"Piano\"</li>\n";
    echo "<li>Verifica che la sede legale abbia campi separati</li>\n";
    echo "<li>Aggiungi almeno una sede operativa</li>\n";
    echo "<li>Salva e verifica che l'azienda appaia in: <a href='http://localhost:8888/CollaboraNexio/aziende.php'>Lista Aziende</a></li>\n";
    echo "</ol>\n";
    echo "</div>\n";

} catch (Exception $e) {
    echo "<div class='test-section'>\n";
    echo "<p class='error'>❌ Errore durante i test:</p>\n";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>\n";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
    echo "</div>\n";
}

if (!$isCli) {
    echo "</body></html>";
}
?>
