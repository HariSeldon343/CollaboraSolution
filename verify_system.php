<?php
/**
 * System Verification Script
 * Checks all components to verify 100% system completion
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// HTML header
if (PHP_SAPI !== 'cli') {
    echo "<!DOCTYPE html>
<html>
<head>
    <title>System Verification - CollaboraNexio</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 3px solid #667eea; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 30px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        .info { color: #2196F3; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #667eea; color: white; }
        tr:hover { background: #f5f5f5; }
        .progress-bar { width: 100%; height: 30px; background: #e0e0e0; border-radius: 15px; overflow: hidden; margin: 20px 0; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #667eea, #764ba2); transition: width 0.5s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; }
        .section { margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 8px; }
        .check-item { padding: 8px 0; }
        .check-icon { display: inline-block; width: 20px; }
    </style>
</head>
<body>
    <div class='container'>";
}

$totalChecks = 0;
$passedChecks = 0;
$results = [];

function checkItem($category, $item, $status, $details = '') {
    global $totalChecks, $passedChecks, $results;
    $totalChecks++;
    if ($status) {
        $passedChecks++;
    }
    if (!isset($results[$category])) {
        $results[$category] = [];
    }
    $results[$category][] = [
        'item' => $item,
        'status' => $status,
        'details' => $details
    ];
}

echo "<h1>CollaboraNexio System Verification</h1>";
echo "<p>Date: " . date('Y-m-d H:i:s') . "</p>";

try {
    // Database connection
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // ===========================================
    // 1. DATABASE TABLES CHECK
    // ===========================================
    echo "<div class='section'>";
    echo "<h2>📊 Database Tables</h2>";

    $requiredTables = [
        // Core tables
        'tenants' => 'Multi-tenancy support',
        'users' => 'User management',
        'projects' => 'Project management',
        'tasks' => 'Task management',
        'files' => 'File management',
        'folders' => 'Folder organization',
        'calendar_events' => 'Calendar functionality',
        'notifications' => 'Notification system',
        'audit_logs' => 'Audit trail',

        // Communication tables
        'messages' => 'Messaging system',
        'channels' => 'Communication channels',
        'channel_members' => 'Channel membership',
        'chat_messages' => 'Chat functionality',

        // Document management
        'documents' => 'Document storage',
        'document_approvals' => 'Approval workflow',
        'approval_notifications' => 'Approval notifications',
        'file_shares' => 'File sharing',
        'file_versions' => 'Version control',

        // Comments and collaboration
        'task_comments' => 'Task discussions',
        'project_comments' => 'Project discussions',

        // New required tables
        'project_milestones' => 'Project milestones',
        'event_attendees' => 'Event participants',
        'sessions' => 'Session management',
        'rate_limits' => 'API rate limiting',
        'system_settings' => 'System configuration',

        // Access control
        'user_sessions' => 'User sessions',
        'user_tenant_access' => 'Multi-tenant access',
        'migration_history' => 'Migration tracking'
    ];

    echo "<table>";
    echo "<tr><th>Table Name</th><th>Description</th><th>Status</th><th>Records</th></tr>";

    foreach ($requiredTables as $table => $description) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        $exists = $stmt->rowCount() > 0;

        $recordCount = 0;
        if ($exists) {
            try {
                $recordCount = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            } catch (Exception $e) {
                $recordCount = 'Error';
            }
        }

        $statusIcon = $exists ? '✓' : '✗';
        $statusClass = $exists ? 'success' : 'error';
        $recordClass = ($exists && $recordCount > 0) ? 'success' : ($exists ? 'warning' : 'error');

        echo "<tr>";
        echo "<td>$table</td>";
        echo "<td>$description</td>";
        echo "<td class='$statusClass'>$statusIcon " . ($exists ? 'Exists' : 'Missing') . "</td>";
        echo "<td class='$recordClass'>$recordCount</td>";
        echo "</tr>";

        checkItem('Database Tables', $table, $exists, "$recordCount records");
    }
    echo "</table>";
    echo "</div>";

    // ===========================================
    // 2. REQUIRED FILES CHECK
    // ===========================================
    echo "<div class='section'>";
    echo "<h2>📁 Required Files</h2>";

    $requiredFiles = [
        '/config.php' => 'Main configuration',
        '/config/config.php' => 'Config directory wrapper',
        '/login.php' => 'Login page',
        '/index.php' => 'Main entry point',
        '/dashboard.php' => 'Dashboard',
        '/includes/db.php' => 'Database class',
        '/api/auth.php' => 'Authentication API',
        '/api/dashboard.php' => 'Dashboard API',
        '/api/files.php' => 'Files API',
        '/api/projects_complete.php' => 'Projects API',
        '/api/tasks.php' => 'Tasks API'
    ];

    echo "<table>";
    echo "<tr><th>File Path</th><th>Description</th><th>Status</th><th>Size</th></tr>";

    foreach ($requiredFiles as $file => $description) {
        $fullPath = __DIR__ . $file;
        $exists = file_exists($fullPath);
        $size = $exists ? filesize($fullPath) : 0;
        $sizeFormatted = $exists ? number_format($size) . ' bytes' : 'N/A';

        $statusIcon = $exists ? '✓' : '✗';
        $statusClass = $exists ? 'success' : 'error';

        echo "<tr>";
        echo "<td>$file</td>";
        echo "<td>$description</td>";
        echo "<td class='$statusClass'>$statusIcon " . ($exists ? 'Present' : 'Missing') . "</td>";
        echo "<td>$sizeFormatted</td>";
        echo "</tr>";

        checkItem('Required Files', $file, $exists, $sizeFormatted);
    }
    echo "</table>";
    echo "</div>";

    // ===========================================
    // 3. API ENDPOINTS CHECK
    // ===========================================
    echo "<div class='section'>";
    echo "<h2>🔌 API Endpoints</h2>";

    $apiEndpoints = [
        '/api/auth.php' => 'Authentication',
        '/api/dashboard.php' => 'Dashboard data',
        '/api/files.php' => 'File management',
        '/api/projects_complete.php' => 'Project management',
        '/api/tasks.php' => 'Task management',
        '/api/events.php' => 'Calendar events',
        '/api/messages.php' => 'Messaging'
    ];

    echo "<table>";
    echo "<tr><th>Endpoint</th><th>Description</th><th>Status</th><th>Response</th></tr>";

    foreach ($apiEndpoints as $endpoint => $description) {
        $url = BASE_URL . $endpoint;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $isOk = $httpCode >= 200 && $httpCode < 400;
        $statusIcon = $isOk ? '✓' : '✗';
        $statusClass = $isOk ? 'success' : 'error';

        echo "<tr>";
        echo "<td>$endpoint</td>";
        echo "<td>$description</td>";
        echo "<td class='$statusClass'>$statusIcon " . ($isOk ? 'Working' : 'Failed') . "</td>";
        echo "<td>HTTP $httpCode</td>";
        echo "</tr>";

        checkItem('API Endpoints', $endpoint, $isOk, "HTTP $httpCode");
    }
    echo "</table>";
    echo "</div>";

    // ===========================================
    // 4. DATA POPULATION CHECK
    // ===========================================
    echo "<div class='section'>";
    echo "<h2>📈 Data Population Status</h2>";

    $dataChecks = [
        'tenants' => ['min' => 1, 'table' => 'tenants'],
        'users' => ['min' => 2, 'table' => 'users'],
        'projects' => ['min' => 1, 'table' => 'projects'],
        'tasks' => ['min' => 1, 'table' => 'tasks'],
        'files' => ['min' => 1, 'table' => 'files'],
        'project_milestones' => ['min' => 1, 'table' => 'project_milestones'],
        'event_attendees' => ['min' => 1, 'table' => 'event_attendees'],
        'sessions' => ['min' => 1, 'table' => 'sessions'],
        'rate_limits' => ['min' => 1, 'table' => 'rate_limits'],
        'system_settings' => ['min' => 1, 'table' => 'system_settings']
    ];

    echo "<table>";
    echo "<tr><th>Table</th><th>Required Min</th><th>Actual Count</th><th>Status</th></tr>";

    foreach ($dataChecks as $name => $check) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM {$check['table']}")->fetchColumn();
            $hasEnough = $count >= $check['min'];
            $statusIcon = $hasEnough ? '✓' : '⚠';
            $statusClass = $hasEnough ? 'success' : 'warning';

            echo "<tr>";
            echo "<td>{$check['table']}</td>";
            echo "<td>{$check['min']}</td>";
            echo "<td>$count</td>";
            echo "<td class='$statusClass'>$statusIcon " . ($hasEnough ? 'Sufficient' : 'Needs data') . "</td>";
            echo "</tr>";

            checkItem('Data Population', $check['table'], $hasEnough, "$count records (min: {$check['min']})");
        } catch (Exception $e) {
            echo "<tr>";
            echo "<td>{$check['table']}</td>";
            echo "<td>{$check['min']}</td>";
            echo "<td>Error</td>";
            echo "<td class='error'>✗ Table missing</td>";
            echo "</tr>";

            checkItem('Data Population', $check['table'], false, "Table missing");
        }
    }
    echo "</table>";
    echo "</div>";

    // ===========================================
    // FINAL SUMMARY
    // ===========================================
    $completionPercentage = round(($passedChecks / $totalChecks) * 100, 1);
    $progressClass = $completionPercentage == 100 ? 'success' : ($completionPercentage >= 80 ? 'warning' : 'error');

    echo "<div class='section'>";
    echo "<h2>📊 System Completion Summary</h2>";

    echo "<div class='progress-bar'>";
    echo "<div class='progress-fill' style='width: $completionPercentage%;'>$completionPercentage%</div>";
    echo "</div>";

    echo "<table>";
    echo "<tr><th>Category</th><th>Passed</th><th>Failed</th><th>Status</th></tr>";

    foreach ($results as $category => $items) {
        $passed = count(array_filter($items, fn($i) => $i['status']));
        $failed = count($items) - $passed;
        $statusClass = $failed == 0 ? 'success' : ($passed > $failed ? 'warning' : 'error');

        echo "<tr>";
        echo "<td>$category</td>";
        echo "<td class='success'>$passed</td>";
        echo "<td class='" . ($failed > 0 ? 'error' : 'success') . "'>$failed</td>";
        echo "<td class='$statusClass'>" . ($failed == 0 ? '✓ Complete' : '⚠ Incomplete') . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "<h3>Overall Status</h3>";
    echo "<p>Total Checks: <strong>$totalChecks</strong></p>";
    echo "<p>Passed: <strong class='success'>$passedChecks</strong></p>";
    echo "<p>Failed: <strong class='error'>" . ($totalChecks - $passedChecks) . "</strong></p>";

    if ($completionPercentage == 100) {
        echo "<h2 class='success'>✅ SYSTEM IS 100% COMPLETE!</h2>";
        echo "<p>All components are properly installed and configured.</p>";
    } elseif ($completionPercentage >= 90) {
        echo "<h2 class='warning'>⚠️ System is $completionPercentage% complete</h2>";
        echo "<p>Minor components missing. System is functional but not fully complete.</p>";
    } else {
        echo "<h2 class='error'>❌ System is only $completionPercentage% complete</h2>";
        echo "<p>Critical components missing. Please run migrations and setup scripts.</p>";
    }

    // Recommendations
    if ($completionPercentage < 100) {
        echo "<h3>Recommended Actions:</h3>";
        echo "<ol>";
        if ($totalChecks - $passedChecks > 0) {
            echo "<li>Run the migration script: <code>php run_missing_tables_migration.php</code></li>";
            echo "<li>Check error logs for any issues</li>";
            echo "<li>Verify database connection settings</li>";
            echo "<li>Ensure all required files are present</li>";
        }
        echo "</ol>";
    }

    echo "</div>";

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>Error during verification</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

// HTML footer
if (PHP_SAPI !== 'cli') {
    echo "</div></body></html>";
} else {
    echo "\nSystem Verification Complete\n";
    echo "Completion: $completionPercentage%\n";
    echo "Passed: $passedChecks / $totalChecks\n";
}