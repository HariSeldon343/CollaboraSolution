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

    // Configura le impostazioni della sessione PRIMA di avviarla
    ini_set('session.cookie_lifetime', '0');  // Session cookie
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '0');  // Disabled to avoid regeneration issues
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);

    // Avvia la sessione
    session_start();
}