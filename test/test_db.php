<?php
/**
 * Test della classe Database per CollaboraNexio
 *
 * Questo file verifica il corretto funzionamento della classe Database singleton
 * e testa le varie funzionalità implementate
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 */

// Abilita la visualizzazione degli errori per il testing
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include la classe Database
require_once __DIR__ . '/../includes/db.php';

// Definisci un flag per output colorato (se supportato dal terminale)
$useColors = PHP_SAPI === 'cli' && !isset($_SERVER['NO_COLOR']);

/**
 * Stampa un messaggio di test con formattazione
 *
 * @param string $message Il messaggio da stampare
 * @param string $status Lo stato del test (PASS, FAIL, INFO, WARNING)
 */
function printTestResult(string $message, string $status = 'INFO'): void {
    global $useColors;

    $colors = [
        'PASS' => "\033[0;32m",    // Verde
        'FAIL' => "\033[0;31m",    // Rosso
        'INFO' => "\033[0;36m",    // Ciano
        'WARNING' => "\033[0;33m", // Giallo
        'RESET' => "\033[0m"        // Reset
    ];

    $symbol = match($status) {
        'PASS' => '✓',
        'FAIL' => '✗',
        'INFO' => 'ℹ',
        'WARNING' => '⚠',
        default => '•'
    };

    if ($useColors && isset($colors[$status])) {
        echo $colors[$status] . "[{$symbol}] {$message}" . $colors['RESET'] . PHP_EOL;
    } else {
        echo "[{$status}] {$message}" . PHP_EOL;
    }
}

/**
 * Esegue un test e gestisce le eccezioni
 *
 * @param string $testName Nome del test
 * @param callable $testFunction Funzione che esegue il test
 * @return bool True se il test passa, false altrimenti
 */
function runTest(string $testName, callable $testFunction): bool {
    try {
        printTestResult("Esecuzione test: {$testName}", 'INFO');
        $result = $testFunction();

        if ($result === true) {
            printTestResult("{$testName} completato con successo", 'PASS');
            return true;
        } else {
            printTestResult("{$testName} fallito", 'FAIL');
            return false;
        }
    } catch (Exception $e) {
        printTestResult("{$testName} ha generato un'eccezione: " . $e->getMessage(), 'FAIL');
        return false;
    }
}

// Intestazione del test
echo PHP_EOL;
echo "=====================================\n";
echo "   TEST DATABASE CLASS - CollaboraNexio\n";
echo "=====================================\n";
echo PHP_EOL;

$testsPassed = 0;
$testsFailed = 0;

// Test 1: Verifica singleton pattern
runTest("Pattern Singleton", function() {
    $db1 = Database::getInstance();
    $db2 = Database::getInstance();

    if ($db1 === $db2) {
        printTestResult("Le istanze sono identiche (singleton funzionante)", 'INFO');
        return true;
    }
    return false;
}) ? $testsPassed++ : $testsFailed++;

echo PHP_EOL;

// Test 2: Verifica connessione al database
runTest("Connessione Database", function() {
    $db = Database::getInstance();

    if ($db->isConnected()) {
        printTestResult("Connessione al database stabilita", 'INFO');

        // Mostra informazioni sulla connessione
        $stats = $db->getConnectionStats();
        printTestResult("Driver: " . $stats['driver'], 'INFO');
        printTestResult("Versione server: " . $stats['server_version'], 'INFO');
        printTestResult("Tentativi di connessione: " . $stats['connection_attempts'], 'INFO');

        return true;
    }
    return false;
}) ? $testsPassed++ : $testsFailed++;

echo PHP_EOL;

// Test 3: Test query semplice
runTest("Query SELECT semplice", function() {
    $db = Database::getInstance();

    // Esegue una query di test con sintassi compatibile
    $sql = "SELECT 1 as test_value";
    $result = $db->fetchOne($sql);

    if ($result && isset($result['test_value']) && $result['test_value'] == 1) {
        printTestResult("Query eseguita correttamente", 'INFO');
        return true;
    }
    return false;
}) ? $testsPassed++ : $testsFailed++;

echo PHP_EOL;

// Test 4: Test prepared statements
runTest("Prepared Statements", function() {
    $db = Database::getInstance();

    // Test con parametri nominali
    $sql = "SELECT :value1 as val1, :value2 as val2";
    $params = [
        ':value1' => 'test1',
        ':value2' => 'test2'
    ];

    $result = $db->fetchOne($sql, $params);

    if ($result && $result['val1'] === 'test1' && $result['val2'] === 'test2') {
        printTestResult("Parametri nominali funzionanti", 'INFO');

        // Test con parametri posizionali
        $sql2 = "SELECT ? as val1, ? as val2";
        $params2 = ['test3', 'test4'];

        $result2 = $db->fetchOne($sql2, $params2);

        if ($result2 && $result2['val1'] === 'test3' && $result2['val2'] === 'test4') {
            printTestResult("Parametri posizionali funzionanti", 'INFO');
            return true;
        }
    }
    return false;
}) ? $testsPassed++ : $testsFailed++;

echo PHP_EOL;

// Test 5: Test transazioni
runTest("Transazioni Database", function() {
    $db = Database::getInstance();

    // Verifica che non ci sia una transazione attiva
    if ($db->inTransaction()) {
        printTestResult("Transazione già attiva inaspettatamente", 'WARNING');
        return false;
    }

    // Inizia una transazione
    $db->beginTransaction();

    if (!$db->inTransaction()) {
        printTestResult("Impossibile iniziare la transazione", 'WARNING');
        return false;
    }

    printTestResult("Transazione iniziata correttamente", 'INFO');

    // Rollback della transazione
    $db->rollback();

    if ($db->inTransaction()) {
        printTestResult("Transazione ancora attiva dopo rollback", 'WARNING');
        return false;
    }

    printTestResult("Rollback eseguito correttamente", 'INFO');

    // Test commit
    $db->beginTransaction();
    $db->commit();

    if ($db->inTransaction()) {
        printTestResult("Transazione ancora attiva dopo commit", 'WARNING');
        return false;
    }

    printTestResult("Commit eseguito correttamente", 'INFO');

    return true;
}) ? $testsPassed++ : $testsFailed++;

echo PHP_EOL;

// Test 6: Test helper functions
runTest("Funzioni Helper", function() {
    // Test funzione db()
    $db1 = db();
    $db2 = Database::getInstance();

    if ($db1 !== $db2) {
        printTestResult("La funzione db() non restituisce l'istanza corretta", 'WARNING');
        return false;
    }

    printTestResult("Funzione db() funzionante", 'INFO');

    // Test funzione query()
    $stmt = query("SELECT 'test' as value");
    $result = $stmt->fetch();

    if (!$result || $result['value'] !== 'test') {
        printTestResult("La funzione query() non funziona correttamente", 'WARNING');
        return false;
    }

    printTestResult("Funzione query() funzionante", 'INFO');

    return true;
}) ? $testsPassed++ : $testsFailed++;

echo PHP_EOL;

// Test 7: Test gestione errori
runTest("Gestione Errori", function() {
    $db = Database::getInstance();

    try {
        // Prova ad eseguire una query non valida
        $db->query("SELECT * FROM tabella_inesistente_12345");
        printTestResult("Errore non catturato correttamente", 'WARNING');
        return false;
    } catch (PDOException $e) {
        printTestResult("Eccezione PDO catturata correttamente", 'INFO');
        printTestResult("Messaggio errore: " . $e->getMessage(), 'INFO');
        return true;
    }
}) ? $testsPassed++ : $testsFailed++;

echo PHP_EOL;

// Test 8: Test metodi fetch
runTest("Metodi Fetch", function() {
    $db = Database::getInstance();

    // Test fetchColumn
    $count = $db->fetchColumn("SELECT COUNT(*) FROM (SELECT 1 UNION SELECT 2 UNION SELECT 3) as t");
    if ($count != 3) {
        printTestResult("fetchColumn non funziona correttamente", 'WARNING');
        return false;
    }
    printTestResult("fetchColumn funzionante (risultato: {$count})", 'INFO');

    // Test fetchAll
    $results = $db->fetchAll("SELECT 1 as id UNION SELECT 2 UNION SELECT 3 ORDER BY id");
    if (count($results) !== 3) {
        printTestResult("fetchAll non funziona correttamente", 'WARNING');
        return false;
    }
    printTestResult("fetchAll funzionante (" . count($results) . " righe)", 'INFO');

    return true;
}) ? $testsPassed++ : $testsFailed++;

echo PHP_EOL;

// Test 9: Verifica clonazione (deve fallire)
runTest("Prevenzione Clonazione", function() {
    $db = Database::getInstance();

    try {
        // Usa reflection per tentare di clonare (il clone diretto genera errore fatale)
        $reflection = new ReflectionClass($db);
        $cloneMethod = $reflection->getMethod('__clone');

        // Verifica che il metodo __clone sia privato
        if (!$cloneMethod->isPrivate()) {
            printTestResult("Il metodo __clone non è privato", 'WARNING');
            return false;
        }

        printTestResult("Protezione contro la clonazione attiva", 'INFO');
        return true;
    } catch (Exception $e) {
        printTestResult("Test clonazione: " . $e->getMessage(), 'INFO');
        return true;
    }
}) ? $testsPassed++ : $testsFailed++;

echo PHP_EOL;

// Riepilogo finale
echo "=====================================\n";
echo "          RIEPILOGO TEST\n";
echo "=====================================\n";
echo PHP_EOL;

$totalTests = $testsPassed + $testsFailed;
$successRate = $totalTests > 0 ? round(($testsPassed / $totalTests) * 100, 2) : 0;

printTestResult("Test completati: {$totalTests}", 'INFO');
printTestResult("Test passati: {$testsPassed}", 'PASS');
if ($testsFailed > 0) {
    printTestResult("Test falliti: {$testsFailed}", 'FAIL');
}
printTestResult("Percentuale di successo: {$successRate}%", 'INFO');

echo PHP_EOL;

// Verifica della presenza del file di log
$logFile = __DIR__ . '/../logs/database_errors.log';
if (file_exists($logFile)) {
    $logSize = filesize($logFile);
    $logSizeKB = round($logSize / 1024, 2);
    printTestResult("File di log presente ({$logSizeKB} KB)", 'INFO');

    // Mostra le ultime righe del log se esistono errori recenti
    if ($logSize > 0) {
        echo PHP_EOL;
        printTestResult("Ultime voci del log:", 'INFO');
        $logLines = file($logFile);
        $lastLines = array_slice($logLines, -5);
        foreach ($lastLines as $line) {
            echo "  " . trim($line) . PHP_EOL;
        }
    }
} else {
    printTestResult("File di log non ancora creato (nessun errore registrato)", 'INFO');
}

echo PHP_EOL;

// Codice di uscita basato sui risultati
exit($testsFailed > 0 ? 1 : 0);