<?php
/**
 * Fix data types in audit_logs table to match foreign key references
 */

require_once __DIR__ . '/includes/db.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    echo "Fixing data types in audit_logs table..." . PHP_EOL;

    // First, drop any existing foreign keys
    echo "Dropping existing foreign keys if any..." . PHP_EOL;

    $dropFKs = [
        "ALTER TABLE audit_logs DROP FOREIGN KEY fk_audit_tenant",
        "ALTER TABLE audit_logs DROP FOREIGN KEY fk_audit_user"
    ];

    foreach ($dropFKs as $sql) {
        try {
            $conn->exec($sql);
            echo "  Dropped foreign key" . PHP_EOL;
        } catch (PDOException $e) {
            // Ignore if foreign key doesn't exist
        }
    }

    // Modify tenant_id to match tenants.id type
    echo "Modifying tenant_id column type..." . PHP_EOL;
    $conn->exec("ALTER TABLE audit_logs MODIFY COLUMN tenant_id INT(10) UNSIGNED NOT NULL");
    echo "  tenant_id changed to INT(10) UNSIGNED NOT NULL" . PHP_EOL;

    // Now add the foreign keys
    echo "Adding foreign keys..." . PHP_EOL;

    // Add foreign key for tenant_id
    try {
        $conn->exec("ALTER TABLE audit_logs
                    ADD CONSTRAINT fk_audit_tenant
                    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE");
        echo "  Added foreign key for tenant_id" . PHP_EOL;
    } catch (PDOException $e) {
        echo "  ERROR: Could not add tenant foreign key: " . $e->getMessage() . PHP_EOL;
    }

    // Add foreign key for user_id (already correct type)
    try {
        $conn->exec("ALTER TABLE audit_logs
                    ADD CONSTRAINT fk_audit_user
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
        echo "  Added foreign key for user_id" . PHP_EOL;
    } catch (PDOException $e) {
        echo "  WARNING: Could not add user foreign key: " . $e->getMessage() . PHP_EOL;
    }

    // Verify the structure
    echo PHP_EOL . "Verifying table structure..." . PHP_EOL;
    $stmt = $conn->query("DESCRIBE audit_logs");
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Key columns:" . PHP_EOL;
    foreach ($fields as $field) {
        if (in_array($field['Field'], ['tenant_id', 'user_id'])) {
            echo sprintf("  %-20s %-30s %s",
                $field['Field'],
                $field['Type'],
                $field['Key'] ? "(" . $field['Key'] . ")" : ""
            ) . PHP_EOL;
        }
    }

    // Check foreign keys
    echo PHP_EOL . "Foreign keys:" . PHP_EOL;
    $stmt = $conn->query("
        SELECT
            CONSTRAINT_NAME,
            COLUMN_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE table_schema = DATABASE()
        AND table_name = 'audit_logs'
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");

    $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fks as $fk) {
        echo sprintf("  %s: %s -> %s.%s",
            $fk['CONSTRAINT_NAME'],
            $fk['COLUMN_NAME'],
            $fk['REFERENCED_TABLE_NAME'],
            $fk['REFERENCED_COLUMN_NAME']
        ) . PHP_EOL;
    }

    echo PHP_EOL . "Success! audit_logs table structure has been fixed." . PHP_EOL;

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}