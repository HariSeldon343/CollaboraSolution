<?php
/**
 * Verify tenant deletion
 */

require_once __DIR__ . '/includes/db.php';

$db = Database::getInstance();

echo "=== Verifying Tenant Deletion ===\n\n";

// Check active tenants
echo "Active Tenants (deleted_at IS NULL):\n";
$activeTenants = $db->fetchAll('SELECT id, name, denominazione, deleted_at FROM tenants WHERE deleted_at IS NULL');
foreach ($activeTenants as $tenant) {
    echo "  - ID {$tenant['id']}: {$tenant['denominazione']}\n";
}

echo "\nDeleted Tenants (deleted_at IS NOT NULL):\n";
$deletedTenants = $db->fetchAll('SELECT id, name, denominazione, deleted_at FROM tenants WHERE deleted_at IS NOT NULL');
foreach ($deletedTenants as $tenant) {
    echo "  - ID {$tenant['id']}: {$tenant['denominazione']} (deleted at {$tenant['deleted_at']})\n";
}

echo "\n=== Latest Audit Logs ===\n";
$auditLogs = $db->fetchAll('SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 3');
foreach ($auditLogs as $log) {
    echo "Action: {$log['action']}, Entity: {$log['entity_type']} #{$log['entity_id']}, User: {$log['user_id']}\n";
    if ($log['old_values']) {
        $oldValues = json_decode($log['old_values'], true);
        echo "  Old values: " . print_r($oldValues, true) . "\n";
    }
}
