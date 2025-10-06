<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Check if table exists
    $stmt = $conn->query("SHOW TABLES LIKE 'system_settings'");
    $tableExists = $stmt->fetch();

    if (!$tableExists) {
        echo "❌ Table 'system_settings' does NOT exist\n\n";
        echo "Creating table...\n";

        $createSql = file_get_contents(__DIR__ . '/database/create_system_settings.sql');
        $conn->exec($createSql);

        echo "✓ Table created successfully\n";
    } else {
        echo "✓ Table 'system_settings' exists\n\n";
    }

    // Show table structure
    echo "=== Table Structure ===\n";
    $stmt = $conn->query("DESCRIBE system_settings");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    printf("%-20s %-20s %-10s %-10s\n", "Field", "Type", "Null", "Key");
    echo str_repeat("-", 70) . "\n";

    foreach ($columns as $column) {
        printf(
            "%-20s %-20s %-10s %-10s\n",
            $column['Field'],
            $column['Type'],
            $column['Null'],
            $column['Key']
        );
    }

    // Show existing email settings
    echo "\n=== Existing Email Settings ===\n";
    $stmt = $conn->query("SELECT * FROM system_settings WHERE category = 'email' OR setting_key LIKE '%email%' OR setting_key LIKE '%smtp%'");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($settings)) {
        echo "No email settings found\n";
    } else {
        foreach ($settings as $setting) {
            echo "{$setting['setting_key']} = {$setting['setting_value']}\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
