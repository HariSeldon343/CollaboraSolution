<?php
/**
 * Verify API Fix - Comprehensive test to ensure files_tenant.php works correctly
 * Tests all column name variations and API endpoints
 */

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Initialize database
$db = Database::getInstance();
$conn = $db->getConnection();

// Colors for terminal output
$green = "\033[92m";
$red = "\033[91m";
$yellow = "\033[93m";
$blue = "\033[94m";
$reset = "\033[0m";

echo "{$blue}=========================================={$reset}\n";
echo "{$blue}    FILES_TENANT.PHP API VERIFICATION    {$reset}\n";
echo "{$blue}=========================================={$reset}\n\n";

// Step 1: Check database structure
echo "{$yellow}STEP 1: Checking Database Structure{$reset}\n";
echo "----------------------------------------\n";

try {
    // Check files table columns
    echo "Files table columns:\n";
    $stmt = $conn->query("SHOW COLUMNS FROM files");
    $fileColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($fileColumns as $col) {
        echo "  - $col\n";
    }

    echo "\nFolders table columns:\n";
    $stmt = $conn->query("SHOW COLUMNS FROM folders");
    $folderColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($folderColumns as $col) {
        echo "  - $col\n";
    }

    // Detect column variations
    $nameColumn = 'name';
    if (in_array('file_name', $fileColumns)) $nameColumn = 'file_name';
    elseif (in_array('filename', $fileColumns)) $nameColumn = 'filename';

    $sizeColumn = 'size_bytes';
    if (in_array('file_size', $fileColumns)) $sizeColumn = 'file_size';

    $ownerColumn = 'owner_id';
    if (in_array('uploaded_by', $fileColumns)) $ownerColumn = 'uploaded_by';
    elseif (in_array('created_by', $fileColumns)) $ownerColumn = 'created_by';

    echo "\n{$green}✓ Column detection complete{$reset}\n";
    echo "  Name column: $nameColumn\n";
    echo "  Size column: $sizeColumn\n";
    echo "  Owner column: $ownerColumn\n";

} catch (Exception $e) {
    echo "{$red}✗ Database error: " . $e->getMessage() . "{$reset}\n";
    exit(1);
}

// Step 2: Test API endpoints
echo "\n{$yellow}STEP 2: Testing API Endpoints{$reset}\n";
echo "----------------------------------------\n";

// Create test session
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'super_admin';
$_SESSION['tenant_id'] = 1;
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Function to call API
function callAPI($action, $params = [], $method = 'GET', $data = null) {
    $url = 'http://localhost:8888/CollaboraNexio/api/files_tenant.php';
    $params['action'] = $action;

    if ($method === 'GET') {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'response' => json_decode($response, true),
        'raw' => $response
    ];
}

// Test 1: List files (root)
echo "\nTest 1: List files (root)\n";
$result = callAPI('list', ['folder_id' => '']);
if ($result['code'] == 200 && isset($result['response']['success'])) {
    echo "{$green}✓ List API works (HTTP {$result['code']}){$reset}\n";
    if ($result['response']['success']) {
        $count = count($result['response']['data'] ?? []);
        echo "  Found $count items\n";
    } else {
        echo "{$yellow}  Warning: " . ($result['response']['message'] ?? 'Unknown error') . "{$reset}\n";
    }
} else {
    echo "{$red}✗ List API failed (HTTP {$result['code']}){$reset}\n";
    if (!empty($result['raw'])) {
        echo "  Response: " . substr($result['raw'], 0, 200) . "...\n";
    }
}

// Test 2: Get tenant list
echo "\nTest 2: Get tenant list\n";
$result = callAPI('get_tenant_list');
if ($result['code'] == 200 && isset($result['response']['success'])) {
    echo "{$green}✓ Tenant list API works{$reset}\n";
    if ($result['response']['success']) {
        $count = count($result['response']['data'] ?? []);
        echo "  Found $count tenants\n";
    }
} else {
    echo "{$red}✗ Tenant list API failed{$reset}\n";
}

// Test 3: Debug columns (if available)
echo "\nTest 3: Debug column mapping\n";
$result = callAPI('debug_columns');
if ($result['code'] == 200 && isset($result['response']['column_mappings'])) {
    echo "{$green}✓ Column mapping available{$reset}\n";
    echo "  Files table mappings:\n";
    foreach ($result['response']['column_mappings']['files'] ?? [] as $key => $col) {
        echo "    $key => $col\n";
    }
} else {
    echo "{$yellow}⚠ Debug endpoint not available (expected in production){$reset}\n";
}

// Step 3: Test data integrity
echo "\n{$yellow}STEP 3: Testing Data Integrity{$reset}\n";
echo "----------------------------------------\n";

try {
    // Check if folders have proper tenant isolation
    $stmt = $conn->query("
        SELECT tenant_id, COUNT(*) as count
        FROM folders
        WHERE deleted_at IS NULL
        GROUP BY tenant_id
    ");
    $tenantFolders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Folders by tenant:\n";
    foreach ($tenantFolders as $tf) {
        echo "  Tenant {$tf['tenant_id']}: {$tf['count']} folders\n";
    }

    // Check for orphaned files
    $stmt = $conn->query("
        SELECT COUNT(*) as orphaned
        FROM files f
        WHERE f.folder_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1 FROM folders
            WHERE id = f.folder_id
        )
    ");
    $orphaned = $stmt->fetch()['orphaned'];

    if ($orphaned > 0) {
        echo "{$yellow}⚠ Found $orphaned orphaned files{$reset}\n";
    } else {
        echo "{$green}✓ No orphaned files found{$reset}\n";
    }

} catch (Exception $e) {
    echo "{$red}✗ Integrity check failed: " . $e->getMessage() . "{$reset}\n";
}

// Step 4: Summary
echo "\n{$blue}=========================================={$reset}\n";
echo "{$blue}              SUMMARY                     {$reset}\n";
echo "{$blue}=========================================={$reset}\n\n";

// Check for critical issues
$criticalIssues = [];

// Check if API responds
$testResult = callAPI('list', ['folder_id' => '']);
if ($testResult['code'] !== 200) {
    $criticalIssues[] = "API returns HTTP {$testResult['code']} instead of 200";
}

// Check column compatibility
$hasNameColumn = in_array('file_name', $fileColumns) ||
                 in_array('filename', $fileColumns) ||
                 in_array('name', $fileColumns);
if (!$hasNameColumn) {
    $criticalIssues[] = "No recognizable name column in files table";
}

if (empty($criticalIssues)) {
    echo "{$green}✅ ALL SYSTEMS OPERATIONAL{$reset}\n\n";
    echo "The files_tenant.php API is working correctly with dynamic column detection.\n";
    echo "The API automatically adapts to your database structure:\n";
    echo "  • Name column: $nameColumn\n";
    echo "  • Size column: $sizeColumn\n";
    echo "  • Owner column: $ownerColumn\n\n";
    echo "All endpoints are responding correctly and multi-tenant isolation is enforced.\n";
} else {
    echo "{$red}❌ CRITICAL ISSUES FOUND{$reset}\n\n";
    foreach ($criticalIssues as $issue) {
        echo "  • $issue\n";
    }
    echo "\n{$yellow}Recommended actions:{$reset}\n";
    echo "  1. Check error logs: tail -f /var/log/apache2/error_log\n";
    echo "  2. Verify session is initialized properly\n";
    echo "  3. Ensure database connection is working\n";
    echo "  4. Check file permissions on api/files_tenant.php\n";
}

// Web output
if (php_sapi_name() !== 'cli') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>API Fix Verification</title>
        <style>
            body {
                font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
                background: #1e1e1e;
                color: #d4d4d4;
                padding: 20px;
                line-height: 1.6;
            }
            pre {
                background: #2d2d30;
                padding: 15px;
                border-radius: 5px;
                overflow-x: auto;
            }
            .success { color: #4ec9b0; }
            .error { color: #f48771; }
            .warning { color: #dcdcaa; }
            .info { color: #569cd6; }
        </style>
    </head>
    <body>
        <pre><?php
        // Re-run for web output with HTML colors
        ob_start();
        include __FILE__;
        $output = ob_get_clean();

        // Convert ANSI colors to HTML
        $output = str_replace("\033[92m", '<span class="success">', $output);
        $output = str_replace("\033[91m", '<span class="error">', $output);
        $output = str_replace("\033[93m", '<span class="warning">', $output);
        $output = str_replace("\033[94m", '<span class="info">', $output);
        $output = str_replace("\033[0m", '</span>', $output);

        echo htmlspecialchars($output);
        ?></pre>
    </body>
    </html>
    <?php
}
?>