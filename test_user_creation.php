<?php
/**
 * Script di test per la creazione utenti
 * Verifica che l'API gestisca correttamente tutti i ruoli
 */

// Inizializza sessione e autenticazione
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/db.php';

$auth = new Auth();

// Simula login come admin per i test
$_SESSION['user_id'] = 1; // Admin user
$_SESSION['tenant_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Funzione per simulare richiesta POST
function testCreateUser($data, $description) {
    echo "\n<h3>Test: $description</h3>\n";

    // Aggiungi CSRF token
    $data['csrf_token'] = $_SESSION['csrf_token'];

    // Simula richiesta POST
    $_POST = $data;
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // Cattura output dell'API
    ob_start();
    include __DIR__ . '/api/users/create_simple.php';
    $output = ob_get_clean();

    // Decodifica risposta
    $response = json_decode($output, true);

    // Mostra risultato
    if ($response['success']) {
        echo "<div style='color: green;'>✓ Successo: " . $response['message'] . "</div>";
        if (isset($response['data'])) {
            echo "<pre>User ID: " . $response['data']['id'] .
                 "\nEmail: " . $response['data']['email'] .
                 "\nRole: " . $response['data']['role'] . "</pre>";
        }
    } else {
        echo "<div style='color: red;'>✗ Errore: " . ($response['error'] ?? 'Errore sconosciuto') . "</div>";
    }

    echo "<pre>Response: " . json_encode($response, JSON_PRETTY_PRINT) . "</pre>";

    // Reset POST
    $_POST = [];

    return $response;
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Test Creazione Utenti</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h3 { color: #333; margin-top: 30px; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
        .success { color: green; }
        .error { color: red; }
    </style>
</head>
<body>
    <h1>Test API Creazione Utenti</h1>

    <?php
    // Genera timestamp unico per evitare conflitti email
    $timestamp = time();

    // Test 1: Creazione utente normale (user) con tenant_id
    echo "<hr>";
    $result1 = testCreateUser([
        'first_name' => 'Test',
        'last_name' => 'User' . $timestamp,
        'email' => 'testuser' . $timestamp . '@test.local',
        'role' => 'user',
        'tenant_id' => '1'
    ], "Creazione User normale con tenant_id");

    // Test 2: Creazione manager con tenant_id
    echo "<hr>";
    $result2 = testCreateUser([
        'first_name' => 'Test',
        'last_name' => 'Manager' . $timestamp,
        'email' => 'testmanager' . $timestamp . '@test.local',
        'role' => 'manager',
        'tenant_id' => '1'
    ], "Creazione Manager con tenant_id");

    // Test 3: Creazione admin con tenant multipli
    echo "<hr>";
    $result3 = testCreateUser([
        'first_name' => 'Test',
        'last_name' => 'Admin' . $timestamp,
        'email' => 'testadmin' . $timestamp . '@test.local',
        'role' => 'admin',
        'tenant_ids' => ['1', '2'] // Array di tenant
    ], "Creazione Admin con tenant multipli");

    // Test 4: Creazione super_admin senza tenant_id
    echo "<hr>";
    $result4 = testCreateUser([
        'first_name' => 'Test',
        'last_name' => 'SuperAdmin' . $timestamp,
        'email' => 'testsuperadmin' . $timestamp . '@test.local',
        'role' => 'super_admin'
        // Nessun tenant_id richiesto
    ], "Creazione Super Admin senza tenant_id");

    // Test 5: Errore - User senza tenant_id
    echo "<hr>";
    $result5 = testCreateUser([
        'first_name' => 'Test',
        'last_name' => 'ErrorUser',
        'email' => 'testerror' . $timestamp . '@test.local',
        'role' => 'user'
        // Manca tenant_id - dovrebbe dare errore
    ], "Errore atteso: User senza tenant_id");

    // Test 6: Errore - Admin senza tenant_ids
    echo "<hr>";
    $result6 = testCreateUser([
        'first_name' => 'Test',
        'last_name' => 'ErrorAdmin',
        'email' => 'testerroradmin' . $timestamp . '@test.local',
        'role' => 'admin'
        // Manca tenant_ids - dovrebbe dare errore
    ], "Errore atteso: Admin senza tenant_ids");

    // Pulizia test users creati (opzionale)
    echo "<hr><h3>Pulizia dati di test</h3>";

    $db = Database::getInstance();
    $conn = $db->getConnection();

    try {
        // Conta utenti di test creati
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE email LIKE ?");
        $stmt->execute(['test%' . $timestamp . '@test.local']);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        echo "<p>Utenti di test creati in questa sessione: $count</p>";

        // Opzione per pulire
        if (isset($_GET['cleanup']) && $_GET['cleanup'] === 'yes') {
            $stmt = $conn->prepare("DELETE FROM users WHERE email LIKE ?");
            $stmt->execute(['test%' . $timestamp . '@test.local']);
            echo "<p style='color: green;'>✓ Utenti di test rimossi</p>";
        } else {
            echo "<p><a href='?cleanup=yes'>Clicca qui per rimuovere gli utenti di test</a></p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Errore nella pulizia: " . $e->getMessage() . "</p>";
    }
    ?>

    <hr>
    <p><a href="/CollaboraNexio/utenti.php">Torna a Gestione Utenti</a></p>
</body>
</html>