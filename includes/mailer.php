<?php
/**
 * Mailer - Helper centralizzato per invio email con PHPMailer
 *
 * Gestisce l'invio di email tramite SMTP usando PHPMailer.
 * Include logging strutturato, gestione errori non bloccante e supporto debug.
 *
 * @author CollaboraNexio
 * @version 1.0.0
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Carica PHPMailer
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/PHPMailer/Exception.php';

/**
 * Invia email tramite SMTP usando PHPMailer
 *
 * @param string $to Email destinatario
 * @param string $subject Oggetto email
 * @param string $htmlBody Contenuto HTML
 * @param string $textBody Contenuto testo alternativo (opzionale)
 * @param array $options Opzioni aggiuntive:
 *   - attachments: array di file da allegare [['path' => '', 'name' => '']]
 *   - cc: array di indirizzi CC
 *   - bcc: array di indirizzi BCC
 *   - replyTo: email per reply-to
 *   - fromName: nome mittente custom
 *   - context: array con info per logging (tenant_id, user_id, action)
 * @return bool True se l'invio ha successo
 */
function sendEmail($to, $subject, $htmlBody, $textBody = '', $options = []) {
    // Carica configurazione email
    $config = loadEmailConfig();

    // Se configurazione non disponibile, logga e ritorna false senza bloccare
    if (!$config || empty($config['smtp_host'])) {
        logMailerError('missing_config', [
            'to' => $to,
            'subject' => $subject,
            'error' => 'Configurazione email non disponibile'
        ], $options['context'] ?? []);
        return false;
    }

    // Crea istanza PHPMailer
    $mail = new PHPMailer(true);

    try {
        // Configurazione SMTP
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->Port = $config['smtp_port'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_username'];
        $mail->Password = $config['smtp_password'];

        // SSL/TLS
        if ($config['smtp_port'] == 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL
        } elseif ($config['smtp_port'] == 587) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS
        }

        // Opzioni SSL per sviluppo (disabilitabili)
        if ($config['smtp_verify_ssl'] === false) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
        }

        // Debug mode
        if ($config['debug_mode']) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function($str, $level) {
                logMailerDebug($str, $level);
            };
        }

        // Timeout
        $mail->Timeout = $config['smtp_timeout'] ?? 10;

        // Mittente
        $fromName = $options['fromName'] ?? $config['from_name'] ?? 'CollaboraNexio';
        $mail->setFrom($config['from_email'], $fromName);

        // Reply-to
        $replyTo = $options['replyTo'] ?? $config['reply_to'] ?? $config['from_email'];
        $mail->addReplyTo($replyTo, $fromName);

        // Destinatario
        $mail->addAddress($to);

        // CC
        if (!empty($options['cc'])) {
            foreach ((array)$options['cc'] as $ccEmail) {
                $mail->addCC($ccEmail);
            }
        }

        // BCC
        if (!empty($options['bcc'])) {
            foreach ((array)$options['bcc'] as $bccEmail) {
                $mail->addBCC($bccEmail);
            }
        }

        // Contenuto
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody ?: strip_tags($htmlBody);

        // Charset
        $mail->CharSet = 'UTF-8';

        // Allegati
        if (!empty($options['attachments'])) {
            foreach ($options['attachments'] as $attachment) {
                if (isset($attachment['path']) && file_exists($attachment['path'])) {
                    $name = $attachment['name'] ?? basename($attachment['path']);
                    $mail->addAttachment($attachment['path'], $name);
                }
            }
        }

        // Invia
        $result = $mail->send();

        // Log successo
        logMailerSuccess($to, $subject, $options['context'] ?? []);

        return $result;

    } catch (Exception $e) {
        // Log errore (non bloccante)
        logMailerError('send_failed', [
            'to' => $to,
            'subject' => $subject,
            'error' => $e->getMessage(),
            'mailer_error' => $mail->ErrorInfo
        ], $options['context'] ?? []);

        return false;
    }
}

/**
 * Carica configurazione email da file o database
 *
 * Ordine di priorità:
 * 1. config_email.php (se esiste)
 * 2. Database (system_settings)
 * 3. Valori di default (fallback)
 *
 * @return array|null Configurazione o null se non disponibile
 */
function loadEmailConfig() {
    static $config = null;

    // Cache della configurazione
    if ($config !== null) {
        return $config;
    }

    // Prova a caricare da file config_email.php
    $configFile = __DIR__ . '/config_email.php';
    if (file_exists($configFile)) {
        require_once $configFile;

        // Se il file definisce EMAIL_CONFIG, usalo
        if (defined('EMAIL_SMTP_HOST')) {
            $config = [
                'smtp_host' => EMAIL_SMTP_HOST,
                'smtp_port' => EMAIL_SMTP_PORT,
                'smtp_username' => EMAIL_SMTP_USERNAME,
                'smtp_password' => EMAIL_SMTP_PASSWORD,
                'from_email' => EMAIL_FROM_EMAIL,
                'from_name' => EMAIL_FROM_NAME ?? 'CollaboraNexio',
                'reply_to' => EMAIL_REPLY_TO ?? EMAIL_FROM_EMAIL,
                'debug_mode' => EMAIL_DEBUG_MODE ?? false,
                'smtp_verify_ssl' => EMAIL_SMTP_VERIFY_SSL ?? true,
                'smtp_timeout' => EMAIL_SMTP_TIMEOUT ?? 10
            ];
            return $config;
        }
    }

    // Fallback: prova a caricare da database
    try {
        require_once __DIR__ . '/email_config.php';
        $dbConfig = getEmailConfigFromDatabase();

        if (!empty($dbConfig)) {
            $config = [
                'smtp_host' => $dbConfig['smtpHost'] ?? '',
                'smtp_port' => $dbConfig['smtpPort'] ?? 465,
                'smtp_username' => $dbConfig['smtpUsername'] ?? '',
                'smtp_password' => $dbConfig['smtpPassword'] ?? '',
                'from_email' => $dbConfig['fromEmail'] ?? '',
                'from_name' => $dbConfig['fromName'] ?? 'CollaboraNexio',
                'reply_to' => $dbConfig['replyTo'] ?? $dbConfig['fromEmail'] ?? '',
                'debug_mode' => false,
                'smtp_verify_ssl' => true,
                'smtp_timeout' => 10
            ];
            return $config;
        }
    } catch (Exception $e) {
        error_log("Errore caricamento config email da DB: " . $e->getMessage());
    }

    // Nessuna configurazione disponibile
    $config = null;
    return null;
}

/**
 * Logga successo invio email
 *
 * @param string $to Destinatario
 * @param string $subject Oggetto
 * @param array $context Contesto (tenant_id, user_id, action)
 */
function logMailerSuccess($to, $subject, $context = []) {
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'success',
        'to' => $to,
        'subject' => $subject,
        'tenant_id' => $context['tenant_id'] ?? null,
        'user_id' => $context['user_id'] ?? null,
        'action' => $context['action'] ?? 'email_sent'
    ];

    writeMailerLog($logData);
}

/**
 * Logga errore invio email
 *
 * @param string $errorType Tipo errore
 * @param array $data Dati errore
 * @param array $context Contesto
 */
function logMailerError($errorType, $data, $context = []) {
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'error',
        'error_type' => $errorType,
        'to' => $data['to'] ?? 'unknown',
        'subject' => $data['subject'] ?? 'unknown',
        'error' => $data['error'] ?? 'Unknown error',
        'mailer_error' => $data['mailer_error'] ?? null,
        'tenant_id' => $context['tenant_id'] ?? null,
        'user_id' => $context['user_id'] ?? null,
        'action' => $context['action'] ?? 'email_failed'
    ];

    writeMailerLog($logData);

    // Log anche in error_log PHP
    error_log("Mailer Error [{$errorType}]: " . ($data['error'] ?? 'Unknown'));
}

/**
 * Logga debug SMTP
 *
 * @param string $message Messaggio debug
 * @param int $level Livello debug
 */
function logMailerDebug($message, $level) {
    if (!defined('EMAIL_DEBUG_MODE') || !EMAIL_DEBUG_MODE) {
        return;
    }

    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'debug',
        'level' => $level,
        'message' => trim($message)
    ];

    writeMailerLog($logData);
}

/**
 * Scrive log su file
 *
 * @param array $logData Dati da loggare
 */
function writeMailerLog($logData) {
    $logDir = __DIR__ . '/../logs';

    // Crea directory logs se non esiste
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/mailer_error.log';

    // Formato log: JSON per parsing facile
    $logLine = json_encode($logData, JSON_UNESCAPED_UNICODE) . PHP_EOL;

    // Scrivi log (non bloccante)
    @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

    // Rotazione log (se > 10MB)
    if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
        @rename($logFile, $logFile . '.' . date('Y-m-d-His'));
    }
}

/**
 * Wrapper per email di benvenuto (retrocompatibilità con EmailSender)
 *
 * @param string $to Email destinatario
 * @param string $userName Nome utente
 * @param string $resetToken Token reset password
 * @param string $tenantName Nome tenant
 * @return bool
 */
function sendWelcomeEmail($to, $userName, $resetToken, $tenantName = '') {
    $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
    $resetLink = $baseUrl . '/set_password.php?token=' . urlencode($resetToken);

    $tenant = $tenantName ? " per l'azienda $tenantName" : '';

    $subject = "Benvenuto in CollaboraNexio - Imposta la tua password";

    $htmlBody = getWelcomeEmailTemplate($userName, $resetLink, $tenantName, $baseUrl);

    $textBody = "Benvenuto $userName!

Il tuo account è stato creato con successo su CollaboraNexio{$tenant}.

Per iniziare ad utilizzare la piattaforma, devi prima impostare la tua password personale.

Requisiti password:
- Minimo 8 caratteri
- Almeno una lettera maiuscola
- Almeno una lettera minuscola
- Almeno un numero

Clicca sul seguente link per impostare la tua password:
$resetLink

IMPORTANTE: Questo link è valido per 24 ore.

---
© " . date('Y') . " CollaboraNexio. Tutti i diritti riservati.";

    $context = [
        'action' => 'welcome_email',
        'tenant_id' => $_SESSION['tenant_id'] ?? null,
        'user_id' => $_SESSION['user_id'] ?? null
    ];

    return sendEmail($to, $subject, $htmlBody, $textBody, ['context' => $context]);
}

/**
 * Wrapper per email reset password
 *
 * @param string $to Email destinatario
 * @param string $userName Nome utente
 * @param string $resetToken Token reset
 * @param string $tenantName Nome tenant
 * @return bool
 */
function sendPasswordResetEmail($to, $userName, $resetToken, $tenantName = '') {
    $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
    $resetLink = $baseUrl . '/set_password.php?token=' . urlencode($resetToken);

    $subject = "Reimposta la tua password - CollaboraNexio";

    $htmlBody = getPasswordResetTemplate($userName, $resetLink, $tenantName, $baseUrl);

    $textBody = "Ciao $userName,

Hai richiesto di reimpostare la tua password per CollaboraNexio.

Clicca sul seguente link per impostare una nuova password:
$resetLink

Questo link scadrà tra 24 ore.

Se non hai richiesto tu il reset della password, ignora questa email.

Cordiali saluti,
Il team di CollaboraNexio";

    $context = [
        'action' => 'password_reset',
        'tenant_id' => $_SESSION['tenant_id'] ?? null,
        'user_id' => $_SESSION['user_id'] ?? null
    ];

    return sendEmail($to, $subject, $htmlBody, $textBody, ['context' => $context]);
}

/**
 * Template HTML email di benvenuto
 */
function getWelcomeEmailTemplate($userName, $resetLink, $tenantName, $baseUrl) {
    $tenant = $tenantName ? " per l'azienda $tenantName" : '';
    $year = date('Y');

    return '<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Benvenuto in CollaboraNexio</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 40px 20px; text-align: center; border-radius: 10px 10px 0 0; }
        .logo { font-size: 32px; font-weight: bold; margin-bottom: 10px; }
        .content { background: white; padding: 40px 30px; border: 1px solid #e0e0e0; border-top: none; }
        .button { display: inline-block; padding: 14px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
        .info-box { background: #f8f9fa; border-radius: 5px; padding: 15px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">CollaboraNexio</div>
            <div>La piattaforma di collaborazione aziendale</div>
        </div>
        <div class="content">
            <h2>Benvenuto ' . htmlspecialchars($userName) . '!</h2>
            <p>Il tuo account è stato creato con successo su CollaboraNexio' . htmlspecialchars($tenant) . '.</p>
            <p>Per iniziare ad utilizzare la piattaforma, devi prima impostare la tua password personale.</p>
            <div class="info-box">
                <strong>📋 Requisiti password:</strong><br>
                • Minimo 8 caratteri<br>
                • Almeno una lettera maiuscola<br>
                • Almeno una lettera minuscola<br>
                • Almeno un numero
            </div>
            <div style="text-align: center;">
                <a href="' . $resetLink . '" class="button">Imposta la tua password</a>
            </div>
            <div class="warning">
                <strong>⏰ Importante:</strong> Questo link è valido per 24 ore.
            </div>
            <p style="font-size: 12px; color: #666;">Se non riesci a cliccare il pulsante, copia questo link:<br>' . htmlspecialchars($resetLink) . '</p>
        </div>
        <div class="footer">
            <p>&copy; ' . $year . ' CollaboraNexio. Tutti i diritti riservati.</p>
        </div>
    </div>
</body>
</html>';
}

/**
 * Template HTML email reset password
 */
function getPasswordResetTemplate($userName, $resetLink, $tenantName, $baseUrl) {
    $tenant = $tenantName ? " - $tenantName" : '';
    $year = date('Y');

    return '<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; background: #f4f7fa; }
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
            <p style="margin: 10px 0 0;">Reset della Password</p>
        </div>
        <div class="content">
            <h2>Ciao ' . htmlspecialchars($userName) . ',</h2>
            <p>Hai richiesto di reimpostare la tua password per CollaboraNexio' . htmlspecialchars($tenant) . '.</p>
            <div style="text-align: center;">
                <a href="' . $resetLink . '" class="button">Reimposta Password</a>
            </div>
            <div class="warning">
                <strong>⏰ Importante:</strong> Questo link è valido per 24 ore.
            </div>
            <p style="color: #666; font-size: 14px;">Se non hai richiesto tu il reset, ignora questa email.</p>
        </div>
        <div class="footer">
            <p>&copy; ' . $year . ' CollaboraNexio. Tutti i diritti riservati.</p>
        </div>
    </div>
</body>
</html>';
}
