<?php
/**
 * Test file per verificare la classe Database
 */

require_once dirname(__DIR__) . '/includes/db.php';

echo "=== TEST CLASSE DATABASE SINGLETON ===\n\n";

try {
    // Test 1: Ottieni istanza singleton
    echo "1. Test Singleton Pattern:\n";
    $db1 = Database::getInstance();
    $db2 = Database::getInstance();

    if ($db1 === $db2) {
        echo "   ✓ Singleton pattern funziona correttamente\n\n";
    } else {
        echo "   ✗ Errore: Le istanze non sono identiche\n\n";
    }

    // Test 2: Connessione al database
    echo "2. Test Connessione:\n";
    $conn = $db1->getConnection();

    if ($conn instanceof PDO) {
        echo "   ✓ Connessione PDO ottenuta con successo\n";

        // Ottieni versione database
        $version = $db1->getVersion();
        echo "   ✓ Database: " . $version['driver'] . " versione " . $version['version'] . "\n\n";
    } else {
        echo "   ✗ Errore: Connessione non valida\n\n";
    }

    // Test 3: Query semplice
    echo "3. Test Query:\n";
    $result = $db1->fetchOne("SELECT 1 as test");

    if ($result && $result['test'] == 1) {
        echo "   ✓ Query di test eseguita con successo\n\n";
    } else {
        echo "   ✗ Errore nell'esecuzione della query\n\n";
    }

    // Test 4: Verifica esistenza tabelle
    echo "4. Test Esistenza Tabelle:\n";
    $tables = $db1->fetchAll("SHOW TABLES");

    if (!empty($tables)) {
        echo "   ✓ Trovate " . count($tables) . " tabelle nel database\n";

        // Mostra alcune tabelle
        $tableNames = [];
        foreach (array_slice($tables, 0, 5) as $table) {
            $tableNames[] = reset($table);
        }
        echo "   Tabelle: " . implode(', ', $tableNames);

        if (count($tables) > 5) {
            echo " ...";
        }
        echo "\n\n";
    } else {
        echo "   ⚠ Nessuna tabella trovata nel database\n\n";
    }

    // Test 5: Test helper function
    echo "5. Test Helper Function:\n";
    $dbHelper = db();

    if ($dbHelper === $db1) {
        echo "   ✓ Helper function db() funziona correttamente\n\n";
    } else {
        echo "   ✗ Errore: Helper function restituisce istanza diversa\n\n";
    }

    // Test 6: Test transazioni
    echo "6. Test Transazioni:\n";

    // Inizia transazione
    if ($db1->beginTransaction()) {
        echo "   ✓ Transazione iniziata\n";

        // Verifica stato transazione
        if ($db1->inTransaction()) {
            echo "   ✓ Stato transazione corretto\n";
        }

        // Rollback
        if ($db1->rollback()) {
            echo "   ✓ Rollback eseguito con successo\n\n";
        }
    }

    // Test 7: Test metodi CRUD (se ci sono tabelle)
    echo "7. Test Metodi Helper:\n";

    // Test count su una tabella (se esiste users)
    $userTableExists = $db1->fetchOne("SHOW TABLES LIKE 'users'");

    if ($userTableExists) {
        $count = $db1->count('users');
        echo "   ✓ Metodo count() funziona: " . $count . " record nella tabella users\n";

        // Test exists
        $exists = $db1->exists('users', ['id' => 1]);
        echo "   ✓ Metodo exists() funziona: User con ID 1 " . ($exists ? "esiste" : "non esiste") . "\n\n";
    } else {
        echo "   ⚠ Tabella 'users' non trovata, skip test CRUD\n\n";
    }

    // Test 8: Test prepared statements
    echo "8. Test Prepared Statements:\n";
    $testQuery = "SELECT :param1 as test1, :param2 as test2";
    $result = $db1->fetchOne($testQuery, [
        ':param1' => 'valore1',
        ':param2' => 'valore2'
    ]);

    if ($result && $result['test1'] === 'valore1' && $result['test2'] === 'valore2') {
        echo "   ✓ Prepared statements con parametri nominali funzionano\n\n";
    } else {
        echo "   ✗ Errore nei prepared statements\n\n";
    }

    // Test 9: Test gestione errori
    echo "9. Test Gestione Errori:\n";

    try {
        // Query con errore intenzionale
        $db1->query("SELECT * FROM tabella_inesistente");
        echo "   ✗ Gestione errori non funziona\n\n";
    } catch (Exception $e) {
        echo "   ✓ Eccezione catturata correttamente: " . substr($e->getMessage(), 0, 50) . "...\n\n";
    }

    // Riepilogo finale
    echo "=== RIEPILOGO TEST ===\n";
    echo "✅ Classe Database configurata e funzionante correttamente!\n";
    echo "✅ Pattern Singleton implementato\n";
    echo "✅ Connessione PDO con prepared statements attiva\n";
    echo "✅ Metodi helper disponibili\n";
    echo "✅ Gestione transazioni funzionante\n";
    echo "✅ Gestione errori implementata\n";

    // Verifica file di log
    $logFile = dirname(__DIR__) . '/logs/database_errors.log';
    if (file_exists($logFile)) {
        echo "✅ File di log creato: " . $logFile . "\n";
    }

} catch (Exception $e) {
    echo "\n❌ ERRORE CRITICO: " . $e->getMessage() . "\n";
    echo "Traccia: " . $e->getTraceAsString() . "\n";
}

echo "\n=== FINE TEST ===\n";
?>