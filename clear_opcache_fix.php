<?php
/**
 * Clear OPcache and fix workflow roles list API
 *
 * This script addresses the issue where the API returns "Unknown column 'u.display_name'"
 * even though the file uses the correct 'u.name' column.
 */

echo "CollaboraNexio - OPcache Clear & Fix Script\n";
echo "===========================================\n\n";

// Check if OPcache is enabled
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);

    if ($status && isset($status['opcache_enabled']) && $status['opcache_enabled']) {
        echo "1. OPcache Status:\n";
        echo "   ✓ OPcache is ENABLED\n";
        echo "   Memory Used: " . round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) . " MB\n";
        echo "   Files Cached: " . $status['opcache_statistics']['num_cached_scripts'] . "\n";
        echo "   Hits: " . $status['opcache_statistics']['hits'] . "\n";
        echo "   Misses: " . $status['opcache_statistics']['misses'] . "\n\n";

        // Clear specific file
        $targetFile = __DIR__ . '/api/workflow/roles/list.php';

        echo "2. Invalidating cached version of list.php:\n";
        if (file_exists($targetFile)) {
            // Method 1: Invalidate specific file
            if (function_exists('opcache_invalidate')) {
                $result = opcache_invalidate($targetFile, true);
                echo "   " . ($result ? "✓" : "✗") . " opcache_invalidate() on list.php\n";
            }

            // Method 2: Touch the file to update timestamp
            touch($targetFile);
            echo "   ✓ Updated file timestamp\n";

            // Method 3: Clear entire cache if needed
            if (isset($_GET['full']) && $_GET['full'] == '1') {
                if (function_exists('opcache_reset')) {
                    $result = opcache_reset();
                    echo "   " . ($result ? "✓" : "✗") . " Full OPcache reset\n";
                }
            } else {
                echo "   ℹ️ Add ?full=1 to URL to clear entire OPcache\n";
            }
        } else {
            echo "   ✗ File not found: $targetFile\n";
        }
    } else {
        echo "ℹ️ OPcache is DISABLED - no cache to clear\n";
    }
} else {
    echo "ℹ️ OPcache extension is not loaded\n";
}

echo "\n3. Verifying file content:\n";

$filePath = __DIR__ . '/api/workflow/roles/list.php';
if (file_exists($filePath)) {
    $content = file_get_contents($filePath);

    // Check for incorrect display_name
    if (strpos($content, 'u.display_name') !== false) {
        echo "   ✗ File STILL contains 'u.display_name' - Manual fix needed!\n";
        echo "   Attempting automatic fix...\n";

        // Create backup
        $backupPath = $filePath . '.backup_' . date('YmdHis');
        copy($filePath, $backupPath);
        echo "   ✓ Backup created: " . basename($backupPath) . "\n";

        // Fix the file
        $fixed = str_replace('u.display_name', 'u.name', $content);
        $fixed = str_replace('display_name AS name', 'name', $fixed);

        file_put_contents($filePath, $fixed);
        echo "   ✓ File fixed - replaced display_name with name\n";

        // Clear cache again
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($filePath, true);
            echo "   ✓ OPcache invalidated after fix\n";
        }
    } else {
        echo "   ✓ File correctly uses 'u.name' (no 'display_name' found)\n";

        // Count occurrences of u.name
        $count = substr_count($content, 'u.name');
        echo "   ✓ Found $count occurrences of 'u.name'\n";
    }

    // Check GROUP BY and ORDER BY clauses
    if (preg_match('/GROUP BY ([^;]+)/i', $content, $matches)) {
        $groupBy = trim($matches[1]);
        if (strpos($groupBy, 'display_name') !== false) {
            echo "   ✗ GROUP BY contains 'display_name'\n";
        } else {
            echo "   ✓ GROUP BY uses correct columns\n";
        }
    }

    if (preg_match('/ORDER BY ([^"]+)"/i', $content, $matches)) {
        $orderBy = trim($matches[1]);
        if (strpos($orderBy, 'display_name') !== false) {
            echo "   ✗ ORDER BY contains 'display_name'\n";
        } else {
            echo "   ✓ ORDER BY uses correct column: $orderBy\n";
        }
    }
} else {
    echo "   ✗ File not found: $filePath\n";
}

echo "\n4. Testing database column:\n";

require_once __DIR__ . '/includes/db.php';

try {
    $db = Database::getInstance();

    // Get column info
    $columns = $db->fetchAll("SHOW COLUMNS FROM users");
    $columnNames = array_column($columns, 'Field');

    if (in_array('name', $columnNames)) {
        echo "   ✓ Column 'name' EXISTS in users table\n";
    } else {
        echo "   ✗ Column 'name' NOT FOUND in users table\n";
    }

    if (in_array('display_name', $columnNames)) {
        echo "   ✗ Column 'display_name' EXISTS (should not exist)\n";
    } else {
        echo "   ✓ Column 'display_name' does NOT exist (correct)\n";
    }

    // Test query
    try {
        $testSql = "SELECT id, name FROM users WHERE deleted_at IS NULL LIMIT 1";
        $result = $db->fetchOne($testSql);
        if ($result) {
            echo "   ✓ Test query with 'name' successful\n";
        }
    } catch (Exception $e) {
        echo "   ✗ Test query failed: " . $e->getMessage() . "\n";
    }

} catch (Exception $e) {
    echo "   ✗ Database error: " . $e->getMessage() . "\n";
}

echo "\n===========================================\n";
echo "Fix completed. Please test the API again.\n";
echo "\nIf the error persists:\n";
echo "1. Restart Apache/PHP-FPM\n";
echo "2. Clear browser cache (CTRL+SHIFT+DELETE)\n";
echo "3. Try accessing the API in an incognito window\n";
?>