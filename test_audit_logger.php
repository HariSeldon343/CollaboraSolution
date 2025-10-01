<?php
/**
 * Test script for AuditLogger class
 */

session_start();

// Simulate session data for testing
$_SESSION['tenant_id'] = 1;
$_SESSION['user_id'] = 1;

require_once __DIR__ . '/includes/audit_logger.php';

$logger = new AuditLogger();

echo "Testing AuditLogger class..." . PHP_EOL . PHP_EOL;

// Test 1: Simple log
echo "Test 1: Simple log entry" . PHP_EOL;
$result = $logger->logSimple(
    AuditLogger::ACTION_VIEW,
    AuditLogger::ENTITY_FILE,
    123,
    "User viewed file: report.pdf"
);
echo "  Result: " . ($result ? "SUCCESS" : "FAILED") . PHP_EOL;

// Test 2: Login log
echo PHP_EOL . "Test 2: Login log" . PHP_EOL;
$result = $logger->logLogin("admin@demo.local", true, 1);
echo "  Result: " . ($result ? "SUCCESS" : "FAILED") . PHP_EOL;

// Test 3: Failed login log
echo PHP_EOL . "Test 3: Failed login log" . PHP_EOL;
$result = $logger->logLogin("hacker@evil.com", false);
echo "  Result: " . ($result ? "SUCCESS" : "FAILED") . PHP_EOL;

// Test 4: File operation
echo PHP_EOL . "Test 4: File upload log" . PHP_EOL;
$result = $logger->logFileOperation(
    AuditLogger::ACTION_UPLOAD,
    456,
    "quarterly_report_2025.pdf",
    ['size' => 2048576, 'mime_type' => 'application/pdf']
);
echo "  Result: " . ($result ? "SUCCESS" : "FAILED") . PHP_EOL;

// Test 5: Document approval
echo PHP_EOL . "Test 5: Document approval log" . PHP_EOL;
$result = $logger->logApproval(
    789,
    AuditLogger::ACTION_APPROVE,
    "Budget Proposal 2025",
    ['status' => 'in_approvazione'],
    ['status' => 'approvato', 'approved_by' => 1]
);
echo "  Result: " . ($result ? "SUCCESS" : "FAILED") . PHP_EOL;

// Test 6: Permission change
echo PHP_EOL . "Test 6: Permission change log" . PHP_EOL;
$result = $logger->logPermissionChange(
    3,
    'user',
    'manager',
    'Mario Rossi'
);
echo "  Result: " . ($result ? "SUCCESS" : "FAILED") . PHP_EOL;

// Test 7: Complex log with all parameters
echo PHP_EOL . "Test 7: Complex log entry" . PHP_EOL;
$result = $logger->log([
    'tenant_id' => 1,
    'user_id' => 1,
    'action' => AuditLogger::ACTION_DELETE,
    'entity_type' => AuditLogger::ENTITY_PROJECT,
    'entity_id' => 999,
    'old_values' => [
        'name' => 'Old Project',
        'status' => 'active',
        'budget' => 50000
    ],
    'new_values' => null,
    'description' => 'Project deleted after completion',
    'severity' => AuditLogger::SEVERITY_WARNING,
    'status' => AuditLogger::STATUS_SUCCESS,
    'execution_time_ms' => 125,
    'request_method' => 'DELETE',
    'request_url' => '/api/projects/999'
]);
echo "  Result: " . ($result ? "SUCCESS" : "FAILED") . PHP_EOL;

// Test 8: Get user logs
echo PHP_EOL . "Test 8: Retrieve user logs" . PHP_EOL;
$logs = $logger->getUserLogs(1, 5);
if ($logs) {
    echo "  Found " . count($logs) . " log entries for user 1:" . PHP_EOL;
    foreach ($logs as $log) {
        echo "    - " . $log['action'] . " on " . $log['entity_type'];
        if ($log['description']) {
            echo ": " . $log['description'];
        }
        echo PHP_EOL;
    }
} else {
    echo "  No logs found" . PHP_EOL;
}

// Test 9: Get critical logs
echo PHP_EOL . "Test 9: Retrieve critical logs" . PHP_EOL;
$criticalLogs = $logger->getCriticalLogs(24);
if ($criticalLogs) {
    echo "  Found " . count($criticalLogs) . " critical log(s) in last 24 hours" . PHP_EOL;
} else {
    echo "  No critical logs found (good!)" . PHP_EOL;
}

// Test 10: Count total logs
echo PHP_EOL . "Test 10: Count total logs" . PHP_EOL;
require_once __DIR__ . '/includes/db.php';
$db = Database::getInstance();
$conn = $db->getConnection();
$count = $conn->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
echo "  Total audit log entries: " . $count . PHP_EOL;

echo PHP_EOL . "All tests completed!" . PHP_EOL;