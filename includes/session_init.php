<?php
/**
 * Inizializzazione centralizzata delle sessioni
 * Configura le impostazioni della sessione e applica timeout inattività (server-side)
 *
 * IMPORTANT:
 * - In alcuni ambienti PHP può partire una sessione prima di questo include (es. session.auto_start=1
 *   o include legacy che fa session_start()). In quel caso il nome sessione può essere PHPSESSID.
 * - Le API usano sempre `includes/api_auth.php` → `initializeApiEnvironment()` → questo file,
 *   quindi devono vedere la STESSA sessione della UI.
 * - Per evitare 401 "Non autorizzato" sulle API mentre la UI sembra loggata, facciamo una
 *   migrazione best-effort: se la sessione è già attiva ma non è COLLAB_SID, copiamo i dati
 *   su una nuova sessione COLLAB_SID e continuiamo.
 */

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

// Timeout inattività (server-side):
// - UX client: warning a 270s, logout a 300s
// - Server-side: logout effettivo a 300s (autoritativo)
$inactivity_timeout = 300;

// Configura le impostazioni della sessione PRIMA di avviarla (quando possibile)
// Nota: se una sessione è già attiva, alcune ini_set non hanno effetto sul cookie già emesso,
// ma manteniamo comunque queste impostazioni per coerenza dei processi successivi.
ini_set('session.cookie_lifetime', '0');  // Session cookie - scade alla chiusura browser
ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '0');  // Disabled to avoid regeneration issues
ini_set('session.gc_maxlifetime', (string)$inactivity_timeout);
ini_set('session.cookie_secure', $cookieSecure ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax'); // Lax per permettere navigazione cross-domain

if (!empty($cookieDomain)) {
    ini_set('session.cookie_domain', $cookieDomain);
}
ini_set('session.cookie_path', $cookiePath);

// Configura i parametri del cookie di sessione
session_set_cookie_params([
    'lifetime' => 0, // Session cookie
    'path' => $cookiePath,
    'domain' => $cookieDomain,
    'secure' => $cookieSecure,
    'httponly' => true,
    'samesite' => 'Lax'
]);

$desiredSessionName = 'COLLAB_SID';

// 1) Se la sessione non è avviata, avviala direttamente con il nome corretto.
if (session_status() === PHP_SESSION_NONE) {
    // If the app UI is already logged-in via a legacy session cookie (often PHPSESSID),
    // but COLLAB_SID is missing, APIs would start an empty session and return 401.
    // Bridge legacy -> COLLAB_SID once per browser by migrating session data.
    $legacyName = session_name(); // usually "PHPSESSID" unless configured otherwise
    $hasDesiredCookie = isset($_COOKIE[$desiredSessionName]) && $_COOKIE[$desiredSessionName] !== '';
    $hasLegacyCookie = ($legacyName !== $desiredSessionName) && isset($_COOKIE[$legacyName]) && $_COOKIE[$legacyName] !== '';

    if (!$hasDesiredCookie && $hasLegacyCookie) {
        // 1) Start legacy session (reads existing login state)
        $oldData = [];
        try {
            session_name($legacyName);
            session_start();
            $oldData = $_SESSION ?? [];
            session_write_close();
        } catch (Throwable $e) {
            // Best-effort: if legacy session can't be read, fall back to new session
            $oldData = [];
        }

        // 2) Start desired session and copy data if not already authenticated
        session_name($desiredSessionName);
        session_start();
        if ((empty($_SESSION) || !isset($_SESSION['user_id'])) && !empty($oldData)) {
            foreach ($oldData as $k => $v) {
                $_SESSION[$k] = $v;
            }
            $_SESSION['__cnx_session_migrated'] = [
                'from_name' => $legacyName,
                'at' => date('c'),
            ];
        }
    } else {
        session_name($desiredSessionName);
        session_start();
    }
} else {
    // 2) Se la sessione è già attiva ma con nome diverso, migra (best-effort) su COLLAB_SID.
    if (session_name() !== $desiredSessionName) {
        $oldData = $_SESSION ?? [];
        $oldName = session_name();
        $oldId = session_id();

        // Chiudi la sessione corrente (non distrugge ancora i dati server-side).
        session_write_close();

        // Avvia la sessione con il nome corretto.
        session_name($desiredSessionName);
        session_start();

        // Copia i dati (se la nuova sessione è vuota o non autenticata).
        // Non sovrascriviamo se per qualche motivo COLLAB_SID era già valorizzata.
        if (empty($_SESSION) || !isset($_SESSION['user_id'])) {
            foreach ($oldData as $k => $v) {
                $_SESSION[$k] = $v;
            }
            $_SESSION['__cnx_session_migrated'] = [
                'from_name' => $oldName,
                'from_id' => $oldId,
                'at' => date('c'),
            ];
        }
    }
}

// One-time per login session marker (used for client-side UX like legal notice)
// Created lazily after login (first authenticated request).
if (isset($_SESSION['user_id']) && empty($_SESSION['login_nonce'])) {
    try {
        $_SESSION['login_nonce'] = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        // Best-effort fallback (still unique enough for UX purposes)
        $_SESSION['login_nonce'] = bin2hex((string)time() . (string)mt_rand());
    }
}

// Nota: timeout inattività è globale (non configurabile per-tenant) come requisito.

// Gestione timeout inattività (valida per TUTTE le request, anche se sessione era già attiva)
if (isset($_SESSION['last_activity'])) {
    $elapsed = time() - (int)$_SESSION['last_activity'];
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
        $_SESSION = [];

        // Distruggi il cookie di sessione
        if (ini_get("session.use_cookies")) {
            setcookie(session_name(), '', time() - 42000, $cookiePath, $cookieDomain, $cookieSecure, true);
        }

        session_destroy();

        // Se è una chiamata API/AJAX, rispondi JSON 401 invece di redirect (evita HTML al posto di JSON)
        $isApiRequest = false;
        $reqUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($reqUri, '/api/') !== false) {
            $isApiRequest = true;
        }
        $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if (strcasecmp($xrw, 'XMLHttpRequest') === 0) {
            $isApiRequest = true;
        }
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (stripos($accept, 'application/json') !== false) {
            $isApiRequest = true;
        }

        if ($isApiRequest) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Sessione scaduta per inattivita',
                'message' => 'Sessione scaduta per inattivita',
                'code' => 401
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        header('Location: /CollaboraNexio/index.php?timeout=1');
        exit();
    }
}

// Aggiorna last_activity ad ogni request
$_SESSION['last_activity'] = time();

// Log per debug (solo in development)
if (!$isProduction && defined('DEBUG_MODE') && DEBUG_MODE) {
    error_log("Session initialized - Host: $currentHost, Domain: $cookieDomain, Secure: " . ($cookieSecure ? 'true' : 'false') . ", Timeout: {$inactivity_timeout}s, SessionName=" . session_name());
}