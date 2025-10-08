<?php
/**
 * Configurazione Email - Template di esempio
 *
 * ISTRUZIONI:
 * 1. Copia questo file come config_email.php nella stessa directory
 * 2. Modifica i valori con le tue credenziali SMTP reali
 * 3. NON committare config_email.php (è in .gitignore)
 *
 * IMPORTANTE: config_email.php è ignorato da Git per sicurezza.
 * La password SMTP NON deve mai essere committata nel repository.
 */

// ============================================
// CONFIGURAZIONE SMTP NEXIO SOLUTION
// ============================================

// Host SMTP (Nexio Solution)
define('EMAIL_SMTP_HOST', 'mail.nexiosolution.it');

// Porta SMTP
// 465 = SSL (consigliato)
// 587 = TLS
define('EMAIL_SMTP_PORT', 465);

// Username SMTP (uguale all'email mittente)
define('EMAIL_SMTP_USERNAME', 'info@nexiosolution.it');

// Password SMTP
// INSERISCI QUI LA PASSWORD REALE (NON committare questo file!)
define('EMAIL_SMTP_PASSWORD', 'YOUR_SMTP_PASSWORD_HERE');

// ============================================
// CONFIGURAZIONE MITTENTE
// ============================================

// Email mittente
define('EMAIL_FROM_EMAIL', 'info@nexiosolution.it');

// Nome mittente
define('EMAIL_FROM_NAME', 'CollaboraNexio');

// Email per reply-to (opzionale, default = from_email)
define('EMAIL_REPLY_TO', 'info@nexiosolution.it');

// ============================================
// OPZIONI AVANZATE
// ============================================

// Debug mode (true = log dettagliati SMTP, false = solo errori)
// ATTENZIONE: attivare solo in sviluppo, mai in produzione!
define('EMAIL_DEBUG_MODE', false);

// Verifica SSL (true = verifica certificati SSL, false = accetta qualsiasi)
// IMPORTANTE: in produzione lasciare sempre true!
// Impostare false SOLO in sviluppo locale se si hanno problemi SSL
define('EMAIL_SMTP_VERIFY_SSL', true);

// Timeout connessione SMTP (secondi)
define('EMAIL_SMTP_TIMEOUT', 10);

// ============================================
// NOTE PER AMBIENTE DI SVILUPPO
// ============================================

/*
 * XAMPP su Windows:
 * - Se riscontri errori SSL in locale, puoi temporaneamente impostare
 *   EMAIL_SMTP_VERIFY_SSL = false (solo per test locali!)
 * - Verifica che l'estensione openssl sia abilitata in php.ini:
 *   extension=openssl
 *
 * Produzione:
 * - Sempre EMAIL_SMTP_VERIFY_SSL = true
 * - Sempre EMAIL_DEBUG_MODE = false
 * - Password mai committata in Git
 */
