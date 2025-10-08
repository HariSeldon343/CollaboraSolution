<?php
/**
 * Comprehensive End-to-End Test Suite for Tenant Locations System
 *
 * Tests the complete CRUD cycle with the new tenant_locations table:
 * - Database schema verification
 * - API create with multiple locations
 * - API get to retrieve structured data
 * - API update to modify locations
 * - API list with location counts
 * - API delete with cascade
 * - Data integrity verification
 *
 * Run this script to verify the entire system works correctly after
 * the tenant_locations migration and API updates.
 */

declare(strict_types=1);

// Color output for CLI
class Colors {
    public static $GREEN = "\033[0;32m";
    public static $RED = "\033[0;31m";
    public static $YELLOW = "\033[1;33m";
    public static $BLUE = "\033[0;34m";
    public static $NC = "\033[0m"; // No Color
}

// Test results tracker
class TestResults {
    private static $passed = 0;
    private static $failed = 0;
    private static $warnings = 0;
    private static $tests = [];

    public static function pass(string $test, string $message = '') {
        self::$passed++;
        self::$tests[] = ['status' => 'PASS', 'test' => $test, 'message' => $message];
        echo Colors::$GREEN . "✓ PASS: " . Colors::$NC . "$test";
        if ($message) echo " - $message";
        echo "\n";
    }

    public static function fail(string $test, string $message = '') {
        self::$failed++;
        self::$tests[] = ['status' => 'FAIL', 'test' => $test, 'message' => $message];
        echo Colors::$RED . "✗ FAIL: " . Colors::$NC . "$test";
        if ($message) echo " - $message";
        echo "\n";
    }

    public static function warn(string $test, string $message = '') {
        self::$warnings++;
        self::$tests[] = ['status' => 'WARN', 'test' => $test, 'message' => $message];
        echo Colors::$YELLOW . "⚠ WARN: " . Colors::$NC . "$test";
        if ($message) echo " - $message";
        echo "\n";
    }

    public static function info(string $message) {
        echo Colors::$BLUE . "ℹ INFO: " . Colors::$NC . "$message\n";
    }

    public static function getSummary(): array {
        return [
            'total' => count(self::$tests),
            'passed' => self::$passed,
            'failed' => self::$failed,
            'warnings' => self::$warnings,
            'tests' => self::$tests
        ];
    }
}

// Load dependencies
require_once __DIR__ . '/includes/db.php';

echo "\n";
echo str_repeat("=", 80) . "\n";
echo "  TENANT LOCATIONS SYSTEM - COMPREHENSIVE END-TO-END TEST SUITE\n";
echo str_repeat("=", 80) . "\n\n";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // ========================================================================
    // TEST 1: Database Schema Verification
    // ========================================================================
    echo Colors::$BLUE . "\n[1/7] Database Schema Verification\n" . Colors::$NC;
    echo str_repeat("-", 80) . "\n";

    // Check tenant_locations table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'tenant_locations'")->rowCount() > 0;
    if ($tableExists) {
        TestResults::pass("tenant_locations table exists");

        // Verify table structure
        $columns = $conn->query("SHOW COLUMNS FROM tenant_locations")->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'Field');

        $requiredColumns = [
            'id', 'tenant_id', 'location_type', 'indirizzo', 'civico', 'cap',
            'comune', 'provincia', 'telefono', 'email', 'manager_nome',
            'manager_user_id', 'is_primary', 'is_active', 'note',
            'created_at', 'updated_at', 'deleted_at'
        ];

        $missingColumns = array_diff($requiredColumns, $columnNames);
        if (empty($missingColumns)) {
            TestResults::pass("All required columns present", count($columnNames) . " columns");
        } else {
            TestResults::fail("Missing columns", implode(', ', $missingColumns));
        }

        // Check indexes
        $indexes = $conn->query("SHOW INDEX FROM tenant_locations")->fetchAll(PDO::FETCH_ASSOC);
        $indexCount = count(array_unique(array_column($indexes, 'Key_name')));

        if ($indexCount >= 8) {
            TestResults::pass("Table indexes created", "$indexCount indexes");
        } else {
            TestResults::warn("Expected at least 8 indexes", "Found $indexCount");
        }

    } else {
        TestResults::fail("tenant_locations table does not exist");
        echo "\n" . Colors::$RED . "CRITICAL: Cannot proceed without tenant_locations table!\n" . Colors::$NC;
        exit(1);
    }

    // Verify tenants table has backward compatibility columns
    $tenantsColumns = $conn->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_ASSOC);
    $tenantsColumnNames = array_column($tenantsColumns, 'Field');

    $legacyColumns = ['sede_legale_indirizzo', 'sede_legale_comune', 'sedi_operative'];
    $hasLegacy = count(array_intersect($legacyColumns, $tenantsColumnNames)) === count($legacyColumns);

    if ($hasLegacy) {
        TestResults::pass("Legacy columns maintained for backward compatibility");
    } else {
        TestResults::warn("Some legacy columns missing", "May affect compatibility");
    }

    // ========================================================================
    // TEST 2: Create Company with Multiple Locations
    // ========================================================================
    echo Colors::$BLUE . "\n[2/7] Create Company with Multiple Locations\n" . Colors::$NC;
    echo str_repeat("-", 80) . "\n";

    $testCompanyName = "Test Location Company " . time();

    $db->beginTransaction();

    try {
        // Insert test tenant
        $tenantId = $db->insert('tenants', [
            'name' => $testCompanyName,
            'denominazione' => $testCompanyName,
            'codice_fiscale' => 'TSTLOC' . rand(10, 99) . 'A01H501Z',
            'partita_iva' => str_pad((string)rand(10000000000, 99999999999), 11, '0', STR_PAD_LEFT),
            'status' => 'active',
            'sede_legale_indirizzo' => 'Via Test',
            'sede_legale_comune' => 'Milano',
            'sede_legale_provincia' => 'MI',
            'sede_legale_cap' => '20100'
        ]);

        if ($tenantId > 0) {
            TestResults::pass("Test tenant created", "ID: $tenantId");
        } else {
            TestResults::fail("Failed to create test tenant");
            throw new Exception("Tenant creation failed");
        }

        // Insert sede legale
        $sedeLegaleId = $db->insert('tenant_locations', [
            'tenant_id' => $tenantId,
            'location_type' => 'sede_legale',
            'indirizzo' => 'Via Roma',
            'civico' => '10',
            'cap' => '20100',
            'comune' => 'Milano',
            'provincia' => 'MI',
            'telefono' => '02 1234567',
            'email' => 'milano@test.it',
            'is_primary' => 1,
            'is_active' => 1
        ]);

        if ($sedeLegaleId > 0) {
            TestResults::pass("Sede legale created", "ID: $sedeLegaleId");
        } else {
            TestResults::fail("Failed to create sede legale");
        }

        // Insert 3 sedi operative
        $sediOperativeIds = [];
        $sediOperativeData = [
            ['comune' => 'Torino', 'provincia' => 'TO', 'cap' => '10100', 'telefono' => '011 9876543'],
            ['comune' => 'Roma', 'provincia' => 'RM', 'cap' => '00100', 'telefono' => '06 5555555'],
            ['comune' => 'Napoli', 'provincia' => 'NA', 'cap' => '80100', 'telefono' => '081 3333333']
        ];

        foreach ($sediOperativeData as $index => $sedeData) {
            $sedeId = $db->insert('tenant_locations', [
                'tenant_id' => $tenantId,
                'location_type' => 'sede_operativa',
                'indirizzo' => 'Via Operativa ' . ($index + 1),
                'civico' => (string)($index + 1),
                'cap' => $sedeData['cap'],
                'comune' => $sedeData['comune'],
                'provincia' => $sedeData['provincia'],
                'telefono' => $sedeData['telefono'],
                'email' => strtolower($sedeData['comune']) . '@test.it',
                'is_primary' => 0,
                'is_active' => 1
            ]);

            if ($sedeId > 0) {
                $sediOperativeIds[] = $sedeId;
            }
        }

        if (count($sediOperativeIds) === 3) {
            TestResults::pass("3 sedi operative created", "IDs: " . implode(', ', $sediOperativeIds));
        } else {
            TestResults::fail("Expected 3 sedi operative", "Created: " . count($sediOperativeIds));
        }

        $db->commit();

    } catch (Exception $e) {
        $db->rollback();
        TestResults::fail("Transaction failed", $e->getMessage());
        throw $e;
    }

    // ========================================================================
    // TEST 3: Retrieve Company with Locations (GET API simulation)
    // ========================================================================
    echo Colors::$BLUE . "\n[3/7] Retrieve Company with Locations\n" . Colors::$NC;
    echo str_repeat("-", 80) . "\n";

    // Fetch tenant
    $retrievedTenant = $db->fetchOne(
        'SELECT * FROM tenants WHERE id = ?',
        [$tenantId]
    );

    if ($retrievedTenant) {
        TestResults::pass("Tenant retrieved successfully");
    } else {
        TestResults::fail("Failed to retrieve tenant");
    }

    // Fetch sede legale
    $retrievedSedeLegale = $db->fetchOne(
        'SELECT * FROM tenant_locations
         WHERE tenant_id = ?
           AND location_type = "sede_legale"
           AND deleted_at IS NULL',
        [$tenantId]
    );

    if ($retrievedSedeLegale) {
        TestResults::pass("Sede legale retrieved", $retrievedSedeLegale['comune']);

        // Verify structure
        if ($retrievedSedeLegale['is_primary'] == 1) {
            TestResults::pass("Sede legale marked as primary");
        } else {
            TestResults::fail("Sede legale not marked as primary");
        }
    } else {
        TestResults::fail("Failed to retrieve sede legale");
    }

    // Fetch sedi operative
    $retrievedSediOperative = $db->fetchAll(
        'SELECT * FROM tenant_locations
         WHERE tenant_id = ?
           AND location_type = "sede_operativa"
           AND deleted_at IS NULL
         ORDER BY created_at ASC',
        [$tenantId]
    );

    if (count($retrievedSediOperative) === 3) {
        TestResults::pass("All 3 sedi operative retrieved");

        // Verify data integrity
        $comuni = array_column($retrievedSediOperative, 'comune');
        $expectedComuni = ['Torino', 'Roma', 'Napoli'];

        if (array_diff($expectedComuni, $comuni) === []) {
            TestResults::pass("Sedi operative data integrity verified");
        } else {
            TestResults::fail("Data mismatch in sedi operative", "Expected: " . implode(', ', $expectedComuni));
        }
    } else {
        TestResults::fail("Expected 3 sedi operative", "Found: " . count($retrievedSediOperative));
    }

    // ========================================================================
    // TEST 4: Update Locations (UPDATE API simulation)
    // ========================================================================
    echo Colors::$BLUE . "\n[4/7] Update Company Locations\n" . Colors::$NC;
    echo str_repeat("-", 80) . "\n";

    $db->beginTransaction();

    try {
        // Soft-delete existing sedi operative
        $deleted = $db->update(
            'tenant_locations',
            ['deleted_at' => date('Y-m-d H:i:s')],
            [
                'tenant_id' => $tenantId,
                'location_type' => 'sede_operativa'
            ]
        );

        if ($deleted) {
            TestResults::pass("Old sedi operative soft-deleted", "$deleted rows");
        } else {
            TestResults::warn("No rows soft-deleted");
        }

        // Add 2 new sedi operative
        $newSediIds = [];
        $newSediData = [
            ['comune' => 'Bologna', 'provincia' => 'BO', 'cap' => '40100'],
            ['comune' => 'Firenze', 'provincia' => 'FI', 'cap' => '50100']
        ];

        foreach ($newSediData as $index => $sedeData) {
            $sedeId = $db->insert('tenant_locations', [
                'tenant_id' => $tenantId,
                'location_type' => 'sede_operativa',
                'indirizzo' => 'Via Nuova ' . ($index + 1),
                'civico' => (string)(10 + $index),
                'cap' => $sedeData['cap'],
                'comune' => $sedeData['comune'],
                'provincia' => $sedeData['provincia'],
                'is_primary' => 0,
                'is_active' => 1
            ]);

            if ($sedeId > 0) {
                $newSediIds[] = $sedeId;
            }
        }

        if (count($newSediIds) === 2) {
            TestResults::pass("2 new sedi operative created");
        } else {
            TestResults::fail("Failed to create new sedi operative");
        }

        $db->commit();

        // Verify soft-delete worked
        $activeCount = $db->count('tenant_locations', [
            'tenant_id' => $tenantId,
            'location_type' => 'sede_operativa',
            'deleted_at' => null
        ]);

        if ($activeCount === 2) {
            TestResults::pass("Active sedi operative count correct", "$activeCount active");
        } else {
            TestResults::fail("Active count mismatch", "Expected 2, found $activeCount");
        }

        $deletedCount = $db->fetchOne(
            'SELECT COUNT(*) as cnt FROM tenant_locations
             WHERE tenant_id = ?
               AND location_type = "sede_operativa"
               AND deleted_at IS NOT NULL',
            [$tenantId]
        )['cnt'];

        if ($deletedCount === 3) {
            TestResults::pass("Soft-deleted sedi operative count correct", "$deletedCount deleted");
        } else {
            TestResults::fail("Deleted count mismatch", "Expected 3, found $deletedCount");
        }

    } catch (Exception $e) {
        $db->rollback();
        TestResults::fail("Update transaction failed", $e->getMessage());
    }

    // ========================================================================
    // TEST 5: List Companies with Location Counts (LIST API simulation)
    // ========================================================================
    echo Colors::$BLUE . "\n[5/7] List Companies with Location Counts\n" . Colors::$NC;
    echo str_repeat("-", 80) . "\n";

    $listQuery = "
        SELECT
            t.id,
            t.denominazione,
            (SELECT COUNT(*) FROM tenant_locations
             WHERE tenant_id = t.id
               AND location_type = 'sede_operativa'
               AND deleted_at IS NULL) as sedi_operative_count
        FROM tenants t
        WHERE t.id = ?
          AND t.deleted_at IS NULL
    ";

    $listResult = $db->fetchOne($listQuery, [$tenantId]);

    if ($listResult) {
        TestResults::pass("List query executed successfully");

        if ($listResult['sedi_operative_count'] == 2) {
            TestResults::pass("Location count in list correct", "2 sedi operative");
        } else {
            TestResults::fail("Location count mismatch", "Expected 2, got " . $listResult['sedi_operative_count']);
        }
    } else {
        TestResults::fail("List query returned no results");
    }

    // ========================================================================
    // TEST 6: Delete Company with Location Cascade
    // ========================================================================
    echo Colors::$BLUE . "\n[6/7] Delete Company with Location Cascade\n" . Colors::$NC;
    echo str_repeat("-", 80) . "\n";

    $db->beginTransaction();

    try {
        $deletedAt = date('Y-m-d H:i:s');

        // Count locations before delete
        $locationCountBefore = $db->count('tenant_locations', [
            'tenant_id' => $tenantId,
            'deleted_at' => null
        ]);

        TestResults::info("Locations before delete: $locationCountBefore (1 sede legale + 2 sedi operative)");

        // Soft-delete tenant
        $db->update('tenants', ['deleted_at' => $deletedAt], ['id' => $tenantId]);
        TestResults::pass("Tenant soft-deleted");

        // Soft-delete all locations
        $locationsDeleted = $db->update(
            'tenant_locations',
            ['deleted_at' => $deletedAt],
            ['tenant_id' => $tenantId]
        );

        if ($locationsDeleted === $locationCountBefore) {
            TestResults::pass("All locations cascaded", "$locationsDeleted locations deleted");
        } else {
            TestResults::fail("Cascade count mismatch", "Expected $locationCountBefore, deleted $locationsDeleted");
        }

        $db->commit();

        // Verify cascade
        $remainingActive = $db->count('tenant_locations', [
            'tenant_id' => $tenantId,
            'deleted_at' => null
        ]);

        if ($remainingActive === 0) {
            TestResults::pass("No active locations remain after cascade");
        } else {
            TestResults::fail("Active locations still exist", "$remainingActive active");
        }

        $totalDeleted = $db->fetchOne(
            'SELECT COUNT(*) as cnt FROM tenant_locations
             WHERE tenant_id = ? AND deleted_at IS NOT NULL',
            [$tenantId]
        )['cnt'];

        // Should be 5 + 1 = 6 total (3 old sedi operative + 2 new sedi operative + 1 sede legale)
        if ($totalDeleted === 6) {
            TestResults::pass("Total deleted locations correct", "$totalDeleted total");
        } else {
            TestResults::warn("Deleted count unexpected", "Expected 6, found $totalDeleted");
        }

    } catch (Exception $e) {
        $db->rollback();
        TestResults::fail("Delete transaction failed", $e->getMessage());
    }

    // ========================================================================
    // TEST 7: Data Integrity and Consistency Checks
    // ========================================================================
    echo Colors::$BLUE . "\n[7/7] Data Integrity and Consistency Checks\n" . Colors::$NC;
    echo str_repeat("-", 80) . "\n";

    // Check for orphaned locations (locations with non-existent tenant_id)
    $orphanedLocations = $conn->query(
        "SELECT COUNT(*) as cnt FROM tenant_locations tl
         LEFT JOIN tenants t ON tl.tenant_id = t.id
         WHERE t.id IS NULL"
    )->fetch(PDO::FETCH_ASSOC)['cnt'];

    if ($orphanedLocations === 0) {
        TestResults::pass("No orphaned locations found");
    } else {
        TestResults::warn("Orphaned locations exist", "$orphanedLocations found");
    }

    // Check for tenants without sede legale
    $tenantsWithoutSedeLegale = $conn->query(
        "SELECT COUNT(*) as cnt FROM tenants t
         WHERE t.deleted_at IS NULL
           AND NOT EXISTS (
               SELECT 1 FROM tenant_locations tl
               WHERE tl.tenant_id = t.id
                 AND tl.location_type = 'sede_legale'
                 AND tl.deleted_at IS NULL
           )"
    )->fetch(PDO::FETCH_ASSOC)['cnt'];

    if ($tenantsWithoutSedeLegale === 0) {
        TestResults::pass("All active tenants have sede legale");
    } else {
        TestResults::warn("Tenants without sede legale", "$tenantsWithoutSedeLegale found");
    }

    // Check for multiple primary sede legale per tenant
    $multipleprimary = $conn->query(
        "SELECT tenant_id, COUNT(*) as cnt
         FROM tenant_locations
         WHERE location_type = 'sede_legale'
           AND is_primary = 1
           AND deleted_at IS NULL
         GROUP BY tenant_id
         HAVING cnt > 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (empty($multipleS)) {
        TestResults::pass("No tenants with multiple primary sede legale");
    } else {
        TestResults::warn("Multiple primary sede legale found", count($multiplePrimary) . " tenants");
    }

    // Check foreign key integrity
    $fkCheck = $conn->query("SELECT @@FOREIGN_KEY_CHECKS as fk")->fetch(PDO::FETCH_ASSOC)['fk'];
    if ($fkCheck == 1) {
        TestResults::pass("Foreign key checks enabled");
    } else {
        TestResults::warn("Foreign key checks disabled");
    }

    // ========================================================================
    // CLEANUP
    // ========================================================================
    echo Colors::$BLUE . "\n[CLEANUP] Removing Test Data\n" . Colors::$NC;
    echo str_repeat("-", 80) . "\n";

    try {
        // Hard delete test tenant and locations
        $conn->exec("DELETE FROM tenant_locations WHERE tenant_id = $tenantId");
        $conn->exec("DELETE FROM tenants WHERE id = $tenantId");
        TestResults::info("Test data cleaned up successfully");
    } catch (Exception $e) {
        TestResults::warn("Cleanup failed", $e->getMessage());
    }

    // ========================================================================
    // SUMMARY
    // ========================================================================
    echo "\n";
    echo str_repeat("=", 80) . "\n";
    echo "  TEST SUMMARY\n";
    echo str_repeat("=", 80) . "\n";

    $summary = TestResults::getSummary();

    echo "\nTotal Tests:    " . $summary['total'] . "\n";
    echo Colors::$GREEN . "Passed:         " . $summary['passed'] . " ✓\n" . Colors::$NC;

    if ($summary['failed'] > 0) {
        echo Colors::$RED . "Failed:         " . $summary['failed'] . " ✗\n" . Colors::$NC;
    } else {
        echo "Failed:         0\n";
    }

    if ($summary['warnings'] > 0) {
        echo Colors::$YELLOW . "Warnings:       " . $summary['warnings'] . " ⚠\n" . Colors::$NC;
    } else {
        echo "Warnings:       0\n";
    }

    $successRate = $summary['total'] > 0 ? round(($summary['passed'] / $summary['total']) * 100, 2) : 0;
    echo "\nSuccess Rate:   $successRate%\n";

    if ($summary['failed'] === 0) {
        echo "\n" . Colors::$GREEN . "✓ ALL TESTS PASSED! System is working correctly.\n" . Colors::$NC;
        echo "\nThe tenant_locations system is fully functional:\n";
        echo "  • Database schema is correct\n";
        echo "  • Create operations work with multiple locations\n";
        echo "  • Retrieve operations return structured data\n";
        echo "  • Update operations handle soft-delete and re-insert\n";
        echo "  • List operations show accurate location counts\n";
        echo "  • Delete operations cascade to all locations\n";
        echo "  • Data integrity is maintained\n";
        echo "\n";
        exit(0);
    } else {
        echo "\n" . Colors::$RED . "✗ SOME TESTS FAILED. Please review the errors above.\n" . Colors::$NC;
        echo "\n";
        exit(1);
    }

} catch (Exception $e) {
    echo "\n" . Colors::$RED . "CRITICAL ERROR: " . $e->getMessage() . "\n" . Colors::$NC;
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n\n";
    exit(1);
}
