<?php
/**
 * API per ottenere informazioni sulla sessione corrente
 * Utile per debugging
 */

session_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Prepara risposta
$response = [
    'authenticated' => isset($_SESSION['user_id']),
    'user_id' => $_SESSION['user_id'] ?? null,
    'tenant_id' => $_SESSION['tenant_id'] ?? null,
    'role' => $_SESSION['role'] ?? $_SESSION['user_role'] ?? null,
    'username' => $_SESSION['username'] ?? null,
    'email' => $_SESSION['email'] ?? null,
    'csrf_token_exists' => isset($_SESSION['csrf_token']),
    'session_id' => session_id(),
    'session_status' => session_status(),
    'php_version' => PHP_VERSION,
    'server_time' => date('Y-m-d H:i:s')
];

// Se autenticato, aggiungi più dettagli
if ($response['authenticated']) {
    $response['access'] = [
        'is_user' => in_array($response['role'], ['user']),
        'is_manager' => in_array($response['role'], ['manager']),
        'is_admin' => in_array($response['role'], ['admin']),
        'is_super_admin' => in_array($response['role'], ['super_admin']),
        'can_access_tenant_list' => in_array($response['role'], ['admin', 'super_admin']),
        'can_delete_companies' => in_array($response['role'], ['super_admin'])
    ];
} else {
    $response['message'] = 'Non autenticato. Effettua il login per testare le API.';
    $response['login_url'] = '/CollaboraNexio/login.php';
}

// Invia risposta
echo json_encode($response, JSON_PRETTY_PRINT);
?>