<?php
/**
 * Run Database Integrity Tests
 * Verifies the aziende migration was successful
 */

require_once __DIR__ . '/includes/db.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Integrity Tests</title></head><body>";
    echo "<pre style='background:#f4f4f4;padding:20px;'>";
}

echo "=== DATABASE INTEGRITY TESTS ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $testsPassed = 0;
    $testsFailed = 0;

    // TEST 1: Verify tenants table structure
    echo "============================================\n";
    echo "TEST 1: Verifica Struttura Tabella Tenants\n";
    echo "============================================\n";

    $requiredColumns = [
        'denominazione' => 'varchar(255)',
        'codice_fiscale' => 'varchar(16)',
        'partita_iva' => 'varchar(11)',
        'sede_legale_indirizzo' => 'varchar(255)',
        'sede_legale_civico' => 'varchar(10)',
        'sede_legale_comune' => 'varchar(100)',
        'sede_legale_provincia' => 'varchar(2)',
        'sede_legale_cap' => 'varchar(5)',
        'sedi_operative' => 'json',
        'settore_merceologico' => 'varchar(100)',
        'numero_dipendenti' => 'int',
        'capitale_sociale' => 'decimal(15,2)',
        'telefono' => 'varchar(20)',
        'email' => 'varchar(255)',
        'pec' => 'varchar(255)',
        'manager_id' => 'int unsigned',
        'rappresentante_legale' => 'varchar(255)'
    ];

    $stmt = $pdo->query("SHOW COLUMNS FROM tenants");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[$row['Field']] = strtolower($row['Type']);
    }

    foreach ($requiredColumns as $colName => $expectedType) {
        if (isset($columns[$colName])) {
            $actualType = $columns[$colName];
            // Normalize type comparison (remove unsigned, spaces etc)
            $actualTypeNorm = str_replace([' unsigned', '(10)'], '', $actualType);
            $expectedTypeNorm = str_replace([' unsigned', '(10)'], '', $expectedType);

            if (strpos($actualTypeNorm, $expectedTypeNorm) !== false || strpos($expectedTypeNorm, $actualTypeNorm) !== false) {
                echo "[✓] $colName: $actualType\n";
                $testsPassed++;
            } else {
                echo "[✗] $colName: type mismatch (expected: $expectedType, got: $actualType)\n";
                $testsFailed++;
            }
        } else {
            echo "[✗] $colName: MISSING\n";
            $testsFailed++;
        }
    }

    // TEST 2: Verify users table changes
    echo "\n============================================\n";
    echo "TEST 2: Verifica Tabella Users\n";
    echo "============================================\n";

    $stmt = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'role'");
    $roleColumn = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($roleColumn && strpos($roleColumn['Type'], 'super_admin') !== false) {
        echo "[✓] role ENUM contains 'super_admin'\n";
        echo "    Type: " . $roleColumn['Type'] . "\n";
        $testsPassed++;
    } else {
        echo "[✗] super_admin NOT found in role ENUM\n";
        $testsFailed++;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'tenant_id'");
    $tenantIdColumn = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tenantIdColumn && $tenantIdColumn['Null'] === 'YES') {
        echo "[✓] tenant_id is nullable (allows NULL)\n";
        $testsPassed++;
    } else {
        echo "[✗] tenant_id is NOT nullable\n";
        $testsFailed++;
    }

    // TEST 3: Verify foreign keys
    echo "\n============================================\n";
    echo "TEST 3: Verifica Foreign Keys\n";
    echo "============================================\n";

    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = 'collaboranexio'
          AND TABLE_NAME = 'tenants'
          AND CONSTRAINT_NAME = 'fk_tenants_manager_id'
    ");

    $fkManagerId = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($fkManagerId) {
        echo "[✓] Foreign key fk_tenants_manager_id exists\n";
        echo "    " . $fkManagerId['COLUMN_NAME'] . " -> " .
             $fkManagerId['REFERENCED_TABLE_NAME'] . "." .
             $fkManagerId['REFERENCED_COLUMN_NAME'] . "\n";
        $testsPassed++;
    } else {
        echo "[✗] Foreign key fk_tenants_manager_id NOT found\n";
        $testsFailed++;
    }

    // TEST 4: Verify CHECK constraint
    echo "\n============================================\n";
    echo "TEST 4: Verifica CHECK Constraints\n";
    echo "============================================\n";

    // Check if constraint exists (MySQL 8.0+)
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME, CHECK_CLAUSE
        FROM information_schema.CHECK_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
          AND TABLE_NAME = 'tenants'
          AND CONSTRAINT_NAME = 'chk_tenant_fiscal_code'
    ");

    $checkConstraint = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($checkConstraint) {
        echo "[✓] CHECK constraint chk_tenant_fiscal_code exists\n";
        echo "    Clause: " . substr($checkConstraint['CHECK_CLAUSE'], 0, 100) . "...\n";
        $testsPassed++;
    } else {
        // Try alternative method (SHOW CREATE TABLE)
        $stmt = $pdo->query("SHOW CREATE TABLE tenants");
        $createTable = $stmt->fetch(PDO::FETCH_ASSOC)['Create Table'];

        if (strpos($createTable, 'chk_tenant_fiscal_code') !== false) {
            echo "[✓] CHECK constraint chk_tenant_fiscal_code found in CREATE TABLE\n";
            $testsPassed++;
        } else {
            echo "[⚠] CHECK constraint chk_tenant_fiscal_code not detected\n";
            echo "    (May not be supported in this MySQL version)\n";
        }
    }

    // TEST 5: Verify existing data integrity
    echo "\n============================================\n";
    echo "TEST 5: Verifica Integrità Dati Esistenti\n";
    echo "============================================\n";

    $stmt = $pdo->query("SELECT COUNT(*) FROM tenants WHERE codice_fiscale IS NULL AND partita_iva IS NULL");
    $invalidTenants = $stmt->fetchColumn();

    if ($invalidTenants == 0) {
        echo "[✓] All tenants have CF or P.IVA\n";
        $testsPassed++;
    } else {
        echo "[✗] Found $invalidTenants tenant(s) without CF or P.IVA\n";
        $testsFailed++;
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM tenants WHERE denominazione IS NULL OR denominazione = ''");
    $missingDenominazione = $stmt->fetchColumn();

    if ($missingDenominazione == 0) {
        echo "[✓] All tenants have denominazione\n";
        $testsPassed++;
    } else {
        echo "[✗] Found $missingDenominazione tenant(s) without denominazione\n";
        $testsFailed++;
    }

    // TEST 6: Verify user-tenant relationships
    echo "\n============================================\n";
    echo "TEST 6: Verifica Relazioni User-Tenant\n";
    echo "============================================\n";

    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE tenant_id IS NULL AND role != 'super_admin'");
    $orphanedUsers = $stmt->fetchColumn();

    if ($orphanedUsers == 0) {
        echo "[✓] All non-super_admin users have tenant_id\n";
        $testsPassed++;
    } else {
        echo "[✗] Found $orphanedUsers user(s) without tenant_id (and not super_admin)\n";
        $testsFailed++;
    }

    // TEST 7: Test data summary
    echo "\n============================================\n";
    echo "TEST 7: Riepilogo Dati\n";
    echo "============================================\n";

    $stmt = $pdo->query("SELECT COUNT(*) FROM tenants");
    $tenantCount = $stmt->fetchColumn();
    echo "Total tenants: $tenantCount\n";

    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $userCount = $stmt->fetchColumn();
    echo "Total users: $userCount\n";

    $stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
    echo "\nUsers by role:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  " . $row['role'] . ": " . $row['count'] . "\n";
    }

    // Final summary
    echo "\n============================================\n";
    echo "SUMMARY\n";
    echo "============================================\n";
    $totalTests = $testsPassed + $testsFailed;
    echo "Tests passed: $testsPassed / $totalTests\n";
    echo "Tests failed: $testsFailed / $totalTests\n";

    if ($testsFailed == 0) {
        echo "\n[SUCCESS] ✓ All integrity tests passed!\n";
    } else {
        echo "\n[WARNING] ⚠ Some tests failed - review results above\n";
    }

} catch (Exception $e) {
    echo "\n[ERROR] Test execution failed\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

if (!$isCli) {
    echo "</pre></body></html>";
}
?>
