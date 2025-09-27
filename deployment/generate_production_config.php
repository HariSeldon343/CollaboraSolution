<?php
/**
 * Generate Production Configuration
 * Helper script for deploy.bat
 */

declare(strict_types=1);

// Base configuration path
$configTemplate = __DIR__ . '/../config.php';
$productionConfig = __DIR__ . '/../config.production.php';

// Check if template exists
if (!file_exists($configTemplate)) {
    echo "Error: config.php template not found\n";
    exit(1);
}

// Read existing config
$currentConfig = file_get_contents($configTemplate);

// Extract database credentials from current config
preg_match("/define\('DB_HOST',\s*'([^']+)'\)/", $currentConfig, $dbHost);
preg_match("/define\('DB_NAME',\s*'([^']+)'\)/", $currentConfig, $dbName);
preg_match("/define\('DB_USER',\s*'([^']+)'\)/", $currentConfig, $dbUser);
preg_match("/define\('DB_PASS',\s*'([^']+)'\)/", $currentConfig, $dbPass);

// Use extracted values or defaults
$host = $dbHost[1] ?? 'localhost';
$database = $dbName[1] ?? 'collaboranexio';
$username = $dbUser[1] ?? 'root';
$password = $dbPass[1] ?? '';

// Copy production template to actual location
if (file_exists(__DIR__ . '/../config.production.php')) {
    // Update database credentials in production config
    $prodConfig = file_get_contents(__DIR__ . '/../config.production.php');

    $prodConfig = preg_replace(
        "/define\('DB_HOST',\s*'[^']+'\)/",
        "define('DB_HOST', '$host')",
        $prodConfig
    );

    $prodConfig = preg_replace(
        "/define\('DB_NAME',\s*'[^']+'\)/",
        "define('DB_NAME', '$database')",
        $prodConfig
    );

    $prodConfig = preg_replace(
        "/define\('DB_USER',\s*'[^']+'\)/",
        "define('DB_USER', '$username')",
        $prodConfig
    );

    $prodConfig = preg_replace(
        "/define\('DB_PASS',\s*'[^']+'\)/",
        "define('DB_PASS', '$password')",
        $prodConfig
    );

    // Backup current config
    if (file_exists($configTemplate)) {
        copy($configTemplate, $configTemplate . '.backup');
    }

    // Write production config
    file_put_contents($configTemplate, $prodConfig);

    echo "Production configuration generated successfully\n";
    exit(0);
} else {
    echo "Error: Production configuration template not found\n";
    exit(1);
}