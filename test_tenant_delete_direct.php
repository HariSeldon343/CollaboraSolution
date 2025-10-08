<?php
/**
 * Direct test of tenant deletion logic
 */

declare(strict_types=1);

// Initialize environment
require_once __DIR__ . '/includes/api_auth.php';
initializeApiEnvironment();

// Simulate authenticated super_admin
$_SESSION['user_id'] = 1;
$_SESSION['tenant_id'] = 1;
$_SESSION['role'] = 'super_admin';
$_SESSION['user_role'] = 'super_admin';
$_SESSION['user_name'] = 'Admin User';
$_SESSION['user_email'] = 'admin@demo.com';
$_SESSION['csrf_token'] = 'test_token_123';

// Simulate POST data
$_POST['tenant_id'] = 2;
$_POST['csrf_token'] = 'test_token_123';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'TestScript/1.0';

require_once __DIR__ . '/includes/db.php';
$db = Database::getInstance();

try {
    // Get user info
    $userInfo = getApiUserInfo();

    echo "User Info: ";
    print_r($userInfo);
    echo "\n\n";

    // Verify super_admin role
    if ($userInfo['role'] !== 'super_admin') {
        throw new Exception('Not super_admin');
    }

    // Read input
    $input = $_POST;
    $tenantId = filter_var($input['tenant_id'] ?? 0, FILTER_VALIDATE_INT);

    echo "Tenant ID to delete: $tenantId\n";

    if (!$tenantId || $tenantId <= 0) {
        throw new Exception('Invalid tenant_id');
    }

    // Prevent deletion of system tenant (ID 1)
    if ($tenantId === 1) {
        throw new Exception('Cannot delete system tenant');
    }

    // Check if tenant exists
    $tenant = $db->fetchOne(
        'SELECT id, name, denominazione, status FROM tenants WHERE id = ? AND deleted_at IS NULL',
        [$tenantId]
    );

    if (!$tenant) {
        throw new Exception('Tenant not found or already deleted');
    }

    echo "Tenant found: {$tenant['denominazione']}\n";

    // Count associated resources
    $userCount = $db->count('users', [
        'tenant_id' => $tenantId,
        'deleted_at' => null
    ]);

    $fileCount = $db->count('files', [
        'tenant_id' => $tenantId,
        'deleted_at' => null
    ]);

    $projectCount = $db->count('projects', [
        'tenant_id' => $tenantId,
        'deleted_at' => null
    ]);

    echo "Resources found:\n";
    echo "  - Users: $userCount\n";
    echo "  - Files: $fileCount\n";
    echo "  - Projects: $projectCount\n\n";

    // Begin transaction
    $db->beginTransaction();

    try {
        $deletedAt = date('Y-m-d H:i:s');

        // 1. Soft-delete tenant
        echo "Soft-deleting tenant...\n";
        $db->update(
            'tenants',
            ['deleted_at' => $deletedAt],
            ['id' => $tenantId]
        );

        // 2. Soft-delete users
        if ($userCount > 0) {
            echo "Soft-deleting $userCount users...\n";
            $db->update(
                'users',
                ['deleted_at' => $deletedAt],
                ['tenant_id' => $tenantId]
            );
        }

        // 3. Soft-delete projects
        if ($projectCount > 0) {
            echo "Soft-deleting $projectCount projects...\n";
            $db->update(
                'projects',
                ['deleted_at' => $deletedAt],
                ['tenant_id' => $tenantId]
            );
        }

        // 4. Soft-delete files
        if ($fileCount > 0) {
            echo "Soft-deleting $fileCount files...\n";
            $db->update(
                'files',
                ['deleted_at' => $deletedAt],
                ['tenant_id' => $tenantId]
            );
        }

        // 5. Remove multi-tenant accesses
        echo "Removing multi-tenant accesses...\n";
        $conn = $db->getConnection();
        $stmt = $conn->prepare('DELETE FROM user_tenant_access WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $accessRemoved = $stmt->rowCount();
        echo "Removed $accessRemoved access records\n";

        // 6. Log audit
        echo "Creating audit log...\n";
        $auditId = $db->insert('audit_logs', [
            'tenant_id' => $userInfo['tenant_id'],
            'user_id' => $userInfo['user_id'],
            'action' => 'delete',
            'entity_type' => 'tenant',
            'entity_id' => $tenantId,
            'old_values' => json_encode([
                'tenant' => $tenant,
                'users_count' => $userCount,
                'files_count' => $fileCount,
                'projects_count' => $projectCount,
                'accesses_removed' => $accessRemoved
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        echo "Audit log created with ID: $auditId\n";

        $db->commit();

        echo "\n=== SUCCESS ===\n";
        echo "Tenant '{$tenant['denominazione']}' deleted successfully\n";
        echo "Cascade operations:\n";
        echo "  - Users deleted: $userCount\n";
        echo "  - Files deleted: $fileCount\n";
        echo "  - Projects deleted: $projectCount\n";
        echo "  - Accesses removed: $accessRemoved\n";

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    echo "\n=== ERROR ===\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
