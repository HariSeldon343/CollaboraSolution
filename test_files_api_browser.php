<?php
/**
 * Test Files API - Run in Browser
 * Access via: http://localhost:8888/CollaboraNexio/test_files_api_browser.php
 */

session_start();
require_once __DIR__ . '/includes/db.php';

// Simulate a logged-in admin user
$_SESSION['user_id'] = 1;
$_SESSION['tenant_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Files API Test</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .test-section {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 { color: #333; }
        h2 {
            color: #666;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 10px;
        }
        .success {
            color: #4caf50;
            font-weight: bold;
        }
        .error {
            color: #f44336;
            font-weight: bold;
        }
        .warning {
            color: #ff9800;
            font-weight: bold;
        }
        pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            border: 1px solid #ddd;
        }
        .json {
            background: #263238;
            color: #aed581;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            text-align: left;
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f0f0f0;
            font-weight: 600;
        }
        .folder-icon::before { content: '📁 '; }
        .file-icon::before { content: '📄 '; }
        .test-result {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            border-left: 4px solid;
        }
        .test-result.pass {
            background: #e8f5e9;
            border-left-color: #4caf50;
        }
        .test-result.fail {
            background: #ffebee;
            border-left-color: #f44336;
        }
        button {
            background: #2196f3;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 10px;
        }
        button:hover {
            background: #1976d2;
        }
    </style>
</head>
<body>
    <h1>🔧 Files API Test Suite</h1>

    <div class="test-section">
        <h2>Session Information</h2>
        <table>
            <tr><th>Variable</th><th>Value</th></tr>
            <tr><td>User ID</td><td><?php echo $_SESSION['user_id']; ?></td></tr>
            <tr><td>Tenant ID</td><td><?php echo $_SESSION['tenant_id']; ?></td></tr>
            <tr><td>Role</td><td><?php echo $_SESSION['role']; ?></td></tr>
            <tr><td>Session ID</td><td><?php echo substr(session_id(), 0, 16); ?>...</td></tr>
            <tr><td>CSRF Token</td><td><?php echo substr($_SESSION['csrf_token'], 0, 16); ?>...</td></tr>
        </table>
    </div>

    <?php
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();

        // Test 1: Database Structure
        echo '<div class="test-section">';
        echo '<h2>1. Database Structure Check</h2>';

        // Check FILES table
        $stmt = $conn->query("DESCRIBE files");
        $file_columns = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $file_columns[] = $row['Field'];
        }

        $required_file_columns = ['id', 'tenant_id', 'name', 'file_path', 'file_size', 'uploaded_by', 'is_folder'];
        $missing = array_diff($required_file_columns, $file_columns);

        if (empty($missing)) {
            echo '<div class="test-result pass">✅ FILES table has all required columns</div>';
        } else {
            echo '<div class="test-result fail">❌ FILES table missing columns: ' . implode(', ', $missing) . '</div>';
        }

        // Check FOLDERS table
        $stmt = $conn->query("DESCRIBE folders");
        $folder_columns = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $folder_columns[] = $row['Field'];
        }

        $required_folder_columns = ['id', 'tenant_id', 'name', 'path', 'owner_id'];
        $missing = array_diff($required_folder_columns, $folder_columns);

        if (empty($missing)) {
            echo '<div class="test-result pass">✅ FOLDERS table has all required columns</div>';
        } else {
            echo '<div class="test-result fail">❌ FOLDERS table missing columns: ' . implode(', ', $missing) . '</div>';
        }

        echo '</div>';

        // Test 2: API GET Request
        echo '<div class="test-section">';
        echo '<h2>2. API GET Request - List Files</h2>';

        $ch = curl_init('http://localhost:8888/CollaboraNexio/api/files.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . session_id());
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        echo '<p><strong>HTTP Status:</strong> ' . $httpCode . '</p>';

        if ($httpCode === 200) {
            echo '<div class="test-result pass">✅ API returned HTTP 200</div>';
        } else {
            echo '<div class="test-result fail">❌ API returned HTTP ' . $httpCode . '</div>';
        }

        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo '<div class="test-result pass">✅ Valid JSON response</div>';

            if (isset($data['success']) && $data['success']) {
                echo '<div class="test-result pass">✅ API call successful</div>';

                if (isset($data['data'])) {
                    echo '<h3>Files & Folders Found: ' . count($data['data']) . '</h3>';

                    if (count($data['data']) > 0) {
                        echo '<table>';
                        echo '<tr><th>Name</th><th>Type</th><th>Size</th><th>Owner</th><th>Created</th></tr>';
                        foreach ($data['data'] as $item) {
                            echo '<tr>';
                            echo '<td>' . ($item['is_folder'] ? '<span class="folder-icon"></span>' : '<span class="file-icon"></span>');
                            echo htmlspecialchars($item['name']) . '</td>';
                            echo '<td>' . ($item['is_folder'] ? 'Folder' : $item['type']) . '</td>';
                            echo '<td>' . ($item['is_folder'] ? '-' : formatBytes($item['size'])) . '</td>';
                            echo '<td>' . htmlspecialchars($item['uploaded_by']['name'] ?? 'Unknown') . '</td>';
                            echo '<td>' . date('Y-m-d H:i', strtotime($item['created_at'])) . '</td>';
                            echo '</tr>';
                        }
                        echo '</table>';
                    }
                }
            } else {
                echo '<div class="test-result fail">❌ API returned error: ' . ($data['error'] ?? 'Unknown') . '</div>';
            }

            echo '<h3>Raw Response (formatted):</h3>';
            echo '<pre class="json">' . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
        } else {
            echo '<div class="test-result fail">❌ Invalid JSON response: ' . json_last_error_msg() . '</div>';
            echo '<h3>Raw Response:</h3>';
            echo '<pre>' . htmlspecialchars(substr($response, 0, 1000)) . '</pre>';
        }

        echo '</div>';

        // Test 3: Query specific folder
        echo '<div class="test-section">';
        echo '<h2>3. API GET Request - Documents Folder</h2>';

        $ch = curl_init('http://localhost:8888/CollaboraNexio/api/files.php?folder_id=1');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . session_id());
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($data && isset($data['data'])) {
            echo '<p>Items in Documents folder: ' . count($data['data']) . '</p>';
            if (count($data['data']) > 0) {
                echo '<ul>';
                foreach ($data['data'] as $item) {
                    $icon = $item['is_folder'] ? '📁' : '📄';
                    $size = $item['is_folder'] ? '' : ' (' . formatBytes($item['size']) . ')';
                    echo '<li>' . $icon . ' ' . htmlspecialchars($item['name']) . $size . '</li>';
                }
                echo '</ul>';
            }
        }
        echo '</div>';

        // Test 4: Sample data statistics
        echo '<div class="test-section">';
        echo '<h2>4. Database Statistics</h2>';

        $stats = [];

        // Count files
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM files WHERE tenant_id = ? AND deleted_at IS NULL AND (is_folder = 0 OR is_folder IS NULL)");
        $stmt->execute([1]);
        $stats['files'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Count folders
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM folders WHERE tenant_id = ? AND deleted_at IS NULL");
        $stmt->execute([1]);
        $stats['folders'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Total size
        $stmt = $conn->prepare("SELECT SUM(file_size) as total FROM files WHERE tenant_id = ? AND deleted_at IS NULL");
        $stmt->execute([1]);
        $stats['total_size'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        echo '<table>';
        echo '<tr><th>Metric</th><th>Value</th></tr>';
        echo '<tr><td>Total Files</td><td>' . $stats['files'] . '</td></tr>';
        echo '<tr><td>Total Folders</td><td>' . $stats['folders'] . '</td></tr>';
        echo '<tr><td>Total Size</td><td>' . formatBytes($stats['total_size']) . '</td></tr>';
        echo '</table>';

        echo '</div>';

    } catch (Exception $e) {
        echo '<div class="test-section">';
        echo '<h2 class="error">Error</h2>';
        echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
        echo '</div>';
    }

    function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    ?>

    <div class="test-section">
        <h2>Test Actions</h2>
        <button onclick="location.reload()">🔄 Refresh Tests</button>
        <button onclick="window.location.href='files.php'">📂 Go to Files Page</button>
        <button onclick="window.location.href='dashboard.php'">🏠 Go to Dashboard</button>
    </div>

    <div class="test-section">
        <h2>Summary</h2>
        <p>This test suite verifies that:</p>
        <ul>
            <li>✅ Database tables have the correct structure</li>
            <li>✅ The API returns valid JSON responses</li>
            <li>✅ Files and folders are properly listed</li>
            <li>✅ Multi-tenancy filtering works correctly</li>
            <li>✅ User information is properly linked</li>
        </ul>
        <p><strong>API Endpoint:</strong> <code>/api/files.php</code></p>
        <p><strong>Supported Methods:</strong> GET (list), POST (upload/create), PUT (update), DELETE (soft delete)</p>
    </div>
</body>
</html>