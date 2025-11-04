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
    // Timeout inattivita: 10 minuti (600 secondi)
    $inactivity_timeout = 600;

    ini_set('session.cookie_lifetime', '0');  // Session cookie - scade alla chiusura browser
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '0');  // Disabled to avoid regeneration issues
    ini_set('session.gc_maxlifetime', (string)$inactivity_timeout);  // 10 minuti
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

    // Gestione timeout inattivita (10 minuti)
    if (isset($_SESSION['last_activity'])) {
        $elapsed = time() - $_SESSION['last_activity'];
        if ($elapsed > $inactivity_timeout) {
            // Audit log - Track session timeout logout BEFORE destroying session
            if (isset($_SESSION['user_id']) && isset($_SESSION['tenant_id'])) {
                try {
                    require_once __DIR__ . '/audit_helper.php';
                    AuditLogger::logLogout($_SESSION['user_id'], $_SESSION['tenant_id']);
                } catch (Exception $e) {
                    error_log("[AUDIT LOG FAILURE] Session timeout logout tracking failed: " . $e->getMessage());
                }
            }

            // Timeout scaduto - distruggi sessione e reindirizza
            $_SESSION = array();

            // Distruggi il cookie di sessione
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $cookiePath, $cookieDomain,
                    $cookieSecure, true
                );
            }

            // Distruggi la sessione
            session_destroy();

            // Reindirizza a index.php con parametro timeout
            header('Location: /CollaboraNexio/index.php?timeout=1');
            exit();
        }
    }

    // Aggiorna last_activity ad ogni request
    $_SESSION['last_activity'] = time();

    // Log per debug (solo in development)
    if (!$isProduction && defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log("Session initialized - Host: $currentHost, Domain: $cookieDomain, Secure: " . ($cookieSecure ? 'true' : 'false') . ", Timeout: {$inactivity_timeout}s");
    }
}