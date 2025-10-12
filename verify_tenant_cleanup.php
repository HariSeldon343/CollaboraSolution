<?php
/**
 * ============================================
 * TENANT CLEANUP VERIFICATION SCRIPT
 * ============================================
 * Module: Tenant Cleanup Diagnostics
 * Version: 2025-10-12
 * Author: Database Architect
 *
 * Description: Comprehensive tenant database verification
 * - Lists all tenants with deleted_at status
 * - Shows active vs deleted counts
 * - Displays exact SQL used by APIs
 * - Identifies why tenants still appear in dropdowns
 * ============================================
 */

// Disable output buffering for immediate feedback
ini_set('output_buffering', 'off');
ini_set('implicit_flush', 'on');
ob_implicit_flush(true);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "==========================================================\n";
echo "TENANT CLEANUP VERIFICATION SCRIPT\n";
echo "==========================================================\n";
echo "Execution Time: " . date('Y-m-d H:i:s') . "\n\n";

// Include configuration
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

try {
    // Get database connection
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    echo "[SUCCESS] Database connection established\n\n";

    // ============================================
    // 1. FULL TENANTS TABLE DUMP
    // ============================================
    echo "==========================================================\n";
    echo "1. COMPLETE TENANTS TABLE STATUS\n";
    echo "==========================================================\n\n";

    $stmt = $pdo->query("
        SELECT
            id,
            name,
            status,
            deleted_at,
            created_at,
            updated_at
        FROM tenants
        ORDER BY id
    ");

    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($tenants)) {
        echo "[WARNING] No tenants found in database!\n\n";
    } else {
        printf("%-5s | %-30s | %-10s | %-20s | %-20s\n",
            'ID', 'NAME', 'STATUS', 'DELETED_AT', 'CREATED_AT');
        echo str_repeat('-', 120) . "\n";

        foreach ($tenants as $tenant) {
            $deleted = $tenant['deleted_at'] ?? 'NULL (ACTIVE)';
            $status = strtoupper($tenant['status'] ?? 'unknown');

            printf("%-5s | %-30s | %-10s | %-20s | %-20s\n",
                $tenant['id'],
                substr($tenant['name'], 0, 30),
                $status,
                $deleted,
                $tenant['created_at']
            );
        }
        echo "\n";
    }

    // ============================================
    // 2. SUMMARY COUNTS
    // ============================================
    echo "==========================================================\n";
    echo "2. TENANT SUMMARY COUNTS\n";
    echo "==========================================================\n\n";

    $active_count = $pdo->query("
        SELECT COUNT(*)
        FROM tenants
        WHERE deleted_at IS NULL
    ")->fetchColumn();

    $deleted_count = $pdo->query("
        SELECT COUNT(*)
        FROM tenants
        WHERE deleted_at IS NOT NULL
    ")->fetchColumn();

    $suspended_count = $pdo->query("
        SELECT COUNT(*)
        FROM tenants
        WHERE status = 'suspended' AND deleted_at IS NULL
    ")->fetchColumn();

    echo "Total Tenants:           " . count($tenants) . "\n";
    echo "Active (deleted_at NULL): " . $active_count . "\n";
    echo "Deleted (deleted_at SET): " . $deleted_count . "\n";
    echo "Suspended (active):       " . $suspended_count . "\n\n";

    // ============================================
    // 3. LIST ACTIVE TENANTS
    // ============================================
    echo "==========================================================\n";
    echo "3. CURRENTLY ACTIVE TENANTS (deleted_at IS NULL)\n";
    echo "==========================================================\n\n";

    $active_tenants = $pdo->query("
        SELECT id, name, status
        FROM tenants
        WHERE deleted_at IS NULL
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($active_tenants)) {
        echo "[WARNING] No active tenants found!\n\n";
    } else {
        foreach ($active_tenants as $tenant) {
            echo "  - ID {$tenant['id']}: {$tenant['name']} (Status: {$tenant['status']})\n";
        }
        echo "\n";
    }

    // ============================================
    // 4. API QUERY SIMULATION (Super Admin)
    // ============================================
    echo "==========================================================\n";
    echo "4. API QUERY SIMULATION (files_tenant_fixed.php)\n";
    echo "==========================================================\n\n";

    echo "--- Query for SUPER ADMIN (lines 578-586) ---\n\n";
    echo "SQL:\n";
    echo "SELECT id, name,\n";
    echo "       CASE WHEN status = 'active' THEN '1' ELSE '0' END as is_active,\n";
    echo "       status\n";
    echo "FROM tenants\n";
    echo "WHERE deleted_at IS NULL\n";
    echo "AND status != 'suspended'\n";
    echo "ORDER BY name\n\n";

    $api_tenants = $pdo->query("
        SELECT id, name,
               CASE WHEN status = 'active' THEN '1' ELSE '0' END as is_active,
               status
        FROM tenants
        WHERE deleted_at IS NULL
        AND status != 'suspended'
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "Results (" . count($api_tenants) . " tenants):\n";
    if (empty($api_tenants)) {
        echo "[INFO] No tenants would appear in dropdown\n\n";
    } else {
        foreach ($api_tenants as $tenant) {
            echo "  - ID {$tenant['id']}: {$tenant['name']} (is_active: {$tenant['is_active']}, status: {$tenant['status']})\n";
        }
        echo "\n";
    }

    // ============================================
    // 5. RELATED DATA ANALYSIS
    // ============================================
    echo "==========================================================\n";
    echo "5. RELATED DATA ANALYSIS (Dependencies)\n";
    echo "==========================================================\n\n";

    // Check users per tenant
    echo "--- Users per Tenant ---\n";
    $users_per_tenant = $pdo->query("
        SELECT
            t.id as tenant_id,
            t.name as tenant_name,
            t.deleted_at,
            COUNT(u.id) as user_count
        FROM tenants t
        LEFT JOIN users u ON t.id = u.tenant_id AND u.deleted_at IS NULL
        GROUP BY t.id, t.name, t.deleted_at
        ORDER BY t.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users_per_tenant as $row) {
        $deleted = $row['deleted_at'] ? ' [DELETED]' : '';
        echo "  Tenant ID {$row['tenant_id']}: {$row['user_count']} active users{$deleted}\n";
    }
    echo "\n";

    // Check folders per tenant
    echo "--- Folders per Tenant ---\n";
    $folders_per_tenant = $pdo->query("
        SELECT
            t.id as tenant_id,
            t.name as tenant_name,
            t.deleted_at,
            COUNT(f.id) as folder_count
        FROM tenants t
        LEFT JOIN folders f ON t.id = f.tenant_id AND f.deleted_at IS NULL
        GROUP BY t.id, t.name, t.deleted_at
        ORDER BY t.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($folders_per_tenant as $row) {
        $deleted = $row['deleted_at'] ? ' [DELETED]' : '';
        echo "  Tenant ID {$row['tenant_id']}: {$row['folder_count']} active folders{$deleted}\n";
    }
    echo "\n";

    // Check files per tenant
    echo "--- Files per Tenant ---\n";
    $files_per_tenant = $pdo->query("
        SELECT
            t.id as tenant_id,
            t.name as tenant_name,
            t.deleted_at,
            COUNT(f.id) as file_count,
            COALESCE(SUM(f.file_size), 0) as total_size
        FROM tenants t
        LEFT JOIN files f ON t.id = f.tenant_id AND f.deleted_at IS NULL
        GROUP BY t.id, t.name, t.deleted_at
        ORDER BY t.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($files_per_tenant as $row) {
        $deleted = $row['deleted_at'] ? ' [DELETED]' : '';
        $size_mb = round($row['total_size'] / 1048576, 2);
        echo "  Tenant ID {$row['tenant_id']}: {$row['file_count']} files ({$size_mb} MB){$deleted}\n";
    }
    echo "\n";

    // ============================================
    // 6. DIAGNOSIS & RECOMMENDATIONS
    // ============================================
    echo "==========================================================\n";
    echo "6. DIAGNOSIS & RECOMMENDATIONS\n";
    echo "==========================================================\n\n";

    if ($active_count > 1) {
        echo "[ISSUE FOUND] Multiple tenants are still active (deleted_at IS NULL)\n\n";
        echo "Expected: Only Tenant ID 11 should be active\n";
        echo "Actual:   {$active_count} tenants are active\n\n";

        echo "Active Tenant IDs:\n";
        foreach ($active_tenants as $tenant) {
            $should_be = ($tenant['id'] == 11) ? '✓ CORRECT' : '✗ SHOULD BE DELETED';
            echo "  - ID {$tenant['id']}: {$tenant['name']} [{$should_be}]\n";
        }
        echo "\n";

        echo "RECOMMENDATION:\n";
        echo "Run the fix script: fix_tenant_cleanup.sql\n";
        echo "This will soft-delete all tenants except ID 11\n\n";

    } elseif ($active_count == 1 && $active_tenants[0]['id'] == 11) {
        echo "[SUCCESS] Only Tenant ID 11 is active - cleanup was successful!\n\n";
        echo "If tenants still appear in dropdown:\n";
        echo "1. Clear browser cache (Ctrl+Shift+Delete)\n";
        echo "2. Check browser console for API errors\n";
        echo "3. Verify user is logged in as super_admin role\n";
        echo "4. Check browser localStorage/sessionStorage\n\n";

    } elseif ($active_count == 1 && $active_tenants[0]['id'] != 11) {
        echo "[WARNING] One tenant is active, but it's NOT ID 11\n";
        echo "Active Tenant: ID {$active_tenants[0]['id']} - {$active_tenants[0]['name']}\n";
        echo "Expected: ID 11\n\n";
        echo "RECOMMENDATION: Run fix_tenant_cleanup.sql\n\n";

    } else {
        echo "[CRITICAL] No active tenants found!\n";
        echo "This will break the application.\n";
        echo "RECOMMENDATION: Restore Tenant ID 11 from backup or unsuspend it\n\n";
    }

    // ============================================
    // 7. API FILE VERIFICATION
    // ============================================
    echo "==========================================================\n";
    echo "7. API FILE VERIFICATION\n";
    echo "==========================================================\n\n";

    $api_file = __DIR__ . '/api/files_tenant_fixed.php';
    if (file_exists($api_file)) {
        echo "[SUCCESS] API file exists: {$api_file}\n";

        $content = file_get_contents($api_file);

        // Check for correct query
        if (strpos($content, 'WHERE deleted_at IS NULL') !== false) {
            echo "[SUCCESS] API correctly filters deleted_at IS NULL\n";
        } else {
            echo "[ERROR] API does NOT filter by deleted_at\n";
        }

        if (strpos($content, "status != 'suspended'") !== false) {
            echo "[SUCCESS] API correctly excludes suspended tenants\n";
        } else {
            echo "[WARNING] API does NOT exclude suspended status\n";
        }
        echo "\n";
    } else {
        echo "[ERROR] API file not found: {$api_file}\n\n";
    }

    // ============================================
    // COMPLETION
    // ============================================
    echo "==========================================================\n";
    echo "VERIFICATION COMPLETE\n";
    echo "==========================================================\n\n";
    echo "Next Steps:\n";
    echo "1. Review the diagnosis above\n";
    echo "2. If issues found, run: /c/xampp/mysql/bin/mysql.exe -u root collaboranexio < fix_tenant_cleanup.sql\n";
    echo "3. Clear browser cache and reload\n";
    echo "4. Re-run this script to verify the fix\n\n";

} catch (PDOException $e) {
    echo "\n[ERROR] Database Error: " . $e->getMessage() . "\n";
    echo "Error Code: " . $e->getCode() . "\n\n";
    exit(1);
} catch (Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n\n";
    exit(1);
}

echo "Script finished at " . date('Y-m-d H:i:s') . "\n";
exit(0);
