<?php
/**
 * Test semplicissimo per creare un utente
 * Mostra esattamente dove si verifica l'errore
 */

// Mostra tutti gli errori
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Test Creazione Utente Semplificato</h1>";
echo "<pre>";

try {
    echo "1. Caricamento config.php...\n";
    require_once __DIR__ . '/config.php';
    echo "   ✓ Config caricato\n\n";

    echo "2. Caricamento db.php...\n";
    require_once __DIR__ . '/includes/db.php';
    echo "   ✓ DB class caricato\n\n";

    echo "3. Connessione database...\n";
    $db = Database::getInstance();
    $conn = $db->getConnection();
    echo "   ✓ Connesso\n\n";

    echo "4. Verifica struttura tabella users...\n";
    $stmt = $conn->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "   Colonne trovate: " . implode(", ", $columns) . "\n\n";

    echo "5. Verifica tenant_id 1 esiste...\n";
    $stmt = $conn->prepare("SELECT id, name FROM tenants WHERE id = 1");
    $stmt->execute();
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tenant) {
        echo "   ✓ Tenant trovato: {$tenant['name']}\n\n";
    } else {
        throw new Exception("Tenant 1 non trovato!");
    }

    echo "6. Preparazione dati utente test...\n";
    $testEmail = 'test_' . time() . '@example.com';
    $testData = [
        'tenant_id' => 1,
        'name' => 'Test User',
        'email' => $testEmail,
        'password_hash' => password_hash('TempPassword123!', PASSWORD_DEFAULT),
        'role' => 'user',
        'is_active' => 1,
        'first_login' => 1,
        'created_at' => date('Y-m-d H:i:s')
    ];
    echo "   Email test: $testEmail\n\n";

    echo "7. Tentativo inserimento...\n";
    $sql = "INSERT INTO users (tenant_id, name, email, password_hash, role, is_active, first_login, created_at)
            VALUES (:tenant_id, :name, :email, :password_hash, :role, :is_active, :first_login, :created_at)";

    $stmt = $conn->prepare($sql);
    $result = $stmt->execute($testData);

    if ($result) {
        $userId = $conn->lastInsertId();
        echo "   ✓ Utente creato con ID: $userId\n\n";

        // Verifica
        echo "8. Verifica utente creato...\n";
        $stmt = $conn->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        print_r($user);
        echo "\n";

        // Pulizia
        echo "9. Pulizia (elimina utente test)...\n";
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        echo "   ✓ Utente test eliminato\n\n";

        echo "<span style='color: green; font-weight: bold;'>✅ TEST COMPLETATO CON SUCCESSO!</span>\n";
        echo "\nIl database funziona correttamente.\n";
        echo "Il problema deve essere nell'API create_v2.php o create_v3.php\n";

    } else {
        echo "   ✗ Inserimento fallito\n";
        print_r($stmt->errorInfo());
    }

} catch (Exception $e) {
    echo "\n<span style='color: red; font-weight: bold;'>❌ ERRORE:</span>\n";
    echo $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString();
}

echo "</pre>";

echo "<hr>";
echo "<h2>Prossimi Passi</h2>";
echo "<p>Se questo test ha successo, il problema è nell'API. Controlla:</p>";
echo "<ul>";
echo "<li>Sessione non inizializzata correttamente</li>";
echo "<li>CSRF token validation</li>";
echo "<li>Classe EmailSender con errori</li>";
echo "<li>Output prima del JSON (warning/notice PHP)</li>";
echo "</ul>";
?>
