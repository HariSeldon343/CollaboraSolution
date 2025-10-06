<?php
/**
 * Helper per gestire CORS tra localhost e produzione
 * Permette la condivisione delle sessioni tra domini diversi
 */

function setupCORS() {
    // Ottieni l'origine della richiesta
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    // Lista delle origini consentite
    $allowedOrigins = [
        'http://localhost',
        'http://localhost:8888',
        'http://localhost:3000',
        'http://127.0.0.1:8888',
        'https://app.nexiosolution.it',
        'https://nexiosolution.it',
        'https://www.nexiosolution.it'
    ];

    // Verifica se l'origine è nella lista consentita
    if (in_array($origin, $allowedOrigins)) {
        // Imposta gli header CORS
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token");
        header("Access-Control-Max-Age: 3600");
    }

    // Gestisci le richieste OPTIONS (preflight)
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

/**
 * Verifica se la richiesta proviene da un dominio autorizzato
 */
function isAllowedDomain(): bool {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    $allowedDomains = [
        'localhost',
        '127.0.0.1',
        'nexiosolution.it',
        'app.nexiosolution.it'
    ];

    foreach ($allowedDomains as $domain) {
        if (strpos($referer, $domain) !== false || strpos($origin, $domain) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Configura gli header di sicurezza appropriati
 */
function setupSecurityHeaders() {
    // Header di sicurezza base
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');

    // Referrer Policy per permettere condivisione tra domini
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Se siamo in produzione, aggiungi header HTTPS
    if (defined('PRODUCTION_MODE') && PRODUCTION_MODE) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}