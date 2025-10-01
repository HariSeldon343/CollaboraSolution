<?php
/**
 * API Diagnostic Script
 * Tests the fixed API endpoints
 */

session_start();

// Include necessary files
require_once 'config.php';
require_once 'includes/db.php';
require_once 'includes/auth_simple.php';

$auth = new Auth();

// Check authentication
if (!$auth->checkAuth()) {
    die("Please login first to run diagnostics.");
}

$currentUser = $auth->getCurrentUser();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>API Diagnostics - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 { color: #333; }
        h2 { color: #666; margin-top: 30px; }
        .test-result {
            margin: 10px 0;
            padding: 10px;
            border-left: 4px solid #ddd;
            background: #f9f9f9;
        }
        .test-result.success {
            border-color: #4caf50;
            background: #f1f8f4;
        }
        .test-result.error {
            border-color: #f44336;
            background: #fef1f0;
        }
        .test-result.warning {
            border-color: #ff9800;
            background: #fff8f1;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
        }
        .button {
            background: #2196F3;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin: 5px;
        }
        .button:hover {
            background: #1976D2;
        }
        .user-info {
            background: #e3f2fd;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 API Diagnostics</h1>

        <div class="user-info">
            <strong>Logged in as:</strong> <?php echo htmlspecialchars($currentUser['email']); ?>
            (Role: <?php echo htmlspecialchars($currentUser['role']); ?>,
            Tenant ID: <?php echo htmlspecialchars($currentUser['tenant_id']); ?>)
        </div>

        <h2>1. Files Tenant API Test</h2>
        <div id="files-api-test"></div>
        <button class="button" onclick="testFilesApi()">Test Files Tenant API</button>

        <h2>2. Companies Delete API Test</h2>
        <div id="companies-api-test"></div>
        <button class="button" onclick="testCompaniesApi()">Test Companies Delete API (Dry Run)</button>

        <h2>3. Session Information</h2>
        <div class="test-result">
            <pre><?php
                $sessionInfo = [
                    'user_id' => $_SESSION['user_id'] ?? 'NOT SET',
                    'tenant_id' => $_SESSION['tenant_id'] ?? 'NOT SET',
                    'role' => $_SESSION['role'] ?? 'NOT SET',
                    'user_role' => $_SESSION['user_role'] ?? 'NOT SET',
                    'csrf_token' => substr($_SESSION['csrf_token'] ?? 'NOT SET', 0, 20) . '...',
                    'session_id' => session_id()
                ];
                echo json_encode($sessionInfo, JSON_PRETTY_PRINT);
            ?></pre>
        </div>

        <h2>4. API Files Check</h2>
        <?php
        $apiFiles = [
            'api/files_tenant_fixed.php' => 'Files Tenant API',
            'api/companies/delete.php' => 'Companies Delete API',
            'includes/api_auth.php' => 'Centralized Auth Helper',
            'includes/session_init.php' => 'Session Initialization'
        ];

        foreach ($apiFiles as $file => $name) {
            $exists = file_exists($file);
            $class = $exists ? 'success' : 'error';
            $status = $exists ? '✓ Found' : '✗ Missing';
            echo "<div class='test-result $class'>";
            echo "<strong>$name:</strong> $file - $status";
            if ($exists) {
                $size = filesize($file);
                $modified = date('Y-m-d H:i:s', filemtime($file));
                echo " (Size: " . number_format($size) . " bytes, Modified: $modified)";
            }
            echo "</div>";
        }
        ?>

        <h2>5. Database Tables Check</h2>
        <?php
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();

            $tables = ['tenants', 'users', 'folders', 'files', 'audit_logs'];
            foreach ($tables as $table) {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM $table");
                $stmt->execute();
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                echo "<div class='test-result success'>";
                echo "<strong>$table:</strong> ✓ Exists ($count records)";
                echo "</div>";
            }
        } catch (Exception $e) {
            echo "<div class='test-result error'>";
            echo "Database error: " . htmlspecialchars($e->getMessage());
            echo "</div>";
        }
        ?>
    </div>

    <script>
    // Get CSRF token from PHP
    const csrfToken = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';

    async function testFilesApi() {
        const resultDiv = document.getElementById('files-api-test');
        resultDiv.innerHTML = '<div class="test-result">Testing...</div>';

        try {
            // Test get_tenant_list action
            const response = await fetch('api/files_tenant_fixed.php?action=get_tenant_list', {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': csrfToken
                }
            });

            const contentType = response.headers.get('content-type');
            const text = await response.text();

            let result;
            try {
                result = JSON.parse(text);
            } catch (e) {
                result = { error: 'Invalid JSON response', raw: text };
            }

            if (response.ok) {
                if (result.success) {
                    resultDiv.innerHTML = `
                        <div class="test-result success">
                            <strong>✓ Success!</strong> Tenant list retrieved successfully.
                            <pre>${JSON.stringify(result, null, 2)}</pre>
                        </div>
                    `;
                } else {
                    resultDiv.innerHTML = `
                        <div class="test-result warning">
                            <strong>⚠ API returned success=false</strong>
                            <pre>${JSON.stringify(result, null, 2)}</pre>
                        </div>
                    `;
                }
            } else {
                resultDiv.innerHTML = `
                    <div class="test-result error">
                        <strong>✗ HTTP ${response.status} ${response.statusText}</strong>
                        <pre>${JSON.stringify(result, null, 2)}</pre>
                    </div>
                `;
            }
        } catch (error) {
            resultDiv.innerHTML = `
                <div class="test-result error">
                    <strong>✗ Request failed:</strong> ${error.message}
                </div>
            `;
        }
    }

    async function testCompaniesApi() {
        const resultDiv = document.getElementById('companies-api-test');

        const userRole = '<?php echo $currentUser['role']; ?>';

        if (userRole !== 'super_admin') {
            resultDiv.innerHTML = `
                <div class="test-result warning">
                    <strong>⚠ Test skipped:</strong> Only Super Admin can test this endpoint.
                    Current role: ${userRole}
                </div>
            `;
            return;
        }

        resultDiv.innerHTML = '<div class="test-result">Testing authorization check...</div>';

        try {
            // Test with a non-existent ID to check auth without actually deleting
            const formData = new FormData();
            formData.append('id', '99999'); // Non-existent ID
            formData.append('csrf_token', csrfToken);

            const response = await fetch('api/companies/delete.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            });

            const text = await response.text();
            let result;
            try {
                result = JSON.parse(text);
            } catch (e) {
                result = { error: 'Invalid JSON response', raw: text };
            }

            // We expect a 404 (company not found) which means auth passed
            if (response.status === 404) {
                resultDiv.innerHTML = `
                    <div class="test-result success">
                        <strong>✓ Auth passed!</strong> API correctly authenticated Super Admin.
                        <br>Got expected 404 for non-existent company ID.
                        <pre>${JSON.stringify(result, null, 2)}</pre>
                    </div>
                `;
            } else if (response.status === 403) {
                resultDiv.innerHTML = `
                    <div class="test-result error">
                        <strong>✗ Auth failed (403 Forbidden)</strong>
                        <pre>${JSON.stringify(result, null, 2)}</pre>
                    </div>
                `;
            } else if (response.status === 401) {
                resultDiv.innerHTML = `
                    <div class="test-result error">
                        <strong>✗ Not authenticated (401)</strong>
                        <pre>${JSON.stringify(result, null, 2)}</pre>
                    </div>
                `;
            } else {
                resultDiv.innerHTML = `
                    <div class="test-result warning">
                        <strong>HTTP ${response.status}</strong>
                        <pre>${JSON.stringify(result, null, 2)}</pre>
                    </div>
                `;
            }
        } catch (error) {
            resultDiv.innerHTML = `
                <div class="test-result error">
                    <strong>✗ Request failed:</strong> ${error.message}
                </div>
            `;
        }
    }
    </script>
</body>
</html>