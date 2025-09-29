<?php
/**
 * Inizializzazione centralizzata delle sessioni
 * Configura le impostazioni della sessione PRIMA di avviarla
 */

// Verifica se la sessione non è già stata avviata
if (session_status() === PHP_SESSION_NONE) {

    // Carica le costanti di configurazione se non già caricate
    if (!defined('SESSION_LIFETIME')) {
        require_once __DIR__ . '/../config.php';
    }

    // Rileva l'ambiente basandosi sull'hostname
    $currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isProduction = false;
    $cookieDomain = '';
    $cookieSecure = false;
    $cookiePath = '/CollaboraNexio/';

    // Determina se siamo in produzione o development
    if (strpos($currentHost, 'nexiosolution.it') !== false) {
        // Ambiente di produzione (Cloudflare)
        $isProduction = true;
        // Usa il dominio con punto iniziale per supportare tutti i sottodomini
        $cookieDomain = '.nexiosolution.it';
        $cookieSecure = true; // HTTPS in produzione
        $cookiePath = '/CollaboraNexio/'; // Path dell'applicazione
    } elseif (strpos($currentHost, 'localhost') !== false || strpos($currentHost, '127.0.0.1') !== false) {
        // Ambiente di sviluppo locale
        $isProduction = false;
        $cookieDomain = ''; // Vuoto per localhost
        $cookieSecure = false; // HTTP in locale
        $cookiePath = '/CollaboraNexio/'; // Path dell'applicazione
    }

    // Configura le impostazioni della sessione PRIMA di avviarla
    ini_set('session.cookie_lifetime', '0');  // Session cookie
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '0');  // Disabled to avoid regeneration issues
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    ini_set('session.cookie_secure', $cookieSecure ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax'); // Lax per permettere navigazione cross-domain

    // Se non è vuoto, imposta il dominio del cookie
    if (!empty($cookieDomain)) {
        ini_set('session.cookie_domain', $cookieDomain);
    }

    // Imposta il percorso del cookie
    ini_set('session.cookie_path', $cookiePath);

    // Configura i parametri del cookie di sessione
    session_set_cookie_params([
        'lifetime' => 0, // Session cookie
        'path' => $cookiePath,
        'domain' => $cookieDomain,
        'secure' => $cookieSecure,
        'httponly' => true,
        'samesite' => 'Lax' // Lax per permettere navigazione cross-domain
    ]);

    // Nome della sessione comune per entrambi gli ambienti
    session_name('COLLAB_SID');

    // Avvia la sessione
    session_start();

    // Log per debug (solo in development)
    if (!$isProduction && defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log("Session initialized - Host: $currentHost, Domain: $cookieDomain, Secure: " . ($cookieSecure ? 'true' : 'false'));
    }
}