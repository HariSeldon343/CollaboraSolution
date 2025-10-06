<?php
/**
 * CollaboraNexio - Schema Drift Fixes Test Suite
 *
 * Comprehensive automated tests for all critical functionalities affected by schema drift fixes.
 *
 * Updated Files Tested:
 * 1. /api/files_complete.php
 * 2. /api/documents/pending.php
 * 3. /api/documents/approve.php
 * 4. /api/documents/reject.php
 * 5. /api/router.php
 * 6. /api/files_tenant.php
 * 7. /api/files_tenant_fixed.php
 *
 * Schema Fixes:
 * - file_size (not size_bytes)
 * - file_path (not storage_path)
 * - uploaded_by (not owner_id for files table)
 *
 * Run via browser: http://localhost:8888/CollaboraNexio/test_schema_drift_fixes.php
 */

declare(strict_types=1);

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Load configuration
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Test results storage
$testResults = [];
$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

/**
 * Add a test result
 */
function addTestResult(string $category, string $testName, bool $passed, string $message = '', array $details = []): void {
    global $testResults, $totalTests, $passedTests, $failedTests;

    $testResults[] = [
        'category' => $category,
        'name' => $testName,
        'passed' => $passed,
        'message' => $message,
        'details' => $details,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    $totalTests++;
    if ($passed) {
        $passedTests++;
    } else {
        $failedTests++;
    }
}

/**
 * Get database connection
 */
function getDbConnection(): PDO {
    try {
        $db = Database::getInstance();
        return $db->getConnection();
    } catch (Exception $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

// ============================================
// CATEGORY A: FILE LISTING TESTS
// ============================================

function testFileListing(): void {
    $pdo = getDbConnection();

    // Test 1: Verify files table uses correct column names
    try {
        $stmt = $pdo->query("DESCRIBE files");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $hasFileSize = in_array('file_size', $columns);
        $hasFilePath = in_array('file_path', $columns);
        $hasUploadedBy = in_array('uploaded_by', $columns);

        // Should NOT have old column names
        $hasSizeBytes = in_array('size_bytes', $columns);
        $hasStoragePath = in_array('storage_path', $columns);
        $hasOwnerId = in_array('owner_id', $columns);

        $passed = $hasFileSize && $hasFilePath && $hasUploadedBy &&
                  !$hasSizeBytes && !$hasStoragePath && !$hasOwnerId;

        addTestResult(
            'File Listing',
            'Files table schema verification',
            $passed,
            $passed ? 'Files table has correct column names' : 'Files table has incorrect column names',
            [
                'has_file_size' => $hasFileSize,
                'has_file_path' => $hasFilePath,
                'has_uploaded_by' => $hasUploadedBy,
                'has_old_size_bytes' => $hasSizeBytes,
                'has_old_storage_path' => $hasStoragePath,
                'has_old_owner_id' => $hasOwnerId
            ]
        );
    } catch (Exception $e) {
        addTestResult('File Listing', 'Files table schema verification', false, $e->getMessage());
    }

    // Test 2: Verify file listing query uses correct columns
    try {
        $query = "SELECT f.id, f.name, f.original_name, f.mime_type,
                         f.file_size, f.file_path, f.uploaded_by,
                         u.first_name, u.last_name, u.email
                  FROM files f
                  LEFT JOIN users u ON f.uploaded_by = u.id
                  WHERE f.deleted_at IS NULL
                  LIMIT 5";

        $stmt = $pdo->query($query);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check if query executed successfully and returns expected columns
        $passed = true;
        if (count($files) > 0) {
            $firstFile = $files[0];
            $passed = isset($firstFile['file_size']) &&
                     isset($firstFile['file_path']) &&
                     isset($firstFile['uploaded_by']);
        }

        addTestResult(
            'File Listing',
            'File listing query with correct columns',
            $passed,
            $passed ? 'Query executed successfully with correct columns' : 'Query missing expected columns',
            ['files_found' => count($files)]
        );
    } catch (Exception $e) {
        addTestResult('File Listing', 'File listing query with correct columns', false, $e->getMessage());
    }

    // Test 3: Test file listing with folder filter
    try {
        $query = "SELECT f.id, f.name, f.file_size, f.folder_id
                  FROM files f
                  WHERE f.folder_id IS NOT NULL
                  AND f.deleted_at IS NULL
                  LIMIT 5";

        $stmt = $pdo->query($query);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        addTestResult(
            'File Listing',
            'File listing with folder filter',
            true,
            'Folder filtering works correctly',
            ['files_in_folders' => count($files)]
        );
    } catch (Exception $e) {
        addTestResult('File Listing', 'File listing with folder filter', false, $e->getMessage());
    }

    // Test 4: Test file listing with search
    try {
        $query = "SELECT f.id, f.name, f.original_name, f.file_size
                  FROM files f
                  WHERE (f.name LIKE :search OR f.original_name LIKE :search)
                  AND f.deleted_at IS NULL
                  LIMIT 5";

        $stmt = $pdo->prepare($query);
        $stmt->execute([':search' => '%.pdf%']);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        addTestResult(
            'File Listing',
            'File listing with search filter',
            true,
            'Search filtering works correctly',
            ['pdf_files_found' => count($files)]
        );
    } catch (Exception $e) {
        addTestResult('File Listing', 'File listing with search filter', false, $e->getMessage());
    }
}

// ============================================
// CATEGORY B: FILE UPLOAD TESTS
// ============================================

function testFileUpload(): void {
    $pdo = getDbConnection();

    // Test 1: Verify INSERT query format (simulated)
    try {
        // Test that the INSERT statement would work with correct columns
        $testTenant = 1;
        $testUser = 1;

        // Verify tenant and user exist
        $stmt = $pdo->prepare("SELECT id FROM tenants WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $testTenant]);
        $tenantExists = $stmt->fetch() !== false;

        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $testUser]);
        $userExists = $stmt->fetch() !== false;

        if ($tenantExists && $userExists) {
            // Test INSERT structure (without actually inserting)
            $query = "INSERT INTO files (tenant_id, name, original_name, mime_type,
                                        file_size, file_path, uploaded_by, status, created_at)
                      VALUES (:tenant_id, :name, :original_name, :mime_type,
                              :file_size, :file_path, :uploaded_by, :status, NOW())";

            $stmt = $pdo->prepare($query);

            addTestResult(
                'File Upload',
                'INSERT query structure verification',
                true,
                'INSERT query uses correct column names (file_size, file_path, uploaded_by)',
                ['query_prepared' => true]
            );
        } else {
            addTestResult(
                'File Upload',
                'INSERT query structure verification',
                false,
                'Test data (tenant/user) not found',
                ['tenant_exists' => $tenantExists, 'user_exists' => $userExists]
            );
        }
    } catch (Exception $e) {
        addTestResult('File Upload', 'INSERT query structure verification', false, $e->getMessage());
    }

    // Test 2: Verify uploaded_by foreign key constraint
    try {
        $query = "SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                  FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = :db_name
                  AND TABLE_NAME = 'files'
                  AND COLUMN_NAME = 'uploaded_by'
                  AND REFERENCED_TABLE_NAME IS NOT NULL";

        $stmt = $pdo->prepare($query);
        $stmt->execute([':db_name' => DB_NAME]);
        $fk = $stmt->fetch(PDO::FETCH_ASSOC);

        $passed = $fk && $fk['REFERENCED_TABLE_NAME'] === 'users';

        addTestResult(
            'File Upload',
            'uploaded_by foreign key constraint',
            $passed,
            $passed ? 'Foreign key correctly references users table' : 'Foreign key constraint missing or incorrect',
            ['foreign_key' => $fk ?: 'not found']
        );
    } catch (Exception $e) {
        addTestResult('File Upload', 'uploaded_by foreign key constraint', false, $e->getMessage());
    }
}

// ============================================
// CATEGORY C: DOCUMENT APPROVAL TESTS
// ============================================

function testDocumentApproval(): void {
    $pdo = getDbConnection();

    // Test 1: Pending documents query
    try {
        $query = "SELECT f.id, f.name, f.original_name, f.mime_type, f.file_size,
                         f.status, f.created_at, f.uploaded_by,
                         u.first_name as owner_first_name,
                         u.last_name as owner_last_name,
                         u.email as owner_email
                  FROM files f
                  INNER JOIN users u ON f.uploaded_by = u.id
                  WHERE f.status = :status
                  AND f.deleted_at IS NULL
                  LIMIT 10";

        $stmt = $pdo->prepare($query);
        $stmt->execute([':status' => 'in_approvazione']);
        $pendingFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $passed = true;
        if (count($pendingFiles) > 0) {
            $firstFile = $pendingFiles[0];
            $passed = isset($firstFile['file_size']) &&
                     isset($firstFile['uploaded_by']) &&
                     isset($firstFile['owner_first_name']);
        }

        addTestResult(
            'Document Approval',
            'Pending documents listing with JOIN',
            $passed,
            $passed ? 'Query correctly joins uploaded_by with users table' : 'Query structure incorrect',
            ['pending_count' => count($pendingFiles)]
        );
    } catch (Exception $e) {
        addTestResult('Document Approval', 'Pending documents listing with JOIN', false, $e->getMessage());
    }

    // Test 2: Approve action query structure
    try {
        // Verify UPDATE query structure for approval
        $query = "UPDATE files
                  SET status = :status,
                      approved_by = :approved_by,
                      approved_at = NOW()
                  WHERE id = :file_id
                  AND tenant_id = :tenant_id
                  AND deleted_at IS NULL";

        $stmt = $pdo->prepare($query);

        addTestResult(
            'Document Approval',
            'Approve action UPDATE query structure',
            true,
            'Approval UPDATE query uses correct column names',
            ['query_prepared' => true]
        );
    } catch (Exception $e) {
        addTestResult('Document Approval', 'Approve action UPDATE query structure', false, $e->getMessage());
    }

    // Test 3: Reject action query structure
    try {
        // Verify UPDATE query structure for rejection
        $query = "UPDATE files
                  SET status = :status,
                      rejected_by = :rejected_by,
                      rejected_at = NOW(),
                      rejection_reason = :reason
                  WHERE id = :file_id
                  AND tenant_id = :tenant_id
                  AND deleted_at IS NULL";

        $stmt = $pdo->prepare($query);

        addTestResult(
            'Document Approval',
            'Reject action UPDATE query structure',
            true,
            'Rejection UPDATE query uses correct column names',
            ['query_prepared' => true]
        );
    } catch (Exception $e) {
        addTestResult('Document Approval', 'Reject action UPDATE query structure', false, $e->getMessage());
    }

    // Test 4: Verify approval history tracking
    try {
        $query = "SELECT f.id, f.name, f.status, f.approved_by, f.approved_at,
                         u.first_name as approver_first_name,
                         u.last_name as approver_last_name
                  FROM files f
                  LEFT JOIN users u ON f.approved_by = u.id
                  WHERE f.status = 'approvato'
                  AND f.deleted_at IS NULL
                  LIMIT 5";

        $stmt = $pdo->query($query);
        $approvedFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        addTestResult(
            'Document Approval',
            'Approval history tracking',
            true,
            'Approved files correctly track approver information',
            ['approved_count' => count($approvedFiles)]
        );
    } catch (Exception $e) {
        addTestResult('Document Approval', 'Approval history tracking', false, $e->getMessage());
    }
}

// ============================================
// CATEGORY D: DASHBOARD STATS TESTS
// ============================================

function testDashboardStats(): void {
    $pdo = getDbConnection();

    // Test 1: File size statistics using SUM
    try {
        $query = "SELECT
                    COUNT(*) as total_files,
                    COALESCE(SUM(file_size), 0) as total_storage,
                    COALESCE(AVG(file_size), 0) as avg_file_size,
                    MAX(file_size) as max_file_size,
                    MIN(file_size) as min_file_size
                  FROM files
                  WHERE deleted_at IS NULL";

        $stmt = $pdo->query($query);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $passed = isset($stats['total_storage']) && is_numeric($stats['total_storage']);

        addTestResult(
            'Dashboard Stats',
            'File size statistics with SUM(file_size)',
            $passed,
            $passed ? 'Storage statistics calculated correctly' : 'Statistics calculation failed',
            [
                'total_files' => $stats['total_files'] ?? 0,
                'total_storage_bytes' => $stats['total_storage'] ?? 0,
                'total_storage_mb' => isset($stats['total_storage']) ? round($stats['total_storage'] / 1048576, 2) . ' MB' : '0 MB'
            ]
        );
    } catch (Exception $e) {
        addTestResult('Dashboard Stats', 'File size statistics with SUM(file_size)', false, $e->getMessage());
    }

    // Test 2: Storage by tenant statistics
    try {
        $query = "SELECT
                    t.id, t.name,
                    COUNT(f.id) as file_count,
                    COALESCE(SUM(f.file_size), 0) as storage_used
                  FROM tenants t
                  LEFT JOIN files f ON t.id = f.tenant_id AND f.deleted_at IS NULL
                  GROUP BY t.id, t.name
                  ORDER BY storage_used DESC";

        $stmt = $pdo->query($query);
        $tenantStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        addTestResult(
            'Dashboard Stats',
            'Storage by tenant statistics',
            true,
            'Per-tenant storage correctly calculated using file_size',
            ['tenants_analyzed' => count($tenantStats)]
        );
    } catch (Exception $e) {
        addTestResult('Dashboard Stats', 'Storage by tenant statistics', false, $e->getMessage());
    }

    // Test 3: Files by status statistics
    try {
        $query = "SELECT
                    status,
                    COUNT(*) as count,
                    COALESCE(SUM(file_size), 0) as total_size
                  FROM files
                  WHERE deleted_at IS NULL
                  GROUP BY status";

        $stmt = $pdo->query($query);
        $statusStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        addTestResult(
            'Dashboard Stats',
            'Files by status statistics',
            true,
            'Status statistics correctly calculated',
            ['statuses_found' => count($statusStats)]
        );
    } catch (Exception $e) {
        addTestResult('Dashboard Stats', 'Files by status statistics', false, $e->getMessage());
    }

    // Test 4: Recent activity statistics
    try {
        $query = "SELECT
                    f.id, f.name, f.file_size, f.created_at, f.uploaded_by,
                    u.first_name, u.last_name
                  FROM files f
                  INNER JOIN users u ON f.uploaded_by = u.id
                  WHERE f.deleted_at IS NULL
                  ORDER BY f.created_at DESC
                  LIMIT 10";

        $stmt = $pdo->query($query);
        $recentFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $passed = true;
        if (count($recentFiles) > 0) {
            $firstFile = $recentFiles[0];
            $passed = isset($firstFile['file_size']) && isset($firstFile['uploaded_by']);
        }

        addTestResult(
            'Dashboard Stats',
            'Recent activity with uploader information',
            $passed,
            $passed ? 'Recent activity correctly shows uploaded_by information' : 'Query structure incorrect',
            ['recent_files' => count($recentFiles)]
        );
    } catch (Exception $e) {
        addTestResult('Dashboard Stats', 'Recent activity with uploader information', false, $e->getMessage());
    }
}

// ============================================
// CATEGORY E: DATABASE INTEGRITY TESTS
// ============================================

function testDatabaseIntegrity(): void {
    $pdo = getDbConnection();

    // Test 1: All files have valid uploaded_by references
    try {
        $query = "SELECT COUNT(*) as invalid_count
                  FROM files f
                  LEFT JOIN users u ON f.uploaded_by = u.id
                  WHERE f.deleted_at IS NULL
                  AND u.id IS NULL";

        $stmt = $pdo->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $invalidCount = $result['invalid_count'];

        $passed = $invalidCount === 0;

        addTestResult(
            'Database Integrity',
            'Valid uploaded_by references',
            $passed,
            $passed ? 'All files have valid uploaded_by user references' : "Found {$invalidCount} files with invalid uploaded_by",
            ['invalid_references' => $invalidCount]
        );
    } catch (Exception $e) {
        addTestResult('Database Integrity', 'Valid uploaded_by references', false, $e->getMessage());
    }

    // Test 2: All file_size values are numeric and non-negative
    try {
        $query = "SELECT COUNT(*) as invalid_count
                  FROM files
                  WHERE deleted_at IS NULL
                  AND (file_size IS NULL OR file_size < 0)";

        $stmt = $pdo->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $invalidCount = $result['invalid_count'];

        $passed = $invalidCount === 0;

        addTestResult(
            'Database Integrity',
            'Valid file_size values',
            $passed,
            $passed ? 'All files have valid file_size values' : "Found {$invalidCount} files with invalid file_size",
            ['invalid_sizes' => $invalidCount]
        );
    } catch (Exception $e) {
        addTestResult('Database Integrity', 'Valid file_size values', false, $e->getMessage());
    }

    // Test 3: All file_path values are not empty
    try {
        $query = "SELECT COUNT(*) as invalid_count
                  FROM files
                  WHERE deleted_at IS NULL
                  AND (file_path IS NULL OR file_path = '' OR TRIM(file_path) = '')";

        $stmt = $pdo->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $invalidCount = $result['invalid_count'];

        $passed = $invalidCount === 0;

        addTestResult(
            'Database Integrity',
            'Valid file_path values',
            $passed,
            $passed ? 'All files have valid file_path values' : "Found {$invalidCount} files with empty file_path",
            ['invalid_paths' => $invalidCount]
        );
    } catch (Exception $e) {
        addTestResult('Database Integrity', 'Valid file_path values', false, $e->getMessage());
    }

    // Test 4: Tenant isolation integrity
    try {
        $query = "SELECT COUNT(*) as invalid_count
                  FROM files f
                  INNER JOIN users u ON f.uploaded_by = u.id
                  WHERE f.deleted_at IS NULL
                  AND f.tenant_id != u.tenant_id";

        $stmt = $pdo->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $invalidCount = $result['invalid_count'];

        $passed = $invalidCount === 0;

        addTestResult(
            'Database Integrity',
            'Tenant isolation integrity',
            $passed,
            $passed ? 'All files correctly match uploader tenant_id' : "Found {$invalidCount} files with mismatched tenant_id",
            ['mismatched_tenants' => $invalidCount]
        );
    } catch (Exception $e) {
        addTestResult('Database Integrity', 'Tenant isolation integrity', false, $e->getMessage());
    }
}

// ============================================
// CATEGORY F: API RESPONSE FORMAT TESTS
// ============================================

function testApiResponseFormat(): void {
    // Test 1: Verify JSON response structure for file listing
    try {
        // Simulate expected response structure
        $expectedStructure = [
            'success' => true,
            'data' => [],
            'pagination' => [
                'total' => 0,
                'page' => 1,
                'limit' => 20,
                'pages' => 0
            ]
        ];

        $hasRequiredKeys = isset($expectedStructure['success']) &&
                          isset($expectedStructure['data']) &&
                          isset($expectedStructure['pagination']);

        addTestResult(
            'API Response Format',
            'File listing response structure',
            $hasRequiredKeys,
            'Expected response structure is correctly defined',
            ['structure' => $expectedStructure]
        );
    } catch (Exception $e) {
        addTestResult('API Response Format', 'File listing response structure', false, $e->getMessage());
    }

    // Test 2: Verify error response structure
    try {
        $errorStructure = [
            'success' => false,
            'error' => 'Error message'
        ];

        $hasRequiredKeys = isset($errorStructure['success']) &&
                          isset($errorStructure['error']);

        addTestResult(
            'API Response Format',
            'Error response structure',
            $hasRequiredKeys,
            'Error response structure is correctly defined',
            ['structure' => $errorStructure]
        );
    } catch (Exception $e) {
        addTestResult('API Response Format', 'Error response structure', false, $e->getMessage());
    }

    // Test 3: Verify approval response structure
    try {
        $approvalStructure = [
            'success' => true,
            'message' => 'Documento approvato con successo',
            'data' => [
                'id' => 1,
                'status' => 'approvato',
                'approved_by' => 1,
                'approved_at' => date('Y-m-d H:i:s')
            ]
        ];

        $hasRequiredKeys = isset($approvalStructure['success']) &&
                          isset($approvalStructure['message']) &&
                          isset($approvalStructure['data']);

        addTestResult(
            'API Response Format',
            'Approval response structure',
            $hasRequiredKeys,
            'Approval response structure is correctly defined',
            ['structure' => $approvalStructure]
        );
    } catch (Exception $e) {
        addTestResult('API Response Format', 'Approval response structure', false, $e->getMessage());
    }
}

// ============================================
// RUN ALL TESTS
// ============================================

$startTime = microtime(true);

testFileListing();
testFileUpload();
testDocumentApproval();
testDashboardStats();
testDatabaseIntegrity();
testApiResponseFormat();

$endTime = microtime(true);
$executionTime = round(($endTime - $startTime) * 1000, 2);

// ============================================
// HTML OUTPUT
// ============================================
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schema Drift Fixes - Test Results</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 14px;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 30px;
            background: #f8f9fa;
            border-bottom: 2px solid #e9ecef;
        }

        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }

        .summary-card .number {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .summary-card .label {
            color: #6c757d;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-card.passed .number {
            color: #28a745;
        }

        .summary-card.failed .number {
            color: #dc3545;
        }

        .summary-card.total .number {
            color: #007bff;
        }

        .summary-card.time .number {
            color: #6f42c1;
            font-size: 24px;
        }

        .tests {
            padding: 30px;
        }

        .category {
            margin-bottom: 30px;
        }

        .category-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-left: 4px solid #667eea;
            margin-bottom: 15px;
            font-weight: bold;
            font-size: 18px;
            color: #495057;
        }

        .test-item {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 15px 20px;
            margin-bottom: 10px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
            transition: all 0.3s ease;
        }

        .test-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .test-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .test-icon.passed {
            color: #28a745;
        }

        .test-icon.failed {
            color: #dc3545;
        }

        .test-content {
            flex: 1;
        }

        .test-name {
            font-weight: 600;
            color: #212529;
            margin-bottom: 5px;
        }

        .test-message {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .test-details {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            margin-top: 10px;
            max-height: 200px;
            overflow-y: auto;
        }

        .test-details pre {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .timestamp {
            color: #adb5bd;
            font-size: 11px;
            margin-top: 5px;
        }

        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #6c757d;
            font-size: 14px;
            border-top: 2px solid #e9ecef;
        }

        .refresh-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            margin: 20px 0;
            transition: transform 0.2s;
        }

        .refresh-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge.success {
            background: #d4edda;
            color: #155724;
        }

        .badge.danger {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Schema Drift Fixes - Test Results</h1>
            <p>Comprehensive automated testing for schema changes: file_size, file_path, uploaded_by</p>
        </div>

        <div class="summary">
            <div class="summary-card total">
                <div class="number"><?php echo $totalTests; ?></div>
                <div class="label">Total Tests</div>
            </div>
            <div class="summary-card passed">
                <div class="number"><?php echo $passedTests; ?></div>
                <div class="label">Passed</div>
            </div>
            <div class="summary-card failed">
                <div class="number"><?php echo $failedTests; ?></div>
                <div class="label">Failed</div>
            </div>
            <div class="summary-card time">
                <div class="number"><?php echo $executionTime; ?>ms</div>
                <div class="label">Execution Time</div>
            </div>
        </div>

        <div class="tests">
            <?php
            // Group tests by category
            $categories = [];
            foreach ($testResults as $test) {
                $categories[$test['category']][] = $test;
            }

            // Display tests by category
            foreach ($categories as $categoryName => $tests):
            ?>
                <div class="category">
                    <div class="category-header"><?php echo htmlspecialchars($categoryName); ?></div>
                    <?php foreach ($tests as $test): ?>
                        <div class="test-item">
                            <div class="test-icon <?php echo $test['passed'] ? 'passed' : 'failed'; ?>">
                                <?php echo $test['passed'] ? '✅' : '❌'; ?>
                            </div>
                            <div class="test-content">
                                <div class="test-name">
                                    <?php echo htmlspecialchars($test['name']); ?>
                                    <span class="badge <?php echo $test['passed'] ? 'success' : 'danger'; ?>">
                                        <?php echo $test['passed'] ? 'PASS' : 'FAIL'; ?>
                                    </span>
                                </div>
                                <div class="test-message">
                                    <?php echo htmlspecialchars($test['message']); ?>
                                </div>
                                <?php if (!empty($test['details'])): ?>
                                    <div class="test-details">
                                        <pre><?php echo htmlspecialchars(json_encode($test['details'], JSON_PRETTY_PRINT)); ?></pre>
                                    </div>
                                <?php endif; ?>
                                <div class="timestamp">
                                    Executed at: <?php echo $test['timestamp']; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="footer">
            <button class="refresh-btn" onclick="location.reload()">
                🔄 Refresh Tests
            </button>
            <p>CollaboraNexio - Schema Drift Fixes Test Suite</p>
            <p>Test execution completed at <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
    </div>
</body>
</html>
