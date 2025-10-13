<?php
/**
 * Test Download Endpoint Accessibility
 *
 * Tests if the download_for_editor.php endpoint is accessible
 * from different contexts (browser, curl, etc.)
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/document_editor_helper.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download Endpoint Test</title>
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
        .test-result {
            margin: 15px 0;
            padding: 12px;
            border-radius: 4px;
        }
        .test-result.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .test-result.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .test-result.warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }
        .code-block {
            background: #263238;
            color: #aed581;
            padding: 15px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            margin: 10px 0;
            overflow-x: auto;
        }
        .url-display {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            word-break: break-all;
            margin: 10px 0;
        }
        button {
            background: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        button:hover {
            background: #2980b9;
        }
    </style>
</head>
<body>
    <h1>🔍 Download Endpoint Accessibility Test</h1>

    <?php
    // Generate test token
    $testFileId = 43; // Use an actual file ID from your database
    $testPayload = [
        'file_id' => $testFileId,
        'user_id' => 1,
        'tenant_id' => 1,
        'session_token' => 'test_session',
        'permissions' => ['download' => true]
    ];
    $testToken = generateOnlyOfficeJWT($testPayload);

    // Build test URLs
    $localhostUrl = "http://localhost:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id={$testFileId}&token=" . urlencode($testToken);
    $dockerUrl = "http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id={$testFileId}&token=" . urlencode($testToken);
    $configuredUrl = ONLYOFFICE_DOWNLOAD_URL . "?file_id={$testFileId}&token=" . urlencode($testToken);
    ?>

    <div class="section">
        <h2>1. Configuration Check</h2>

        <div class="test-result <?php echo defined('PRODUCTION_MODE') ? 'success' : 'error'; ?>">
            <strong>PRODUCTION_MODE:</strong>
            <?php
            if (defined('PRODUCTION_MODE')) {
                echo PRODUCTION_MODE ? 'TRUE (Production)' : 'FALSE (Development)';
            } else {
                echo 'UNDEFINED ❌';
            }
            ?>
        </div>

        <div class="test-result <?php echo defined('ONLYOFFICE_DOWNLOAD_URL') ? 'success' : 'error'; ?>">
            <strong>ONLYOFFICE_DOWNLOAD_URL:</strong>
            <?php
            if (defined('ONLYOFFICE_DOWNLOAD_URL')) {
                echo htmlspecialchars(ONLYOFFICE_DOWNLOAD_URL);

                if (strpos(ONLYOFFICE_DOWNLOAD_URL, 'host.docker.internal') !== false) {
                    echo '<br><small>✅ Uses host.docker.internal (correct for Docker on Windows)</small>';
                } else {
                    echo '<br><small>⚠️ Does NOT use host.docker.internal (may not work with Docker)</small>';
                }
            } else {
                echo 'UNDEFINED ❌';
            }
            ?>
        </div>
    </div>

    <div class="section">
        <h2>2. Test URLs</h2>

        <h3>From Browser (localhost)</h3>
        <div class="url-display"><?php echo htmlspecialchars($localhostUrl); ?></div>
        <button onclick="testUrl('<?php echo addslashes($localhostUrl); ?>', 'browser-result')">
            Test from Browser
        </button>
        <div id="browser-result" style="margin-top: 10px;"></div>

        <h3>What OnlyOffice Container Should Use</h3>
        <div class="url-display"><?php echo htmlspecialchars($dockerUrl); ?></div>

        <h3>Currently Configured URL</h3>
        <div class="url-display"><?php echo htmlspecialchars($configuredUrl); ?></div>

        <?php if ($dockerUrl === $configuredUrl): ?>
            <div class="test-result success">
                ✅ Configured URL matches Docker URL - Configuration is correct!
            </div>
        <?php else: ?>
            <div class="test-result error">
                ❌ Configured URL does NOT match Docker URL - This is the problem!
                <br><br>
                <strong>Expected:</strong> <?php echo htmlspecialchars($dockerUrl); ?>
                <br>
                <strong>Actual:</strong> <?php echo htmlspecialchars($configuredUrl); ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>3. Server Accessibility Tests</h2>

        <h3>Test from Browser (should work)</h3>
        <div class="test-result warning">
            Click the button above to test if the endpoint is accessible from your browser.
        </div>

        <h3>Test from Docker Container</h3>
        <p>Run this command in your terminal to test from inside the Docker container:</p>
        <div class="code-block">
docker exec onlyoffice-document-server curl -v \
  "<?php echo $dockerUrl; ?>"
        </div>

        <p><strong>Expected result:</strong> Should download the file or show headers with HTTP 200 OK</p>
        <p><strong>If it fails:</strong> Docker cannot reach XAMPP on the host machine</p>
    </div>

    <div class="section">
        <h2>4. Network Connectivity Tests</h2>

        <p>Test if Docker can reach the host at all:</p>
        <div class="code-block">
# Test basic connectivity
docker exec onlyoffice-document-server ping -c 4 host.docker.internal

# Test if port 8888 is reachable
docker exec onlyoffice-document-server curl -v http://host.docker.internal:8888/

# Test if the CollaboraNexio directory is accessible
docker exec onlyoffice-document-server curl -v http://host.docker.internal:8888/CollaboraNexio/
        </div>
    </div>

    <div class="section">
        <h2>5. JWT Secret Verification</h2>

        <div class="test-result info">
            <strong>PHP JWT Secret (first 20 chars):</strong>
            <?php echo htmlspecialchars(substr(ONLYOFFICE_JWT_SECRET, 0, 20)) . '...'; ?>
        </div>

        <p>Check Docker container's JWT secret:</p>
        <div class="code-block">
# View the secret in Docker container
docker exec onlyoffice-document-server cat /etc/onlyoffice/documentserver/local.json | grep -A 2 "secret"

# Or view the entire config
docker exec onlyoffice-document-server cat /etc/onlyoffice/documentserver/local.json
        </div>

        <div class="test-result warning">
            ⚠️ The JWT secret in PHP config MUST match the secret in Docker container's local.json
        </div>
    </div>

    <div class="section">
        <h2>6. Debugging Steps</h2>

        <ol>
            <li>
                <strong>Check if PRODUCTION_MODE is FALSE:</strong>
                <?php if (defined('PRODUCTION_MODE') && PRODUCTION_MODE === false): ?>
                    <span style="color: green;">✅ PASS</span>
                <?php else: ?>
                    <span style="color: red;">❌ FAIL - Fix config.php</span>
                <?php endif; ?>
            </li>

            <li>
                <strong>Verify ONLYOFFICE_DOWNLOAD_URL uses host.docker.internal:</strong>
                <?php if (strpos(ONLYOFFICE_DOWNLOAD_URL, 'host.docker.internal') !== false): ?>
                    <span style="color: green;">✅ PASS</span>
                <?php else: ?>
                    <span style="color: red;">❌ FAIL - Fix includes/onlyoffice_config.php</span>
                <?php endif; ?>
            </li>

            <li>
                <strong>Test from Docker container:</strong>
                Run the curl command above and check if it returns file data
            </li>

            <li>
                <strong>Check Docker logs:</strong>
                <div class="code-block">docker logs onlyoffice-document-server --tail 50</div>
            </li>

            <li>
                <strong>Check PHP error logs:</strong>
                <?php
                $logFile = __DIR__ . '/logs/php_errors.log';
                if (file_exists($logFile)) {
                    echo '<div class="url-display">' . htmlspecialchars($logFile) . '</div>';
                    echo '<p>Last 20 lines:</p>';
                    echo '<div class="code-block">';
                    $lines = file($logFile);
                    $lastLines = array_slice($lines, -20);
                    foreach ($lastLines as $line) {
                        echo htmlspecialchars($line) . "\n";
                    }
                    echo '</div>';
                } else {
                    echo '<span style="color: orange;">Log file not found</span>';
                }
                ?>
            </li>
        </ol>
    </div>

    <script>
        function testUrl(url, resultId) {
            const resultDiv = document.getElementById(resultId);
            resultDiv.innerHTML = '<div style="color: blue;">Testing...</div>';

            fetch(url, {
                method: 'GET',
                mode: 'cors',
                credentials: 'omit'
            })
            .then(response => {
                if (response.ok) {
                    resultDiv.innerHTML = '<div class="test-result success">✅ SUCCESS: Endpoint is accessible (HTTP ' + response.status + ')</div>';
                } else {
                    resultDiv.innerHTML = '<div class="test-result error">❌ ERROR: HTTP ' + response.status + ' - ' + response.statusText + '</div>';
                }
            })
            .catch(error => {
                resultDiv.innerHTML = '<div class="test-result error">❌ NETWORK ERROR: ' + error.message + '<br><small>This might be a CORS issue or the server is not reachable</small></div>';
            });
        }
    </script>

    <p style="text-align: center; color: #7f8c8d; margin-top: 40px;">
        <small>Generated: <?php echo date('Y-m-d H:i:s'); ?></small>
    </p>
</body>
</html>
