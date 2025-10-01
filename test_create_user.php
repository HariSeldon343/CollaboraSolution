<?php
/**
 * Script di test per verificare la creazione utenti via API
 * Testa l'API create_v2.php simulando una richiesta AJAX
 */

session_start();

// Simula un utente admin autenticato
$_SESSION['user_id'] = 1;
$_SESSION['tenant_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

echo "=== TEST CREAZIONE UTENTE ===\n\n";

// Test data
$test_user = [
    'first_name' => 'Test',
    'last_name' => 'User_' . time(),
    'email' => 'test.user.' . time() . '@example.com',
    'role' => 'user',
    'tenant_id' => 1,
    'csrf_token' => $_SESSION['csrf_token']
];

echo "Dati utente di test:\n";
echo "- Nome: {$test_user['first_name']} {$test_user['last_name']}\n";
echo "- Email: {$test_user['email']}\n";
echo "- Ruolo: {$test_user['role']}\n";
echo "- Tenant ID: {$test_user['tenant_id']}\n\n";

// Prepara la richiesta cURL
$ch = curl_init();
$url = 'http://localhost:8888/CollaboraNexio/api/users/create_v2.php';

curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($test_user));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-CSRF-Token: ' . $_SESSION['csrf_token']
]);

// Usa i cookie della sessione
curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookie.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookie.txt');
curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . session_id());

echo "Invio richiesta a: $url\n";

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

echo "\n=== RISPOSTA ===\n";
echo "HTTP Code: $http_code\n";

if ($error) {
    echo "cURL Error: $error\n";
} else {
    echo "Response:\n";
    $decoded = json_decode($response, true);
    if ($decoded) {
        print_r($decoded);
    } else {
        echo $response . "\n";
    }
}

// Test diretto del database
echo "\n=== VERIFICA DATABASE ===\n";
require_once __DIR__ . '/includes/db.php';

try {
    $db = Database::getInstance();

    // Verifica che la tabella audit_logs esista
    $tables = $db->query("SHOW TABLES LIKE 'audit_logs'");
    if ($tables && count($tables) > 0) {
        echo "✓ Tabella audit_logs esiste\n";
    } else {
        echo "✗ Tabella audit_logs NON esiste\n";
    }

    // Verifica che la tabella activity_logs NON esista (era l'errore)
    $old_tables = $db->query("SHOW TABLES LIKE 'activity_logs'");
    if ($old_tables && count($old_tables) > 0) {
        echo "⚠️ Tabella activity_logs esiste ancora (dovrebbe essere audit_logs)\n";
    } else {
        echo "✓ Tabella activity_logs non esiste (corretto)\n";
    }

    // Verifica l'ultimo utente creato
    $last_user = $db->fetchOne(
        "SELECT * FROM users WHERE email = :email",
        [':email' => $test_user['email']]
    );

    if ($last_user) {
        echo "\n✓ Utente creato nel database:\n";
        echo "  - ID: {$last_user['id']}\n";
        echo "  - Nome: {$last_user['first_name']} {$last_user['last_name']}\n";
        echo "  - Email: {$last_user['email']}\n";
        echo "  - Ruolo: {$last_user['role']}\n";
        echo "  - Token Reset: " . (isset($last_user['password_reset_token']) ? 'Presente' : 'Assente') . "\n";
        echo "  - First Login: " . ($last_user['first_login'] ?? 'N/D') . "\n";
    } else {
        echo "✗ Utente non trovato nel database\n";
    }

} catch (Exception $e) {
    echo "Errore database: " . $e->getMessage() . "\n";
}

echo "\n=== TEST COMPLETATO ===\n";
?>