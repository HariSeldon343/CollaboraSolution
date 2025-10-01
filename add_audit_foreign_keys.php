<?php
/**
 * Script to add foreign keys to audit_logs table
 */

require_once __DIR__ . '/includes/db.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    echo "Adding foreign keys to audit_logs table..." . PHP_EOL;

    // Check if foreign keys already exist
    $checkFK = "SELECT COUNT(*) as count
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE table_schema = DATABASE()
                AND table_name = 'audit_logs'
                AND constraint_name IN ('fk_audit_tenant', 'fk_audit_user')";

    $stmt = $conn->query($checkFK);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result['count'] > 0) {
        echo "Foreign keys already exist." . PHP_EOL;
    } else {
        // Add foreign key for tenant_id
        try {
            $conn->exec("ALTER TABLE audit_logs
                        ADD CONSTRAINT fk_audit_tenant
                        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE");
            echo "Added foreign key for tenant_id" . PHP_EOL;
        } catch (PDOException $e) {
            echo "Warning: Could not add tenant foreign key: " . $e->getMessage() . PHP_EOL;
        }

        // Add foreign key for user_id
        try {
            $conn->exec("ALTER TABLE audit_logs
                        ADD CONSTRAINT fk_audit_user
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
            echo "Added foreign key for user_id" . PHP_EOL;
        } catch (PDOException $e) {
            echo "Warning: Could not add user foreign key: " . $e->getMessage() . PHP_EOL;
        }
    }

    // Insert demo data
    echo "Inserting demo data..." . PHP_EOL;

    $demoData = [
        [
            'tenant_id' => 1,
            'user_id' => null,
            'action' => 'system_update',
            'entity_type' => 'system_setting',
            'entity_id' => null,
            'description' => 'Audit logs table created and configured',
            'ip_address' => '127.0.0.1',
            'severity' => 'info',
            'status' => 'success'
        ]
    ];

    foreach ($demoData as $data) {
        try {
            $sql = "INSERT INTO audit_logs (
                        tenant_id, user_id, action, entity_type, entity_id,
                        description, ip_address, severity, status
                    ) VALUES (
                        :tenant_id, :user_id, :action, :entity_type, :entity_id,
                        :description, :ip_address, :severity, :status
                    )";

            $stmt = $conn->prepare($sql);
            $stmt->execute($data);
            echo "Inserted demo record" . PHP_EOL;
        } catch (PDOException $e) {
            echo "Warning: Could not insert demo data: " . $e->getMessage() . PHP_EOL;
        }
    }

    // Verify the installation
    $count = $conn->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
    echo PHP_EOL . "Success! audit_logs table has $count record(s)." . PHP_EOL;

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}