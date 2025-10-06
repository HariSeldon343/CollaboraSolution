<?php
// DEBUG VERSION - Detailed error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Start output buffering to catch any warnings
ob_start();

// Set JSON header first
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Debug output function
function debug_output($message, $data = null) {
    $output = [
        'debug' => true,
        'message' => $message,
        'data' => $data,
        'session' => [
            'session_id' => session_id(),
            'user_id' => $_SESSION['user_id'] ?? 'NOT SET',
            'tenant_id' => $_SESSION['tenant_id'] ?? 'NOT SET',
            'role' => $_SESSION['role'] ?? 'NOT SET'
        ]
    ];

    // Clear any buffered output
    ob_clean();
    echo json_encode($output);
    exit;
}

// Check session
if (!isset($_SESSION['user_id'])) {
    debug_output('No user_id in session', $_SESSION);
}

// Try to load config
try {
    if (!file_exists('../config.php')) {
        debug_output('Config file not found', ['path' => realpath('../config.php')]);
    }
    require_once '../config.php';
} catch (Exception $e) {
    debug_output('Error loading config', ['error' => $e->getMessage()]);
}

// Try to load database
try {
    if (!file_exists('../includes/db.php')) {
        debug_output('Database file not found', ['path' => realpath('../includes/db.php')]);
    }
    require_once '../includes/db.php';
} catch (Exception $e) {
    debug_output('Error loading database', ['error' => $e->getMessage()]);
}

// Try to get database connection
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    if (!$pdo) {
        debug_output('Could not get PDO connection');
    }
} catch (Exception $e) {
    debug_output('Database connection error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

// Test database connection
try {
    $test = $pdo->query("SELECT 1");
    if (!$test) {
        debug_output('Database query test failed');
    }
} catch (PDOException $e) {
    debug_output('Database test query error', [
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
}

// Check tables existence
try {
    $tables_check = [];
    $check_tables = ['files', 'folders', 'tenants', 'users'];

    foreach ($check_tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $tables_check[$table] = $stmt->rowCount() > 0 ? 'EXISTS' : 'MISSING';
    }

    // Check if any table is missing
    if (in_array('MISSING', $tables_check)) {
        debug_output('Required tables missing', $tables_check);
    }
} catch (PDOException $e) {
    debug_output('Error checking tables', [
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
}

// Check columns in files table
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM files");
    $file_columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $file_columns[] = $row['Field'];
    }

    // Check columns in folders table
    $stmt = $pdo->query("SHOW COLUMNS FROM folders");
    $folder_columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $folder_columns[] = $row['Field'];
    }
} catch (PDOException $e) {
    debug_output('Error checking columns', [
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'sql_state' => $e->errorInfo[0] ?? null
    ]);
}

// Get request parameters
$action = $_GET['action'] ?? '';
$folder_id = $_GET['folder_id'] ?? null;
$search = $_GET['search'] ?? '';

// Session variables
$user_id = $_SESSION['user_id'] ?? null;
$tenant_id = $_SESSION['tenant_id'] ?? null;
$user_role = $_SESSION['role'] ?? 'user';

if (!$user_id || !$tenant_id) {
    debug_output('Missing session variables', [
        'user_id' => $user_id,
        'tenant_id' => $tenant_id,
        'role' => $user_role
    ]);
}

// Try simple query first
try {
    // Test 1: Simple folders query
    $test_query = "SELECT * FROM folders WHERE deleted_at IS NULL LIMIT 1";
    $stmt = $pdo->query($test_query);
    $test_result = $stmt->fetch(PDO::FETCH_ASSOC);

    // Test 2: Simple files query
    $test_query2 = "SELECT * FROM files WHERE deleted_at IS NULL LIMIT 1";
    $stmt2 = $pdo->query($test_query2);
    $test_result2 = $stmt2->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    debug_output('Simple query failed', [
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'sql_state' => $e->errorInfo[0] ?? null,
        'driver_error' => $e->errorInfo[1] ?? null,
        'driver_message' => $e->errorInfo[2] ?? null
    ]);
}

// Now try the actual list query with detailed error handling
if ($action === 'list') {
    try {
        // Build folder query
        $folder_query = "
            SELECT
                f.id,
                f.name,
                f.parent_id,
                f.tenant_id,
                f.created_at,
                f.updated_at,
                'folder' as type,
                NULL as size,
                NULL as mime_type,
                t.name as tenant_name,
                0 as subfolder_count,
                0 as file_count
            FROM folders f
            LEFT JOIN tenants t ON f.tenant_id = t.id
            WHERE f.deleted_at IS NULL
        ";

        $params = [];

        // Handle folder_id parameter
        if ($folder_id !== null && $folder_id !== '') {
            $folder_query .= " AND f.parent_id = :folder_id";
            $params[':folder_id'] = $folder_id;
        } else {
            $folder_query .= " AND f.parent_id IS NULL";
        }

        // Add tenant filter for non-super_admin
        if ($user_role !== 'super_admin') {
            $folder_query .= " AND f.tenant_id = :tenant_id";
            $params[':tenant_id'] = $tenant_id;
        }

        // Execute folder query
        $stmt = $pdo->prepare($folder_query);
        $stmt->execute($params);
        $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build file query - simpler version
        $files = [];
        if ($folder_id !== null && $folder_id !== '') {
            // Check what columns exist in files table
            $name_column = 'name'; // default
            if (in_array('file_name', $file_columns ?? [])) {
                $name_column = 'file_name';
            } elseif (in_array('filename', $file_columns ?? [])) {
                $name_column = 'filename';
            }

            $size_column = 'size'; // default
            if (in_array('file_size', $file_columns ?? [])) {
                $size_column = 'file_size';
            } elseif (in_array('size_bytes', $file_columns ?? [])) {
                $size_column = 'size_bytes';
            }

            $file_query = "
                SELECT
                    f.id,
                    f.$name_column as name,
                    f.folder_id as parent_id,
                    f.tenant_id,
                    f.created_at,
                    f.updated_at,
                    'file' as type,
                    f.$size_column as size,
                    " . (in_array('mime_type', $file_columns ?? []) ? "f.mime_type" : "NULL as mime_type") . ",
                    t.name as tenant_name,
                    0 as subfolder_count,
                    0 as file_count
                FROM files f
                LEFT JOIN tenants t ON f.tenant_id = t.id
                WHERE f.deleted_at IS NULL
                AND f.folder_id = :folder_id
            ";

            if ($user_role !== 'super_admin') {
                $file_query .= " AND f.tenant_id = :tenant_id";
            }

            $stmt = $pdo->prepare($file_query);
            $stmt->execute($params);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Combine results
        $items = array_merge($folders, $files);

        // Clear output buffer and send success response
        ob_clean();
        echo json_encode([
            'success' => true,
            'data' => [
                'items' => $items,
                'breadcrumb' => [],
                'current_folder' => null,
                'user_role' => $user_role,
                'can_create_root' => in_array($user_role, ['admin', 'super_admin']),
                'debug_info' => [
                    'folder_count' => count($folders),
                    'file_count' => count($files),
                    'folder_id' => $folder_id,
                    'tenant_id' => $tenant_id,
                    'file_columns' => $file_columns ?? [],
                    'folder_columns' => $folder_columns ?? []
                ]
            ]
        ]);
        exit;

    } catch (PDOException $e) {
        debug_output('List query failed', [
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
            'sql_state' => $e->errorInfo[0] ?? null,
            'driver_error' => $e->errorInfo[1] ?? null,
            'driver_message' => $e->errorInfo[2] ?? null,
            'query_params' => $params ?? null
        ]);
    } catch (Exception $e) {
        debug_output('Unexpected error in list', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
} else {
    // Not a list action
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => 'Debug mode - action not list',
        'action_received' => $action
    ]);
}

// Clean up any remaining output
ob_end_clean();
?>