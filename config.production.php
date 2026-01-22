<?php
/**
 * CollaboraNexio - Production Overrides (SAFE TEMPLATE)
 *
 * IMPORTANT:
 * - Questo file è pensato come **override** opzionale caricato da `config.php` SOLO in produzione.
 * - NON salvare credenziali reali nel repository: usa una copia privata sul server.
 * - Questo file deve essere **safe-to-include**:
 *   - niente random_bytes() / generazione segreti
 *   - niente side-effects (mkdir, header, setlocale, timezone, mb_internal_encoding, ecc.)
 *   - solo `define()` protette da `defined()`
 *
 * Esempio d'uso (server):
 * - copia questo file fuori git / o sovrascrivilo in deploy
 * - inserisci qui la chiave OpenAI e (se vuoi) eventuali override di modello/timeout
 */

declare(strict_types=1);

// -------------------------------
// OpenAI (Planning AI proposal)
// -------------------------------

// Define ONLY in your private production copy. Keep it empty in repo/template.
if (!defined('OPENAI_API_KEY')) {
    define('OPENAI_API_KEY', ''); // TODO: set on server (private)
}

if (!defined('OPENAI_MODEL')) {
    define('OPENAI_MODEL', 'gpt-5.2');
}

if (!defined('OPENAI_TIMEOUT_SECONDS')) {
    define('OPENAI_TIMEOUT_SECONDS', 20);
}

// Optional: allow custom base URL (rare)
if (!defined('OPENAI_API_BASE')) {
    define('OPENAI_API_BASE', 'https://api.openai.com');
}

// -------------------------------
// Database (optional override)
// -------------------------------
// If your tunnel host triggers PRODUCTION_MODE but you still run locally, you can usually leave DB defaults alone.
// If you DO need overrides, define them in your private copy (not committed):
// if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
// if (!defined('DB_PORT')) define('DB_PORT', 3306);
// if (!defined('DB_NAME')) define('DB_NAME', 'collaboranexio');
// if (!defined('DB_USER')) define('DB_USER', '...');
// if (!defined('DB_PASS')) define('DB_PASS', '...');