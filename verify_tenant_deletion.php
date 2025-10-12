<?php
/**
 * Tenant Deletion Verification Script
 * Analyzes tenant table data and identifies soft-delete compliance issues
 */

require_once 'config.php';
require_once 'includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=================================================================\n";
echo "TENANT DELETION VERIFICATION REPORT\n";
echo "=================================================================\n";
echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Check if tenants table has deleted_at column
    echo "1. CHECKING TENANTS TABLE STRUCTURE\n";
    echo "-----------------------------------\n";

    $columns = $conn->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_ASSOC);
    $hasDeletedAt = false;

    echo "Columns in tenants table:\n";
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']}) {$col['Null']} {$col['Key']} {$col['Default']}\n";
        if ($col['Field'] === 'deleted_at') {
            $hasDeletedAt = true;
        }
    }

    if ($hasDeletedAt) {
        echo "\n✓ PASS: 'deleted_at' column exists\n\n";
    } else {
        echo "\n✗ FAIL: 'deleted_at' column is MISSING! Soft delete not supported!\n\n";
    }

    // List all tenants with their status
    echo "2. ALL TENANTS IN DATABASE\n";
    echo "-----------------------------------\n";

    $query = "SELECT
                id,
                name,
                company_name,
                status,
                " . ($hasDeletedAt ? "deleted_at," : "") . "
                created_at,
                updated_at
              FROM tenants
              ORDER BY id";

    $stmt = $conn->query($query);
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo sprintf("%-5s | %-30s | %-30s | %-10s | %-20s | %-20s\n",
                 'ID', 'Name', 'Company Name', 'Status',
                 $hasDeletedAt ? 'Deleted At' : 'N/A', 'Created At');
    echo str_repeat('-', 140) . "\n";

    $activeCount = 0;
    $deletedCount = 0;
    $suspendedCount = 0;

    foreach ($tenants as $tenant) {
        $deletedAt = $hasDeletedAt ? ($tenant['deleted_at'] ?? 'NULL') : 'N/A';

        if ($hasDeletedAt && $tenant['deleted_at'] !== null) {
            $deletedCount++;
        } elseif ($tenant['status'] === 'suspended') {
            $suspendedCount++;
        } else {
            $activeCount++;
        }

        echo sprintf("%-5s | %-30s | %-30s | %-10s | %-20s | %-20s\n",
                    $tenant['id'],
                    substr($tenant['name'], 0, 30),
                    substr($tenant['company_name'] ?? 'N/A', 0, 30),
                    $tenant['status'] ?? 'active',
                    $deletedAt,
                    $tenant['created_at']);
    }

    echo "\nSummary:\n";
    echo "  Total Tenants: " . count($tenants) . "\n";
    echo "  Active: $activeCount\n";
    if ($hasDeletedAt) {
        echo "  Soft Deleted: $deletedCount\n";
    }
    echo "  Suspended: $suspendedCount\n\n";

    // Check if only tenant ID 11 should exist
    echo "3. TENANT ID 11 (S.co) STATUS\n";
    echo "-----------------------------------\n";

    $stmt = $conn->prepare("SELECT * FROM tenants WHERE id = 11");
    $stmt->execute();
    $tenant11 = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tenant11) {
        echo "✓ Tenant ID 11 EXISTS\n";
        echo "  Name: {$tenant11['name']}\n";
        echo "  Company: " . ($tenant11['company_name'] ?? 'N/A') . "\n";
        echo "  Status: " . ($tenant11['status'] ?? 'active') . "\n";
        if ($hasDeletedAt) {
            echo "  Deleted: " . ($tenant11['deleted_at'] ? 'YES' : 'NO') . "\n";
        }
    } else {
        echo "✗ Tenant ID 11 NOT FOUND\n";
    }

    echo "\n";

    // Check tenants that should be deleted
    echo "4. TENANTS THAT SHOULD BE DELETED (except ID 11)\n";
    echo "-----------------------------------\n";

    if ($hasDeletedAt) {
        $stmt = $conn->query("SELECT id, name, deleted_at FROM tenants WHERE id != 11 AND deleted_at IS NULL");
        $shouldBeDeleted = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($shouldBeDeleted) > 0) {
            echo "✗ ISSUE: Found " . count($shouldBeDeleted) . " tenants that are NOT soft-deleted:\n";
            foreach ($shouldBeDeleted as $t) {
                echo "  - ID {$t['id']}: {$t['name']}\n";
            }
        } else {
            echo "✓ PASS: All tenants except ID 11 are properly soft-deleted\n";
        }
    } else {
        $stmt = $conn->query("SELECT id, name FROM tenants WHERE id != 11");
        $shouldBeDeleted = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($shouldBeDeleted) > 0) {
            echo "✗ ISSUE: Found " . count($shouldBeDeleted) . " tenants that should be deleted (hard delete):\n";
            foreach ($shouldBeDeleted as $t) {
                echo "  - ID {$t['id']}: {$t['name']}\n";
            }
        } else {
            echo "✓ PASS: Only tenant ID 11 exists\n";
        }
    }

    echo "\n";

    // Check API query for get_tenant_list
    echo "5. API QUERY ANALYSIS (files_tenant_production.php)\n";
    echo "-----------------------------------\n";

    $apiFile = __DIR__ . '/api/files_tenant_production.php';
    if (file_exists($apiFile)) {
        $content = file_get_contents($apiFile);

        // Check if getTenantList filters by deleted_at
        if (strpos($content, 'getTenantList') !== false) {
            echo "✓ getTenantList function found\n";

            if (strpos($content, 'deleted_at IS NULL') !== false) {
                echo "✓ PASS: Query includes 'deleted_at IS NULL' filter\n";
            } else {
                echo "✗ FAIL: Query does NOT filter deleted_at!\n";
                echo "  This is the ROOT CAUSE - deleted tenants appear in dropdown\n";
            }

            if (strpos($content, "status != 'suspended'") !== false ||
                strpos($content, "status = 'active'") !== false) {
                echo "✓ Query includes status filter\n";
            } else {
                echo "⚠ Warning: No status filter found\n";
            }
        } else {
            echo "✗ getTenantList function NOT found\n";
        }
    } else {
        echo "✗ API file not found: $apiFile\n";
    }

    echo "\n";

    // Recommendations
    echo "6. RECOMMENDATIONS\n";
    echo "-----------------------------------\n";

    if (!$hasDeletedAt) {
        echo "CRITICAL: Add deleted_at column to tenants table:\n";
        echo "  ALTER TABLE tenants ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;\n\n";
    }

    if ($hasDeletedAt && count($shouldBeDeleted) > 0) {
        echo "ACTION REQUIRED: Soft delete all tenants except ID 11:\n";
        echo "  UPDATE tenants SET deleted_at = NOW() WHERE id != 11 AND deleted_at IS NULL;\n\n";
    }

    echo "VERIFY: Ensure getTenantList API filters by deleted_at:\n";
    echo "  WHERE deleted_at IS NULL in the query\n\n";

    echo "CLEAR CACHE: After fixes, clear browser cache and restart PHP session:\n";
    echo "  - Close browser completely\n";
    echo "  - Restart Apache/PHP-FPM\n";
    echo "  - Open fresh browser session\n\n";

    echo "=================================================================\n";
    echo "END OF REPORT\n";
    echo "=================================================================\n";

} catch (PDOException $e) {
    echo "\n✗ DATABASE ERROR: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
}
?>
