<?php
/**
 * Tool: Deduplicate tenants by codice_fiscale / partita_iva (safe merge)
 *
 * Usage:
 *   php tools/tenant_deduplicate.php            # dry-run
 *   php tools/tenant_deduplicate.php --apply    # apply changes
 *
 * What it does:
 * - Finds active (deleted_at IS NULL) duplicate tenants by:
 *   - partita_iva (non-empty)
 *   - codice_fiscale (non-empty)
 * - Chooses a canonical tenant per group (most referenced by users, else smallest id)
 * - Rewrites tenant_id references in known tables (if table+column exist)
 * - Soft-deletes duplicate tenant rows (sets deleted_at, and status=inactive if column exists)
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

$apply = in_array('--apply', $argv, true);

function out(string $msg): void {
    echo $msg . PHP_EOL;
}

function tableExists(Database $db, string $table): bool {
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS cnt
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?",
        [$table]
    );
    return (int)($row['cnt'] ?? 0) > 0;
}

function columnExists(Database $db, string $table, string $column): bool {
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS cnt
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?",
        [$table, $column]
    );
    return (int)($row['cnt'] ?? 0) > 0;
}

function pickCanonicalTenantId(Database $db, array $tenantIds): int {
    // Prefer tenant referenced by most users. If tie, smallest id.
    $placeholders = implode(',', array_fill(0, count($tenantIds), '?'));
    $rows = $db->fetchAll(
        "SELECT t.id,
                (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) AS user_count
         FROM tenants t
         WHERE t.id IN ($placeholders)
         ORDER BY user_count DESC, t.id ASC",
        $tenantIds
    );
    return (int)$rows[0]['id'];
}

$db = Database::getInstance();

out("Tenant deduplicate: mode=" . ($apply ? 'APPLY' : 'DRY-RUN'));

// Find duplicates by partita_iva
$dupGroups = [];

$pivaGroups = $db->fetchAll(
    "SELECT partita_iva AS k, GROUP_CONCAT(id ORDER BY id) AS ids, COUNT(*) AS cnt
     FROM tenants
     WHERE deleted_at IS NULL
       AND partita_iva IS NOT NULL
       AND partita_iva <> ''
     GROUP BY partita_iva
     HAVING cnt > 1"
);

foreach ($pivaGroups as $g) {
    $dupGroups[] = ['type' => 'partita_iva', 'key' => (string)$g['k'], 'ids' => array_map('intval', explode(',', (string)$g['ids']))];
}

// Find duplicates by codice_fiscale
$cfGroups = $db->fetchAll(
    "SELECT UPPER(codice_fiscale) AS k, GROUP_CONCAT(id ORDER BY id) AS ids, COUNT(*) AS cnt
     FROM tenants
     WHERE deleted_at IS NULL
       AND codice_fiscale IS NOT NULL
       AND codice_fiscale <> ''
     GROUP BY UPPER(codice_fiscale)
     HAVING cnt > 1"
);

foreach ($cfGroups as $g) {
    $dupGroups[] = ['type' => 'codice_fiscale', 'key' => (string)$g['k'], 'ids' => array_map('intval', explode(',', (string)$g['ids']))];
}

if (empty($dupGroups)) {
    out("No duplicate tenant groups found.");
    exit(0);
}

// Tables to rewrite tenant_id in (if exist + have tenant_id col)
$tenantIdTables = [
    'users',
    'user_tenant_access',
    'tenant_roles',
    'audit_logs',
    'activity_logs',
    'tickets',
    'tasks',
    'files',
    'folders',
    'file_assignments',
    'events',
    'documents',
];

foreach ($dupGroups as $group) {
    $ids = $group['ids'];
    if (count($ids) < 2) continue;

    $canonical = pickCanonicalTenantId($db, $ids);
    $dups = array_values(array_filter($ids, fn($id) => $id !== $canonical));

    out("Group {$group['type']}={$group['key']} ids=[" . implode(',', $ids) . "] canonical=$canonical dups=[" . implode(',', $dups) . "]");

    if (!$apply) {
        continue;
    }

    $db->beginTransaction();
    try {
        foreach ($dups as $dupId) {
            // Rewrite tenant_id references
            foreach ($tenantIdTables as $table) {
                if (!tableExists($db, $table)) continue;
                if (!columnExists($db, $table, 'tenant_id')) continue;

                // Special-case: tenant_locations has a UNIQUE constraint (tenant_id, location_type, is_primary).
                // To avoid conflicts, do NOT merge duplicate sede_legale rows into canonical. Keep canonical sede_legale,
                // and retire duplicate sede_legale rows; merge only non-sede_legale rows.
                if ($table === 'tenant_locations') {
                    $hasLocationType = columnExists($db, 'tenant_locations', 'location_type');
                    $hasIsPrimary = columnExists($db, 'tenant_locations', 'is_primary');
                    $hasDeletedAt = columnExists($db, 'tenant_locations', 'deleted_at');
                    if ($hasLocationType && $hasIsPrimary && $hasDeletedAt) {
                        $db->query(
                            "UPDATE tenant_locations
                             SET is_primary = 0,
                                 deleted_at = IFNULL(deleted_at, NOW())
                             WHERE tenant_id = ?
                               AND location_type = 'sede_legale'
                               AND is_primary = 1",
                            [$dupId]
                        );
                    }

                    // Retire ALL sede_legale rows from duplicate to avoid UNIQUE conflicts (even is_primary=0 is unique)
                    if ($hasDeletedAt) {
                        $db->query(
                            "UPDATE tenant_locations
                             SET deleted_at = IFNULL(deleted_at, NOW()),
                                 is_active = 0
                             WHERE tenant_id = ?
                               AND location_type = 'sede_legale'",
                            [$dupId]
                        );
                    }

                    // Merge only non-sede_legale rows
                    $db->query(
                        "UPDATE tenant_locations
                         SET tenant_id = ?
                         WHERE tenant_id = ?
                           AND location_type <> 'sede_legale'",
                        [$canonical, $dupId]
                    );
                    continue;
                }

                $db->query(
                    "UPDATE `$table` SET tenant_id = ? WHERE tenant_id = ?",
                    [$canonical, $dupId]
                );
            }

            // Soft delete duplicate tenant
            $update = ['deleted_at' => date('Y-m-d H:i:s')];
            if (columnExists($db, 'tenants', 'status')) {
                $update['status'] = 'inactive';
            }
            $db->update('tenants', $update, ['id' => $dupId]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollback();
        throw $e;
    }
}

out("Done.");


