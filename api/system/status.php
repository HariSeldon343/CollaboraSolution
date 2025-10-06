<?php
/**
 * API Endpoint: System Status
 * Returns system health status without authentication for monitoring purposes
 */

// Include API response handler first - this sets up proper error handling
require_once '../../includes/api_response.php';

try {
    $status = [
        'status' => 'operational',
        'timestamp' => time(),
        'components' => []
    ];

    // Check database connection
    try {
        require_once '../../includes/db.php';
        $db = Database::getInstance();
        $conn = $db->getConnection();

        // Simple query to test connection
        $conn->query("SELECT 1");

        $status['components']['database'] = [
            'status' => 'operational',
            'message' => 'Database connection successful'
        ];

        // Get database stats
        $tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $status['components']['database']['tables_count'] = count($tables);

        // Check if main tables exist
        $requiredTables = ['users', 'tenants', 'audit_logs'];
        $missingTables = array_diff($requiredTables, $tables);

        if (!empty($missingTables)) {
            $status['components']['database']['status'] = 'degraded';
            $status['components']['database']['missing_tables'] = $missingTables;
            $status['components']['database']['message'] = 'Some required tables are missing';
            $status['status'] = 'degraded';
        }

    } catch (Exception $e) {
        $status['components']['database'] = [
            'status' => 'error',
            'message' => 'Database connection failed',
            'error' => DEBUG_MODE ? $e->getMessage() : 'Connection error'
        ];
        $status['status'] = 'error';
    }

    // Check PHP version
    $status['components']['php'] = [
        'status' => 'operational',
        'version' => PHP_VERSION,
        'required' => '8.3.0'
    ];

    if (version_compare(PHP_VERSION, '8.3.0', '<')) {
        $status['components']['php']['status'] = 'warning';
        $status['components']['php']['message'] = 'PHP version is below recommended 8.3.0';
    }

    // Check required PHP extensions
    $requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'session', 'mbstring'];
    $missingExtensions = [];

    foreach ($requiredExtensions as $ext) {
        if (!extension_loaded($ext)) {
            $missingExtensions[] = $ext;
        }
    }

    if (!empty($missingExtensions)) {
        $status['components']['php']['missing_extensions'] = $missingExtensions;
        $status['components']['php']['status'] = 'error';
        $status['status'] = 'error';
    }

    // Check configuration file
    $configFile = dirname(dirname(dirname(__FILE__))) . '/config.php';
    if (file_exists($configFile)) {
        $status['components']['configuration'] = [
            'status' => 'operational',
            'config_file' => 'Found'
        ];
    } else {
        $status['components']['configuration'] = [
            'status' => 'error',
            'config_file' => 'Missing',
            'message' => 'Configuration file not found'
        ];
        $status['status'] = 'error';
    }

    // Check write permissions on important directories
    $writableDirs = [
        'logs' => dirname(dirname(dirname(__FILE__))) . '/logs',
        'uploads' => dirname(dirname(dirname(__FILE__))) . '/uploads'
    ];

    $status['components']['filesystem'] = [
        'status' => 'operational',
        'directories' => []
    ];

    foreach ($writableDirs as $name => $dir) {
        if (is_dir($dir)) {
            if (is_writable($dir)) {
                $status['components']['filesystem']['directories'][$name] = 'writable';
            } else {
                $status['components']['filesystem']['directories'][$name] = 'not writable';
                $status['components']['filesystem']['status'] = 'warning';
            }
        } else {
            $status['components']['filesystem']['directories'][$name] = 'not found';
        }
    }

    // Overall status determination
    if ($status['status'] === 'operational') {
        $message = 'All systems operational';
    } elseif ($status['status'] === 'degraded') {
        $message = 'System is operational with minor issues';
    } else {
        $message = 'System has critical issues';
    }

    // Return status
    api_success($status, $message);

} catch (Exception $e) {
    error_log('System Status Error: ' . $e->getMessage());
    api_error('Failed to retrieve system status', 500);
}