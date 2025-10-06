<?php
/**
 * Test Email Configuration Loading
 *
 * Verifica che il sistema di caricamento configurazione email funzioni correttamente
 * con il nuovo sistema database-driven
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Test Email Configuration Loading</h1>";
echo "<style>body { font-family: monospace; padding: 20px; } .success { color: green; } .error { color: red; } .info { color: blue; } pre { background: #f4f4f4; padding: 10px; border-radius: 5px; }</style>";

// Test 1: Load configuration from database
echo "<h2>Test 1: Load Email Config from Database</h2>";
try {
    require_once __DIR__ . '/includes/email_config.php';

    $config = getEmailConfigFromDatabase();

    echo "<p class='success'>✓ Configuration loaded successfully</p>";
    echo "<pre>" . print_r($config, true) . "</pre>";

    // Verify required keys
    $requiredKeys = ['smtpHost', 'smtpPort', 'smtpUsername', 'smtpPassword', 'fromEmail', 'fromName', 'replyTo'];
    $allPresent = true;

    foreach ($requiredKeys as $key) {
        if (!isset($config[$key])) {
            echo "<p class='error'>✗ Missing key: $key</p>";
            $allPresent = false;
        }
    }

    if ($allPresent) {
        echo "<p class='success'>✓ All required keys present</p>";
    }

} catch (Exception $e) {
    echo "<p class='error'>✗ Error: " . $e->getMessage() . "</p>";
}

// Test 2: Check if configured in database
echo "<h2>Test 2: Check Database Configuration Status</h2>";
try {
    $isConfigured = isEmailConfiguredInDatabase();

    if ($isConfigured) {
        echo "<p class='success'>✓ Email is configured in database (using DB values)</p>";
    } else {
        echo "<p class='info'>ℹ Email NOT configured in database (using fallback hardcoded values)</p>";
    }

} catch (Exception $e) {
    echo "<p class='error'>✗ Error: " . $e->getMessage() . "</p>";
}

// Test 3: EmailSender with auto-load
echo "<h2>Test 3: EmailSender Auto-Load Configuration</h2>";
try {
    require_once __DIR__ . '/includes/EmailSender.php';

    // Create instance WITHOUT passing config (should auto-load from DB)
    $emailSender = new EmailSender();

    echo "<p class='success'>✓ EmailSender instantiated successfully (auto-loaded config from DB)</p>";

    // Use reflection to check private properties
    $reflection = new ReflectionClass($emailSender);

    $properties = ['smtpHost', 'smtpPort', 'smtpUsername', 'fromEmail', 'fromName', 'replyTo'];
    $configCheck = [];

    foreach ($properties as $prop) {
        $property = $reflection->getProperty($prop);
        $property->setAccessible(true);
        $value = $property->getValue($emailSender);

        // Mask password
        if ($prop === 'smtpPassword') {
            $value = str_repeat('*', min(strlen($value), 8));
        }

        $configCheck[$prop] = $value;
    }

    echo "<pre>" . print_r($configCheck, true) . "</pre>";

} catch (Exception $e) {
    echo "<p class='error'>✗ Error: " . $e->getMessage() . "</p>";
}

// Test 4: EmailSender with explicit config
echo "<h2>Test 4: EmailSender with Explicit Config</h2>";
try {
    $customConfig = [
        'smtpHost' => 'custom.example.com',
        'smtpPort' => 587,
        'smtpUsername' => 'test@example.com',
        'smtpPassword' => 'testpass',
        'fromEmail' => 'test@example.com',
        'fromName' => 'Test Sender',
        'replyTo' => 'reply@example.com'
    ];

    $emailSender = new EmailSender($customConfig);

    echo "<p class='success'>✓ EmailSender instantiated with custom config</p>";

    // Verify it used custom config
    $reflection = new ReflectionClass($emailSender);
    $hostProp = $reflection->getProperty('smtpHost');
    $hostProp->setAccessible(true);
    $host = $hostProp->getValue($emailSender);

    if ($host === 'custom.example.com') {
        echo "<p class='success'>✓ Custom config correctly applied (smtpHost: $host)</p>";
    } else {
        echo "<p class='error'>✗ Custom config NOT applied (smtpHost: $host)</p>";
    }

} catch (Exception $e) {
    echo "<p class='error'>✗ Error: " . $e->getMessage() . "</p>";
}

// Test 5: Database query directly
echo "<h2>Test 5: Direct Database Query</h2>";
try {
    require_once __DIR__ . '/includes/db.php';
    $db = Database::getInstance();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("
        SELECT setting_key, setting_value
        FROM system_settings
        WHERE setting_key LIKE 'smtp%' OR setting_key LIKE 'from_%' OR setting_key = 'reply_to'
        ORDER BY setting_key
    ");

    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($settings) > 0) {
        echo "<p class='success'>✓ Found " . count($settings) . " email settings in database</p>";
        echo "<pre>";
        foreach ($settings as $setting) {
            $value = $setting['setting_value'];
            if (strpos($setting['setting_key'], 'password') !== false) {
                $value = str_repeat('*', min(strlen($value), 8));
            }
            echo "{$setting['setting_key']}: $value\n";
        }
        echo "</pre>";
    } else {
        echo "<p class='info'>ℹ No email settings found in database (will use fallback)</p>";
    }

} catch (Exception $e) {
    echo "<p class='error'>✗ Error: " . $e->getMessage() . "</p>";
}

// Test 6: Cache functionality
echo "<h2>Test 6: Configuration Caching</h2>";
try {
    // First call
    $start1 = microtime(true);
    $config1 = getEmailConfigFromDatabase();
    $time1 = (microtime(true) - $start1) * 1000;

    // Second call (should be cached)
    $start2 = microtime(true);
    $config2 = getEmailConfigFromDatabase();
    $time2 = (microtime(true) - $start2) * 1000;

    echo "<p class='info'>First call: " . number_format($time1, 3) . " ms</p>";
    echo "<p class='info'>Second call (cached): " . number_format($time2, 3) . " ms</p>";

    if ($time2 < $time1) {
        echo "<p class='success'>✓ Caching is working (second call faster)</p>";
    } else {
        echo "<p class='info'>ℹ Caching may not be optimal (times similar)</p>";
    }

    if ($config1 === $config2) {
        echo "<p class='success'>✓ Cached config matches original</p>";
    } else {
        echo "<p class='error'>✗ Cached config differs from original</p>";
    }

} catch (Exception $e) {
    echo "<p class='error'>✗ Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>Summary</h2>";
echo "<p>All tests completed. Check results above for any errors.</p>";
echo "<p><a href='configurazioni.php'>Go to Configurazioni Page</a> | <a href='dashboard.php'>Dashboard</a></p>";
?>
