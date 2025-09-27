<?php
/**
 * File di configurazione principale per CollaboraNexio
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 */

// ===================================
// CONFIGURAZIONE DATABASE
// ===================================

// Configurazione Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'collabora');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PORT', 3306);
define('DB_PERSISTENT', false);

// ===================================
// CONFIGURAZIONE APPLICAZIONE
// ===================================

// Timezone
date_default_timezone_set('Europe/Rome');

// Modalità debug (true per sviluppo, false per produzione)
define('DEBUG_MODE', true);

// Error reporting per development
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Path del progetto - CORRETTI PER CollaboraNexio
define('BASE_PATH', 'C:/xampp/htdocs/CollaboraNexio');
define('UPLOAD_PATH', BASE_PATH . '/uploads');
define('PROJECT_URL', 'http://localhost/CollaboraNexio');
define('BASE_URL', PROJECT_URL);

// ===================================
// CONFIGURAZIONE SESSIONE
// ===================================

// Sessioni - Imposta solo se la sessione non è già attiva
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
}

// Nome della sessione
define('SESSION_NAME', 'COLLABORANEXIO_SESSION');

// Durata della sessione in secondi (24 ore)
define('SESSION_LIFETIME', 86400);

// ===================================
// CONFIGURAZIONE SICUREZZA
// ===================================

// Numero massimo di tentativi di login
define('MAX_LOGIN_ATTEMPTS', 5);

// Tempo di blocco dopo troppi tentativi (in secondi)
define('LOGIN_LOCKOUT_TIME', 900); // 15 minuti

// ===================================
// CONFIGURAZIONE LOG
// ===================================

// Directory dei log
define('LOG_DIR', BASE_PATH . '/logs');

// Livello di log (DEBUG, INFO, WARNING, ERROR, CRITICAL)
define('LOG_LEVEL', 'DEBUG');
?>
