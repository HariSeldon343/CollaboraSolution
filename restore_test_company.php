<?php
/**
 * Restore Test Company for testing
 */

require_once __DIR__ . '/includes/db.php';

$db = Database::getInstance();

echo "Restoring Test Company...\n";

$db->update(
    'tenants',
    ['deleted_at' => null],
    ['id' => 2]
);

echo "Test Company restored!\n\n";

// Verify
$tenant = $db->fetchOne('SELECT * FROM tenants WHERE id = 2');
echo "Tenant: {$tenant['denominazione']}\n";
echo "Status: {$tenant['status']}\n";
echo "Deleted at: " . ($tenant['deleted_at'] ?? 'null') . "\n";
