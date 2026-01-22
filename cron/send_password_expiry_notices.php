<?php
/**
 * Cron Job: Send Password Expiry Notices (T-1 day) with 6-digit code
 *
 * Schedule: daily (e.g. 08:10)
 * Example:
 *   10 8 * * * /usr/bin/php /path/to/CollaboraNexio/cron/send_password_expiry_notices.php
 *
 * Logic:
 * - Find users whose password_expires_at is between +24h and +48h
 * - For each user, generate (or avoid duplicate) 6-digit code (hashed in DB)
 * - Send email notice containing code
 */

declare(strict_types=1);

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/password_expiry_helper.php';
require_once __DIR__ . '/../includes/audit_helper.php';

define('BATCH_SIZE', 200);

try {
    $startTime = microtime(true);
    $db = Database::getInstance();

    echo "[" . date('Y-m-d H:i:s') . "] Starting password expiry notice cron...\n";

    // Ensure required tables/columns exist (soft-fail if not migrated yet)
    $hasCodesTable = (int)($db->fetchOne(
        "SELECT COUNT(*) AS cnt
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'password_expiry_codes'"
    )['cnt'] ?? 0) > 0;

    $hasMaxAgeCol = (int)($db->fetchOne(
        "SELECT COUNT(*) AS cnt
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'password_max_age_days'"
    )['cnt'] ?? 0) > 0;

    if (!$hasCodesTable || !$hasMaxAgeCol) {
        echo "Migration not applied yet (missing password_expiry_codes and/or users.password_max_age_days). Exiting.\n";
        exit(0);
    }

    $from = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $to = date('Y-m-d H:i:s', strtotime('+48 hours'));

    echo "Looking for users expiring between $from and $to\n";

    $query = "
        SELECT
            u.id,
            u.email,
            u.name,
            u.tenant_id,
            u.password_expires_at,
            t.name AS tenant_name
        FROM users u
        LEFT JOIN tenants t ON t.id = u.tenant_id
        WHERE u.deleted_at IS NULL
          AND u.is_active = 1
          AND u.email IS NOT NULL
          AND u.email <> ''
          AND u.password_expires_at IS NOT NULL
          AND u.password_expires_at BETWEEN ? AND ?
        ORDER BY u.password_expires_at ASC
        LIMIT ?
    ";

    $users = $db->fetchAll($query, [$from, $to, BATCH_SIZE]);

    $processed = 0;
    $sent = 0;
    $skipped = 0;
    $errors = 0;

    foreach ($users as $u) {
        $processed++;
        $userId = (int)$u['id'];
        $email = (string)$u['email'];
        $userName = (string)($u['name'] ?? '');
        $tenantId = (int)($u['tenant_id'] ?? 0);
        $tenantName = (string)($u['tenant_name'] ?? '');
        $passwordExpiresAt = (string)$u['password_expires_at'];

        try {
            // Code validity: until password_expires_at + 1 day
            $codeExpiresAt = date('Y-m-d H:i:s', strtotime($passwordExpiresAt . ' +1 day'));

            // Avoid duplicate notices for same expiry (if already sent and still active)
            $alreadySent = $db->fetchOne(
                "SELECT id
                 FROM password_expiry_codes
                 WHERE user_id = ?
                   AND used_at IS NULL
                   AND sent_at IS NOT NULL
                   AND expires_at = ?
                 ORDER BY id DESC
                 LIMIT 1",
                [$userId, $codeExpiresAt]
            );

            if ($alreadySent) {
                $skipped++;
                continue;
            }

            // Create a new code record (invalidates previous unused codes)
            $created = cnx_create_password_expiry_code($db, $userId, $codeExpiresAt, null);

            $ok = sendPasswordExpiryNoticeEmail(
                $email,
                $userName,
                $tenantName,
                $passwordExpiresAt,
                $created['code'],
                defined('BASE_URL') ? BASE_URL : null,
                [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'action' => 'password_expiry_notice_cron'
                ]
            );

            if ($ok) {
                cnx_mark_password_expiry_code_sent($db, (int)$created['code_id'], null);
                $sent++;

                // Audit (best effort)
                try {
                    AuditLogger::logGeneric(
                        0,
                        $tenantId,
                        'password_expiry_notice',
                        'user',
                        $userId,
                        'Inviata email avviso scadenza password (T-1) con codice 6 cifre',
                        [
                            'code_id' => (int)$created['code_id'],
                            'expires_at' => $codeExpiresAt
                        ]
                    );
                } catch (Throwable $auditEx) {
                    error_log('[PasswordExpiryCron] Audit log failed: ' . $auditEx->getMessage());
                }
            } else {
                $errors++;
            }
        } catch (Throwable $e) {
            $errors++;
            error_log('[PasswordExpiryCron] Error for user_id=' . $userId . ': ' . $e->getMessage());
        }
    }

    $dur = round(microtime(true) - $startTime, 3);
    echo "Done. processed=$processed sent=$sent skipped=$skipped errors=$errors duration={$dur}s\n";

} catch (Throwable $e) {
    error_log('[PasswordExpiryCron] Fatal: ' . $e->getMessage());
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}


