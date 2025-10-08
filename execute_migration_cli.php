<?php
/**
 * Execute Aziende Migration - CLI Version
 * Run from command line or browser
 */

// Detect if running from CLI or browser
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Migration</title></head><body>";
    echo "<pre style='background:#f4f4f4;padding:20px;'>";
}

echo "=== AZIENDE MIGRATION ===\n";
echo "Starting migration at " . date('Y-m-d H:i:s') . "\n\n";

require_once __DIR__ . '/includes/db.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    echo "[INFO] Database connection established\n";

    // Get pre-migration counts
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tenants");
    $tenantCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $userCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    echo "[INFO] Current tenants: $tenantCount\n";
    echo "[INFO] Current users: $userCount\n\n";

    // Execute migration statements one by one
    echo "[STEP 1] Adding super_admin role to users.role ENUM...\n";
    try {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin', 'manager', 'user', 'guest') DEFAULT 'user'");
        echo "[OK] super_admin role added\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "[SKIP] super_admin role already exists\n";
        } else {
            throw $e;
        }
    }

    echo "\n[STEP 2] Making users.tenant_id nullable...\n";
    try {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN tenant_id INT UNSIGNED NULL");
        echo "[OK] users.tenant_id is now nullable\n";
    } catch (PDOException $e) {
        echo "[SKIP] tenant_id modification failed: " . $e->getMessage() . "\n";
    }

    echo "\n[STEP 3] Adding new columns to tenants table...\n";

    $newColumns = [
        "denominazione VARCHAR(255) NOT NULL DEFAULT ''",
        "codice_fiscale VARCHAR(16) NULL",
        "partita_iva VARCHAR(11) NULL",
        "sede_legale_indirizzo VARCHAR(255) NULL",
        "sede_legale_civico VARCHAR(10) NULL",
        "sede_legale_comune VARCHAR(100) NULL",
        "sede_legale_provincia VARCHAR(2) NULL",
        "sede_legale_cap VARCHAR(5) NULL",
        "sedi_operative JSON NULL",
        "settore_merceologico VARCHAR(100) NULL",
        "numero_dipendenti INT NULL",
        "capitale_sociale DECIMAL(15,2) NULL",
        "telefono VARCHAR(20) NULL",
        "email VARCHAR(255) NULL",
        "pec VARCHAR(255) NULL",
        "manager_id INT UNSIGNED NULL",
        "rappresentante_legale VARCHAR(255) NULL"
    ];

    foreach ($newColumns as $columnDef) {
        preg_match('/^(\w+)\s+(.+)$/', $columnDef, $matches);
        $columnName = $matches[1];

        try {
            $pdo->exec("ALTER TABLE tenants ADD COLUMN $columnDef");
            echo "[OK] Added column: $columnName\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "[SKIP] Column already exists: $columnName\n";
            } else {
                echo "[ERROR] Failed to add $columnName: " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\n[STEP 4] Adding CHECK constraint for CF/P.IVA...\n";
    try {
        $pdo->exec("ALTER TABLE tenants ADD CONSTRAINT chk_tenant_fiscal_code CHECK (codice_fiscale IS NOT NULL OR partita_iva IS NOT NULL)");
        echo "[OK] CHECK constraint added\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "[SKIP] CHECK constraint already exists\n";
        } else {
            echo "[WARNING] CHECK constraint failed: " . $e->getMessage() . "\n";
        }
    }

    echo "\n[STEP 5] Adding foreign key for manager_id...\n";
    try {
        $pdo->exec("ALTER TABLE tenants ADD CONSTRAINT fk_tenants_manager_id FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE RESTRICT");
        echo "[OK] Foreign key added\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "[SKIP] Foreign key already exists\n";
        } else {
            echo "[WARNING] Foreign key failed: " . $e->getMessage() . "\n";
        }
    }

    echo "\n[STEP 6] Dropping 'piano' column if exists...\n";
    try {
        // Check if column exists first
        $stmt = $pdo->query("SHOW COLUMNS FROM tenants LIKE 'piano'");
        if ($stmt->rowCount() > 0) {
            $pdo->exec("ALTER TABLE tenants DROP COLUMN piano");
            echo "[OK] Column 'piano' dropped\n";
        } else {
            echo "[SKIP] Column 'piano' does not exist\n";
        }
    } catch (PDOException $e) {
        echo "[WARNING] Drop piano failed: " . $e->getMessage() . "\n";
    }

    echo "\n[VERIFICATION] Checking migration results...\n";

    // Verify super_admin role
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $roleColumn = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($roleColumn && strpos($roleColumn['Type'], 'super_admin') !== false) {
        echo "[✓] super_admin role exists in users.role\n";
    } else {
        echo "[✗] super_admin role NOT found\n";
    }

    // Verify tenant_id nullable
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'tenant_id'");
    $tenantIdColumn = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tenantIdColumn && $tenantIdColumn['Null'] === 'YES') {
        echo "[✓] users.tenant_id is nullable\n";
    } else {
        echo "[✗] users.tenant_id is NOT nullable\n";
    }

    // Verify new tenant columns
    $requiredColumns = ['denominazione', 'codice_fiscale', 'partita_iva', 'sede_legale_indirizzo', 'sedi_operative', 'manager_id'];
    $stmt = $pdo->query("SHOW COLUMNS FROM tenants");
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    foreach ($requiredColumns as $col) {
        if (in_array($col, $existingColumns)) {
            echo "[✓] tenants.$col exists\n";
        } else {
            echo "[✗] tenants.$col NOT found\n";
        }
    }

    echo "\n[SUCCESS] Migration completed successfully!\n";
    echo "Date: " . date('Y-m-d H:i:s') . "\n";

} catch (Exception $e) {
    echo "\n[ERROR] Migration failed!\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

if (!$isCli) {
    echo "</pre></body></html>";
}
?>
