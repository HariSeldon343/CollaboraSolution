<?php
/**
 * Audit Integrity Backfill
 *
 * Signs existing audit_logs rows that have no integrity_hash, in chronological order per tenant.
 *
 * Usage:
 *   php tools/audit_integrity_backfill.php
 *   php tools/audit_integrity_backfill.php --tenant_id=11
 *   php tools/audit_integrity_backfill.php --limit=500
 *
 * Notes:
 * - Requires environment variables:
 *   - AUDIT_LOG_HMAC_KEY
 *   - AUDIT_LOG_HMAC_KEY_ID
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
$limit = (int)(cliOpt('limit', 0) ?: 0);

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

    $totalSigned = 0;
    foreach ($tenants as $tenantId) {
        if ($tenantId <= 0) continue;
        out("Tenant {$tenantId}: scanning unsigned logs...");

        $sql = "
            SELECT id
            FROM audit_logs
            WHERE tenant_id = ?
              AND (integrity_hash IS NULL OR integrity_hash = '')
            ORDER BY created_at ASC, id ASC
        ";
        if ($limit > 0) $sql .= " LIMIT " . (int)$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tenantId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        if (!$ids) {
            out("Tenant {$tenantId}: nothing to sign.");
            continue;
        }

        $signed = 0;
        foreach ($ids as $id) {
            $res = audit_integrity_signLog($pdo, $tenantId, (int)$id);
            if (($res['status'] ?? '') === 'ok') {
                $signed++;
            } else {
                out("Tenant {$tenantId}: FAILED signing id={$id}: " . ($res['reason'] ?? 'unknown'));
            }
        }

        $totalSigned += $signed;
        out("Tenant {$tenantId}: signed {$signed} logs.");
    }

    out("Backfill completed. Total signed: {$totalSigned}");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Backfill failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

