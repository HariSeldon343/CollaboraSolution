<?php
/**
 * API: Resend password expiry code (super_admin)
 *
 * Method: POST
 * Auth: super_admin
 * CSRF: required
 *
 * Body: { user_id: int, csrf_token?: string }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api_auth.php';
initializeApiEnvironment();

try {
    verifyApiAuthentication();
    verifyApiCsrfToken(true);
    requireApiRole('super_admin');

    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/mailer.php';
    require_once __DIR__ . '/../../includes/password_expiry_helper.php';
    require_once __DIR__ . '/../../includes/audit_helper.php';

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    if ($userId <= 0) {
        apiError('ID utente non valido', 400);
    }

    $db = Database::getInstance();

    // Ensure codes table exists
    $tbl = $db->fetchOne(
        "SELECT COUNT(*) AS cnt
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'password_expiry_codes'"
    );
    if ((int)($tbl['cnt'] ?? 0) === 0) {
        apiError('Sistema codici scadenza non configurato (manca tabella password_expiry_codes)', 500);
    }

    $user = $db->fetchOne(
        "SELECT id, email, name, tenant_id, password_expires_at
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

    if (empty($user['password_expires_at'])) {
        apiError('Questo utente non ha una scadenza password impostata', 400);
    }

    $expiresAt = (string)$user['password_expires_at'];
    $codeExpiresAt = date('Y-m-d H:i:s', strtotime($expiresAt . ' +1 day'));

    $tenantName = '';
    if (!empty($user['tenant_id'])) {
        $t = $db->fetchOne("SELECT name FROM tenants WHERE id = ? LIMIT 1", [(int)$user['tenant_id']]);
        $tenantName = (string)($t['name'] ?? '');
    }

    // Create new code (invalidates previous unused)
    $created = cnx_create_password_expiry_code($db, (int)$user['id'], $codeExpiresAt, (int)($_SESSION['user_id'] ?? 0));

    $ok = sendPasswordExpiryNoticeEmail(
        (string)$user['email'],
        (string)$user['name'],
        $tenantName,
        $expiresAt,
        (string)$created['code'],
        defined('BASE_URL') ? BASE_URL : null,
        [
            'tenant_id' => $user['tenant_id'] ?? null,
            'user_id' => (int)$user['id'],
            'action' => 'password_expiry_code_resend'
        ]
    );

    if (!$ok) {
        apiError('Invio email fallito (verifica configurazione SMTP)', 500);
    }

    cnx_mark_password_expiry_code_sent($db, (int)$created['code_id'], (int)($_SESSION['user_id'] ?? 0));

    // Audit best effort
    try {
        AuditLogger::logGeneric(
            (int)($_SESSION['user_id'] ?? 0),
            (int)($user['tenant_id'] ?? 0),
            'password_expiry_code_resend',
            'user',
            (int)$user['id'],
            'Reinviato codice scadenza password (6 cifre)',
            [
                'code_id' => (int)$created['code_id'],
                'expires_at' => $codeExpiresAt
            ]
        );
    } catch (Throwable $e) {
        // non-blocking
    }

    apiSuccess([
        'user_id' => (int)$user['id'],
        'code_id' => (int)$created['code_id']
    ], 'Codice reinviato con successo');

} catch (Throwable $e) {
    error_log('[users/resend_password_expiry_code] ' . $e->getMessage());
    apiError('Errore interno del server', 500);
}


