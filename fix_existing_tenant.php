<?php
/**
 * Fix existing tenant to comply with new constraints
 */

require_once __DIR__ . '/includes/db.php';

echo "=== FIXING EXISTING TENANT ===\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Get existing tenants
    $stmt = $pdo->query("SELECT id, name, codice_fiscale, partita_iva FROM tenants");
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($tenants) . " tenant(s)\n\n";

    foreach ($tenants as $tenant) {
        echo "Tenant ID: " . $tenant['id'] . " - " . $tenant['name'] . "\n";

        // Check if CF or P.IVA exists
        if (empty($tenant['codice_fiscale']) && empty($tenant['partita_iva'])) {
            echo "  ⚠️  No CF or P.IVA - adding placeholder P.IVA\n";

            // Add a placeholder Partita IVA (99999999999 is a common test value)
            $placeholderPIVA = '01234567890'; // Valid format but placeholder

            $updateStmt = $pdo->prepare("UPDATE tenants SET partita_iva = ? WHERE id = ?");
            $updateStmt->execute([$placeholderPIVA, $tenant['id']]);

            echo "  ✓ Updated with placeholder P.IVA: $placeholderPIVA\n";
            echo "  ℹ️  IMPORTANTE: Aggiornare con dati reali il prima possibile!\n";
        } else {
            echo "  ✓ CF or P.IVA already set\n";
        }

        // Update denominazione if empty
        $stmt = $pdo->prepare("SELECT denominazione FROM tenants WHERE id = ?");
        $stmt->execute([$tenant['id']]);
        $denominazione = $stmt->fetchColumn();

        if (empty($denominazione)) {
            echo "  ⚠️  Denominazione empty - setting to tenant name\n";
            $updateStmt = $pdo->prepare("UPDATE tenants SET denominazione = ? WHERE id = ?");
            $updateStmt->execute([$tenant['name'], $tenant['id']]);
            echo "  ✓ Denominazione set to: " . $tenant['name'] . "\n";
        }
        echo "\n";
    }

    // Now try to add the CHECK constraint again
    echo "[STEP] Adding CHECK constraint for CF/P.IVA...\n";
    try {
        $pdo->exec("ALTER TABLE tenants ADD CONSTRAINT chk_tenant_fiscal_code CHECK (codice_fiscale IS NOT NULL OR partita_iva IS NOT NULL)");
        echo "[OK] CHECK constraint added successfully\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "[SKIP] CHECK constraint already exists\n";
        } else {
            echo "[ERROR] CHECK constraint failed: " . $e->getMessage() . "\n";
        }
    }

    echo "\n[SUCCESS] Tenant fixes completed!\n";

} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
?>
