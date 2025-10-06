<?php
/**
 * Script to execute Infomaniak email configuration migration
 * Run this file once to update system_settings with Infomaniak SMTP settings
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "=== Infomaniak Email Configuration Migration ===\n\n";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Read the migration SQL file
    $sqlFile = __DIR__ . '/database/update_infomaniak_email_config.sql';

    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }

    $sql = file_get_contents($sqlFile);

    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) &&
                   !preg_match('/^--/', $stmt) &&
                   !preg_match('/^\/\*/', $stmt);
        }
    );

    $conn->beginTransaction();

    $executed = 0;
    foreach ($statements as $statement) {
        if (trim($statement)) {
            try {
                $conn->exec($statement);
                $executed++;
            } catch (PDOException $e) {
                // Skip CREATE TABLE IF NOT EXISTS errors
                if (strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        }
    }

    $conn->commit();

    echo "✓ Migration completed successfully!\n";
    echo "✓ Executed $executed SQL statements\n\n";

    // Verify settings
    echo "=== Current Email Settings ===\n";
    $stmt = $conn->query("
        SELECT setting_key,
               CASE
                   WHEN setting_key LIKE '%password%' THEN '••••••••'
                   ELSE setting_value
               END as setting_value,
               value_type,
               description
        FROM system_settings
        WHERE category = 'email'
        ORDER BY setting_key
    ");

    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($settings)) {
        echo "⚠ No email settings found in database!\n";
    } else {
        echo "\n";
        printf("%-30s %-20s %-12s %s\n", "Setting Key", "Value", "Type", "Description");
        echo str_repeat("-", 100) . "\n";

        foreach ($settings as $setting) {
            printf(
                "%-30s %-20s %-12s %s\n",
                $setting['setting_key'],
                $setting['setting_value'],
                $setting['value_type'],
                $setting['description'] ?? ''
            );
        }
    }

    echo "\n\n✓ Infomaniak SMTP configuration installed successfully!\n";
    echo "✓ Settings: mail.infomaniak.com:465 (SSL)\n";
    echo "✓ From: info@fortibyte.it\n\n";
    echo "Next steps:\n";
    echo "1. Go to http://localhost:8888/CollaboraNexio/configurazioni.php\n";
    echo "2. Navigate to Email tab\n";
    echo "3. Click 'Test Connessione' to verify SMTP settings\n";
    echo "4. Click 'Salva Modifiche' to save configuration\n\n";

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
