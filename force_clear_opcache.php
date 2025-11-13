<?php
/**
 * Force clear OPcache and verify workflow API
 * This script aggressively clears cache and tests the workflow API
 */

// Start session for testing
session_start();
$_SESSION['authenticated'] = true;
$_SESSION['user_id'] = 32;  // Pippo Baudo
$_SESSION['tenant_id'] = 11;
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

echo "<!DOCTYPE html>";
echo "<html><head><title>Force Clear OPcache & Test</title>";
echo "<style>body{font-family:monospace;background:#1e1e1e;color:#d4d4d4;padding:20px;}";
echo ".success{color:#4ec9b0;}.error{color:#f48771;}.warning{color:#dcdcaa;}";
echo "pre{background:#2d2d30;padding:10px;overflow:auto;}";
echo "button{padding:10px 20px;margin:10px;cursor:pointer;background:#0e639c;color:white;border:none;border-radius:3px;}";
echo "button:hover{background:#1177bb;}</style></head><body>";

echo "<h1>CollaboraNexio - Force Clear OPcache & Test Workflow API</h1>";
echo "<hr>";

// Step 1: OPcache Status and Clear
echo "<h2>1. OPcache Management</h2>";
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    if ($status && $status['opcache_enabled']) {
        echo "<p class='warning'>⚠️ OPcache is ENABLED</p>";

        // Force reset entire cache
        if (function_exists('opcache_reset')) {
            $result = opcache_reset();
            echo "<p class='" . ($result ? "success" : "error") . "'>";
            echo $result ? "✅ OPcache COMPLETELY RESET" : "❌ Failed to reset OPcache";
            echo "</p>";
        }

        // Also invalidate specific files
        $files_to_invalidate = [
            '/api/workflow/roles/list.php',
            '/api/workflow/roles/create.php',
            '/api/documents/workflow/status.php'
        ];

        foreach ($files_to_invalidate as $file) {
            $fullPath = __DIR__ . $file;
            if (file_exists($fullPath)) {
                opcache_invalidate($fullPath, true);
                echo "<p class='success'>✅ Invalidated: $file</p>";

                // Touch the file to force timestamp update
                touch($fullPath);
                echo "<p>Updated timestamp for: $file</p>";
            }
        }
    } else {
        echo "<p class='success'>✅ OPcache is DISABLED (no cache to clear)</p>";
    }
} else {
    echo "<p>OPcache extension not loaded</p>";
}

// Step 2: Verify File Content
echo "<h2>2. Verify File Content</h2>";
$listPhpPath = __DIR__ . '/api/workflow/roles/list.php';
$content = file_get_contents($listPhpPath);

// Check for problematic references
$hasDisplayName = strpos($content, 'display_name') !== false;
$hasUDotDisplayName = strpos($content, 'u.display_name') !== false;

echo "<p>File: /api/workflow/roles/list.php</p>";
echo "<ul>";
echo "<li>" . ($hasDisplayName ? "❌ Contains 'display_name'" : "✅ Does NOT contain 'display_name'") . "</li>";
echo "<li>" . ($hasUDotDisplayName ? "❌ Contains 'u.display_name'" : "✅ Does NOT contain 'u.display_name'") . "</li>";
echo "</ul>";

// Extract and show the SQL query
if (preg_match('/\$sql = "SELECT DISTINCT(.+?)";/s', $content, $matches)) {
    echo "<h3>SQL Query in file:</h3>";
    $query = $matches[0];
    // Highlight the name columns
    $query = str_replace('u.name', '<span class="success">u.name</span>', $query);
    $query = str_replace('u.display_name', '<span class="error">u.display_name</span>', $query);
    echo "<pre>" . htmlspecialchars(substr($query, 0, 800)) . "...</pre>";
}

// Step 3: Database Column Check
echo "<h2>3. Database Column Verification</h2>";
require_once __DIR__ . '/includes/db.php';
try {
    $db = Database::getInstance();

    // Check columns
    $columns = $db->fetchAll("SHOW COLUMNS FROM users");
    $columnNames = array_column($columns, 'Field');

    echo "<p>Users table columns:</p>";
    echo "<ul>";
    echo "<li>" . (in_array('name', $columnNames) ? "✅ Column 'name' EXISTS" : "❌ Column 'name' MISSING") . "</li>";
    echo "<li>" . (in_array('display_name', $columnNames) ? "❌ Column 'display_name' EXISTS (should not)" : "✅ Column 'display_name' does NOT exist (correct)") . "</li>";
    echo "</ul>";

    // Test direct query
    echo "<h3>Test Direct SQL Query:</h3>";
    try {
        $sql = "SELECT DISTINCT
            u.id,
            u.name,
            u.email,
            u.role AS system_role
        FROM users u
        INNER JOIN user_tenant_access uta ON u.id = uta.user_id
            AND uta.tenant_id = ?
            AND uta.deleted_at IS NULL
        WHERE u.deleted_at IS NULL
          AND u.status = 'active'
        ORDER BY u.name ASC
        LIMIT 3";

        $users = $db->fetchAll($sql, [11]);
        echo "<p class='success'>✅ Query with 'u.name' executed successfully!</p>";
        echo "<p>Found " . count($users) . " users:</p>";
        echo "<ul>";
        foreach ($users as $user) {
            echo "<li>" . htmlspecialchars($user['name']) . " (" . htmlspecialchars($user['email']) . ")</li>";
        }
        echo "</ul>";
    } catch (Exception $e) {
        echo "<p class='error'>❌ Query failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Step 4: Live API Test
echo "<h2>4. Live API Test</h2>";
echo "<button onclick='testAPI()'>Test API Now</button>";
echo "<button onclick='clearAllAndTest()'>Clear Everything & Test</button>";
echo "<div id='api-result'></div>";

?>

<script>
function testAPI() {
    const resultDiv = document.getElementById('api-result');
    resultDiv.innerHTML = '<p>Testing API...</p>';

    fetch('/CollaboraNexio/api/workflow/roles/list.php?tenant_id=11', {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'X-CSRF-Token': '<?php echo $_SESSION['csrf_token']; ?>'
        }
    })
    .then(response => {
        const contentType = response.headers.get('content-type');
        return response.text().then(text => ({
            status: response.status,
            contentType: contentType,
            text: text
        }));
    })
    .then(result => {
        let html = '<h3>API Response:</h3>';
        html += '<p>Status: ' + result.status + '</p>';
        html += '<p>Content-Type: ' + result.contentType + '</p>';

        if (result.contentType && result.contentType.includes('application/json')) {
            html += '<p class="success">✅ Response is JSON</p>';
            try {
                const data = JSON.parse(result.text);
                html += '<pre>' + JSON.stringify(data, null, 2) + '</pre>';

                if (data.data && data.data.available_users) {
                    html += '<p class="success">✅ Found ' + data.data.available_users.length + ' users</p>';
                }
            } catch (e) {
                html += '<p class="error">Failed to parse JSON: ' + e.message + '</p>';
            }
        } else {
            html += '<p class="error">❌ Response is NOT JSON (likely HTML error)</p>';

            // Check for specific errors
            if (result.text.includes('display_name')) {
                html += '<p class="error">❌ Error mentions "display_name"</p>';

                // Extract error message
                const match = result.text.match(/Unknown column[^<]*/);
                if (match) {
                    html += '<p class="error">SQL Error: ' + match[0] + '</p>';
                }
            }

            // Show first part of response
            html += '<h4>Response (first 500 chars):</h4>';
            html += '<pre>' + result.text.substring(0, 500).replace(/</g, '&lt;') + '...</pre>';
        }

        resultDiv.innerHTML = html;
    })
    .catch(error => {
        resultDiv.innerHTML = '<p class="error">Network error: ' + error.message + '</p>';
    });
}

function clearAllAndTest() {
    // Force page reload first to clear any JavaScript cache
    if (confirm('This will reload the page and clear all caches. Continue?')) {
        // Add timestamp to force bypass cache
        window.location.href = window.location.pathname + '?nocache=' + Date.now();
    }
}

// Auto-test after 2 seconds
window.onload = function() {
    setTimeout(testAPI, 2000);
};
</script>

<h2>5. Manual Actions Required</h2>
<ol>
    <li><strong>Clear Browser Cache:</strong> Press CTRL+SHIFT+DELETE → Select "All time" → Clear</li>
    <li><strong>Restart XAMPP/Apache:</strong> Stop and start Apache from XAMPP Control Panel</li>
    <li><strong>Test in Incognito:</strong> Open an incognito window (CTRL+SHIFT+N) to bypass all cache</li>
</ol>

<h2>6. If Still Not Working</h2>
<p>If the error persists after all the above steps:</p>
<ol>
    <li>Check if there's a PHP opcode cache other than OPcache (like APC, XCache)</li>
    <li>Look for any reverse proxy or CDN caching</li>
    <li>Verify the correct file is being loaded (no symlinks or aliases)</li>
    <li>Check Apache/PHP error logs for additional clues</li>
</ol>

</body></html>