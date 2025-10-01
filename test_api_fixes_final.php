<?php
/**
 * Test script per verificare le correzioni alle API
 */

session_start();
require_once 'config.php';
require_once 'includes/db.php';

// Colori per output
$green = "\033[32m";
$red = "\033[31m";
$yellow = "\033[33m";
$blue = "\033[34m";
$reset = "\033[0m";

echo "\n{$blue}=== TEST API FIXES FINALI ==={$reset}\n\n";

// 1. Test Files Tenant API
echo "{$yellow}1. Testing Files Tenant API (get_tenant_list):{$reset}\n";

// Simula un utente admin per il test
$_SESSION['user_id'] = 2; // Admin user
$_SESSION['tenant_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$ch = curl_init('http://localhost:8888/CollaboraNexio/api/files_tenant_fixed.php?action=get_tenant_list');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Requested-With: XMLHttpRequest'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: " . substr($response, 0, 200) . "\n";

$data = json_decode($response, true);
if ($httpCode == 200 && isset($data['success']) && $data['success']) {
    echo "{$green}✓ Files Tenant API funziona correttamente{$reset}\n";
    echo "  Tenant trovati: " . count($data['data'] ?? []) . "\n";
} else {
    echo "{$red}✗ Files Tenant API ha ancora problemi{$reset}\n";
    if (isset($data['error'])) {
        echo "  Errore: {$data['error']}\n";
    }
}

echo "\n";

// 2. Test Companies Delete API
echo "{$yellow}2. Testing Companies Delete API (CSRF validation):{$reset}\n";

// Simula super admin
$_SESSION['role'] = 'super_admin';

// Prima creiamo un'azienda di test
$db = Database::getInstance();
$conn = $db->getConnection();

try {
    // Crea azienda di test
    $stmt = $conn->prepare("INSERT INTO tenants (name, denominazione, is_active, created_at) VALUES (?, ?, 1, NOW())");
    $stmt->execute(['test_delete_' . time(), 'Test Delete Company']);
    $testCompanyId = $conn->lastInsertId();

    echo "Creata azienda di test ID: $testCompanyId\n";

    // Test eliminazione con CSRF token
    $postData = [
        'company_id' => $testCompanyId,
        'csrf_token' => $_SESSION['csrf_token']
    ];

    $ch = curl_init('http://localhost:8888/CollaboraNexio/api/companies/delete.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Requested-With: XMLHttpRequest'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP Code: $httpCode\n";
    echo "Response: " . substr($response, 0, 200) . "\n";

    $data = json_decode($response, true);
    if ($httpCode == 200 && isset($data['success']) && $data['success']) {
        echo "{$green}✓ Companies Delete API funziona correttamente{$reset}\n";
        echo "  Azienda eliminata con successo\n";
    } else {
        echo "{$red}✗ Companies Delete API ha ancora problemi{$reset}\n";
        if (isset($data['error'])) {
            echo "  Errore: {$data['error']}\n";
        }

        // Pulizia manuale se il test fallisce
        try {
            $stmt = $conn->prepare("DELETE FROM tenants WHERE id = ?");
            $stmt->execute([$testCompanyId]);
        } catch (Exception $e) {
            // Ignora
        }
    }

} catch (Exception $e) {
    echo "{$red}Errore nel test: " . $e->getMessage() . "{$reset}\n";
}

echo "\n";

// 3. Test con ruoli diversi
echo "{$yellow}3. Testing con ruoli diversi:{$reset}\n";

$roles = ['user', 'manager', 'admin', 'super_admin'];

foreach ($roles as $role) {
    $_SESSION['role'] = $role;

    $ch = curl_init('http://localhost:8888/CollaboraNexio/api/files_tenant_fixed.php?action=get_tenant_list');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Requested-With: XMLHttpRequest'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($role === 'admin' || $role === 'super_admin') {
        if ($httpCode == 200 && isset($data['success'])) {
            echo "{$green}✓{$reset} Ruolo $role: Accesso consentito correttamente\n";
        } else {
            echo "{$red}✗{$reset} Ruolo $role: Dovrebbe avere accesso ma ha fallito\n";
        }
    } else {
        if ($httpCode == 403 || (isset($data['error']) && strpos($data['error'], 'autorizzato') !== false)) {
            echo "{$green}✓{$reset} Ruolo $role: Accesso negato correttamente\n";
        } else {
            echo "{$red}✗{$reset} Ruolo $role: Dovrebbe essere negato ma ha avuto accesso\n";
        }
    }
}

echo "\n{$blue}=== TEST COMPLETATI ==={$reset}\n\n";

// Ripristina sessione originale
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 2;
$_SESSION['tenant_id'] = 1;

?>