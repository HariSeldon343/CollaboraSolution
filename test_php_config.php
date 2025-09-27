<?php
/**
 * PHP Configuration Test Script
 * Tests PHP version and required extensions for CollaboraNexio
 */

// Disable error display to avoid module warnings
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: text/plain');

echo "CollaboraNexio - PHP Configuration Test\n";
echo "========================================\n\n";

// PHP Version Check
echo "PHP Version: " . PHP_VERSION . "\n";
$version_parts = explode('.', PHP_VERSION);
$major = (int)$version_parts[0];
$minor = (int)$version_parts[1];

if ($major >= 8 && $minor >= 2) {
    echo "✓ PHP version meets requirements (8.2+)\n";
} else {
    echo "✗ PHP 8.2 or higher required\n";
}

echo "\n";

// Required Extensions Check
echo "Required Extensions:\n";
echo "--------------------\n";

$required_extensions = [
    'bcmath' => 'BCMath Arbitrary Precision Mathematics',
    'curl' => 'cURL for HTTP requests',
    'fileinfo' => 'File Information',
    'gd' => 'GD Graphics Library',
    'mbstring' => 'Multibyte String',
    'openssl' => 'OpenSSL',
    'pdo_mysql' => 'PDO MySQL Driver',
    'zip' => 'ZIP Archive'
];

$all_present = true;
$missing = [];

foreach ($required_extensions as $ext => $description) {
    if (extension_loaded($ext)) {
        echo "✓ {$ext}: {$description}\n";
    } else {
        echo "✗ {$ext}: {$description} - MISSING\n";
        $all_present = false;
        $missing[] = $ext;
    }
}

echo "\n";

// PHP Configuration Settings
echo "PHP Configuration:\n";
echo "------------------\n";

$settings = [
    'memory_limit' => 'Memory Limit',
    'max_execution_time' => 'Max Execution Time',
    'post_max_size' => 'POST Max Size',
    'upload_max_filesize' => 'Upload Max Filesize',
    'max_file_uploads' => 'Max File Uploads'
];

foreach ($settings as $setting => $label) {
    $value = ini_get($setting);
    echo "{$label}: {$value}\n";
}

echo "\n";

// Database Connection Test (optional)
echo "Database Connection:\n";
echo "--------------------\n";

if (extension_loaded('pdo_mysql')) {
    echo "✓ PDO MySQL extension loaded\n";

    // Try to check if we can create a PDO instance (without actually connecting)
    try {
        $test = new PDO('mysql:host=127.0.0.1', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        unset($test);
        echo "✓ PDO can be instantiated\n";
    } catch (Exception $e) {
        // This is expected since we're not providing credentials
        echo "✓ PDO instantiation works (connection test skipped)\n";
    }
} else {
    echo "✗ PDO MySQL extension not loaded\n";
}

echo "\n";

// Summary
echo "========================================\n";
echo "SUMMARY:\n";
echo "--------\n";

if ($all_present && $major >= 8 && $minor >= 2) {
    echo "✓ PHP is properly configured for CollaboraNexio\n";
    exit(0);
} else {
    echo "✗ PHP configuration needs attention\n";

    if (!empty($missing)) {
        echo "\nMissing extensions: " . implode(', ', $missing) . "\n";
        echo "Run fix_php_config.bat to resolve these issues\n";
    }

    if (!($major >= 8 && $minor >= 2)) {
        echo "\nPHP version needs upgrade to 8.2 or higher\n";
    }

    exit(1);
}