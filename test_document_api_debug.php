<?php
/**
 * Debug script to test document API and database
 */

declare(strict_types=1);

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/db.php';

echo "=== DOCUMENT API DEBUG ===\n\n";

try {
    $db = Database::getInstance();

    // Check if tables exist
    echo "1. Checking database tables:\n";

    $tables = ['document_editor_sessions', 'file_versions', 'files'];
    foreach ($tables as $table) {
        try {
            $columns = $db->query("DESCRIBE $table");
            echo "   - $table: EXISTS\n";
            if (is_array($columns) && count($columns) > 0) {
                echo "     Columns: " . implode(', ', array_column($columns, 'Field')) . "\n";
            }
        } catch (Exception $e) {
            echo "   - $table: MISSING or ERROR\n";
        }
    }

    echo "\n2. Checking file ID 43:\n";
    $file = $db->fetchOne("SELECT id, name, file_path, tenant_id, uploaded_by FROM files WHERE id = 43");

    if ($file) {
        echo "   - File found:\n";
        echo "     ID: " . $file['id'] . "\n";
        echo "     Name: " . $file['name'] . "\n";
        echo "     Path: " . $file['file_path'] . "\n";
        echo "     Tenant: " . $file['tenant_id'] . "\n";
        echo "     Uploaded by: " . $file['uploaded_by'] . "\n";
    } else {
        echo "   - File NOT FOUND\n";
    }

    echo "\n3. Checking config defines:\n";
    $defines = [
        'BASE_URL',
        'UPLOAD_PATH',
        'DEBUG_MODE',
        'ONLYOFFICE_SERVER_URL',
        'ONLYOFFICE_JWT_ENABLED'
    ];

    foreach ($defines as $define) {
        echo "   - $define: " . (defined($define) ? constant($define) : "NOT DEFINED") . "\n";
    }

    echo "\n4. Testing includes:\n";
    $includes = [
        __DIR__ . '/includes/api_auth.php',
        __DIR__ . '/includes/document_editor_helper.php',
        __DIR__ . '/includes/onlyoffice_config.php'
    ];

    foreach ($includes as $file) {
        echo "   - " . basename($file) . ": " . (file_exists($file) ? "EXISTS" : "MISSING") . "\n";
    }

    echo "\n5. Testing session configuration:\n";
    if (!headers_sent()) {
        require_once __DIR__ . '/includes/session_init.php';
        echo "   - Session initialized successfully\n";
        echo "   - Session ID: " . session_id() . "\n";
    } else {
        echo "   - Headers already sent, cannot test session\n";
    }

    echo "\n=== DEBUG COMPLETE ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
