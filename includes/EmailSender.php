<?php
/**
 * EmailSender Class
 * Gestione invio email tramite SMTP per CollaboraNexio
 * Utilizza la funzione mail() nativa di PHP con configurazione SMTP
 */

class EmailSender {
    private $smtpHost = 'mail.infomaniak.com';
    private $smtpPort = 465;
    private $smtpUsername = 'info@fortibyte.it';
    private $smtpPassword = 'Ricord@1991';
    private $fromEmail = 'info@fortibyte.it';
    private $fromName = 'CollaboraNexio';
    private $replyTo = 'info@fortibyte.it';

    /**
     * Costruttore - può accettare configurazioni custom
     */
    public function __construct($config = []) {
        if (!empty($config)) {
            foreach ($config as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }
        }
    }

    /**
     * Invia email di reset password per utenti esistenti
     *
     * @param string $toEmail Email del destinatario
     * @param string $userName Nome dell'utente
     * @param string $resetToken Token per il reset password
     * @param string $tenantName Nome dell'azienda
     * @return bool True se l'invio ha successo
     */
    public function sendPasswordResetEmail($toEmail, $userName, $resetToken, $tenantName = '') {
        $subject = "Reimposta la tua password - CollaboraNexio";

        // Costruisci il link per impostare la password
        $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
        $resetLink = $baseUrl . '/set_password.php?token=' . urlencode($resetToken);

        // HTML per email di reset (diverso dal benvenuto)
        $htmlBody = $this->getPasswordResetTemplate($userName, $resetLink, $tenantName);

        // Crea versione plain text
        $textBody = "Ciao $userName,\n\nHai richiesto di reimpostare la tua password per CollaboraNexio.\n\n";
        $textBody .= "Clicca sul seguente link per impostare una nuova password:\n$resetLink\n\n";
        $textBody .= "Questo link scadrà tra 24 ore.\n\n";
        $textBody .= "Se non hai richiesto tu il reset della password, ignora questa email.\n\n";
        $textBody .= "Cordiali saluti,\nIl team di CollaboraNexio";

        // Invia l'email
        return $this->sendEmail($toEmail, $subject, $htmlBody, $textBody);
    }

    /**
     * Invia email di benvenuto con link per impostare la password
     *
     * @param string $toEmail Email del destinatario
     * @param string $userName Nome dell'utente
     * @param string $resetToken Token per il reset password
     * @param string $tenantName Nome dell'azienda
     * @return bool True se l'invio ha successo
     */
    public function sendWelcomeEmail($toEmail, $userName, $resetToken, $tenantName = '') {
        $subject = "Benvenuto in CollaboraNexio - Imposta la tua password";

        // Costruisci il link per impostare la password
        $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
        $resetLink = $baseUrl . '/set_password.php?token=' . urlencode($resetToken);

        // Carica il template HTML
        $htmlTemplate = $this->getWelcomeEmailTemplate();

        // Sostituisci i placeholder
        $replacements = [
            '{{USER_NAME}}' => htmlspecialchars($userName),
            '{{RESET_LINK}}' => $resetLink,
            '{{TENANT_NAME}}' => htmlspecialchars($tenantName),
            '{{YEAR}}' => date('Y'),
            '{{BASE_URL}}' => $baseUrl
        ];

        $htmlBody = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $htmlTemplate
        );

        // Crea versione plain text
        $textBody = $this->createPlainTextVersion($userName, $resetLink, $tenantName);

        // Invia l'email
        return $this->sendEmail($toEmail, $subject, $htmlBody, $textBody);
    }

    /**
     * Invia email generica
     *
     * @param string $to Email destinatario
     * @param string $subject Oggetto
     * @param string $htmlBody Contenuto HTML
     * @param string $textBody Contenuto plain text (opzionale)
     * @return bool
     */
    public function sendEmail($to, $subject, $htmlBody, $textBody = '') {
        // Genera boundary per multipart
        $boundary = md5(uniqid(time()));

        // Headers
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'From: ' . $this->fromName . ' <' . $this->fromEmail . '>',
            'Reply-To: ' . $this->replyTo,
            'X-Mailer: PHP/' . phpversion(),
            'X-Priority: 3',
            'X-MSMail-Priority: Normal',
            'Return-Path: ' . $this->fromEmail
        ];

        // Costruisci il messaggio multipart
        $message = '';

        // Plain text part
        if (empty($textBody)) {
            $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
        }

        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $textBody . "\r\n";

        // HTML part
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $htmlBody . "\r\n";

        // End boundary
        $message .= '--' . $boundary . '--';

        // Per Windows/XAMPP, configura SMTP tramite ini_set
        if (stripos(PHP_OS, 'WIN') !== false) {
            // Configurazione per Windows con SMTP esterno
            ini_set('SMTP', $this->smtpHost);
            ini_set('smtp_port', $this->smtpPort);
            ini_set('sendmail_from', $this->fromEmail);

            // Nota: PHP mail() su Windows non supporta autenticazione SMTP
            // Per un'implementazione completa, considera l'uso di PHPMailer o SwiftMailer
        }

        // Invia l'email
        try {
            $success = @mail($to, $subject, $message, implode("\r\n", $headers));

            if (!$success) {
                error_log("EmailSender: Errore invio email a $to - " . error_get_last()['message']);

                // Fallback: prova con configurazione semplificata
                $simpleHeaders = "From: {$this->fromEmail}\r\n";
                $simpleHeaders .= "Reply-To: {$this->replyTo}\r\n";
                $simpleHeaders .= "Content-Type: text/html; charset=UTF-8\r\n";

                $success = @mail($to, $subject, $htmlBody, $simpleHeaders);
            }

            return $success;

        } catch (Exception $e) {
            error_log("EmailSender Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ottiene il template HTML per l'email di benvenuto
     *
     * @return string
     */
    private function getWelcomeEmailTemplate() {
        $templatePath = __DIR__ . '/../templates/email/welcome.html';

        if (file_exists($templatePath)) {
            return file_get_contents($templatePath);
        }

        // Template di default se il file non esiste
        return '<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Benvenuto in CollaboraNexio</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 40px 20px; text-align: center; border-radius: 10px 10px 0 0; }
        .logo { font-size: 32px; font-weight: bold; margin-bottom: 10px; }
        .content { background: white; padding: 40px 30px; border: 1px solid #e0e0e0; border-top: none; }
        .button { display: inline-block; padding: 14px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 20px 0; }
        .button:hover { opacity: 0.9; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
        h2 { color: #333; margin-top: 0; }
        .info-box { background: #f8f9fa; border-radius: 5px; padding: 15px; margin: 20px 0; }
        .link-text { color: #667eea; word-break: break-all; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">CollaboraNexio</div>
            <div>La piattaforma di collaborazione aziendale</div>
        </div>

        <div class="content">
            <h2>Benvenuto {{USER_NAME}}!</h2>

            <p>Il tuo account è stato creato con successo su CollaboraNexio{{TENANT_NAME}}.</p>

            <p>Per iniziare ad utilizzare la piattaforma, devi prima impostare la tua password personale.</p>

            <div class="info-box">
                <strong>📋 Requisiti password:</strong><br>
                • Minimo 8 caratteri<br>
                • Almeno una lettera maiuscola<br>
                • Almeno una lettera minuscola<br>
                • Almeno un numero
            </div>

            <div style="text-align: center;">
                <a href="{{RESET_LINK}}" class="button">Imposta la tua password</a>
            </div>

            <div class="warning">
                <strong>⏰ Importante:</strong> Questo link è valido per 24 ore. Dopo questo periodo dovrai richiedere un nuovo link di reset.
            </div>

            <p>Se non riesci a cliccare il pulsante, copia e incolla questo link nel tuo browser:</p>
            <p class="link-text">{{RESET_LINK}}</p>

            <hr style="margin: 30px 0; border: none; border-top: 1px solid #e0e0e0;">

            <p><strong>Hai bisogno di aiuto?</strong></p>
            <p>Se hai domande o problemi con l\'accesso, contatta il tuo amministratore di sistema o rispondi a questa email.</p>
        </div>

        <div class="footer">
            <p>&copy; {{YEAR}} CollaboraNexio. Tutti i diritti riservati.</p>
            <p>Questa è un\'email automatica, per favore non rispondere direttamente.</p>
            <p style="margin-top: 20px;">
                <a href="{{BASE_URL}}" style="color: #667eea;">www.collaboranexio.com</a>
            </p>
        </div>
    </div>
</body>
</html>';
    }

    /**
     * Crea versione plain text dell'email
     *
     * @param string $userName
     * @param string $resetLink
     * @param string $tenantName
     * @return string
     */
    private function createPlainTextVersion($userName, $resetLink, $tenantName = '') {
        $tenant = $tenantName ? " per l'azienda $tenantName" : '';

        return "Benvenuto $userName!

Il tuo account è stato creato con successo su CollaboraNexio{$tenant}.

Per iniziare ad utilizzare la piattaforma, devi prima impostare la tua password personale.

Requisiti password:
- Minimo 8 caratteri
- Almeno una lettera maiuscola
- Almeno una lettera minuscola
- Almeno un numero

Clicca sul seguente link per impostare la tua password:
$resetLink

IMPORTANTE: Questo link è valido per 24 ore. Dopo questo periodo dovrai richiedere un nuovo link di reset.

Se hai domande o problemi con l'accesso, contatta il tuo amministratore di sistema.

---
© " . date('Y') . " CollaboraNexio. Tutti i diritti riservati.
Questa è un'email automatica, per favore non rispondere direttamente.";
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
     * Ottiene il template HTML per l'email di reset password
     *
     * @param string $userName
     * @param string $resetLink
     * @param string $tenantName
     * @return string
     */
    private function getPasswordResetTemplate($userName, $resetLink, $tenantName = '') {
        $year = date('Y');
        $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
        $tenant = $tenantName ? " - $tenantName" : '';

        return '<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - CollaboraNexio</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; background: #f4f7fa; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 40px auto; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { padding: 30px; }
        .button { display: inline-block; padding: 14px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 20px 0; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; background: #f8f9fa; border-radius: 0 0 8px 8px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="margin: 0;">CollaboraNexio</h1>
            <p style="margin: 10px 0 0; opacity: 0.9;">Reset della Password</p>
        </div>
        <div class="content">
            <h2>Ciao ' . htmlspecialchars($userName) . ',</h2>
            <p>Hai richiesto di reimpostare la tua password per CollaboraNexio' . htmlspecialchars($tenant) . '.</p>
            <p>Clicca sul pulsante qui sotto per impostare una nuova password:</p>
            <div style="text-align: center;">
                <a href="' . $resetLink . '" class="button">Reimposta Password</a>
            </div>
            <div class="warning">
                <strong>⏰ Importante:</strong> Questo link è valido per 24 ore.
            </div>
            <p style="color: #666; font-size: 14px;">Se non hai richiesto tu il reset della password, puoi ignorare questa email. Il tuo account rimarrà sicuro.</p>
            <hr style="margin: 30px 0; border: none; border-top: 1px solid #e0e0e0;">
            <p style="font-size: 12px; color: #999;">Se il pulsante non funziona, copia e incolla questo link nel browser:<br>
            <span style="color: #667eea; word-break: break-all;">' . $resetLink . '</span></p>
        </div>
        <div class="footer">
            <p>&copy; ' . $year . ' CollaboraNexio. Tutti i diritti riservati.</p>
        </div>
    </div>
</body>
</html>';
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