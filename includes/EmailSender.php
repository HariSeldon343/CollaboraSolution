<?php
/**
 * EmailSender Class - DEPRECATO
 *
 * Questa classe è mantenuta per retrocompatibilità.
 * Per nuovo codice, usare direttamente le funzioni in includes/mailer.php
 *
 * Ora utilizza PHPMailer tramite il nuovo helper centralizzato.
 * Le credenziali hardcoded sono state rimosse per sicurezza.
 */

// Carica il nuovo helper centralizzato
require_once __DIR__ . '/mailer.php';

class EmailSender {
    /**
     * Costruttore - mantenuto per retrocompatibilità
     * La configurazione ora viene gestita dal nuovo sistema in mailer.php
     */
    public function __construct($config = []) {
        // Il nuovo sistema non usa più configurazione passata al costruttore
        // Configurazione caricata da config_email.php o database
        if (!empty($config)) {
            error_log("EmailSender: Configurazione passata al costruttore è deprecata. Usa includes/config_email.php");
        }
    }

    /**
     * Invia email di reset password - WRAPPER per retrocompatibilità
     *
     * @param string $toEmail Email del destinatario
     * @param string $userName Nome dell'utente
     * @param string $resetToken Token per il reset password
     * @param string $tenantName Nome dell'azienda
     * @return bool True se l'invio ha successo
     */
    public function sendPasswordResetEmail($toEmail, $userName, $resetToken, $tenantName = '') {
        // Delega al nuovo helper centralizzato
        return sendPasswordResetEmail($toEmail, $userName, $resetToken, $tenantName);
    }

    /**
     * Invia email di benvenuto - WRAPPER per retrocompatibilità
     *
     * @param string $toEmail Email del destinatario
     * @param string $userName Nome dell'utente
     * @param string $resetToken Token per il reset password
     * @param string $tenantName Nome dell'azienda
     * @return bool True se l'invio ha successo
     */
    public function sendWelcomeEmail($toEmail, $userName, $resetToken, $tenantName = '') {
        // Delega al nuovo helper centralizzato
        return sendWelcomeEmail($toEmail, $userName, $resetToken, $tenantName);
    }

    /**
     * Invia email generica - WRAPPER per retrocompatibilità
     *
     * @param string $to Email destinatario
     * @param string $subject Oggetto
     * @param string $htmlBody Contenuto HTML
     * @param string $textBody Contenuto plain text (opzionale)
     * @return bool
     */
    public function sendEmail($to, $subject, $htmlBody, $textBody = '') {
        // Delega al nuovo helper centralizzato
        return sendEmail($to, $subject, $htmlBody, $textBody);
    }

    /**
     * Valida un indirizzo email
     *
     * @param string $email
     * @return bool
     */
    public function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Genera un token sicuro per il reset password
     *
     * @return string
     */
    public static function generateSecureToken() {
        return bin2hex(random_bytes(32));
    }
}
?>