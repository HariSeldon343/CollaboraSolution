<?php
/**
 * Configurazione Email - FILE REALE (NON COMMITTARE!)
 *
 * Questo file contiene le credenziali SMTP reali.
 * DEVE essere ignorato da Git (.gitignore).
 */

// SMTP Nexio Solution
define('EMAIL_SMTP_HOST', 'mail.nexiosolution.it');
define('EMAIL_SMTP_PORT', 465); // SSL
define('EMAIL_SMTP_USERNAME', 'info@nexiosolution.it');

// INSERIRE QUI LA PASSWORD SMTP REALE
define('EMAIL_SMTP_PASSWORD', 'Ricord@1991');

// Mittente
define('EMAIL_FROM_EMAIL', 'info@nexiosolution.it');
define('EMAIL_FROM_NAME', 'CollaboraNexio');
define('EMAIL_REPLY_TO', 'info@nexiosolution.it');

// Opzioni
define('EMAIL_DEBUG_MODE', true); // true in sviluppo per debug
define('EMAIL_SMTP_VERIFY_SSL', false); // false solo in locale se problemi SSL
define('EMAIL_SMTP_TIMEOUT', 10);
