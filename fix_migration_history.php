<?php
/**
 * Script to fix migration history for already-completed migrations
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "Fixing migration history...\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // List of migrations that should be marked as completed based on existing database state
    $completedMigrations = [
        'add_approval_fields_to_files',      // Files table has approval columns
        'create_user_tenant_access_table',   // Table exists
        'create_document_approvals_table',   // Table exists
        'create_approval_notifications_table', // Table exists
    ];

    foreach ($completedMigrations as $migration) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO migration_history (migration_name) VALUES (:name)");
        $stmt->execute([':name' => $migration]);
        if ($stmt->rowCount() > 0) {
            echo "✓ Marked migration as completed: {$migration}\n";
        } else {
            echo "- Migration already marked: {$migration}\n";
        }
    }

    // Now mark populate_user_tenant_access as well
    echo "\nPopulating user_tenant_access table...\n";

    // First, populate for existing admins/super_admins
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO user_tenant_access (user_id, tenant_id)
        SELECT DISTINCT u.id, u.tenant_id
        FROM users u
        WHERE u.role IN ('admin', 'super_admin')
        AND u.deleted_at IS NULL
    ");
    $stmt->execute();
    echo "Added {$stmt->rowCount()} admin users to their primary tenants\n";

    // Grant super admins access to all active tenants
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO user_tenant_access (user_id, tenant_id)
        SELECT u.id, t.id
        FROM users u
        CROSS JOIN tenants t
        WHERE u.role = 'super_admin'
        AND u.deleted_at IS NULL
        AND t.status = 'active'
    ");
    $stmt->execute();
    echo "Granted super admins access to all tenants ({$stmt->rowCount()} entries)\n";

    // Mark this migration as completed
    $stmt = $pdo->prepare("INSERT IGNORE INTO migration_history (migration_name) VALUES ('populate_user_tenant_access')");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        echo "✓ Marked migration as completed: populate_user_tenant_access\n";
    }

    echo "\n✅ Migration history fixed successfully!\n";

    // Show final migration status
    echo "\nFinal migration status:\n";
    $stmt = $pdo->query("SELECT migration_name, executed_at FROM migration_history ORDER BY executed_at");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  ✓ {$row['migration_name']} - {$row['executed_at']}\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}