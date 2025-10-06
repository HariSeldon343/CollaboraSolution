<?php
/**
 * CollaboraNexio Deployment Status Dashboard
 * Real-time monitoring of deployment health
 * PHP 8.3 Compatible
 */

declare(strict_types=1);

// Security check - this should only be accessible locally or with auth
$allowedIPs = ['127.0.0.1', '::1', '192.168.1.0/24'];
$clientIP = $_SERVER['REMOTE_ADDR'] ?? '';

$allowed = false;
foreach ($allowedIPs as $ip) {
    if (str_contains($ip, '/')) {
        // CIDR notation
        [$subnet, $mask] = explode('/', $ip);
        if ((ip2long($clientIP) & ~((1 << (32 - $mask)) - 1)) == ip2long($subnet)) {
            $allowed = true;
            break;
        }
    } elseif ($clientIP === $ip) {
        $allowed = true;
        break;
    }
}

if (!$allowed) {
    header('HTTP/1.1 403 Forbidden');
    die('Access Denied');
}

// Load configuration
$configFile = __DIR__ . '/config.php';
$productionConfigFile = __DIR__ . '/config.production.php';
$configExists = file_exists($configFile);
$isProduction = file_exists($productionConfigFile) &&
                filemtime($productionConfigFile) > filemtime($configFile);

// System information
$phpVersion = PHP_VERSION;
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
$serverTime = date('Y-m-d H:i:s');
$timezone = date_default_timezone_get();

// Check deployment logs
$logsDir = __DIR__ . '/logs';
$deploymentLogs = [];
if (is_dir($logsDir)) {
    $files = glob($logsDir . '/deploy_*.log');
    rsort($files);
    $deploymentLogs = array_slice($files, 0, 5);
}

// Check backup status
$backupsDir = __DIR__ . '/backups';
$backups = [];
if (is_dir($backupsDir)) {
    $files = glob($backupsDir . '/*.zip');
    rsort($files);
    foreach (array_slice($files, 0, 10) as $file) {
        $backups[] = [
            'name' => basename($file),
            'size' => filesize($file),
            'date' => date('Y-m-d H:i:s', filemtime($file))
        ];
    }
}

// Run quick health checks
$healthChecks = [];

// PHP Version
$healthChecks['PHP Version'] = [
    'status' => version_compare($phpVersion, '8.3.0', '>=') ? 'ok' : 'warning',
    'value' => $phpVersion,
    'required' => '8.3.0+'
];

// Configuration
$healthChecks['Configuration'] = [
    'status' => $configExists ? 'ok' : 'error',
    'value' => $configExists ? ($isProduction ? 'Production' : 'Development') : 'Missing',
    'required' => 'config.php'
];

// Database
$dbStatus = 'unknown';
$dbVersion = 'N/A';
if ($configExists) {
    require_once $configFile;
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s', DB_HOST, DB_NAME),
            DB_USER,
            DB_PASS
        );
        $stmt = $pdo->query('SELECT VERSION()');
        $dbVersion = $stmt->fetchColumn();
        $dbStatus = 'ok';
    } catch (Exception $e) {
        $dbStatus = 'error';
    }
}

$healthChecks['Database'] = [
    'status' => $dbStatus,
    'value' => $dbStatus === 'ok' ? "Connected (MySQL $dbVersion)" : 'Connection Failed',
    'required' => 'MySQL Connection'
];

// OPcache
$opcacheStatus = 'disabled';
if (function_exists('opcache_get_status')) {
    $opcache = opcache_get_status(false);
    if ($opcache && isset($opcache['opcache_enabled']) && $opcache['opcache_enabled']) {
        $opcacheStatus = 'enabled';
        $memUsed = $opcache['memory_usage']['used_memory'];
        $memFree = $opcache['memory_usage']['free_memory'];
        $memUsage = round(($memUsed / ($memUsed + $memFree)) * 100, 1);
        $opcacheStatus .= " ($memUsage% memory used)";
    }
}

$healthChecks['OPcache'] = [
    'status' => str_contains($opcacheStatus, 'enabled') ? 'ok' : 'warning',
    'value' => $opcacheStatus,
    'required' => 'Recommended'
];

// Disk Space
$freeSpace = disk_free_space(__DIR__);
$totalSpace = disk_total_space(__DIR__);
$usedPercent = round((($totalSpace - $freeSpace) / $totalSpace) * 100, 1);
$freeSpaceGB = round($freeSpace / 1073741824, 2);

$healthChecks['Disk Space'] = [
    'status' => $freeSpaceGB > 1 ? 'ok' : ($freeSpaceGB > 0.5 ? 'warning' : 'error'),
    'value' => "$freeSpaceGB GB free ($usedPercent% used)",
    'required' => '>500 MB free'
];

// SSL/HTTPS
$isHTTPS = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
           ($_SERVER['SERVER_PORT'] == 443);

$healthChecks['HTTPS'] = [
    'status' => $isHTTPS ? 'ok' : 'warning',
    'value' => $isHTTPS ? 'Enabled' : 'Disabled',
    'required' => 'Recommended for production'
];

// Last smoke test
$smokeTestResult = 'Not run';
$smokeTestFiles = glob($logsDir . '/smoke_test_*.json');
if (!empty($smokeTestFiles)) {
    rsort($smokeTestFiles);
    $lastTest = json_decode(file_get_contents($smokeTestFiles[0]), true);
    if ($lastTest) {
        $smokeTestResult = sprintf(
            '%s - %d/%d passed (%.1f%%)',
            $lastTest['timestamp'] ?? 'Unknown',
            $lastTest['passed'] ?? 0,
            $lastTest['total_tests'] ?? 0,
            $lastTest['success_rate'] ?? 0
        );
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollaboraNexio - Deployment Status</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header .subtitle {
            color: #666;
            font-size: 14px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .card h2 {
            color: #333;
            font-size: 18px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f5f5f5;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #666;
            font-size: 14px;
        }

        .info-value {
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }

        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status.ok {
            background: #d4edda;
            color: #155724;
        }

        .status.warning {
            background: #fff3cd;
            color: #856404;
        }

        .status.error {
            background: #f8d7da;
            color: #721c24;
        }

        .status.unknown {
            background: #e2e3e5;
            color: #383d41;
        }

        .health-check {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .health-check:last-child {
            border-bottom: none;
        }

        .health-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .health-indicator.ok {
            background: #28a745;
        }

        .health-indicator.warning {
            background: #ffc107;
        }

        .health-indicator.error {
            background: #dc3545;
        }

        .health-indicator.unknown {
            background: #6c757d;
        }

        .health-details {
            flex-grow: 1;
        }

        .health-name {
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }

        .health-value {
            color: #666;
            font-size: 12px;
            margin-top: 2px;
        }

        .log-list {
            max-height: 200px;
            overflow-y: auto;
        }

        .log-item {
            padding: 8px;
            background: #f8f9fa;
            margin-bottom: 8px;
            border-radius: 4px;
            font-size: 13px;
            color: #666;
            word-break: break-all;
        }

        .backup-item {
            display: flex;
            justify-content: space-between;
            padding: 10px;
            background: #f8f9fa;
            margin-bottom: 8px;
            border-radius: 4px;
        }

        .backup-name {
            font-size: 13px;
            color: #333;
            font-weight: 500;
        }

        .backup-meta {
            font-size: 11px;
            color: #666;
            margin-top: 4px;
        }

        .backup-size {
            font-size: 12px;
            color: #666;
        }

        .refresh-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            float: right;
            margin-top: -5px;
        }

        .refresh-btn:hover {
            background: #5a67d8;
        }

        .deployment-mode {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-weight: 600;
            margin-left: 20px;
        }

        .deployment-mode.production {
            background: #d4edda;
            color: #155724;
        }

        .deployment-mode.development {
            background: #fff3cd;
            color: #856404;
        }

        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <button class="refresh-btn" onclick="location.reload()">Refresh</button>
            <h1>
                CollaboraNexio Deployment Status
                <?php if ($isProduction): ?>
                    <span class="deployment-mode production">PRODUCTION</span>
                <?php else: ?>
                    <span class="deployment-mode development">DEVELOPMENT</span>
                <?php endif; ?>
            </h1>
            <div class="subtitle">
                Server Time: <?php echo $serverTime; ?> (<?php echo $timezone; ?>) |
                PHP <?php echo $phpVersion; ?> |
                <?php echo $serverSoftware; ?>
            </div>
        </div>

        <div class="grid">
            <!-- System Health -->
            <div class="card">
                <h2>System Health</h2>
                <?php foreach ($healthChecks as $name => $check): ?>
                    <div class="health-check">
                        <div class="health-indicator <?php echo $check['status']; ?>"></div>
                        <div class="health-details">
                            <div class="health-name"><?php echo $name; ?></div>
                            <div class="health-value">
                                <?php echo $check['value']; ?>
                                <span style="color: #999;">(<?php echo $check['required']; ?>)</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Deployment Info -->
            <div class="card">
                <h2>Deployment Information</h2>
                <div class="info-row">
                    <span class="info-label">Environment:</span>
                    <span class="info-value">
                        <span class="status <?php echo $isProduction ? 'ok' : 'warning'; ?>">
                            <?php echo $isProduction ? 'Production' : 'Development'; ?>
                        </span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Config File:</span>
                    <span class="info-value"><?php echo $configExists ? 'Present' : 'Missing'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Last Modified:</span>
                    <span class="info-value">
                        <?php echo $configExists ? date('Y-m-d H:i:s', filemtime($configFile)) : 'N/A'; ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Document Root:</span>
                    <span class="info-value" style="font-size: 12px;"><?php echo __DIR__; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Last Smoke Test:</span>
                    <span class="info-value" style="font-size: 12px;"><?php echo $smokeTestResult; ?></span>
                </div>
            </div>

            <!-- Recent Deployments -->
            <div class="card">
                <h2>Recent Deployment Logs</h2>
                <div class="log-list">
                    <?php if (empty($deploymentLogs)): ?>
                        <div class="log-item">No deployment logs found</div>
                    <?php else: ?>
                        <?php foreach ($deploymentLogs as $log): ?>
                            <div class="log-item">
                                <?php echo basename($log); ?>
                                <div class="backup-meta">
                                    <?php echo date('Y-m-d H:i:s', filemtime($log)); ?> |
                                    <?php echo number_format(filesize($log) / 1024, 1); ?> KB
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Backups -->
            <div class="card">
                <h2>Recent Backups</h2>
                <div class="log-list">
                    <?php if (empty($backups)): ?>
                        <div class="log-item">No backups found</div>
                    <?php else: ?>
                        <?php foreach ($backups as $backup): ?>
                            <div class="backup-item">
                                <div>
                                    <div class="backup-name"><?php echo $backup['name']; ?></div>
                                    <div class="backup-meta">
                                        <?php echo $backup['date']; ?>
                                    </div>
                                </div>
                                <div class="backup-size">
                                    <?php echo number_format($backup['size'] / 1048576, 1); ?> MB
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card">
            <h2>Quick Actions</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                <button class="refresh-btn" onclick="runSmokeTest()">Run Smoke Test</button>
                <button class="refresh-btn" style="background: #28a745;" onclick="viewLogs()">View Logs</button>
                <button class="refresh-btn" style="background: #ffc107;" onclick="clearCache()">Clear Cache</button>
                <button class="refresh-btn" style="background: #dc3545;" onclick="if(confirm('Are you sure?')) runRollback()">Emergency Rollback</button>
            </div>
        </div>
    </div>

    <script>
        function runSmokeTest() {
            if (confirm('Run smoke tests now?')) {
                window.location.href = 'smoke_test.php';
            }
        }

        function viewLogs() {
            window.open('logs/', '_blank');
        }

        function clearCache() {
            if (confirm('Clear all cache files?')) {
                fetch('api/cache.php?action=clear', {method: 'POST'})
                    .then(() => {
                        alert('Cache cleared successfully');
                        location.reload();
                    })
                    .catch(err => alert('Error clearing cache'));
            }
        }

        function runRollback() {
            alert('Please run rollback.bat from the command line for safety.');
        }

        // Auto-refresh every 30 seconds
        setTimeout(() => location.reload(), 30000);
    </script>
</body>
</html>