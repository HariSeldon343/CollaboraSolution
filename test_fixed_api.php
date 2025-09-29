<?php
// Test the Fixed Files API
session_start();

// Set test session variables if not already set
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['tenant_id'] = 1;
    $_SESSION['role'] = 'admin';
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Test Fixed Files API</title>
    <style>
        body {
            font-family: Arial, sans-serif;
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
        h1 {
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        .test-section {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .test-result {
            margin-top: 10px;
            padding: 10px;
            border-radius: 4px;
        }
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 10px;
        }
        button:hover {
            background: #0056b3;
        }
        pre {
            background: #f5f5f5;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
            white-space: pre-wrap;
        }
        .api-response {
            max-height: 400px;
            overflow-y: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background: #007bff;
            color: white;
        }
        .folder-icon { color: #ffc107; }
        .file-icon { color: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Test Fixed Files API</h1>

        <div class="test-section">
            <h2>Session Info</h2>
            <div class="info test-result">
                <strong>User ID:</strong> <?php echo $_SESSION['user_id']; ?><br>
                <strong>Tenant ID:</strong> <?php echo $_SESSION['tenant_id']; ?><br>
                <strong>Role:</strong> <?php echo $_SESSION['role']; ?><br>
                <strong>Session ID:</strong> <?php echo session_id(); ?>
            </div>
        </div>

        <div class="test-section">
            <h2>API Tests</h2>

            <div>
                <button onclick="testListFiles()">Test List Files (Root)</button>
                <button onclick="testListFilesWithFolder()">Test List Files (With Folder)</button>
                <button onclick="testGetTenantList()">Test Get Tenant List</button>
                <button onclick="testDebugEndpoint()">Test Debug Endpoint</button>
            </div>

            <div id="testResult" class="test-result" style="display:none; margin-top:20px;">
                <h3>API Response:</h3>
                <div class="api-response">
                    <pre id="responseContent"></pre>
                </div>
            </div>

            <div id="fileList" style="display:none; margin-top:20px;">
                <h3>Files and Folders:</h3>
                <table id="fileTable">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Name</th>
                            <th>Tenant</th>
                            <th>Size</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody id="fileTableBody"></tbody>
                </table>
            </div>
        </div>

        <div class="test-section">
            <h2>Direct API Calls</h2>
            <p>Click the links below to test the API endpoints directly:</p>
            <ul>
                <li><a href="/CollaboraNexio/api/files_tenant_fixed.php?action=list&folder_id=&search=" target="_blank">List Files (Root) - Fixed API</a></li>
                <li><a href="/CollaboraNexio/api/files_tenant_debug.php?action=list&folder_id=&search=" target="_blank">List Files (Root) - Debug API</a></li>
                <li><a href="/CollaboraNexio/api/files_tenant.php?action=list&folder_id=&search=" target="_blank">List Files (Root) - Original API</a></li>
            </ul>
        </div>
    </div>

    <script>
        const API_BASE = '/CollaboraNexio/api/files_tenant_fixed.php';

        function showResult(success, data, raw = false) {
            const resultDiv = document.getElementById('testResult');
            const responseDiv = document.getElementById('responseContent');

            resultDiv.style.display = 'block';
            resultDiv.className = success ? 'success test-result' : 'error test-result';

            if (raw) {
                responseDiv.textContent = data;
            } else {
                responseDiv.textContent = JSON.stringify(data, null, 2);
            }

            // If successful list response, display in table
            if (success && data.data && data.data.items) {
                displayFileList(data.data.items);
            }
        }

        function displayFileList(items) {
            const fileListDiv = document.getElementById('fileList');
            const tbody = document.getElementById('fileTableBody');

            fileListDiv.style.display = 'block';
            tbody.innerHTML = '';

            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5">Nessun file o cartella trovato</td></tr>';
                return;
            }

            items.forEach(item => {
                const row = tbody.insertRow();
                row.innerHTML = `
                    <td>${item.type === 'folder' ?
                        '<span class="folder-icon">📁</span> Cartella' :
                        '<span class="file-icon">📄</span> File'}</td>
                    <td>${item.name}</td>
                    <td>${item.tenant_name || 'N/A'}</td>
                    <td>${item.size ? formatBytes(item.size) : '-'}</td>
                    <td>${formatDate(item.created_at)}</td>
                `;
            });
        }

        function formatBytes(bytes) {
            if (!bytes) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function formatDate(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            return date.toLocaleString('it-IT');
        }

        async function testListFiles() {
            try {
                const response = await fetch(`${API_BASE}?action=list&folder_id=&search=`);
                const data = await response.json();
                showResult(response.ok && data.success, data);
            } catch (error) {
                showResult(false, error.message, true);
            }
        }

        async function testListFilesWithFolder() {
            const folderId = prompt('Enter folder ID (or leave empty for root):', '');
            try {
                const response = await fetch(`${API_BASE}?action=list&folder_id=${folderId}&search=`);
                const data = await response.json();
                showResult(response.ok && data.success, data);
            } catch (error) {
                showResult(false, error.message, true);
            }
        }

        async function testGetTenantList() {
            try {
                const response = await fetch(`${API_BASE}?action=get_tenant_list`);
                const data = await response.json();
                showResult(response.ok && data.success, data);
            } catch (error) {
                showResult(false, error.message, true);
            }
        }

        async function testDebugEndpoint() {
            try {
                const response = await fetch('/CollaboraNexio/api/files_tenant_debug.php?action=list&folder_id=&search=');
                const data = await response.json();
                showResult(response.ok, data);
            } catch (error) {
                showResult(false, error.message, true);
            }
        }

        // Auto-load on page load
        window.addEventListener('load', () => {
            testListFiles();
        });
    </script>
</body>
</html>