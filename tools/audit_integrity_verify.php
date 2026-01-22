<?php
/**
 * Audit Integrity Verify
 *
 * Verifies integrity_hash for the latest N signed logs per tenant.
 * Also checks chain consistency (prev-hash) using audit_integrity_verifyLog().
 *
 * Usage:
 *   php tools/audit_integrity_verify.php
 *   php tools/audit_integrity_verify.php --tenant_id=11 --limit=200
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit_integrity.php';

function cliOpt(string $name, $default = null)
{
    $opts = getopt('', [$name . '::']);
    return array_key_exists($name, $opts) ? $opts[$name] : $default;
}

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

$keyInfo = audit_integrity_get_key();
if ($keyInfo === null) {
    fwrite(STDERR, "Missing AUDIT_LOG_HMAC_KEY / AUDIT_LOG_HMAC_KEY_ID environment variables.\n");
    exit(2);
}

$tenantFilter = cliOpt('tenant_id', null);
$limit = (int)(cliOpt('limit', 200) ?: 200);
if ($limit <= 0) $limit = 200;

try {
    $pdo = Database::getInstance()->getConnection();

    $tenants = [];
    if ($tenantFilter !== null && $tenantFilter !== '') {
        $tenants = [(int)$tenantFilter];
    } else {
        $rows = $pdo->query("SELECT DISTINCT tenant_id FROM audit_logs ORDER BY tenant_id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $tenants = array_map(static fn($r) => (int)$r['tenant_id'], $rows);
    }

    if (!$tenants) {
        out('No tenants found in audit_logs.');
        exit(0);
    }

    $failures = 0;
    foreach ($tenants as $tenantId) {
        if ($tenantId <= 0) continue;
        out("Tenant {$tenantId}: verifying last {$limit} signed logs...");

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
            WHERE tenant_id = ?
              AND integrity_hash IS NOT NULL
              AND integrity_hash <> ''
            ORDER BY created_at DESC, id DESC
            LIMIT ?
        ");
        $stmt->execute([$tenantId, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            out("Tenant {$tenantId}: no signed logs found.");
            continue;
        }

        foreach ($rows as $row) {
            $res = audit_integrity_verifyLog($pdo, $row);
            if (!$res['ok']) {
                $failures++;
                out("FAIL tenant={$tenantId} id={$row['id']} status={$res['status']} errors=" . implode(',', $res['errors'] ?? []));
            }
        }

        out("Tenant {$tenantId}: checked " . count($rows) . " rows.");
    }

    if ($failures > 0) {
        out("Verification finished with failures: {$failures}");
        exit(1);
    }

    out('Verification OK (no failures).');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Verify failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

