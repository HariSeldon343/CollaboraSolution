<?php
/**
 * Update SMTP password to correct Infomaniak password
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "=== Update Infomaniak SMTP Password ===\n\n";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Update SMTP password from Cartesi@1991 to Cartesi@1987
    $stmt = $conn->prepare("
        UPDATE system_settings
        SET setting_value = :new_password,
            updated_at = NOW()
        WHERE setting_key = 'smtp_password'
    ");

    $stmt->execute([':new_password' => 'Cartesi@1987']);

    echo "✓ SMTP password updated successfully\n";
    echo "✓ Changed from: Cartesi@1991\n";
    echo "✓ Changed to: Cartesi@1987\n\n";

    // Verify current settings
    echo "=== Current Infomaniak Settings ===\n";
    $stmt = $conn->query("
        SELECT setting_key,
               CASE
                   WHEN setting_key = 'smtp_password' THEN '••••••••'
                   ELSE setting_value
               END as setting_value
        FROM system_settings
        WHERE category = 'email' OR setting_key LIKE '%smtp%'
        ORDER BY setting_key
    ");

    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($settings as $setting) {
        printf("%-20s: %s\n", $setting['setting_key'], $setting['setting_value']);
    }

    echo "\n✓ Configuration complete!\n";
    echo "\nInfomaniak SMTP Settings:\n";
    echo "- Server: mail.infomaniak.com\n";
    echo "- Port: 465 (SSL)\n";
    echo "- Username: info@fortibyte.it\n";
    echo "- Password: ••••••••\n";
    echo "- From: info@fortibyte.it\n\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
