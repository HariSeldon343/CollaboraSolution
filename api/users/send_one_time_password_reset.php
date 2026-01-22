<?php
/**
 * API: Send one-time password + reset link (super_admin)
 *
 * Goal:
 * - Generate a one-time password (OTP) and a reset token
 * - Email user: OTP + link to set_password.php?token=...
 * - User can set a new password only by providing the OTP on set_password.php
 *
 * Method: POST
 * Auth: super_admin
 * CSRF: required
 *
 * Body: { user_id: int }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/audit_helper.php';

initializeApiEnvironment();

/**
 * Generate a user-friendly OTP.
 * - Avoids ambiguous characters (0/O, 1/I/l)
 */
function cnx_generate_reset_otp(int $length = 12): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/**
 * Check if users OTP columns exist.
 */
function cnx_users_has_otp_columns(Database $db): bool
{
    try {
        $rows = $db->fetchAll(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME IN ('password_reset_otp_hash', 'password_reset_otp_expires')"
        );
        $names = array_map(static fn($r) => (string)($r['COLUMN_NAME'] ?? ''), $rows);
        return in_array('password_reset_otp_hash', $names, true) && in_array('password_reset_otp_expires', $names, true);
    } catch (Throwable $e) {
        return false;
    }
}

try {
    verifyApiAuthentication();
    verifyApiCsrfToken(true);
    requireApiRole('super_admin');

    $input = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($input) || empty($input)) {
        $input = $_POST;
    }

    $userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    if ($userId <= 0) {
        apiError('ID utente non valido', 400);
    }

    $db = Database::getInstance();

    // Require schema (avoid 500 / console noise: return 200 with success=false + instructions)
    if (!cnx_users_has_otp_columns($db)) {
        apiError(
            'Sistema OTP non configurato (mancano colonne users.password_reset_otp_hash / users.password_reset_otp_expires). Applica la migrazione 26.',
            200,
            ['required_migration' => 'database/migrations/26_password_reset_otp.sql']
        );
    }

    $user = $db->fetchOne(
        "SELECT id, email, name, tenant_id
         FROM users
         WHERE id = ?
           AND deleted_at IS NULL
           AND is_active = 1
         LIMIT 1",
        [$userId]
    );
    if (!$user) {
        apiError('Utente non trovato', 404);
    }

    if (empty($user['email'])) {
        apiError('Utente senza email configurata', 400);
    }

    // Build tenant name (best effort)
    $tenantName = '';
    if (!empty($user['tenant_id'])) {
        $t = $db->fetchOne("SELECT name FROM tenants WHERE id = ? LIMIT 1", [(int)$user['tenant_id']]);
        $tenantName = (string)($t['name'] ?? '');
    }

    // Generate reset token + OTP (24h)
    $resetToken = bin2hex(random_bytes(32));
    $tokenExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $otp = cnx_generate_reset_otp(12);
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    $otpExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // Persist to user (invalidate previous reset)
    $db->update('users', [
        'password_reset_token' => $resetToken,
        'password_reset_expires' => $tokenExpires,
        'password_reset_otp_hash' => $otpHash,
        'password_reset_otp_expires' => $otpExpires,
        'updated_at' => date('Y-m-d H:i:s')
    ], [
        'id' => (int)$user['id']
    ]);

    $baseUrl = defined('BASE_URL') ? (string)BASE_URL : 'http://localhost:8888/CollaboraNexio';
    $resetLink = rtrim($baseUrl, '/') . '/set_password.php?token=' . urlencode($resetToken);

    $subject = 'Reset password Nexio - credenziali one-time';

    $brandColor = '#1a2332';
    $layoutVars = [
        'BASE_URL' => $baseUrl,
        'TENANT_NAME' => $tenantName,
        'YEAR' => date('Y'),
    ];

    $safeUser = cnx_email_escape((string)$user['name']);
    $safeOtp = cnx_email_escape($otp);

    $body = ''
        . '<p style="margin:0 0 10px 0;">Ciao <strong>' . $safeUser . '</strong>,</p>'
        . '<p style="margin:0 0 14px 0;">È stato richiesto un reset della password per il tuo account.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0;background:#fafafa;border:1px solid #eef0f3;border-radius:8px;">'
        . '  <tr><td style="padding:12px 12px;">'
        . '    <div style="font-weight:700;margin:0 0 6px 0;color:#111827;">Password one-time</div>'
        . '    <div style="font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:16px;letter-spacing:0.3px;background:#ffffff;border:1px solid #e5e7eb;border-radius:6px;padding:10px 12px;display:inline-block;">'
        .       $safeOtp
        . '    </div>'
        . '  </td></tr>'
        . '</table>'
        . '<p style="margin:0 0 10px 0;">Apri questo link e inserisci la password one-time per impostare una nuova password:</p>'
        . renderEmailPrimaryButton($resetLink, 'Imposta nuova password', $brandColor)
        . '<div style="margin:12px 0 0 0;padding:10px 12px;border-left:3px solid ' . cnx_email_escape($brandColor) . ';background:#fbfbfb;border-radius:6px;color:#374151;">'
        . '  <strong>Validità:</strong> 24 ore.'
        . '</div>'
        . '<p style="margin:12px 0 0 0;color:#6b7280;font-size:13px;">Se non hai richiesto tu questa operazione, puoi ignorare questa email.</p>';

    $htmlBody = renderEmailLayout(
        'Reset password (credenziali one-time)',
        $body,
        $layoutVars,
        [
            'brandColor' => $brandColor,
            'preheader' => 'Password one-time + link per impostare una nuova password (valido 24 ore).',
        ]
    );

    $textBody = "Ciao {$user['name']},\n\n"
        . "È stato richiesto un reset della password per il tuo account.\n\n"
        . "Password one-time: {$otp}\n\n"
        . "Apri questo link e inserisci la password one-time per impostare una nuova password:\n{$resetLink}\n\n"
        . "Validità: 24 ore.\n";

    $context = [
        'action' => 'one_time_password_reset',
        'tenant_id' => $user['tenant_id'] ?? null,
        'target_user_id' => (int)$user['id'],
    ];

    $ok = sendEmail((string)$user['email'], $subject, $htmlBody, $textBody, ['context' => $context]);
    if (!$ok) {
        apiError('Invio email fallito (verifica configurazione SMTP)', 200);
    }

    // Audit best effort
    try {
        AuditLogger::logGeneric(
            (int)($_SESSION['user_id'] ?? 0),
            (int)($user['tenant_id'] ?? 0),
            'one_time_password_reset_send',
            'user',
            (int)$user['id'],
            'Inviata password one-time + link set_password',
            [
                'otp_expires' => $otpExpires,
                'token_expires' => $tokenExpires
            ]
        );
    } catch (Throwable $e) {
        // non-blocking
    }

    apiSuccess([
        'user_id' => (int)$user['id'],
        'otp_expires' => $otpExpires,
        'token_expires' => $tokenExpires
    ], 'Password one-time e link inviati con successo');

} catch (Throwable $e) {
    error_log('[users/send_one_time_password_reset] ' . $e->getMessage());
    apiError('Errore interno del server', 500);
}


