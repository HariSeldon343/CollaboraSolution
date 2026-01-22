<?php
/**
 * Purge calendar events (safe default: DRY-RUN unless --force).
 *
 * Usage:
 *   php tools/purge_calendar_events.php --tenant=11 --force
 *   php tools/purge_calendar_events.php --all --force
 *   php tools/purge_calendar_events.php --all            (dry-run)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

require_once dirname(__DIR__) . '/includes/db.php';

function hasColumn(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return (int)($row['c'] ?? 0) > 0;
}

$opts = getopt('', ['tenant:', 'all', 'force', 'dry-run']);
$force = isset($opts['force']);
$dryRun = isset($opts['dry-run']) || !$force;
$all = isset($opts['all']);
$tenantOpt = isset($opts['tenant']) ? (int)$opts['tenant'] : 0;

if (!$all && $tenantOpt <= 0) {
    fwrite(STDERR, "ERROR: specify --tenant=<id> or --all\n");
    exit(2);
}

$db = Database::getInstance();
$pdo = $db->getConnection();

$eventsHasTenant = hasColumn($pdo, 'events', 'tenant_id');
if (!$eventsHasTenant) {
    fwrite(STDERR, "ERROR: events table has no tenant_id column\n");
    exit(3);
}

$eventsHasDeletedAt = hasColumn($pdo, 'events', 'deleted_at');
$eventsHasDeletedBy = hasColumn($pdo, 'events', 'deleted_by');
$eventsHasUpdatedAt = hasColumn($pdo, 'events', 'updated_at');

$tenantIds = [];
if ($all) {
    $stmt = $pdo->query("SELECT DISTINCT tenant_id FROM events ORDER BY tenant_id");
    $tenantIds = array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
} else {
    $tenantIds = [$tenantOpt];
}

if (empty($tenantIds)) {
    echo "No tenants found in events.\n";
    exit(0);
}

echo ($dryRun ? "DRY-RUN (add --force to execute)\n" : "EXECUTION MODE\n");
echo "Tenants: " . implode(', ', $tenantIds) . "\n";

foreach ($tenantIds as $tenantId) {
    $where = "tenant_id = :tenant_id";
    $params = [':tenant_id' => $tenantId];
    if ($eventsHasDeletedAt) {
        $where .= " AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM events WHERE {$where}");
    $stmt->execute($params);
    $count = (int)(($stmt->fetch(PDO::FETCH_ASSOC) ?: [])['c'] ?? 0);
    echo "Tenant {$tenantId}: events to purge = {$count}\n";

    if ($dryRun || $count === 0) {
        continue;
    }

    $pdo->beginTransaction();
    try {
        if ($eventsHasDeletedAt) {
            $set = ["deleted_at = NOW()"];
            if ($eventsHasDeletedBy) $set[] = "deleted_by = 0";
            if ($eventsHasUpdatedAt) $set[] = "updated_at = NOW()";

            $sql = "UPDATE events SET " . implode(', ', $set) . " WHERE {$where}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $affected = $stmt->rowCount();
            echo "Tenant {$tenantId}: soft-deleted {$affected} events\n";
        } else {
            // Fallback: hard delete
            $stmt = $pdo->prepare("DELETE ep FROM event_participants ep INNER JOIN events e ON e.id = ep.event_id WHERE e.tenant_id = :tenant_id");
            $stmt->execute([':tenant_id' => $tenantId]);

            $stmt = $pdo->prepare("DELETE er FROM event_reminders er INNER JOIN events e ON e.id = er.event_id WHERE e.tenant_id = :tenant_id");
            $stmt->execute([':tenant_id' => $tenantId]);

            $stmt = $pdo->prepare("DELETE FROM events WHERE tenant_id = :tenant_id");
            $stmt->execute([':tenant_id' => $tenantId]);
            echo "Tenant {$tenantId}: hard-deleted events + related rows\n";
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "ERROR tenant {$tenantId}: {$e->getMessage()}\n");
        exit(4);
    }
}

echo "Done.\n";


