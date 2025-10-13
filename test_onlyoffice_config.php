<?php
/**
 * OnlyOffice Configuration Diagnostic Script
 *
 * Tests and displays all critical OnlyOffice configuration values
 * to diagnose the editor error issue.
 */

declare(strict_types=1);

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Load configuration
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/onlyoffice_config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnlyOffice Configuration Diagnostic</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
        }
        h2 {
            color: #34495e;
            background: #ecf0f1;
            padding: 10px;
            margin-top: 30px;
            border-left: 4px solid #3498db;
        }
        .section {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .config-item {
            margin: 15px 0;
            padding: 12px;
            background: #f8f9fa;
            border-left: 4px solid #95a5a6;
            border-radius: 4px;
        }
        .config-item.warning {
            border-left-color: #e74c3c;
            background: #fee;
        }
        .config-item.success {
            border-left-color: #27ae60;
            background: #efe;
        }
        .config-item.info {
            border-left-color: #3498db;
            background: #e3f2fd;
        }
        .label {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .value {
            font-family: 'Courier New', monospace;
            background: white;
            padding: 8px;
            border-radius: 4px;
            word-break: break-all;
        }
        .test-command {
            background: #263238;
            color: #aed581;
            padding: 15px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            margin: 10px 0;
            overflow-x: auto;
        }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
        .status.ok {
            background: #27ae60;
            color: white;
        }
        .status.warning {
            background: #f39c12;
            color: white;
        }
        .status.error {
            background: #e74c3c;
            color: white;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #34495e;
            color: white;
        }
        tr:hover {
            background: #f5f5f5;
        }
    </style>
</head>
<body>
    <h1>🔍 OnlyOffice Configuration Diagnostic Report</h1>

    <?php
    // Environment Detection
    $isProduction = defined('PRODUCTION_MODE') ? PRODUCTION_MODE : 'UNDEFINED';
    $environment = defined('ENVIRONMENT') ? ENVIRONMENT : 'UNDEFINED';
    $debugMode = defined('DEBUG_MODE') ? DEBUG_MODE : 'UNDEFINED';
    ?>

    <div class="section">
        <h2>1. Environment Configuration</h2>

        <div class="config-item <?php echo $isProduction === false ? 'success' : 'warning'; ?>">
            <div class="label">PRODUCTION_MODE:</div>
            <div class="value">
                <?php
                if (is_bool($isProduction)) {
                    echo $isProduction ? 'TRUE (Production)' : 'FALSE (Development)';
                    echo ' <span class="status ' . ($isProduction ? 'warning' : 'ok') . '">' .
                         ($isProduction ? 'PROD' : 'DEV') . '</span>';
                } else {
                    echo 'UNDEFINED ❌';
                    echo ' <span class="status error">ERROR</span>';
                }
                ?>
            </div>
        </div>

        <div class="config-item info">
            <div class="label">ENVIRONMENT:</div>
            <div class="value"><?php echo htmlspecialchars($environment); ?></div>
        </div>

        <div class="config-item info">
            <div class="label">DEBUG_MODE:</div>
            <div class="value">
                <?php
                if (is_bool($debugMode)) {
                    echo $debugMode ? 'TRUE (Enabled)' : 'FALSE (Disabled)';
                } else {
                    echo 'UNDEFINED';
                }
                ?>
            </div>
        </div>

        <div class="config-item info">
            <div class="label">HTTP_HOST:</div>
            <div class="value"><?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'NOT SET'); ?></div>
        </div>

        <div class="config-item info">
            <div class="label">SERVER_PORT:</div>
            <div class="value"><?php echo htmlspecialchars($_SERVER['SERVER_PORT'] ?? 'NOT SET'); ?></div>
        </div>
    </div>

    <div class="section">
        <h2>2. Base URL Configuration</h2>

        <div class="config-item <?php echo strpos(BASE_URL, '8888') !== false ? 'success' : 'warning'; ?>">
            <div class="label">BASE_URL:</div>
            <div class="value"><?php echo htmlspecialchars(BASE_URL); ?></div>
        </div>

        <div class="config-item info">
            <div class="label">Expected for Development:</div>
            <div class="value">http://localhost:8888/CollaboraNexio</div>
        </div>
    </div>

    <div class="section">
        <h2>3. OnlyOffice Server Configuration</h2>

        <div class="config-item info">
            <div class="label">ONLYOFFICE_SERVER_URL:</div>
            <div class="value"><?php echo htmlspecialchars(ONLYOFFICE_SERVER_URL); ?></div>
        </div>

        <div class="config-item info">
            <div class="label">ONLYOFFICE_API_URL:</div>
            <div class="value"><?php echo htmlspecialchars(ONLYOFFICE_API_URL); ?></div>
        </div>
    </div>

    <div class="section">
        <h2>4. Critical URLs - Download & Callback</h2>

        <div class="config-item <?php echo strpos(ONLYOFFICE_DOWNLOAD_URL, 'host.docker.internal') !== false && !$isProduction ? 'success' : 'warning'; ?>">
            <div class="label">ONLYOFFICE_DOWNLOAD_URL:</div>
            <div class="value"><?php echo htmlspecialchars(ONLYOFFICE_DOWNLOAD_URL); ?></div>
            <?php if (strpos(ONLYOFFICE_DOWNLOAD_URL, 'host.docker.internal') !== false): ?>
                <div style="margin-top: 10px; color: #27ae60;">
                    ✅ Correctly configured for Docker on Windows (Development)
                </div>
            <?php elseif (!$isProduction): ?>
                <div style="margin-top: 10px; color: #e74c3c;">
                    ⚠️ WARNING: Should use 'host.docker.internal' for Docker to reach XAMPP!
                </div>
            <?php endif; ?>
        </div>

        <div class="config-item <?php echo strpos(ONLYOFFICE_CALLBACK_URL, 'host.docker.internal') !== false && !$isProduction ? 'success' : 'warning'; ?>">
            <div class="label">ONLYOFFICE_CALLBACK_URL:</div>
            <div class="value"><?php echo htmlspecialchars(ONLYOFFICE_CALLBACK_URL); ?></div>
            <?php if (strpos(ONLYOFFICE_CALLBACK_URL, 'host.docker.internal') !== false): ?>
                <div style="margin-top: 10px; color: #27ae60;">
                    ✅ Correctly configured for Docker on Windows (Development)
                </div>
            <?php elseif (!$isProduction): ?>
                <div style="margin-top: 10px; color: #e74c3c;">
                    ⚠️ WARNING: Should use 'host.docker.internal' for Docker to reach XAMPP!
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="section">
        <h2>5. JWT Configuration</h2>

        <div class="config-item info">
            <div class="label">ONLYOFFICE_JWT_ENABLED:</div>
            <div class="value"><?php echo ONLYOFFICE_JWT_ENABLED ? 'TRUE (Enabled)' : 'FALSE (Disabled)'; ?></div>
        </div>

        <div class="config-item info">
            <div class="label">ONLYOFFICE_JWT_SECRET:</div>
            <div class="value">
                <?php
                echo substr(ONLYOFFICE_JWT_SECRET, 0, 20) . '...' . substr(ONLYOFFICE_JWT_SECRET, -10);
                echo ' (Length: ' . strlen(ONLYOFFICE_JWT_SECRET) . ' chars)';
                ?>
            </div>
        </div>

        <div class="config-item warning">
            <div class="label">⚠️ IMPORTANT: JWT Secret Must Match Docker Container</div>
            <div class="value">
                Check your Docker container JWT secret with:<br>
                <div class="test-command">docker exec onlyoffice-document-server env | grep JWT</div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>6. Connectivity Tests</h2>

        <?php
        // Test OnlyOffice connectivity
        $onlyOfficeReachable = false;
        $onlyOfficeError = '';

        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5,
                    'ignore_errors' => true
                ]
            ]);

            $healthUrl = ONLYOFFICE_SERVER_URL . '/healthcheck';
            $response = @file_get_contents($healthUrl, false, $context);

            if ($response !== false) {
                $onlyOfficeReachable = true;
            } else {
                $onlyOfficeError = 'Connection failed';
            }
        } catch (Exception $e) {
            $onlyOfficeError = $e->getMessage();
        }
        ?>

        <div class="config-item <?php echo $onlyOfficeReachable ? 'success' : 'warning'; ?>">
            <div class="label">OnlyOffice Server Health Check:</div>
            <div class="value">
                <?php if ($onlyOfficeReachable): ?>
                    ✅ OnlyOffice Document Server is REACHABLE
                    <span class="status ok">ONLINE</span>
                <?php else: ?>
                    ❌ OnlyOffice Document Server is NOT REACHABLE
                    <span class="status error">OFFLINE</span>
                    <?php if ($onlyOfficeError): ?>
                        <br><br>Error: <?php echo htmlspecialchars($onlyOfficeError); ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>7. Docker Container Test Commands</h2>

        <p><strong>Test if Docker can reach the download endpoint:</strong></p>

        <div class="test-command">
# Test with sample file_id (replace 43 with actual file ID)
docker exec onlyoffice-document-server curl -v \
  "http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=43&token=dummy"
        </div>

        <p><strong>Check JWT secret in Docker container:</strong></p>

        <div class="test-command">
docker exec onlyoffice-document-server cat /etc/onlyoffice/documentserver/local.json | grep -A 2 "secret"
        </div>

        <p><strong>View Docker logs for OnlyOffice errors:</strong></p>

        <div class="test-command">
docker logs onlyoffice-document-server --tail 50
        </div>

        <p><strong>Test network connectivity from Docker to host:</strong></p>

        <div class="test-command">
docker exec onlyoffice-document-server ping -c 4 host.docker.internal
        </div>
    </div>

    <div class="section">
        <h2>8. Browser Console Test</h2>

        <p>To see the exact config being sent to OnlyOffice, open your browser's Developer Console (F12) on the files.php page and run:</p>

        <div class="test-command">
// Check what URL is being sent to OnlyOffice
console.log('Document Config:', window.lastOnlyOfficeConfig);
        </div>
    </div>

    <div class="section">
        <h2>9. File System Checks</h2>

        <?php
        $uploadPath = UPLOAD_PATH;
        $uploadPathExists = is_dir($uploadPath);
        $uploadPathWritable = is_writable($uploadPath);
        ?>

        <div class="config-item <?php echo $uploadPathExists ? 'success' : 'warning'; ?>">
            <div class="label">Upload Directory:</div>
            <div class="value">
                <?php echo htmlspecialchars($uploadPath); ?>
                <?php if ($uploadPathExists): ?>
                    <span class="status ok">EXISTS</span>
                <?php else: ?>
                    <span class="status error">NOT FOUND</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="config-item <?php echo $uploadPathWritable ? 'success' : 'warning'; ?>">
            <div class="label">Upload Directory Writable:</div>
            <div class="value">
                <?php if ($uploadPathWritable): ?>
                    ✅ Yes
                    <span class="status ok">WRITABLE</span>
                <?php else: ?>
                    ❌ No - This will cause problems!
                    <span class="status error">NOT WRITABLE</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>10. Summary & Diagnosis</h2>

        <table>
            <thead>
                <tr>
                    <th>Check</th>
                    <th>Status</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Production Mode</td>
                    <td>
                        <?php if ($isProduction === false): ?>
                            <span class="status ok">✓</span>
                        <?php elseif ($isProduction === true): ?>
                            <span class="status warning">!</span>
                        <?php else: ?>
                            <span class="status error">✗</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        if ($isProduction === false) {
                            echo 'Correctly set to FALSE (Development)';
                        } elseif ($isProduction === true) {
                            echo 'Set to TRUE (Production) - may cause issues in dev';
                        } else {
                            echo 'NOT DEFINED - Critical issue!';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>Download URL</td>
                    <td>
                        <?php if (!$isProduction && strpos(ONLYOFFICE_DOWNLOAD_URL, 'host.docker.internal') !== false): ?>
                            <span class="status ok">✓</span>
                        <?php else: ?>
                            <span class="status error">✗</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        if (!$isProduction && strpos(ONLYOFFICE_DOWNLOAD_URL, 'host.docker.internal') !== false) {
                            echo 'Correctly uses host.docker.internal';
                        } else {
                            echo 'NOT using host.docker.internal - Docker cannot reach XAMPP!';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>OnlyOffice Server</td>
                    <td>
                        <?php if ($onlyOfficeReachable): ?>
                            <span class="status ok">✓</span>
                        <?php else: ?>
                            <span class="status error">✗</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        if ($onlyOfficeReachable) {
                            echo 'OnlyOffice is online and reachable';
                        } else {
                            echo 'OnlyOffice is NOT reachable - Check Docker container';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>Upload Directory</td>
                    <td>
                        <?php if ($uploadPathExists && $uploadPathWritable): ?>
                            <span class="status ok">✓</span>
                        <?php else: ?>
                            <span class="status error">✗</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        if ($uploadPathExists && $uploadPathWritable) {
                            echo 'Directory exists and is writable';
                        } elseif (!$uploadPathExists) {
                            echo 'Directory does not exist!';
                        } else {
                            echo 'Directory exists but is NOT writable!';
                        }
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php
        $criticalIssues = [];

        if ($isProduction !== false && $isProduction !== true) {
            $criticalIssues[] = 'PRODUCTION_MODE is not defined';
        }

        if (!$isProduction && strpos(ONLYOFFICE_DOWNLOAD_URL, 'host.docker.internal') === false) {
            $criticalIssues[] = 'ONLYOFFICE_DOWNLOAD_URL does not use host.docker.internal in development';
        }

        if (!$onlyOfficeReachable) {
            $criticalIssues[] = 'OnlyOffice Document Server is not reachable';
        }

        if (!$uploadPathExists || !$uploadPathWritable) {
            $criticalIssues[] = 'Upload directory issues detected';
        }
        ?>

        <?php if (count($criticalIssues) > 0): ?>
            <div class="config-item warning">
                <div class="label">⚠️ Critical Issues Found:</div>
                <div class="value">
                    <ul>
                        <?php foreach ($criticalIssues as $issue): ?>
                            <li><?php echo htmlspecialchars($issue); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php else: ?>
            <div class="config-item success">
                <div class="label">✅ All Critical Checks Passed</div>
                <div class="value">
                    Configuration looks correct. If the editor still shows errors, check:
                    <ul>
                        <li>Browser console for JavaScript errors</li>
                        <li>Docker logs: <code>docker logs onlyoffice-document-server</code></li>
                        <li>Network connectivity from Docker to host</li>
                        <li>JWT secret matches between PHP and Docker container</li>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>11. Next Steps</h2>

        <ol>
            <li><strong>Verify PRODUCTION_MODE is FALSE</strong>: Confirm this diagnostic shows PRODUCTION_MODE = FALSE</li>
            <li><strong>Test Docker connectivity</strong>: Run the Docker curl command above to test from container</li>
            <li><strong>Check JWT secret</strong>: Verify JWT secret matches between PHP config and Docker</li>
            <li><strong>View browser console</strong>: Open file editor and check browser console for errors</li>
            <li><strong>Check Docker logs</strong>: Look for error messages in OnlyOffice container logs</li>
        </ol>
    </div>

    <p style="text-align: center; color: #7f8c8d; margin-top: 40px;">
        <small>Generated: <?php echo date('Y-m-d H:i:s'); ?></small>
    </p>
</body>
</html>
