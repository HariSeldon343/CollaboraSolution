<?php
/**
 * Script per pulire la sessione durante il testing
 * NOTA: Rimuovere questo file in produzione
 */

// Inizializza sessione
require_once __DIR__ . '/includes/session_init.php';

// Salva informazioni per il log
$sessionInfo = [
    'session_id' => session_id(),
    'session_name' => session_name(),
    'had_user' => isset($_SESSION['user_id']),
    'environment' => $_SERVER['HTTP_HOST'] ?? 'unknown'
];

// Pulisci tutte le variabili di sessione
$_SESSION = [];

// Invalida il cookie di sessione
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    // Determina il dominio del cookie basato sull'ambiente
    $cookieDomain = $params['domain'];
    if (empty($cookieDomain)) {
        $currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (strpos($currentHost, 'nexiosolution.it') !== false) {
            $cookieDomain = '.nexiosolution.it';
        }
    }

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $cookieDomain,
        $params['secure'],
        $params['httponly']
    );
}

// Distruggi la sessione
session_destroy();

// Output
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'Session cleared successfully',
    'previous_session' => $sessionInfo,
    'cookie_domain' => $cookieDomain ?? 'not set'
], JSON_PRETTY_PRINT);