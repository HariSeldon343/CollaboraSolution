<?php
/**
 * Diagnostic script for Work Shifts feature
 * Run via browser: /CollaboraNexio/tools/check_shifts_setup.php
 */

require_once __DIR__ . '/../includes/session_init.php';
require_once __DIR__ . '/../includes/auth_simple.php';

$auth = new Auth();
if (!$auth->checkAuth()) {
    die('Authentication required');
}

$currentUser = $auth->getCurrentUser();
if ($currentUser['role'] !== 'super_admin') {
    die('Super admin access required');
}

require_once __DIR__ . '/../includes/database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Shifts Setup Check</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1a1a2e; color: #eee; }
        .ok { color: #10b981; }
        .fail { color: #ef4444; }
        .warn { color: #f59e0b; }
        h2 { color: #60a5fa; border-bottom: 1px solid #333; padding-bottom: 10px; }
        pre { background: #0f0f1a; padding: 15px; border-radius: 8px; overflow-x: auto; }
    </style>
</head>
<body>
<h1>Work Shifts Setup Diagnostic</h1>
<pre>
<?php
$db = Database::getInstance();

echo "=== 1. DATABASE TABLES ===\n\n";

$tables = ['shift_types', 'work_shifts', 'shift_change_requests'];
$allTablesExist = true;

foreach ($tables as $table) {
    $result = $db->fetchOne(
        "SELECT COUNT(*) as cnt FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = ?",
        [$table]
    );
    $exists = $result && $result['cnt'] > 0;
    $allTablesExist = $allTablesExist && $exists;
    $status = $exists ? '<span class="ok">EXISTS</span>' : '<span class="fail">MISSING</span>';
    echo "  $table: $status\n";
}

// Check tenants column
$col = $db->fetchOne(
    "SELECT COUNT(*) as cnt FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'has_shift_management'"
);
$colExists = $col && $col['cnt'] > 0;
$status = $colExists ? '<span class="ok">EXISTS</span>' : '<span class="fail">MISSING</span>';
echo "  tenants.has_shift_management: $status\n";

if (!$allTablesExist || !$colExists) {
    echo "\n<span class='fail'>⚠ Migration required!</span>\n";
    echo "Run: mysql -u root collaboranexio < database/migrations/22_work_shifts_feature.sql\n";
}

echo "\n=== 2. API FILES ===\n\n";

$apiFiles = [
    'api/shifts/types.php',
    'api/shifts/list.php',
    'api/shifts/manage.php',
    'api/shifts/requests.php'
];

foreach ($apiFiles as $file) {
    $path = __DIR__ . '/../' . $file;
    $exists = file_exists($path);
    $status = $exists ? '<span class="ok">EXISTS</span>' : '<span class="fail">MISSING</span>';
    $size = $exists ? ' (' . filesize($path) . ' bytes)' : '';
    echo "  $file: $status$size\n";
}

echo "\n=== 3. HELPER & TEMPLATES ===\n\n";

$helperFiles = [
    'includes/shift_notification_helper.php',
    'includes/email_templates/shifts/shift_assigned.html',
    'includes/email_templates/shifts/shift_updated.html',
    'includes/email_templates/shifts/shift_cancelled.html',
    'includes/email_templates/shifts/shift_request_received.html',
    'includes/email_templates/shifts/shift_request_approved.html',
    'includes/email_templates/shifts/shift_request_rejected.html'
];

foreach ($helperFiles as $file) {
    $path = __DIR__ . '/../' . $file;
    $exists = file_exists($path);
    $status = $exists ? '<span class="ok">EXISTS</span>' : '<span class="fail">MISSING</span>';
    echo "  $file: $status\n";
}

echo "\n=== 4. FRONTEND FILES ===\n\n";

$frontendFiles = [
    'turni.php',
    'assets/js/shifts.js',
    'assets/css/shifts.css'
];

foreach ($frontendFiles as $file) {
    $path = __DIR__ . '/../' . $file;
    $exists = file_exists($path);
    $status = $exists ? '<span class="ok">EXISTS</span>' : '<span class="fail">MISSING</span>';
    $size = $exists ? ' (' . filesize($path) . ' bytes)' : '';
    echo "  $file: $status$size\n";
}

echo "\n=== 5. API SYNTAX CHECK ===\n\n";

// Quick syntax check on API files
foreach ($apiFiles as $file) {
    $path = __DIR__ . '/../' . $file;
    if (file_exists($path)) {
        $output = [];
        $returnCode = 0;
        exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $returnCode);
        $status = $returnCode === 0 ? '<span class="ok">OK</span>' : '<span class="fail">SYNTAX ERROR</span>';
        echo "  $file: $status\n";
        if ($returnCode !== 0) {
            echo "    " . implode("\n    ", $output) . "\n";
        }
    }
}

echo "\n=== 6. TEST API ENDPOINT ===\n\n";

// Test if types.php responds correctly (internal call)
$testUrl = 'http://localhost:8888/CollaboraNexio/api/shifts/types.php?tenant_id=1';
echo "  Testing: $testUrl\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $testUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "  <span class='warn'>cURL error: $error</span>\n";
} else {
    $status = $httpCode === 200 ? '<span class="ok">OK</span>' : '<span class="fail">HTTP ' . $httpCode . '</span>';
    echo "  Response: $status\n";
    if ($httpCode !== 200 && $response) {
        $decoded = json_decode($response, true);
        if ($decoded) {
            echo "  Message: " . ($decoded['message'] ?? 'N/A') . "\n";
        }
    }
}

echo "\n=== SUMMARY ===\n\n";

if ($allTablesExist && $colExists) {
    echo '<span class="ok">✓ Database ready</span>' . "\n";
} else {
    echo '<span class="fail">✗ Database needs migration</span>' . "\n";
}

echo "\nDiagnostic completed at: " . date('Y-m-d H:i:s') . "\n";
?>
</pre>

<h2>Quick Actions</h2>
<p>If migration is needed, run this command in MySQL/phpMyAdmin:</p>
<pre>SOURCE C:/xampp/htdocs/CollaboraNexio/database/migrations/22_work_shifts_feature.sql;</pre>

</body>
</html>
