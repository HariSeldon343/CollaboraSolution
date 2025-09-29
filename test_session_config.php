<?php
/**
 * Test script per verificare la configurazione delle sessioni
 * Questo file può essere eliminato dopo il testing
 */

// Carica la configurazione
require_once __DIR__ . '/config.php';

// Inizializza la sessione usando il sistema centralizzato
require_once __DIR__ . '/includes/session_init.php';

// Ottieni informazioni sull'ambiente e sulla sessione
$info = [
    'environment' => [
        'host' => $_SERVER['HTTP_HOST'] ?? 'unknown',
        'production_mode' => PRODUCTION_MODE,
        'environment' => ENVIRONMENT,
        'base_url' => BASE_URL,
        'debug_mode' => DEBUG_MODE
    ],
    'session_config' => [
        'session_name' => session_name(),
        'session_id' => session_id(),
        'session_status' => session_status(),
        'cookie_params' => session_get_cookie_params(),
        'ini_settings' => [
            'session.cookie_domain' => ini_get('session.cookie_domain'),
            'session.cookie_path' => ini_get('session.cookie_path'),
            'session.cookie_secure' => ini_get('session.cookie_secure'),
            'session.cookie_httponly' => ini_get('session.cookie_httponly'),
            'session.cookie_samesite' => ini_get('session.cookie_samesite'),
            'session.gc_maxlifetime' => ini_get('session.gc_maxlifetime')
        ]
    ],
    'session_data' => $_SESSION,
    'cookies' => $_COOKIE
];

// Test: Imposta un valore di sessione se non esiste
if (!isset($_SESSION['test_value'])) {
    $_SESSION['test_value'] = 'Test session value created at ' . date('Y-m-d H:i:s');
    $_SESSION['test_counter'] = 1;
} else {
    $_SESSION['test_counter']++;
}

// Output formattato
header('Content-Type: application/json');
echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);