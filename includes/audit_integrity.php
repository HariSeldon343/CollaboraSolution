<?php
/**
 * Audit Log Integrity (Tamper-Evident) Helper
 *
 * Implements per-tenant HMAC-SHA256 hash chaining:
 * - integrity_prev_hash: previous signed record hash for the same tenant
 * - integrity_hash: HMAC-SHA256(canonical_payload + prev_hash)
 *
 * Keying:
 * - Reads AUDIT_LOG_HMAC_KEY and AUDIT_LOG_HMAC_KEY_ID from environment.
 * - If not configured, signing/verifying returns "unconfigured".
 *
 * Concurrency:
 * - Uses GET_LOCK('auditlog:<tenant_id>', 5) to serialize chain updates per tenant.
 */

declare(strict_types=1);

/**
 * @return array{key:string,key_id:string}|null
 */
function audit_integrity_get_key(): ?array
{
    $ring = audit_integrity_get_keyring();
    $currentId = audit_integrity_get_current_key_id();
    if ($currentId !== null && isset($ring[$currentId]) && $ring[$currentId] !== '') {
        return ['key' => $ring[$currentId], 'key_id' => $currentId];
    }
    return null;
}

/**
 * Return a map of key_id => key (supports rotation / verifying older logs).
 *
 * Priority:
 * - Explicit env key (AUDIT_LOG_HMAC_KEY/AUDIT_LOG_HMAC_KEY_ID)
 * - Optional env JSON keyring: AUDIT_LOG_HMAC_KEYS_JSON (e.g. {"k1":"...","k2":"..."})
 * - Derived fallback from ENCRYPTION_KEY + JWT_SECRET (key_id = derived-v1)
 *
 * @return array<string,string>
 */
function audit_integrity_get_keyring(): array
{
    $ring = [];

    // 1) Optional keyring JSON for rotation/verification
    $json = getenv('AUDIT_LOG_HMAC_KEYS_JSON') ?: '';
    if ($json !== '') {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                if (is_string($k) && is_string($v) && $k !== '' && $v !== '') {
                    $ring[$k] = $v;
                }
            }
        }
    }

    // 2) Explicit current key/id
    $key = getenv('AUDIT_LOG_HMAC_KEY') ?: '';
    $keyId = getenv('AUDIT_LOG_HMAC_KEY_ID') ?: '';
    if ($key !== '' && $keyId !== '') {
        $ring[$keyId] = $key;
    }

    // 3) Derived fallback from application secrets already present in config.php
    // This avoids "unconfigured" in environments where env vars are not set.
    // SECURITY NOTE: ENCRYPTION_KEY/JWT_SECRET MUST be changed in production.
    $enc = defined('ENCRYPTION_KEY') ? (string)ENCRYPTION_KEY : '';
    $jwt = defined('JWT_SECRET') ? (string)JWT_SECRET : '';
    if ($enc !== '' && $jwt !== '') {
        $derived = hash('sha256', $enc . '|' . $jwt . '|audit_log_integrity_v1');
        $ring['derived-v1'] = $derived;
    }

    return $ring;
}

/**
 * Current key id for signing newly inserted logs.
 */
function audit_integrity_get_current_key_id(): ?string
{
    $keyId = getenv('AUDIT_LOG_HMAC_KEY_ID') ?: '';
    if ($keyId !== '') return $keyId;

    // Default derived key id
    $enc = defined('ENCRYPTION_KEY') ? (string)ENCRYPTION_KEY : '';
    $jwt = defined('JWT_SECRET') ? (string)JWT_SECRET : '';
    if ($enc !== '' && $jwt !== '') return 'derived-v1';

    return null;
}

/**
 * Build canonical JSON payload (stable ordering).
 * We use DB-native values to avoid differences in JSON re-encoding.
 *
 * @param array<string,mixed> $row Database row (raw fields)
 */
function audit_integrity_buildCanonicalPayload(array $row): string
{
    $keys = [
        'tenant_id',
        'id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'description',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
        'session_id',
        'request_method',
        'request_url',
        'request_data',
        'response_code',
        'execution_time_ms',
        'memory_usage_kb',
        'severity',
        'status',
        'created_at',
    ];

    $payload = [];
    foreach ($keys as $k) {
        $v = $row[$k] ?? null;
        // Normalize empty strings
        if ($v === '') $v = null;
        $payload[$k] = $v;
    }

    // Canonical JSON encoding
    return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function audit_integrity_computeHash(
    int $tenantId,
    string $prevHash,
    string $canonicalPayload,
    string $key
): string {
    // Include tenantId in the MAC input to prevent cross-tenant replay
    $macInput = $tenantId . '|' . $prevHash . '|' . $canonicalPayload;
    return hash_hmac('sha256', $macInput, $key);
}

function audit_integrity_lockTenant(PDO $pdo, int $tenantId, int $timeoutSec = 5): bool
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?) AS l');
    $stmt->execute(['auditlog:' . $tenantId, $timeoutSec]);
    return (int)$stmt->fetchColumn() === 1;
}

function audit_integrity_unlockTenant(PDO $pdo, int $tenantId): void
{
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute(['auditlog:' . $tenantId]);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Sign a single audit_logs record.
 *
 * @return array{status:string,reason?:string,integrity_hash?:string,integrity_prev_hash?:string,integrity_key_id?:string}
 */
function audit_integrity_signLog(PDO $pdo, int $tenantId, int $logId): array
{
    $keyInfo = audit_integrity_get_key();
    if ($keyInfo === null) {
        return ['status' => 'unconfigured', 'reason' => 'AUDIT_LOG_HMAC_KEY/AUDIT_LOG_HMAC_KEY_ID missing'];
    }

    if (!audit_integrity_lockTenant($pdo, $tenantId, 5)) {
        return ['status' => 'failed', 'reason' => 'Lock timeout'];
    }

    try {
        // Fetch the row we need to sign (raw DB values)
        $stmt = $pdo->prepare("
            SELECT
                tenant_id, id, user_id, action, entity_type, entity_id,
                description, old_values, new_values, metadata,
                ip_address, user_agent, session_id,
                request_method, request_url, request_data, response_code,
                execution_time_ms, memory_usage_kb,
                severity, status, created_at,
                integrity_algo, integrity_key_id, integrity_prev_hash, integrity_hash, integrity_signed_at
            FROM audit_logs
            WHERE tenant_id = ? AND id = ?
            LIMIT 1
        ");
        $stmt->execute([$tenantId, $logId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => 'failed', 'reason' => 'Log not found'];
        }

        // If already signed, do not re-sign (immutability)
        if (!empty($row['integrity_hash'])) {
            return [
                'status' => 'ok',
                'integrity_hash' => (string)$row['integrity_hash'],
                'integrity_prev_hash' => (string)($row['integrity_prev_hash'] ?? ''),
                'integrity_key_id' => (string)($row['integrity_key_id'] ?? ''),
            ];
        }

        // Get previous signed hash for this tenant (strict ordering by created_at,id)
        $stmtPrev = $pdo->prepare("
            SELECT integrity_hash
            FROM audit_logs
            WHERE tenant_id = ?
              AND integrity_hash IS NOT NULL
              AND integrity_hash <> ''
              AND (
                  created_at < (SELECT created_at FROM audit_logs WHERE tenant_id = ? AND id = ?)
                  OR (created_at = (SELECT created_at FROM audit_logs WHERE tenant_id = ? AND id = ?) AND id < ?)
              )
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stmtPrev->execute([$tenantId, $tenantId, $logId, $tenantId, $logId, $logId]);
        $prevHash = (string)($stmtPrev->fetchColumn() ?: '');

        $canonical = audit_integrity_buildCanonicalPayload($row);
        $hash = audit_integrity_computeHash($tenantId, $prevHash, $canonical, $keyInfo['key']);

        // Persist signature
        $upd = $pdo->prepare("
            UPDATE audit_logs
            SET
                integrity_algo = 'hmac-sha256',
                integrity_key_id = ?,
                integrity_prev_hash = ?,
                integrity_hash = ?,
                integrity_signed_at = NOW()
            WHERE tenant_id = ? AND id = ?
        ");
        $upd->execute([$keyInfo['key_id'], $prevHash, $hash, $tenantId, $logId]);

        return [
            'status' => 'ok',
            'integrity_hash' => $hash,
            'integrity_prev_hash' => $prevHash,
            'integrity_key_id' => $keyInfo['key_id'],
        ];
    } catch (Throwable $e) {
        return ['status' => 'failed', 'reason' => $e->getMessage()];
    } finally {
        audit_integrity_unlockTenant($pdo, $tenantId);
    }
}

/**
 * Verify a signed audit_logs row, including basic chain check.
 *
 * @param array<string,mixed> $row Raw DB row including integrity_* fields.
 * @return array{status:string,ok:bool,errors:string[],computed_hash?:string}
 */
function audit_integrity_verifyLog(PDO $pdo, array $row): array
{
    $ring = audit_integrity_get_keyring();
    $currentId = audit_integrity_get_current_key_id();
    if (!$ring) {
        return ['status' => 'key_unconfigured', 'ok' => false, 'errors' => ['integrity_key_unconfigured']];
    }

    $tenantId = (int)($row['tenant_id'] ?? 0);
    $logId = (int)($row['id'] ?? 0);
    $storedHash = (string)($row['integrity_hash'] ?? '');
    $storedPrev = (string)($row['integrity_prev_hash'] ?? '');
    $rowKeyId = (string)($row['integrity_key_id'] ?? '');

    if ($tenantId <= 0 || $logId <= 0) {
        return ['status' => 'fail', 'ok' => false, 'errors' => ['invalid_row_identity']];
    }
    if ($storedHash === '') {
        return ['status' => 'unsigned', 'ok' => false, 'errors' => ['integrity_hash_missing']];
    }

    $keyToUseId = $rowKeyId !== '' ? $rowKeyId : ($currentId ?? '');
    if ($keyToUseId === '' || !isset($ring[$keyToUseId]) || $ring[$keyToUseId] === '') {
        // Log was signed with a key we don't have (rotation) OR key not configured
        return [
            'status' => 'key_unknown',
            'ok' => false,
            'errors' => ['integrity_key_unknown'],
        ];
    }
    $key = $ring[$keyToUseId];

    $canonical = audit_integrity_buildCanonicalPayload($row);
    $computed = audit_integrity_computeHash($tenantId, $storedPrev, $canonical, $key);

    $errors = [];
    if (!hash_equals($storedHash, $computed)) {
        $errors[] = 'integrity_hash_mismatch';
    }

    // Chain check: ensure stored prev hash matches actual previous signed record
    try {
        $stmtPrev = $pdo->prepare("
            SELECT integrity_hash
            FROM audit_logs
            WHERE tenant_id = ?
              AND integrity_hash IS NOT NULL
              AND integrity_hash <> ''
              AND (created_at < ? OR (created_at = ? AND id < ?))
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stmtPrev->execute([(int)$row['tenant_id'], $row['created_at'], $row['created_at'], (int)$row['id']]);
        $actualPrev = (string)($stmtPrev->fetchColumn() ?: '');
        if ($actualPrev !== '' && $storedPrev !== $actualPrev) {
            $errors[] = 'integrity_prev_hash_mismatch';
        }
    } catch (Throwable $e) {
        $errors[] = 'integrity_prev_hash_check_failed';
    }

    return [
        'status' => $errors ? 'fail' : 'ok',
        'ok' => !$errors,
        'errors' => $errors,
        'computed_hash' => $computed,
    ];
}

