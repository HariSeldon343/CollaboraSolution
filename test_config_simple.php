<?php
/**
 * Simple OnlyOffice Configuration Test
 */

require_once 'config.php';
require_once 'includes/onlyoffice_config.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>OnlyOffice Config Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #2563EB; padding-bottom: 10px; }
        .section { margin: 20px 0; padding: 15px; background: #f9fafb; border-left: 4px solid #2563EB; }
        .config-item { margin: 10px 0; padding: 10px; background: white; border: 1px solid #e5e7eb; border-radius: 4px; }
        .label { font-weight: bold; color: #6b7280; display: inline-block; width: 250px; }
        .value { color: #1f2937; font-family: monospace; word-break: break-all; }
        .pass { color: #10b981; font-weight: bold; }
        .fail { color: #ef4444; font-weight: bold; }
        .warning { color: #f59e0b; font-weight: bold; }
        .test-section { margin: 20px 0; padding: 15px; background: #fef3c7; border-left: 4px solid #f59e0b; }
        .test-item { margin: 10px 0; padding: 10px; background: white; border: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 OnlyOffice Configuration Test</h1>

        <div class="section">
            <h2>Environment Settings</h2>
            <div class="config-item">
                <span class="label">PRODUCTION_MODE:</span>
                <span class="value <?php echo PRODUCTION_MODE ? 'fail' : 'pass'; ?>">
                    <?php echo PRODUCTION_MODE ? 'TRUE (Production)' : 'FALSE (Development)'; ?>
                </span>
            </div>
            <div class="config-item">
                <span class="label">DEBUG_MODE:</span>
                <span class="value <?php echo DEBUG_MODE ? 'pass' : 'warning'; ?>">
                    <?php echo DEBUG_MODE ? 'TRUE (Enabled)' : 'FALSE (Disabled)'; ?>
                </span>
            </div>
            <div class="config-item">
                <span class="label">BASE_URL:</span>
                <span class="value"><?php echo BASE_URL; ?></span>
            </div>
        </div>

        <div class="section">
            <h2>OnlyOffice Configuration</h2>
            <div class="config-item">
                <span class="label">ONLYOFFICE_SERVER_URL:</span>
                <span class="value"><?php echo ONLYOFFICE_SERVER_URL; ?></span>
            </div>
            <div class="config-item">
                <span class="label">ONLYOFFICE_DOWNLOAD_URL:</span>
                <span class="value <?php echo strpos(ONLYOFFICE_DOWNLOAD_URL, 'host.docker.internal') !== false ? 'pass' : 'fail'; ?>">
                    <?php echo ONLYOFFICE_DOWNLOAD_URL; ?>
                    <?php if (strpos(ONLYOFFICE_DOWNLOAD_URL, 'localhost') !== false): ?>
                        <br><strong class="fail">⚠️ WARNING: Using localhost - Docker cannot reach this!</strong>
                    <?php endif; ?>
                </span>
            </div>
            <div class="config-item">
                <span class="label">ONLYOFFICE_CALLBACK_URL:</span>
                <span class="value <?php echo strpos(ONLYOFFICE_CALLBACK_URL, 'host.docker.internal') !== false ? 'pass' : 'fail'; ?>">
                    <?php echo ONLYOFFICE_CALLBACK_URL; ?>
                </span>
            </div>
            <div class="config-item">
                <span class="label">JWT_SECRET:</span>
                <span class="value"><?php echo substr(ONLYOFFICE_JWT_SECRET, 0, 20) . '...'; ?></span>
            </div>
        </div>

        <div class="test-section">
            <h2>🧪 Connectivity Tests</h2>

            <div class="test-item">
                <h3>Test 1: OnlyOffice Server Reachable</h3>
                <?php
                $ch = curl_init(ONLYOFFICE_SERVER_URL);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                $result = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode >= 200 && $httpCode < 400) {
                    echo '<span class="pass">✓ PASS</span> - HTTP ' . $httpCode;
                } else {
                    echo '<span class="fail">✗ FAIL</span> - HTTP ' . $httpCode;
                }
                ?>
            </div>

            <div class="test-item">
                <h3>Test 2: Download Endpoint Exists</h3>
                <?php
                $downloadEndpoint = str_replace(BASE_URL, $_SERVER['DOCUMENT_ROOT'] . '/CollaboraNexio', ONLYOFFICE_DOWNLOAD_URL);
                $downloadEndpoint = str_replace('http://host.docker.internal:8888/CollaboraNexio', $_SERVER['DOCUMENT_ROOT'] . '/CollaboraNexio', $downloadEndpoint);
                $localFile = preg_replace('/\?.*$/', '', parse_url(ONLYOFFICE_DOWNLOAD_URL, PHP_URL_PATH));
                $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/CollaboraNexio' . $localFile;

                if (file_exists($fullPath)) {
                    echo '<span class="pass">✓ PASS</span> - File exists: ' . $fullPath;
                } else {
                    echo '<span class="fail">✗ FAIL</span> - File not found: ' . $fullPath;
                }
                ?>
            </div>

            <div class="test-item">
                <h3>Test 3: Configuration Summary</h3>
                <?php
                $errors = [];
                $warnings = [];

                if (PRODUCTION_MODE) {
                    $warnings[] = "You're in PRODUCTION mode locally - should be FALSE";
                }

                if (!DEBUG_MODE) {
                    $warnings[] = "DEBUG_MODE is FALSE - logging disabled";
                }

                if (strpos(ONLYOFFICE_DOWNLOAD_URL, 'localhost') !== false) {
                    $errors[] = "DOWNLOAD_URL uses 'localhost' - Docker container cannot reach this!";
                }

                if (strpos(ONLYOFFICE_CALLBACK_URL, 'localhost') !== false) {
                    $errors[] = "CALLBACK_URL uses 'localhost' - Docker container cannot reach this!";
                }

                if (!strpos(ONLYOFFICE_DOWNLOAD_URL, 'host.docker.internal') && !PRODUCTION_MODE) {
                    $errors[] = "In development, URLs should use 'host.docker.internal'";
                }

                if (empty($errors) && empty($warnings)) {
                    echo '<span class="pass">✓ ALL CHECKS PASSED</span>';
                } else {
                    if (!empty($errors)) {
                        echo '<div style="margin: 10px 0;"><strong class="fail">ERRORS:</strong><ul>';
                        foreach ($errors as $error) {
                            echo '<li class="fail">' . $error . '</li>';
                        }
                        echo '</ul></div>';
                    }

                    if (!empty($warnings)) {
                        echo '<div style="margin: 10px 0;"><strong class="warning">WARNINGS:</strong><ul>';
                        foreach ($warnings as $warning) {
                            echo '<li class="warning">' . $warning . '</li>';
                        }
                        echo '</ul></div>';
                    }
                }
                ?>
            </div>
        </div>

        <div class="section">
            <h2>🔍 Docker Test Command</h2>
            <p>Run this command to test if Docker can reach the download endpoint:</p>
            <pre style="background: #1f2937; color: #10b981; padding: 15px; border-radius: 4px; overflow-x: auto;">docker exec collaboranexio-onlyoffice curl -v "<?php echo ONLYOFFICE_DOWNLOAD_URL; ?>?file_id=1&amp;token=test" 2>&1 | head -20</pre>

            <p><strong>Expected result:</strong> Should see HTTP response (400 Bad Request is OK - means endpoint is reachable)</p>
            <p><strong>If it fails:</strong> You'll see "Could not resolve host" - this means Docker networking issue</p>
        </div>

        <div class="section">
            <h2>📝 Next Steps</h2>
            <ol>
                <li>Ensure all configuration checks above are <span class="pass">PASSING</span></li>
                <li>Run the Docker test command in terminal</li>
                <li>Check PHP error logs: <code>tail -f logs/php_errors.log</code></li>
                <li>Try opening a document in the file manager</li>
                <li>Check browser console (F12) for [DocumentEditor] errors</li>
            </ol>
        </div>
    </div>
</body>
</html>
