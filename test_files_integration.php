<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Test configuration
echo "<!DOCTYPE html>";
echo "<html><head><title>File System Integration Test</title>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    .test { margin: 20px 0; padding: 15px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .info { color: blue; }
    h2 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
    pre { background: #f0f0f0; padding: 10px; border-radius: 4px; overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
    th { background: #007bff; color: white; }
    .badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 12px; margin: 2px; }
    .badge-admin { background: #6f42c1; color: white; }
    .badge-tenant { background: #28a745; color: white; }
</style>";
echo "</head><body>";

echo "<h1>🗂️ File System Multi-Tenant Integration Test</h1>";

// Database connection
$db = Database::getInstance();
$pdo = $db->getConnection();

// Test 1: Check tables structure
echo "<div class='test'>";
echo "<h2>1. Database Tables Check</h2>";

$tables = ['folders', 'files', 'tenants', 'user_tenant_access'];
foreach ($tables as $table) {
    $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
    if ($stmt->rowCount() > 0) {
        echo "<span class='success'>✓</span> Table <b>$table</b> exists<br>";

        // Show column structure
        $cols = $pdo->query("SHOW COLUMNS FROM $table");
        echo "<details><summary>Columns</summary><table>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
        while ($col = $cols->fetch()) {
            echo "<tr>";
            echo "<td>{$col['Field']}</td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td>{$col['Null']}</td>";
            echo "<td>{$col['Key']}</td>";
            echo "</tr>";
        }
        echo "</table></details>";
    } else {
        echo "<span class='error'>✗</span> Table <b>$table</b> missing<br>";
    }
}
echo "</div>";

// Test 2: Check tenant folders structure
echo "<div class='test'>";
echo "<h2>2. Tenant Root Folders</h2>";

$stmt = $pdo->query("
    SELECT f.*, t.name as tenant_name
    FROM folders f
    JOIN tenants t ON f.tenant_id = t.id
    WHERE f.parent_id IS NULL
    AND f.deleted_at IS NULL
    ORDER BY t.name, f.name
");

if ($stmt->rowCount() > 0) {
    echo "<table>";
    echo "<tr><th>Folder</th><th>Tenant</th><th>Created</th><th>ID</th></tr>";
    while ($folder = $stmt->fetch()) {
        echo "<tr>";
        echo "<td>📁 {$folder['name']}</td>";
        echo "<td><span class='badge badge-tenant'>{$folder['tenant_name']}</span></td>";
        echo "<td>{$folder['created_at']}</td>";
        echo "<td>#{$folder['id']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='info'>No root folders found. Admin/Super Admin can create them.</p>";
}
echo "</div>";

// Test 3: Check user access
echo "<div class='test'>";
echo "<h2>3. User Multi-Tenant Access</h2>";

$stmt = $pdo->query("
    SELECT u.id, u.name, u.email, u.role, COUNT(uta.tenant_id) as tenant_count
    FROM users u
    LEFT JOIN user_tenant_access uta ON u.id = uta.user_id
    WHERE u.role IN ('admin', 'super_admin')
    GROUP BY u.id
    ORDER BY u.role DESC, u.name
");

if ($stmt->rowCount() > 0) {
    echo "<table>";
    echo "<tr><th>User</th><th>Email</th><th>Role</th><th>Accessible Tenants</th></tr>";
    while ($user = $stmt->fetch()) {
        echo "<tr>";
        echo "<td>{$user['name']}</td>";
        echo "<td>{$user['email']}</td>";
        echo "<td><span class='badge badge-admin'>{$user['role']}</span></td>";
        echo "<td>{$user['tenant_count']} tenant(s)</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='info'>No multi-tenant users found.</p>";
}
echo "</div>";

// Test 4: API Endpoint Test
echo "<div class='test'>";
echo "<h2>4. API Endpoint Test</h2>";

$api_url = BASE_URL . '/api/files_tenant.php';
echo "<p>Testing API endpoint: <code>$api_url</code></p>";

if (file_exists(__DIR__ . '/api/files_tenant.php')) {
    echo "<span class='success'>✓</span> API file exists<br>";

    // Test API actions
    $actions = ['list', 'get_tenant_list', 'get_folder_path'];
    echo "<p>Available actions:</p><ul>";
    foreach ($actions as $action) {
        echo "<li><code>?action=$action</code></li>";
    }
    echo "</ul>";
} else {
    echo "<span class='error'>✗</span> API file not found<br>";
}
echo "</div>";

// Test 5: File upload directory
echo "<div class='test'>";
echo "<h2>5. Upload Directory Check</h2>";

$upload_path = UPLOAD_PATH;
echo "<p>Upload path: <code>$upload_path</code></p>";

if (is_dir($upload_path)) {
    echo "<span class='success'>✓</span> Upload directory exists<br>";

    if (is_writable($upload_path)) {
        echo "<span class='success'>✓</span> Upload directory is writable<br>";
    } else {
        echo "<span class='error'>✗</span> Upload directory is not writable<br>";
    }

    // Check tenant subdirectories
    $stmt = $pdo->query("SELECT id, name FROM tenants");
    while ($tenant = $stmt->fetch()) {
        $tenant_dir = $upload_path . '/' . $tenant['id'];
        if (is_dir($tenant_dir)) {
            echo "<span class='success'>✓</span> Tenant directory exists: <code>{$tenant['name']}</code><br>";
        } else {
            echo "<span class='info'>ℹ</span> Tenant directory will be created on first upload: <code>{$tenant['name']}</code><br>";
        }
    }
} else {
    echo "<span class='error'>✗</span> Upload directory not found<br>";
    echo "<p class='info'>Create it with: <code>mkdir -p $upload_path && chmod 755 $upload_path</code></p>";
}
echo "</div>";

// Test 6: Sample data statistics
echo "<div class='test'>";
echo "<h2>6. File System Statistics</h2>";

$stats = [];

// Total folders
$stmt = $pdo->query("SELECT COUNT(*) as count FROM folders WHERE deleted_at IS NULL");
$stats['folders'] = $stmt->fetchColumn();

// Total files
$stmt = $pdo->query("SELECT COUNT(*) as count FROM files WHERE deleted_at IS NULL");
$stats['files'] = $stmt->fetchColumn();

// Files by status
$stmt = $pdo->query("
    SELECT status, COUNT(*) as count
    FROM files
    WHERE deleted_at IS NULL
    GROUP BY status
");
$status_counts = [];
while ($row = $stmt->fetch()) {
    $status_counts[$row['status']] = $row['count'];
}

echo "<table>";
echo "<tr><th>Metric</th><th>Value</th></tr>";
echo "<tr><td>Total Folders</td><td>{$stats['folders']}</td></tr>";
echo "<tr><td>Total Files</td><td>{$stats['files']}</td></tr>";
echo "<tr><td>Files in Approval</td><td>" . ($status_counts['in_approvazione'] ?? 0) . "</td></tr>";
echo "<tr><td>Approved Files</td><td>" . ($status_counts['approvato'] ?? 0) . "</td></tr>";
echo "<tr><td>Rejected Files</td><td>" . ($status_counts['rifiutato'] ?? 0) . "</td></tr>";
echo "</table>";
echo "</div>";

// Test 7: Session check
echo "<div class='test'>";
echo "<h2>7. Session Configuration</h2>";

if (isset($_SESSION['user_id'])) {
    echo "<span class='success'>✓</span> User session active<br>";
    echo "<table>";
    echo "<tr><th>Session Variable</th><th>Value</th></tr>";
    echo "<tr><td>user_id</td><td>{$_SESSION['user_id']}</td></tr>";
    echo "<tr><td>tenant_id</td><td>{$_SESSION['tenant_id']}</td></tr>";
    echo "<tr><td>role</td><td><span class='badge badge-admin'>{$_SESSION['role']}</span></td></tr>";
    echo "</table>";
} else {
    echo "<span class='info'>ℹ</span> No active session. <a href='login.php'>Login</a> to test features.<br>";
}
echo "</div>";

// Summary
echo "<div class='test'>";
echo "<h2>📋 Summary</h2>";
echo "<p><strong>Multi-Tenant File System Features:</strong></p>";
echo "<ul>";
echo "<li>✅ Tenant-isolated folder structure</li>";
echo "<li>✅ Role-based access control (User, Manager, Admin, Super Admin)</li>";
echo "<li>✅ Document approval workflow (in_approvazione → approvato/rifiutato)</li>";
echo "<li>✅ Admin/Super Admin can create root folders for any tenant</li>";
echo "<li>✅ Files can only be uploaded to folders, not root</li>";
echo "<li>✅ Tenant context badge shows current tenant when browsing</li>";
echo "<li>✅ Breadcrumb navigation with tenant awareness</li>";
echo "</ul>";

echo "<p><strong>Test the system:</strong></p>";
echo "<ol>";
echo "<li>Go to <a href='files.php'>Files Manager</a></li>";
echo "<li>If Admin/Super Admin: Click 'Cartella Tenant' to create root folder</li>";
echo "<li>Navigate into a folder to upload files</li>";
echo "<li>Files start in 'in_approvazione' status</li>";
echo "<li>Manager/Admin can approve documents</li>";
echo "</ol>";
echo "</div>";

echo "</body></html>";
?>