<?php
/**
 * Endpoint di test semplificato per verificare funzionalità base
 */

// Test 1: Headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Test 2: Session
require_once dirname(__DIR__) . '/includes/session_init.php';

// Test 3: Config
require_once dirname(__DIR__) . '/config.php';

// Test 4: Database
require_once dirname(__DIR__) . '/includes/db.php';

// Simuliamo un utente autenticato per test
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['tenant_id'] = 1;
    $_SESSION['role'] = 'admin';
}

try {
    // Test connessione database
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Query di test per verificare che la tabella files esista
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'files'");
    $stmt->execute();
    $tableExists = $stmt->fetch() !== false;

    if (!$tableExists) {
        // Crea la tabella files se non esiste
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
        )";
        $pdo->exec($createTableSQL);

        echo json_encode([
            'success' => true,
            'message' => 'Tabella files creata con successo',
            'data' => []
        ]);
    } else {
        // Recupera alcuni file di test
        $tenant_id = $_SESSION['tenant_id'];
        $stmt = $pdo->prepare("
            SELECT * FROM files
            WHERE tenant_id = :tenant_id
            AND deleted_at IS NULL
            LIMIT 10
        ");
        $stmt->execute([':tenant_id' => $tenant_id]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Test endpoint funzionante',
            'data' => $files,
            'session' => [
                'user_id' => $_SESSION['user_id'],
                'tenant_id' => $_SESSION['tenant_id'],
                'role' => $_SESSION['role']
            ]
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Errore del server',
        'details' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}