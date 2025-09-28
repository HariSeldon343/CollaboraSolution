<?php
/**
 * Browser-based test for files_tenant.php API endpoint
 * Tests all multi-tenant functionalities
 */

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Get database connection
$db = Database::getInstance();
$conn = $db->getConnection();

// Check if we have a test user session
$test_mode = isset($_GET['test_mode']) ? $_GET['test_mode'] : '';
$clear_session = isset($_GET['clear']) ? true : false;

if ($clear_session) {
    session_destroy();
    session_start();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Function to make API calls
function callAPI($endpoint, $params = [], $method = 'GET', $data = null) {
    $url = 'http://localhost:8888/CollaboraNexio' . $endpoint;

    if ($method === 'GET' && !empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');
    curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');

    // Pass session cookie
    if (session_id()) {
        curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'response' => $response,
        'error' => $error,
        'data' => json_decode($response, true)
    ];
}

// Set up test session based on role
if ($test_mode) {
    $test_users = [
        'super_admin' => ['id' => 1, 'role' => 'super_admin', 'tenant_id' => 1, 'name' => 'Super Admin'],
        'admin' => ['id' => 2, 'role' => 'admin', 'tenant_id' => 1, 'name' => 'Admin User'],
        'manager' => ['id' => 3, 'role' => 'manager', 'tenant_id' => 1, 'name' => 'Manager User'],
        'user' => ['id' => 4, 'role' => 'user', 'tenant_id' => 2, 'name' => 'Regular User']
    ];

    if (isset($test_users[$test_mode])) {
        $_SESSION['user_id'] = $test_users[$test_mode]['id'];
        $_SESSION['role'] = $test_users[$test_mode]['role'];
        $_SESSION['tenant_id'] = $test_users[$test_mode]['tenant_id'];
        $_SESSION['user_name'] = $test_users[$test_mode]['name'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// Get current session info
$current_user = isset($_SESSION['user_id']) ? [
    'id' => $_SESSION['user_id'],
    'role' => $_SESSION['role'] ?? 'guest',
    'tenant_id' => $_SESSION['tenant_id'] ?? null,
    'name' => $_SESSION['user_name'] ?? 'Guest'
] : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Files Tenant API Test</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
        }
        h1 {
            margin: 0;
            font-size: 28px;
        }
        .user-info {
            margin-top: 15px;
            padding: 15px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
        }
        .content {
            padding: 30px;
        }
        .test-section {
            margin-bottom: 30px;
            border: 1px solid #e1e4e8;
            border-radius: 8px;
            overflow: hidden;
        }
        .test-header {
            background: #f6f8fa;
            padding: 15px;
            font-weight: bold;
            border-bottom: 1px solid #e1e4e8;
        }
        .test-body {
            padding: 20px;
        }
        .test-controls {
            margin-bottom: 15px;
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            margin-right: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .role-selector {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .role-btn {
            padding: 8px 16px;
            background: #f0f0f0;
            border: 2px solid transparent;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .role-btn.active {
            background: #667eea;
            color: white;
            border-color: #764ba2;
        }
        .result-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            margin-top: 15px;
            max-height: 400px;
            overflow-y: auto;
        }
        .success {
            color: #28a745;
            font-weight: bold;
        }
        .error {
            color: #dc3545;
            font-weight: bold;
        }
        pre {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
            font-size: 12px;
            line-height: 1.5;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
        .status-200 { background: #28a745; color: white; }
        .status-400 { background: #ffc107; color: #212529; }
        .status-500 { background: #dc3545; color: white; }
        input[type="text"] {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            margin-right: 10px;
        }
        .folder-tree {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
        }
        .folder-item {
            padding: 8px;
            margin: 4px 0;
            background: white;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            cursor: pointer;
            transition: all 0.2s;
        }
        .folder-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        .file-item {
            padding: 8px;
            margin: 4px 0;
            background: #fff3cd;
            border-radius: 4px;
            border: 1px solid #ffc107;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🗂️ Files Tenant API Test Suite</h1>
            <?php if ($current_user): ?>
            <div class="user-info">
                <strong>Current Session:</strong><br>
                User ID: <?= htmlspecialchars($current_user['id']) ?><br>
                Role: <?= htmlspecialchars($current_user['role']) ?><br>
                Tenant ID: <?= htmlspecialchars($current_user['tenant_id']) ?><br>
                Name: <?= htmlspecialchars($current_user['name']) ?>
            </div>
            <?php else: ?>
            <div class="user-info">
                <strong>No active session</strong> - Select a test role below
            </div>
            <?php endif; ?>
        </div>

        <div class="content">
            <!-- Role Selector -->
            <div class="test-section">
                <div class="test-header">Select Test Role</div>
                <div class="test-body">
                    <div class="role-selector">
                        <a href="?test_mode=super_admin" class="role-btn <?= $test_mode === 'super_admin' ? 'active' : '' ?>">
                            Super Admin
                        </a>
                        <a href="?test_mode=admin" class="role-btn <?= $test_mode === 'admin' ? 'active' : '' ?>">
                            Admin
                        </a>
                        <a href="?test_mode=manager" class="role-btn <?= $test_mode === 'manager' ? 'active' : '' ?>">
                            Manager
                        </a>
                        <a href="?test_mode=user" class="role-btn <?= $test_mode === 'user' ? 'active' : '' ?>">
                            Regular User
                        </a>
                        <a href="?clear=1" class="role-btn">
                            Clear Session
                        </a>
                    </div>
                </div>
            </div>

            <!-- Test 1: List Files -->
            <div class="test-section">
                <div class="test-header">Test 1: List Files/Folders</div>
                <div class="test-body">
                    <div class="test-controls">
                        <input type="text" id="folder_id" placeholder="Folder ID (empty for root)">
                        <button onclick="testListFiles()">List Files</button>
                    </div>
                    <div id="listResult" class="result-box" style="display:none;"></div>
                </div>
            </div>

            <!-- Test 2: Get Tenant List -->
            <?php if (in_array($current_user['role'] ?? '', ['admin', 'super_admin'])): ?>
            <div class="test-section">
                <div class="test-header">Test 2: Get Tenant List (Admin/Super Admin Only)</div>
                <div class="test-body">
                    <button onclick="testGetTenantList()">Get Tenant List</button>
                    <div id="tenantResult" class="result-box" style="display:none;"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Test 3: Create Root Folder -->
            <?php if (in_array($current_user['role'] ?? '', ['admin', 'super_admin'])): ?>
            <div class="test-section">
                <div class="test-header">Test 3: Create Root Folder (Admin/Super Admin Only)</div>
                <div class="test-body">
                    <div class="test-controls">
                        <input type="text" id="root_folder_name" placeholder="Folder Name">
                        <input type="text" id="root_tenant_id" placeholder="Tenant ID">
                        <button onclick="testCreateRootFolder()">Create Root Folder</button>
                    </div>
                    <div id="createRootResult" class="result-box" style="display:none;"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Test 4: Create Subfolder -->
            <div class="test-section">
                <div class="test-header">Test 4: Create Subfolder</div>
                <div class="test-body">
                    <div class="test-controls">
                        <input type="text" id="subfolder_name" placeholder="Folder Name">
                        <input type="text" id="parent_folder_id" placeholder="Parent Folder ID">
                        <button onclick="testCreateSubfolder()">Create Subfolder</button>
                    </div>
                    <div id="createSubResult" class="result-box" style="display:none;"></div>
                </div>
            </div>

            <!-- Test 5: Database Status -->
            <div class="test-section">
                <div class="test-header">Test 5: Database Status</div>
                <div class="test-body">
                    <button onclick="testDatabaseStatus()">Check Database</button>
                    <div id="dbResult" class="result-box" style="display:none;"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showResult(elementId, success, data, httpCode = null) {
            const element = document.getElementById(elementId);
            element.style.display = 'block';

            let statusBadge = '';
            if (httpCode) {
                const statusClass = httpCode === 200 ? 'status-200' :
                                  httpCode >= 400 && httpCode < 500 ? 'status-400' :
                                  'status-500';
                statusBadge = `<span class="status-badge ${statusClass}">HTTP ${httpCode}</span>`;
            }

            const statusText = success ? '<span class="success">SUCCESS</span>' : '<span class="error">ERROR</span>';

            element.innerHTML = `
                <div>${statusText} ${statusBadge}</div>
                <pre>${JSON.stringify(data, null, 2)}</pre>
            `;
        }

        async function testListFiles() {
            const folderId = document.getElementById('folder_id').value;
            const params = new URLSearchParams({
                action: 'list',
                folder_id: folderId || '',
                search: ''
            });

            try {
                const response = await fetch('/CollaboraNexio/api/files_tenant.php?' + params, {
                    credentials: 'same-origin'
                });

                const data = await response.json();
                showResult('listResult', response.ok, data, response.status);

                // Display folder tree if successful
                if (data.success && data.data) {
                    displayFolderTree(data.data);
                }
            } catch (error) {
                showResult('listResult', false, {error: error.message});
            }
        }

        async function testGetTenantList() {
            const params = new URLSearchParams({
                action: 'get_tenant_list'
            });

            try {
                const response = await fetch('/CollaboraNexio/api/files_tenant.php?' + params, {
                    credentials: 'same-origin'
                });

                const data = await response.json();
                showResult('tenantResult', response.ok, data, response.status);
            } catch (error) {
                showResult('tenantResult', false, {error: error.message});
            }
        }

        async function testCreateRootFolder() {
            const name = document.getElementById('root_folder_name').value;
            const tenantId = document.getElementById('root_tenant_id').value;

            if (!name || !tenantId) {
                alert('Please enter both folder name and tenant ID');
                return;
            }

            const params = new URLSearchParams({
                action: 'create_root_folder'
            });

            try {
                const response = await fetch('/CollaboraNexio/api/files_tenant.php?' + params, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        name: name,
                        tenant_id: tenantId,
                        csrf_token: '<?= $_SESSION['csrf_token'] ?? '' ?>'
                    })
                });

                const data = await response.json();
                showResult('createRootResult', response.ok, data, response.status);

                // Refresh file list if successful
                if (data.success) {
                    testListFiles();
                }
            } catch (error) {
                showResult('createRootResult', false, {error: error.message});
            }
        }

        async function testCreateSubfolder() {
            const name = document.getElementById('subfolder_name').value;
            const parentId = document.getElementById('parent_folder_id').value;

            if (!name || !parentId) {
                alert('Please enter both folder name and parent folder ID');
                return;
            }

            const params = new URLSearchParams({
                action: 'create_folder'
            });

            try {
                const response = await fetch('/CollaboraNexio/api/files_tenant.php?' + params, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        name: name,
                        parent_id: parentId,
                        csrf_token: '<?= $_SESSION['csrf_token'] ?? '' ?>'
                    })
                });

                const data = await response.json();
                showResult('createSubResult', response.ok, data, response.status);

                // Refresh file list if successful
                if (data.success) {
                    testListFiles();
                }
            } catch (error) {
                showResult('createSubResult', false, {error: error.message});
            }
        }

        async function testDatabaseStatus() {
            try {
                const response = await fetch('/CollaboraNexio/api/files_tenant.php?action=debug', {
                    credentials: 'same-origin'
                });

                const data = await response.json();

                // Also fetch database info directly
                <?php
                try {
                    $folders_count = $conn->query("SELECT COUNT(*) FROM folders WHERE deleted_at IS NULL")->fetchColumn();
                    $files_count = $conn->query("SELECT COUNT(*) FROM files WHERE deleted_at IS NULL")->fetchColumn();
                    $tenants_count = $conn->query("SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL")->fetchColumn();

                    $db_status = [
                        'folders_count' => $folders_count,
                        'files_count' => $files_count,
                        'tenants_count' => $tenants_count,
                        'tables_exist' => [
                            'folders' => true,
                            'files' => true,
                            'tenants' => true,
                            'users' => true
                        ]
                    ];
                } catch (Exception $e) {
                    $db_status = ['error' => $e->getMessage()];
                }
                ?>

                const dbInfo = <?= json_encode($db_status) ?>;
                showResult('dbResult', true, {
                    api_response: data,
                    database_info: dbInfo
                });
            } catch (error) {
                showResult('dbResult', false, {error: error.message});
            }
        }

        function displayFolderTree(items) {
            const listResult = document.getElementById('listResult');

            if (!items || items.length === 0) {
                return;
            }

            let treeHtml = '<div class="folder-tree"><h4>📁 Folder Structure:</h4>';

            items.forEach(item => {
                if (item.is_folder) {
                    treeHtml += `
                        <div class="folder-item" onclick="document.getElementById('folder_id').value='${item.id}'; testListFiles();">
                            📁 ${item.name} (ID: ${item.id})
                            ${item.tenant_name ? `<small> - Tenant: ${item.tenant_name}</small>` : ''}
                        </div>
                    `;
                } else {
                    treeHtml += `
                        <div class="file-item">
                            📄 ${item.name} (${item.size} bytes)
                        </div>
                    `;
                }
            });

            treeHtml += '</div>';
            listResult.innerHTML += treeHtml;
        }

        // Auto-load files on page load if session exists
        <?php if ($current_user): ?>
        window.onload = function() {
            testListFiles();
        };
        <?php endif; ?>
    </script>
</body>
</html>