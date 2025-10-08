<?php
/**
 * Test script to reproduce the tenant deletion issue
 */

declare(strict_types=1);

// Start session
session_start();

// Load configuration
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Simulate super_admin session
$_SESSION['user_id'] = 1;
$_SESSION['tenant_id'] = 1;
$_SESSION['role'] = 'super_admin';
$_SESSION['user_role'] = 'super_admin';

echo "=== Testing Tenant Deletion Logic ===\n\n";

try {
    $db = Database::getInstance();
    $tenantId = 2; // Test Company

    // Check if tenant exists
    $tenant = $db->fetchOne(
        'SELECT id, name, denominazione FROM tenants WHERE id = ? AND deleted_at IS NULL',
        [$tenantId]
    );

    echo "Tenant found: ";
    print_r($tenant);
    echo "\n";

    // Test count with deleted_at = null
    echo "Testing count method with deleted_at => null:\n";

    $userCount = $db->count('users', [
        'tenant_id' => $tenantId,
        'deleted_at' => null
    ]);
    echo "User count: $userCount\n";

    $fileCount = $db->count('files', [
        'tenant_id' => $tenantId,
        'deleted_at' => null
    ]);
    echo "File count: $fileCount\n";

    $projectCount = $db->count('projects', [
        'tenant_id' => $tenantId,
        'deleted_at' => null
    ]);
    echo "Project count: $projectCount\n\n";

    // Test update
    echo "Testing update method:\n";
    $deletedAt = date('Y-m-d H:i:s');

    // Simulate the update that would happen in delete.php
    echo "Would update tenants with deleted_at = $deletedAt for id = $tenantId\n";
    echo "Would update users with deleted_at = $deletedAt for tenant_id = $tenantId\n";
    echo "Would update projects with deleted_at = $deletedAt for tenant_id = $tenantId\n";
    echo "Would update files with deleted_at = $deletedAt for tenant_id = $tenantId\n";

    echo "\n=== Test completed successfully ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
