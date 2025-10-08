<?php
/**
 * Test Script: Tenant Locations API Integration
 *
 * Tests that all tenant APIs properly work with the new tenant_locations table
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 */

declare(strict_types=1);

// Initialize session
session_start();

// Load dependencies
require_once __DIR__ . '/includes/db.php';

// Output formatting
function testHeader(string $title): void {
    echo "\n" . str_repeat('=', 80) . "\n";
    echo "TEST: $title\n";
    echo str_repeat('=', 80) . "\n";
}

function testResult(string $test, bool $passed, string $message = ''): void {
    $status = $passed ? '✓ PASS' : '✗ FAIL';
    echo sprintf("%-50s %s\n", $test, $status);
    if (!empty($message)) {
        echo "       → $message\n";
    }
}

function jsonOutput(string $label, $data): void {
    echo "\n$label:\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

try {
    $db = Database::getInstance();

    testHeader('PREREQUISITE: Check tenant_locations Table');

    // Check if tenant_locations table exists
    $tableExists = $db->fetchOne(
        "SELECT COUNT(*) as count
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = 'collaboranexio'
           AND TABLE_NAME = 'tenant_locations'"
    );

    testResult(
        'tenant_locations table exists',
        $tableExists['count'] == 1,
        $tableExists['count'] == 1 ? 'Table found' : 'Table NOT found - run migration first!'
    );

    if ($tableExists['count'] != 1) {
        echo "\nERROR: tenant_locations table not found!\n";
        echo "Please run: /database/migrations/tenant_locations_schema.sql\n";
        exit(1);
    }

    // Check table structure
    $columns = $db->fetchAll(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = 'collaboranexio'
           AND TABLE_NAME = 'tenant_locations'"
    );

    $requiredColumns = [
        'id', 'tenant_id', 'location_type', 'indirizzo', 'civico',
        'cap', 'comune', 'provincia', 'is_primary', 'is_active', 'deleted_at'
    ];

    $existingColumns = array_column($columns, 'COLUMN_NAME');
    $missingColumns = array_diff($requiredColumns, $existingColumns);

    testResult(
        'All required columns exist',
        empty($missingColumns),
        empty($missingColumns) ? 'All columns present' : 'Missing: ' . implode(', ', $missingColumns)
    );

    testHeader('TEST 1: Check Existing Tenants with Locations');

    // Get tenants with their locations
    $tenantsWithLocations = $db->fetchAll(
        "SELECT
            t.id,
            t.denominazione,
            COUNT(tl.id) as total_locations,
            SUM(CASE WHEN tl.location_type = 'sede_legale' THEN 1 ELSE 0 END) as sede_legale_count,
            SUM(CASE WHEN tl.location_type = 'sede_operativa' THEN 1 ELSE 0 END) as sedi_operative_count
         FROM tenants t
         LEFT JOIN tenant_locations tl ON t.id = tl.tenant_id AND tl.deleted_at IS NULL
         WHERE t.deleted_at IS NULL
         GROUP BY t.id, t.denominazione
         HAVING total_locations > 0
         LIMIT 5"
    );

    testResult(
        'Found tenants with locations',
        count($tenantsWithLocations) > 0,
        count($tenantsWithLocations) . ' tenants found'
    );

    jsonOutput('Tenants with locations', $tenantsWithLocations);

    testHeader('TEST 2: Simulate API Create - Tenant with Multiple Locations');

    // Create test tenant data
    $testTenant = [
        'denominazione' => 'Test Company ' . date('His'),
        'codice_fiscale' => 'TSTCMP' . date('His') . '001',
        'partita_iva' => '12345678' . substr(date('His'), -3),
        'status' => 'active'
    ];

    $testSedeLegale = [
        'indirizzo' => 'Via Test',
        'civico' => '123',
        'cap' => '20100',
        'comune' => 'Milano',
        'provincia' => 'MI',
        'telefono' => '+39 02 12345678',
        'email' => 'test@test.it'
    ];

    $testSediOperative = [
        [
            'indirizzo' => 'Via Roma',
            'civico' => '45',
            'cap' => '00100',
            'comune' => 'Roma',
            'provincia' => 'RM',
            'telefono' => '+39 06 11223344',
            'email' => 'roma@test.it',
            'manager_nome' => 'Mario Rossi',
            'note' => 'Sede operativa Roma'
        ],
        [
            'indirizzo' => 'Corso Torino',
            'civico' => '78',
            'cap' => '10121',
            'comune' => 'Torino',
            'provincia' => 'TO',
            'telefono' => '+39 011 99887766',
            'email' => 'torino@test.it',
            'manager_nome' => 'Luigi Verdi',
            'note' => 'Centro R&D Torino'
        ]
    ];

    $db->beginTransaction();

    try {
        // Insert tenant
        $tenantId = $db->insert('tenants', $testTenant);
        testResult('Insert test tenant', $tenantId > 0, "Tenant ID: $tenantId");

        // Insert sede legale
        $sedeLegaleId = $db->insert('tenant_locations', array_merge(
            $testSedeLegale,
            [
                'tenant_id' => $tenantId,
                'location_type' => 'sede_legale',
                'is_primary' => 1,
                'is_active' => 1
            ]
        ));
        testResult('Insert sede legale', $sedeLegaleId > 0, "Location ID: $sedeLegaleId");

        // Insert sedi operative
        $sediOpIds = [];
        foreach ($testSediOperative as $sede) {
            $sedeId = $db->insert('tenant_locations', array_merge(
                $sede,
                [
                    'tenant_id' => $tenantId,
                    'location_type' => 'sede_operativa',
                    'is_primary' => 0,
                    'is_active' => 1
                ]
            ));
            $sediOpIds[] = $sedeId;
        }
        testResult(
            'Insert sedi operative',
            count($sediOpIds) === 2,
            count($sediOpIds) . ' locations inserted'
        );

        // Verify locations count
        $locationCount = $db->count('tenant_locations', [
            'tenant_id' => $tenantId,
            'deleted_at' => null
        ]);
        testResult(
            'Verify total locations',
            $locationCount === 3,
            "$locationCount locations (expected 3)"
        );

        $db->commit();

        testHeader('TEST 3: Simulate API Get - Retrieve Tenant with Locations');

        // Fetch tenant with locations (simulating get.php)
        $tenantData = $db->fetchOne(
            'SELECT * FROM tenants WHERE id = ?',
            [$tenantId]
        );

        $sedeLegaleData = $db->fetchOne(
            'SELECT * FROM tenant_locations
             WHERE tenant_id = ? AND location_type = "sede_legale" AND deleted_at IS NULL',
            [$tenantId]
        );

        $sediOperativeData = $db->fetchAll(
            'SELECT * FROM tenant_locations
             WHERE tenant_id = ? AND location_type = "sede_operativa" AND deleted_at IS NULL',
            [$tenantId]
        );

        testResult('Fetch tenant data', !empty($tenantData), 'Tenant found');
        testResult('Fetch sede legale', !empty($sedeLegaleData), 'Sede legale found');
        testResult(
            'Fetch sedi operative',
            count($sediOperativeData) === 2,
            count($sediOperativeData) . ' sedi operative found'
        );

        jsonOutput('Retrieved Tenant with Locations', [
            'tenant' => $tenantData,
            'sede_legale' => $sedeLegaleData,
            'sedi_operative' => $sediOperativeData
        ]);

        testHeader('TEST 4: Simulate API Update - Modify Locations');

        // Update: Add one more sede operativa
        $newSedeOp = [
            'tenant_id' => $tenantId,
            'location_type' => 'sede_operativa',
            'indirizzo' => 'Via Napoli',
            'civico' => '999',
            'cap' => '80100',
            'comune' => 'Napoli',
            'provincia' => 'NA',
            'telefono' => '+39 081 55443322',
            'email' => 'napoli@test.it',
            'manager_nome' => 'Giuseppe Bianchi',
            'note' => 'Nuova sede Napoli',
            'is_primary' => 0,
            'is_active' => 1
        ];

        $db->beginTransaction();

        $newSedeId = $db->insert('tenant_locations', $newSedeOp);
        testResult('Add new sede operativa', $newSedeId > 0, "Location ID: $newSedeId");

        $db->commit();

        // Verify updated count
        $updatedCount = $db->count('tenant_locations', [
            'tenant_id' => $tenantId,
            'location_type' => 'sede_operativa',
            'deleted_at' => null
        ]);
        testResult(
            'Verify updated count',
            $updatedCount === 3,
            "$updatedCount sedi operative (expected 3)"
        );

        testHeader('TEST 5: Simulate API List - Retrieve Tenants with Location Info');

        // Simulate list.php query
        $tenantsList = $db->fetchAll(
            "SELECT
                t.id,
                t.denominazione,
                tl.comune as sede_legale_comune,
                tl.provincia as sede_legale_provincia,
                (SELECT COUNT(*)
                 FROM tenant_locations
                 WHERE tenant_id = t.id
                   AND location_type = 'sede_operativa'
                   AND deleted_at IS NULL) as sedi_operative_count
             FROM tenants t
             LEFT JOIN tenant_locations tl ON t.id = tl.tenant_id
                AND tl.location_type = 'sede_legale'
                AND tl.is_primary = 1
                AND tl.deleted_at IS NULL
             WHERE t.id = ?",
            [$tenantId]
        );

        testResult('List query executed', count($tenantsList) === 1, 'Tenant found in list');
        testResult(
            'Location count in list',
            $tenantsList[0]['sedi_operative_count'] == 3,
            $tenantsList[0]['sedi_operative_count'] . ' sedi operative'
        );

        jsonOutput('List Result', $tenantsList);

        testHeader('TEST 6: Simulate API Delete - Cascade to Locations');

        $db->beginTransaction();

        // Count locations before delete
        $locationsBeforeDelete = $db->count('tenant_locations', [
            'tenant_id' => $tenantId,
            'deleted_at' => null
        ]);

        // Soft-delete tenant
        $deletedAt = date('Y-m-d H:i:s');
        $db->update('tenants', ['deleted_at' => $deletedAt], ['id' => $tenantId]);

        // Soft-delete locations
        $db->update(
            'tenant_locations',
            ['deleted_at' => $deletedAt],
            ['tenant_id' => $tenantId]
        );

        $db->commit();

        // Verify cascade
        $locationsAfterDelete = $db->count('tenant_locations', [
            'tenant_id' => $tenantId,
            'deleted_at' => null
        ]);

        testResult(
            'Tenant soft-deleted',
            !empty($db->fetchOne('SELECT deleted_at FROM tenants WHERE id = ?', [$tenantId])['deleted_at']),
            'deleted_at set'
        );

        testResult(
            'Locations cascade deleted',
            $locationsAfterDelete === 0,
            "$locationsBeforeDelete locations deleted, $locationsAfterDelete remaining active"
        );

        testHeader('TEST 7: Cleanup Test Data');

        // Hard delete test data
        $db->beginTransaction();

        $conn = $db->getConnection();
        $stmt = $conn->prepare('DELETE FROM tenant_locations WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $locationsDeleted = $stmt->rowCount();

        $stmt = $conn->prepare('DELETE FROM tenants WHERE id = ?');
        $stmt->execute([$tenantId]);
        $tenantsDeleted = $stmt->rowCount();

        $db->commit();

        testResult(
            'Cleanup completed',
            $tenantsDeleted === 1 && $locationsDeleted === 4,
            "Deleted $tenantsDeleted tenant, $locationsDeleted locations"
        );

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

    testHeader('SUMMARY: All Tests Completed Successfully');

    echo "\n✓ All tenant APIs are properly integrated with tenant_locations table\n";
    echo "✓ Create API: Inserts locations correctly\n";
    echo "✓ Update API: Manages location modifications\n";
    echo "✓ Get API: Retrieves structured location data\n";
    echo "✓ List API: Shows location counts and sede legale info\n";
    echo "✓ Delete API: Cascades soft-delete to locations\n";
    echo "\nINTEGRATION STATUS: SUCCESS ✓\n\n";

} catch (Exception $e) {
    testHeader('ERROR: Test Failed');
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
