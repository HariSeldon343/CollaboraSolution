<?php
/**
 * Script di test CLI per verificare l'API files
 */

echo "=== TEST API FILES ===\n\n";

// Test 1: Includi i file necessari
echo "1. Test inclusione file...\n";
try {
    require_once __DIR__ . '/includes/session_init.php';
    echo "   ✓ session_init.php caricato\n";

    require_once __DIR__ . '/config.php';
    echo "   ✓ config.php caricato\n";

    require_once __DIR__ . '/includes/db.php';
    echo "   ✓ db.php caricato\n";
} catch (Exception $e) {
    echo "   ✗ Errore: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Connessione database
echo "\n2. Test connessione database...\n";
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    echo "   ✓ Connessione al database stabilita\n";

    // Verifica versione MySQL
    $version = $pdo->query("SELECT VERSION()")->fetchColumn();
    echo "   ✓ MySQL version: " . $version . "\n";
} catch (Exception $e) {
    echo "   ✗ Errore connessione: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Verifica tabella files
echo "\n3. Verifica tabella files...\n";
try {
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'files'");
    $stmt->execute();
    $tableExists = $stmt->fetch() !== false;

    if (!$tableExists) {
        echo "   ! Tabella files non esiste, creazione in corso...\n";

        $createTableSQL = "
        CREATE TABLE IF NOT EXISTS files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500),
            file_type VARCHAR(50),
            file_size BIGINT,
            mime_type VARCHAR(100),
            folder_id INT NULL,
            is_folder TINYINT DEFAULT 0,
            uploaded_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP NULL,
            INDEX idx_tenant (tenant_id),
            INDEX idx_folder (folder_id),
            INDEX idx_deleted (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $pdo->exec($createTableSQL);
        echo "   ✓ Tabella files creata con successo\n";
    } else {
        echo "   ✓ Tabella files esiste\n";

        // Mostra struttura tabella
        $stmt = $pdo->prepare("DESCRIBE files");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "   Colonne trovate:\n";
        foreach ($columns as $col) {
            echo "     - " . $col['Field'] . " (" . $col['Type'] . ")\n";
        }
    }
} catch (Exception $e) {
    echo "   ✗ Errore tabella: " . $e->getMessage() . "\n";
}

// Test 4: Simula sessione utente
echo "\n4. Test sessione utente...\n";
$_SESSION['user_id'] = 1;
$_SESSION['tenant_id'] = 1;
$_SESSION['role'] = 'admin';
echo "   ✓ Sessione configurata (user_id=1, tenant_id=1, role=admin)\n";

// Test 5: Query di esempio
echo "\n5. Test query files...\n";
try {
    $tenant_id = $_SESSION['tenant_id'];

    // Conta files
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM files WHERE tenant_id = :tenant_id AND deleted_at IS NULL");
    $stmt->execute([':tenant_id' => $tenant_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "   Files totali per tenant $tenant_id: " . $result['total'] . "\n";

    // Recupera alcuni files
    $stmt = $pdo->prepare("
        SELECT id, name, file_type, is_folder, created_at
        FROM files
        WHERE tenant_id = :tenant_id
        AND deleted_at IS NULL
        LIMIT 5
    ");
    $stmt->execute([':tenant_id' => $tenant_id]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($files) > 0) {
        echo "   Primi 5 files:\n";
        foreach ($files as $file) {
            $type = $file['is_folder'] ? '[FOLDER]' : '[FILE]';
            echo "     - $type " . $file['name'] . " (created: " . $file['created_at'] . ")\n";
        }
    } else {
        echo "   Nessun file trovato per il tenant\n";

        // Inserisci file di esempio
        echo "\n   Creazione file di esempio...\n";
        $stmt = $pdo->prepare("
            INSERT INTO files (tenant_id, name, file_path, file_type, is_folder, uploaded_by, created_at)
            VALUES
                (:tenant_id, 'Documenti', '/documenti', null, 1, 1, NOW()),
                (:tenant_id2, 'Immagini', '/immagini', null, 1, 1, NOW()),
                (:tenant_id3, 'Report.pdf', '/files/report.pdf', 'pdf', 0, 1, NOW())
        ");
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':tenant_id2' => $tenant_id,
            ':tenant_id3' => $tenant_id
        ]);
        echo "   ✓ File di esempio creati\n";
    }
} catch (Exception $e) {
    echo "   ✗ Errore query: " . $e->getMessage() . "\n";
}

// Test 6: Test endpoint files.php
echo "\n6. Test inclusione api/files.php...\n";
try {
    // Simula richiesta GET
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['action'] = '';
    $_GET['folder_id'] = '';

    // Cattura output
    ob_start();

    // Previeni die() catturando l'output
    $includeError = false;
    try {
        // Non possiamo includere direttamente perché potrebbe chiamare die()
        // Quindi verifichiamo solo che il file esista e sia leggibile
        $apiFile = __DIR__ . '/api/files.php';
        if (file_exists($apiFile)) {
            echo "   ✓ File api/files.php esiste\n";

            // Verifica sintassi PHP
            $output = shell_exec("php -l " . escapeshellarg($apiFile) . " 2>&1");
            if (strpos($output, 'No syntax errors') !== false) {
                echo "   ✓ Sintassi PHP corretta\n";
            } else {
                echo "   ✗ Errori di sintassi: $output\n";
            }
        } else {
            echo "   ✗ File api/files.php non trovato\n";
        }
    } catch (Exception $e) {
        $includeError = true;
        echo "   ✗ Errore: " . $e->getMessage() . "\n";
    }

    ob_end_clean();

} catch (Exception $e) {
    echo "   ✗ Errore test endpoint: " . $e->getMessage() . "\n";
}

echo "\n=== TEST COMPLETATO ===\n";
echo "\nPer testare l'API via HTTP:\n";
echo "1. Assicurati che Apache sia in esecuzione\n";
echo "2. Apri il browser e vai a: http://localhost/CollaboraNexio/api/test_files.php\n";
echo "3. Oppure usa: curl http://localhost/CollaboraNexio/api/test_files.php\n";