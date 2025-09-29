<?php
/**
 * Test script for company deletion functionality
 * Access via browser: http://localhost:8888/CollaboraNexio/test_company_deletion.php
 */

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Check if user is logged in as Super Admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'super_admin') {
    die('This test requires Super Admin access. Please login as Super Admin first.');
}

$db = Database::getInstance();
$pdo = $db->getConnection();

// HTML header
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Company Deletion - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #667eea;
            padding-bottom: 15px;
            margin-bottom: 30px;
        }
        .section {
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .section h2 {
            color: #495057;
            margin-top: 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        th {
            background: #667eea;
            color: white;
            font-weight: 600;
        }
        tr:hover {
            background: #f1f3f5;
        }
        .status {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }
        .status.ok { background: #d4edda; color: #155724; }
        .status.warning { background: #fff3cd; color: #856404; }
        .status.error { background: #f8d7da; color: #721c24; }
        .status.info { background: #d1ecf1; color: #0c5460; }
        .btn {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            margin-right: 10px;
        }
        .btn:hover { background: #5a67d8; }
        .btn.danger { background: #dc3545; }
        .btn.danger:hover { background: #c82333; }
        .btn.success { background: #28a745; }
        .btn.success:hover { background: #218838; }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 6px;
        }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert.info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .test-results {
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: 8px;
            border: 2px solid #e9ecef;
        }
        .test-item {
            padding: 10px;
            margin: 5px 0;
            border-left: 4px solid #e9ecef;
        }
        .test-item.pass { border-left-color: #28a745; background: #f0f9f0; }
        .test-item.fail { border-left-color: #dc3545; background: #fff5f5; }
        .null-value { color: #6c757d; font-style: italic; }
    </style>
</head>
<body>
<div class="container">
    <h1>🧪 Test Company Deletion Functionality</h1>

    <?php
    // Get CSRF token
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = $_SESSION['csrf_token'];
    ?>

    <!-- System Status Section -->
    <div class="section">
        <h2>📊 System Status</h2>
        <?php
        // Check if migration has been applied
        $checkMigration = $pdo->prepare("SELECT * FROM migration_history WHERE migration_name = '07_company_deletion_fix.sql'");
        $checkMigration->execute();
        $migration = $checkMigration->fetch(PDO::FETCH_ASSOC);

        if ($migration) {
            echo '<div class="alert success">✅ Migration applied on: ' . $migration['executed_at'] . '</div>';
        } else {
            echo '<div class="alert error">❌ Migration not yet applied. Run: <code>php run_company_deletion_fix.php</code></div>';
        }

        // Check nullable columns
        $checks = [
            ['table' => 'users', 'column' => 'tenant_id', 'description' => 'Users can exist without company'],
            ['table' => 'folders', 'column' => 'tenant_id', 'description' => 'Folders can be global (Super Admin)'],
            ['table' => 'files', 'column' => 'tenant_id', 'description' => 'Files can be global (Super Admin)']
        ];

        echo '<table>';
        echo '<tr><th>Table</th><th>Column</th><th>Nullable</th><th>Description</th></tr>';

        foreach ($checks as $check) {
            $stmt = $pdo->prepare("
                SELECT IS_NULLABLE
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'collaboranexio'
                AND TABLE_NAME = :table
                AND COLUMN_NAME = :column
            ");
            $stmt->execute(['table' => $check['table'], 'column' => $check['column']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $isNullable = ($result && $result['IS_NULLABLE'] === 'YES');
            echo '<tr>';
            echo '<td>' . $check['table'] . '</td>';
            echo '<td>' . $check['column'] . '</td>';
            echo '<td><span class="status ' . ($isNullable ? 'ok' : 'error') . '">' .
                 ($isNullable ? '✓ Yes' : '✗ No') . '</span></td>';
            echo '<td>' . $check['description'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';

        // Check stored procedures
        $procedures = ['SafeDeleteCompany', 'CheckUserLoginAccess', 'GetAccessibleFolders'];
        echo '<h3 style="margin-top: 20px;">Stored Procedures</h3>';
        echo '<table>';
        echo '<tr><th>Procedure</th><th>Status</th></tr>';

        foreach ($procedures as $proc) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM information_schema.ROUTINES
                WHERE ROUTINE_SCHEMA = 'collaboranexio'
                AND ROUTINE_NAME = :name
            ");
            $stmt->execute(['name' => $proc]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $exists = ($result && $result['count'] > 0);

            echo '<tr>';
            echo '<td>' . $proc . '</td>';
            echo '<td><span class="status ' . ($exists ? 'ok' : 'warning') . '">' .
                 ($exists ? '✓ Exists' : '⚠ Missing') . '</span></td>';
            echo '</tr>';
        }
        echo '</table>';
        ?>
    </div>

    <!-- Current Companies Section -->
    <div class="section">
        <h2>🏢 Current Companies</h2>
        <?php
        $stmt = $pdo->query("
            SELECT t.*,
                   (SELECT COUNT(*) FROM users WHERE tenant_id = t.id) as user_count,
                   (SELECT COUNT(*) FROM folders WHERE tenant_id = t.id) as folder_count,
                   (SELECT COUNT(*) FROM files WHERE tenant_id = t.id) as file_count
            FROM tenants t
            ORDER BY t.id
        ");
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo '<table>';
        echo '<tr><th>ID</th><th>Name</th><th>Status</th><th>Users</th><th>Folders</th><th>Files</th><th>Actions</th></tr>';

        foreach ($companies as $company) {
            echo '<tr>';
            echo '<td>' . $company['id'] . '</td>';
            echo '<td>' . htmlspecialchars($company['name']) . '</td>';
            echo '<td><span class="status ' . ($company['status'] === 'active' ? 'ok' : 'warning') . '">' .
                 $company['status'] . '</span></td>';
            echo '<td>' . $company['user_count'] . '</td>';
            echo '<td>' . $company['folder_count'] . '</td>';
            echo '<td>' . $company['file_count'] . '</td>';
            echo '<td>';
            if ($company['id'] != 1) { // Don't allow deleting system company
                echo '<button class="btn danger" onclick="deleteCompany(' . $company['id'] . ', \'' .
                     htmlspecialchars($company['name']) . '\')">Delete</button>';
            } else {
                echo '<span class="status info">System</span>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
        ?>
    </div>

    <!-- Users Without Company Section -->
    <div class="section">
        <h2>👥 Users Without Company</h2>
        <?php
        // Check if original_tenant_id column exists
        $checkColumn = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = 'collaboranexio'
            AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'original_tenant_id'
        ");
        $checkColumn->execute();
        $hasOriginalTenantId = $checkColumn->fetchColumn() > 0;

        if ($hasOriginalTenantId) {
            $stmt = $pdo->query("
                SELECT u.*, u.original_tenant_id,
                       t.name as original_tenant_name
                FROM users u
                LEFT JOIN tenants t ON u.original_tenant_id = t.id
                WHERE u.tenant_id IS NULL
                ORDER BY u.role, u.name
            ");
        } else {
            $stmt = $pdo->query("
                SELECT u.*,
                       NULL as original_tenant_id,
                       NULL as original_tenant_name
                FROM users u
                WHERE u.tenant_id IS NULL
                ORDER BY u.role, u.name
            ");
        }
        $orphanedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($orphanedUsers) > 0) {
            echo '<table>';
            echo '<tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Original Company</th><th>Can Login?</th></tr>';

            foreach ($orphanedUsers as $user) {
                $canLogin = in_array($user['role'], ['admin', 'super_admin']);
                echo '<tr>';
                echo '<td>' . $user['id'] . '</td>';
                echo '<td>' . htmlspecialchars($user['name']) . '</td>';
                echo '<td>' . htmlspecialchars($user['email']) . '</td>';
                echo '<td><span class="status info">' . $user['role'] . '</span></td>';
                echo '<td>' . ($user['original_tenant_name'] ?
                     htmlspecialchars($user['original_tenant_name']) . ' (ID: ' . $user['original_tenant_id'] . ')' :
                     '<span class="null-value">None</span>') . '</td>';
                echo '<td><span class="status ' . ($canLogin ? 'ok' : 'error') . '">' .
                     ($canLogin ? '✓ Yes' : '✗ No') . '</span></td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<p>No users without company assignment.</p>';
        }
        ?>
    </div>

    <!-- Super Admin Folders Section -->
    <div class="section">
        <h2>📁 Super Admin Folders (No Company)</h2>
        <?php
        // Check if original_tenant_id and reassigned_at columns exist in folders table
        $checkFolderColumns = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = 'collaboranexio'
            AND TABLE_NAME = 'folders'
            AND COLUMN_NAME IN ('original_tenant_id', 'reassigned_at')
        ");
        $checkFolderColumns->execute();
        $existingColumns = $checkFolderColumns->fetchAll(PDO::FETCH_COLUMN);

        $hasOriginalTenantIdInFolders = in_array('original_tenant_id', $existingColumns);
        $hasReassignedAt = in_array('reassigned_at', $existingColumns);

        if ($hasOriginalTenantIdInFolders) {
            $selectClause = "f.*, u.name as owner_name, t.name as original_tenant_name";
            $joinClause = "LEFT JOIN tenants t ON f.original_tenant_id = t.id";
        } else {
            $selectClause = "f.*, u.name as owner_name, NULL as original_tenant_id, NULL as original_tenant_name";
            if (!$hasReassignedAt) {
                $selectClause .= ", NULL as reassigned_at";
            }
            $joinClause = "";
        }

        $query = "
            SELECT $selectClause
            FROM folders f
            LEFT JOIN users u ON f.owner_id = u.id
            $joinClause
            WHERE f.tenant_id IS NULL
            ORDER BY f.parent_id, f.name
        ";

        $stmt = $pdo->query($query);
        $superAdminFolders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($superAdminFolders) > 0) {
            echo '<table>';
            echo '<tr><th>ID</th><th>Name</th><th>Path</th><th>Owner</th><th>Original Company</th><th>Reassigned</th></tr>';

            foreach ($superAdminFolders as $folder) {
                echo '<tr>';
                echo '<td>' . $folder['id'] . '</td>';
                echo '<td>' . htmlspecialchars($folder['name']) . '</td>';
                echo '<td>' . htmlspecialchars($folder['path']) . '</td>';
                echo '<td>' . htmlspecialchars($folder['owner_name'] ?? 'Unknown') . '</td>';
                echo '<td>' . ($folder['original_tenant_name'] ?
                     htmlspecialchars($folder['original_tenant_name']) . ' (ID: ' . $folder['original_tenant_id'] . ')' :
                     '<span class="null-value">None</span>') . '</td>';
                echo '<td>' . ($folder['reassigned_at'] ?? '<span class="null-value">N/A</span>') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<p>No Super Admin folders found.</p>';
        }
        ?>
    </div>

    <!-- Test Create Company Section -->
    <div class="section">
        <h2>➕ Create Test Company</h2>
        <form id="createCompanyForm">
            <input type="text" id="companyName" placeholder="Company Name" required style="padding: 8px; margin-right: 10px;">
            <button type="submit" class="btn success">Create Test Company</button>
        </form>
        <div id="createResult"></div>
    </div>

    <!-- Test Results Section -->
    <div id="testResults" style="display: none;">
        <div class="section">
            <h2>🧪 Test Results</h2>
            <div class="test-results" id="testResultsContent"></div>
        </div>
    </div>

</div>

<script>
const csrfToken = '<?php echo $csrfToken; ?>';

function deleteCompany(id, name) {
    if (!confirm(`Are you sure you want to delete company "${name}" (ID: ${id})?`)) {
        return;
    }

    fetch('/CollaboraNexio/api/companies/delete.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            id: id,
            csrf_token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Company deleted successfully!\n\n' + data.message + '\n\nDetails:\n' + JSON.stringify(data.data, null, 2));
            location.reload();
        } else {
            alert('Error: ' + (data.error || data.message));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}

document.getElementById('createCompanyForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const companyName = document.getElementById('companyName').value;

    // Create company with test data
    fetch('/CollaboraNexio/api/companies/create.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            name: companyName,
            denominazione: companyName + ' S.r.l.',
            codice_fiscale: 'CF' + Math.random().toString(36).substr(2, 9).toUpperCase(),
            partita_iva: 'IT' + Math.floor(Math.random() * 99999999999),
            indirizzo: 'Via Test, 123',
            citta: 'Test City',
            provincia: 'TS',
            cap: '12345',
            telefono: '+39 123 456 7890',
            email: companyName.toLowerCase().replace(/\s+/g, '') + '@test.com',
            max_users: 10,
            max_storage_gb: 50,
            status: 'active',
            csrf_token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        const resultDiv = document.getElementById('createResult');
        if (data.success) {
            resultDiv.innerHTML = '<div class="alert success">✅ Company created successfully! ID: ' + data.data.id + '</div>';

            // Create test folders and users for this company
            createTestData(data.data.id, companyName);

            setTimeout(() => location.reload(), 3000);
        } else {
            resultDiv.innerHTML = '<div class="alert error">❌ Error: ' + (data.error || data.message) + '</div>';
        }
    })
    .catch(error => {
        document.getElementById('createResult').innerHTML = '<div class="alert error">❌ Error: ' + error.message + '</div>';
    });
});

function createTestData(companyId, companyName) {
    // This would normally create test folders and users for the company
    console.log('Test data would be created for company ID:', companyId);
}
</script>
</body>
</html>