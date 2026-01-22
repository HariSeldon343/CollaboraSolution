<?php
/**
 * Password expiry codes helper
 *
 * - Generates 6-digit numeric codes (stored hashed)
 * - Verifies codes (when password is expired)
 * - Marks codes as sent/used
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Ensure password_expiry_codes table exists (schema-drift safe).
 *
 * Some environments may not have applied Migration 20 yet. Without this table,
 * users with expired passwords would be locked out. We attempt a best-effort
 * CREATE TABLE IF NOT EXISTS with a minimal schema compatible with this helper.
 */
function cnx_ensure_password_expiry_codes_table(Database $db): bool
{
    try {
        $tbl = $db->fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'password_expiry_codes'"
        );
        if ((int)($tbl['cnt'] ?? 0) > 0) {
            return true;
        }
    } catch (Throwable $e) {
        // continue to best-effort create
    }

    try {
        // Minimal schema (no foreign keys) to maximize compatibility.
        $db->query("
            CREATE TABLE IF NOT EXISTS password_expiry_codes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                code_hash VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                sent_at DATETIME NULL,
                used_at DATETIME NULL,
                sent_by_user_id INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_password_expiry_codes_user_used (user_id, used_at),
                INDEX idx_password_expiry_codes_user_expires (user_id, expires_at),
                INDEX idx_password_expiry_codes_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('[PasswordExpiry] Failed to auto-create password_expiry_codes: ' . $e->getMessage());
        return false;
    }

    try {
        $tbl = $db->fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'password_expiry_codes'"
        );
        return (int)($tbl['cnt'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Generate a 6-digit numeric code as string (leading zeros allowed).
 */
function cnx_generate_6digit_code(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Invalidate any previous unused codes for the user (so only one active code exists).
 */
function cnx_invalidate_unused_password_expiry_codes(Database $db, int $userId): void
{
    try {
        $db->query(
            "UPDATE password_expiry_codes
             SET used_at = NOW()
             WHERE user_id = ?
               AND used_at IS NULL",
            [$userId]
        );
    } catch (Throwable $e) {
        // Non-blocking: table might not exist in older environments
        error_log('[PasswordExpiry] Failed to invalidate old codes: ' . $e->getMessage());
    }
}

/**
 * Create a new expiry code record for a user and return plaintext code + code_id.
 *
 * @return array{code_id:int, code:string, expires_at:string}
 */
function cnx_create_password_expiry_code(Database $db, int $userId, string $expiresAt, ?int $sentByUserId = null): array
{
    $code = cnx_generate_6digit_code();
    $hash = password_hash($code, PASSWORD_DEFAULT);

    // Ensure only one active code exists per user
    cnx_invalidate_unused_password_expiry_codes($db, $userId);

    $db->insert('password_expiry_codes', [
        'user_id' => $userId,
        'code_hash' => $hash,
        'expires_at' => $expiresAt,
        'sent_at' => null,
        'used_at' => null,
        'sent_by_user_id' => $sentByUserId,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    $codeId = (int)$db->lastInsertId();

    return [
        'code_id' => $codeId,
        'code' => $code,
        'expires_at' => $expiresAt
    ];
}

/**
 * Mark a code as sent.
 */
function cnx_mark_password_expiry_code_sent(Database $db, int $codeId, ?int $sentByUserId = null): void
{
    $data = ['sent_at' => date('Y-m-d H:i:s')];
    if ($sentByUserId !== null) {
        $data['sent_by_user_id'] = $sentByUserId;
    }
    $db->update('password_expiry_codes', $data, ['id' => $codeId]);
}

/**
 * Verify a user-provided code.
 *
 * @return int|null code_id if valid, otherwise null
 */
function cnx_verify_password_expiry_code(Database $db, int $userId, string $code): ?int
{
    $code = trim($code);
    if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
        return null;
    }

    // Check a few most recent active codes (in case of resend)
    $rows = $db->fetchAll(
        "SELECT id, code_hash
         FROM password_expiry_codes
         WHERE user_id = ?
           AND used_at IS NULL
           AND expires_at >= NOW()
         ORDER BY id DESC
         LIMIT 5",
        [$userId]
    );

    foreach ($rows as $row) {
        if (!empty($row['code_hash']) && password_verify($code, (string)$row['code_hash'])) {
            return (int)$row['id'];
        }
    }

    return null;
}

/**
 * Mark a code as used.
 */
function cnx_mark_password_expiry_code_used(Database $db, int $codeId): void
{
    $db->update('password_expiry_codes', ['used_at' => date('Y-m-d H:i:s')], ['id' => $codeId]);
}


