<?php
/**
 * Simple End-to-End Test for Tenant Locations
 *
 * Tests the complete workflow without complex transaction scenarios
 * Uses existing Demo Company (ID=1) to avoid foreign key issues
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

echo "\n=== TENANT LOCATIONS SYSTEM TEST ===\n\n";

$db = Database::getInstance();
$conn = $db->getConnection();

$testsPassed = 0;
$testsFailed = 0;

function test_pass($msg) {
    global $testsPassed;
    $testsPassed++;
    echo "✓ PASS: $msg\n";
}

function test_fail($msg, $detail = '') {
    global $testsFailed;
    $testsFailed++;
    echo "✗ FAIL: $msg";
    if ($detail) echo " ($detail)";
    echo "\n";
}

try {
    // Test 1: Verify table exists and structure
    echo "[1] Database Schema Check\n";
    $tableCheck = $conn->query("SHOW TABLES LIKE 'tenant_locations'")->rowCount();
    if ($tableCheck > 0) {
        test_pass("tenant_locations table exists");

        $colCount = $conn->query("SELECT COUNT(*) as cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'tenant_locations'")->fetch()['cnt'];
        test_pass("Table has $colCount columns");
    } else {
        test_fail("tenant_locations table not found");
        exit(1);
    }

    // Test 2: Check existing data
    echo "\n[2] Existing Data Check\n";
    $existingLocations = $conn->query("SELECT COUNT(*) as cnt FROM tenant_locations WHERE deleted_at IS NULL")->fetch()['cnt'];
    echo "   Current active locations: $existingLocations\n";

    $locByType = $conn->query("
        SELECT location_type, COUNT(*) as cnt
        FROM tenant_locations
        WHERE deleted_at IS NULL
        GROUP BY location_type
    ")->fetchAll();

    foreach ($locByType as $row) {
        echo "   - {$row['location_type']}: {$row['cnt']}\n";
    }
    test_pass("Location data retrieved successfully");

    // Test 3: Create new locations for testing (using Demo Company ID=1)
    echo "\n[3] Create Test Locations\n";
    $testTenantId = 1; // Demo Company

    // Clean up any test locations first
    $conn->exec("DELETE FROM tenant_locations WHERE comune IN ('TestCity1', 'TestCity2', 'TestCity3')");

    // Insert test sede legale
    try {
        $stmt = $conn->prepare("
            INSERT INTO tenant_locations (
                tenant_id, location_type, indirizzo, civico, cap, comune, provincia,
                is_primary, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $testTenantId,
            'sede_operativa', // Using sede_operativa to avoid conflicts with existing sede legale
            'Via Test Inserimento',
            '999',
            '00100',
            'TestCity1',
            'RM',
            0,
            1
        ]);

        $insertedId = $conn->lastInsertId();
        if ($insertedId > 0) {
            test_pass("Test location inserted (ID: $insertedId)");
        } else {
            test_fail("Failed to get inserted ID");
        }
    } catch (Exception $e) {
        test_fail("Insert failed", $e->getMessage());
    }

    // Test 4: Retrieve locations
    echo "\n[4] Retrieve Locations\n";
    try {
        $locations = $conn->query("
            SELECT id, comune, location_type
            FROM tenant_locations
            WHERE tenant_id = $testTenantId
              AND deleted_at IS NULL
            ORDER BY id DESC
            LIMIT 5
        ")->fetchAll();

        if (count($locations) > 0) {
            test_pass("Retrieved " . count($locations) . " locations for tenant $testTenantId");
            foreach ($locations as $loc) {
                echo "   - ID {$loc['id']}: {$loc['comune']} ({$loc['location_type']})\n";
            }
        } else {
            test_fail("No locations found for tenant $testTenantId");
        }
    } catch (Exception $e) {
        test_fail("Retrieve failed", $e->getMessage());
    }

    // Test 5: Update location
    echo "\n[5] Update Location\n";
    if (isset($insertedId) && $insertedId > 0) {
        try {
            $stmt = $conn->prepare("
                UPDATE tenant_locations
                SET comune = 'TestCityUpdated',
                    note = 'Updated by test script'
                WHERE id = ?
            ");
            $stmt->execute([$insertedId]);

            test_pass("Location updated successfully");

            // Verify update
            $updated = $conn->query("SELECT comune, note FROM tenant_locations WHERE id = $insertedId")->fetch();
            if ($updated['comune'] === 'TestCityUpdated') {
                test_pass("Update verified: comune = {$updated['comune']}");
            } else {
                test_fail("Update not reflected in database");
            }
        } catch (Exception $e) {
            test_fail("Update failed", $e->getMessage());
        }
    }

    // Test 6: Soft delete
    echo "\n[6] Soft Delete Location\n";
    if (isset($insertedId) && $insertedId > 0) {
        try {
            $stmt = $conn->prepare("
                UPDATE tenant_locations
                SET deleted_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$insertedId]);

            test_pass("Location soft-deleted");

            // Verify soft delete
            $deleted = $conn->query("SELECT deleted_at FROM tenant_locations WHERE id = $insertedId")->fetch();
            if ($deleted['deleted_at'] !== null) {
                test_pass("Soft delete verified: deleted_at = {$deleted['deleted_at']}");
            } else {
                test_fail("Soft delete not reflected");
            }

            // Verify it's excluded from active queries
            $activeCount = $conn->query("
                SELECT COUNT(*) as cnt
                FROM tenant_locations
                WHERE id = $insertedId AND deleted_at IS NULL
            ")->fetch()['cnt'];

            if ($activeCount === 0) {
                test_pass("Soft-deleted location excluded from active queries");
            } else {
                test_fail("Soft-deleted location still appears as active");
            }
        } catch (Exception $e) {
            test_fail("Soft delete failed", $e->getMessage());
        }
    }

    // Test 7: List query with counts (simulates API list.php)
    echo "\n[7] List Query with Location Counts\n";
    try {
        $listQuery = "
            SELECT
                t.id,
                t.denominazione,
                (SELECT COUNT(*) FROM tenant_locations
                 WHERE tenant_id = t.id
                   AND location_type = 'sede_operativa'
                   AND deleted_at IS NULL) as sedi_operative_count
            FROM tenants t
            WHERE t.deleted_at IS NULL
            LIMIT 5
        ";

        $results = $conn->query($listQuery)->fetchAll();

        if (count($results) > 0) {
            test_pass("List query executed successfully (" . count($results) . " tenants)");
            foreach ($results as $tenant) {
                echo "   - {$tenant['denominazione']}: {$tenant['sedi_operative_count']} sedi operative\n";
            }
        } else {
            test_fail("List query returned no results");
        }
    } catch (Exception $e) {
        test_fail("List query failed", $e->getMessage());
    }

    // Test 8: API GET simulation (retrieve structured locations)
    echo "\n[8] Structured Location Retrieval (API GET simulation)\n";
    try {
        // Get sede legale
        $sedeLegale = $conn->query("
            SELECT * FROM tenant_locations
            WHERE tenant_id = $testTenantId
              AND location_type = 'sede_legale'
              AND deleted_at IS NULL
            LIMIT 1
        ")->fetch();

        if ($sedeLegale) {
            test_pass("Sede legale retrieved: {$sedeLegale['comune']}");
        } else {
            echo "   ℹ No sede legale found for test tenant\n";
        }

        // Get sedi operative
        $sediOperative = $conn->query("
            SELECT * FROM tenant_locations
            WHERE tenant_id = $testTenantId
              AND location_type = 'sede_operativa'
              AND deleted_at IS NULL
        ")->fetchAll();

        if (count($sediOperative) > 0) {
            test_pass("Sedi operative retrieved: " . count($sediOperative) . " locations");
            foreach ($sediOperative as $sede) {
                echo "   - {$sede['indirizzo']}, {$sede['comune']} ({$sede['provincia']})\n";
            }
        } else {
            echo "   ℹ No sedi operative found for test tenant\n";
        }
    } catch (Exception $e) {
        test_fail("Structured retrieval failed", $e->getMessage());
    }

    // Cleanup
    echo "\n[CLEANUP]\n";
    $conn->exec("DELETE FROM tenant_locations WHERE comune LIKE 'TestCity%'");
    echo "   Test data removed\n";

    // Summary
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "SUMMARY\n";
    echo str_repeat("=", 50) . "\n";
    echo "Passed: $testsPassed\n";
    echo "Failed: $testsFailed\n";
    echo "Total:  " . ($testsPassed + $testsFailed) . "\n";

    if ($testsFailed === 0) {
        echo "\n✓ ALL TESTS PASSED!\n";
        echo "The tenant_locations system is working correctly.\n";
        exit(0);
    } else {
        echo "\n✗ SOME TESTS FAILED\n";
        exit(1);
    }

} catch (Exception $e) {
    echo "\n✗ CRITICAL ERROR: {$e->getMessage()}\n";
    exit(1);
}
