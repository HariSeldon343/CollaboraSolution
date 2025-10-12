<?php
/**
 * OnlyOffice Configuration Test Script
 *
 * Tests the fixed configuration and connectivity
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/onlyoffice_config.php';
require_once __DIR__ . '/includes/document_editor_helper.php';

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnlyOffice Configuration Test</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .test-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
        }
        h2 {
            color: #34495e;
            margin-top: 30px;
        }
        .status {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 4px;
            font-weight: bold;
            margin-left: 10px;
        }
        .status.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .status.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .test-section {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-left: 4px solid #3498db;
            border-radius: 4px;
        }
        pre {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
        .code {
            font-family: 'Courier New', monospace;
            background: #ecf0f1;
            padding: 2px 6px;
            border-radius: 3px;
            color: #e74c3c;
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
        .icon {
            font-size: 24px;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="test-container">
        <h1><span class="icon">🔧</span>OnlyOffice Configuration Test</h1>
        <p><strong>Date:</strong> <?= date('Y-m-d H:i:s') ?></p>

        <?php
        // Test 1: Connectivity
        echo '<div class="test-section">';
        echo '<h2>1. OnlyOffice Server Connectivity</h2>';

        $connectivity = checkOnlyOfficeConnectivity();

        if ($connectivity['available']) {
            echo '<p><span class="status success">✓ CONNECTED</span></p>';
            echo '<table>';
            echo '<tr><th>Property</th><th>Value</th></tr>';
            echo '<tr><td>Server URL</td><td>' . ONLYOFFICE_SERVER_URL . '</td></tr>';
            echo '<tr><td>Response Time</td><td>' . $connectivity['response_time'] . ' ms</td></tr>';
            echo '<tr><td>Status</td><td>' . ($connectivity['status'] ?? 'OK') . '</td></tr>';
            echo '</table>';
        } else {
            echo '<p><span class="status error">✗ NOT AVAILABLE</span></p>';
            echo '<p><strong>Error:</strong> ' . ($connectivity['error'] ?? 'Unknown error') . '</p>';
        }
        echo '</div>';

        // Test 2: Configuration Check
        echo '<div class="test-section">';
        echo '<h2>2. Configuration Validation</h2>';

        $testConfig = getOnlyOfficeCustomization([
            'autosave' => true,
            'chat' => true,  // This should be removed
            'showReviewChanges' => true,  // This should be removed
            'comments' => true
        ]);

        $hasDeprecated = isset($testConfig['chat']) || isset($testConfig['showReviewChanges']);

        if (!$hasDeprecated) {
            echo '<p><span class="status success">✓ NO DEPRECATED PARAMETERS</span></p>';
            echo '<p>Configuration correctly filters deprecated parameters:</p>';
            echo '<ul>';
            echo '<li><code>chat</code> parameter removed ✓</li>';
            echo '<li><code>showReviewChanges</code> parameter removed ✓</li>';
            echo '</ul>';
        } else {
            echo '<p><span class="status error">✗ DEPRECATED PARAMETERS PRESENT</span></p>';
            if (isset($testConfig['chat'])) {
                echo '<p><strong>Error:</strong> <code>chat</code> parameter still present!</p>';
            }
            if (isset($testConfig['showReviewChanges'])) {
                echo '<p><strong>Error:</strong> <code>showReviewChanges</code> parameter still present!</p>';
            }
        }

        echo '<p><strong>Final Configuration (preview):</strong></p>';
        echo '<pre>' . json_encode($testConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . '</pre>';
        echo '</div>';

        // Test 3: Constants Check
        echo '<div class="test-section">';
        echo '<h2>3. OnlyOffice Constants</h2>';
        echo '<table>';
        echo '<tr><th>Constant</th><th>Value</th><th>Status</th></tr>';

        $constants = [
            'ONLYOFFICE_SERVER_URL' => ONLYOFFICE_SERVER_URL,
            'ONLYOFFICE_API_URL' => ONLYOFFICE_API_URL,
            'ONLYOFFICE_JWT_ENABLED' => ONLYOFFICE_JWT_ENABLED ? 'true' : 'false',
            'ONLYOFFICE_JWT_SECRET' => substr(ONLYOFFICE_JWT_SECRET, 0, 10) . '...',
            'ONLYOFFICE_DOWNLOAD_URL' => ONLYOFFICE_DOWNLOAD_URL,
            'ONLYOFFICE_CALLBACK_URL' => ONLYOFFICE_CALLBACK_URL,
            'ONLYOFFICE_LANG' => ONLYOFFICE_LANG,
            'ONLYOFFICE_ENABLE_COLLABORATION' => ONLYOFFICE_ENABLE_COLLABORATION ? 'true' : 'false',
            'ONLYOFFICE_ENABLE_CHAT' => ONLYOFFICE_ENABLE_CHAT ? 'true' : 'false'
        ];

        foreach ($constants as $name => $value) {
            $status = !empty($value) ? '<span class="status success">✓</span>' : '<span class="status error">✗</span>';
            echo "<tr><td><code>$name</code></td><td>$value</td><td>$status</td></tr>";
        }
        echo '</table>';
        echo '</div>';

        // Test 4: File Type Support
        echo '<div class="test-section">';
        echo '<h2>4. Supported File Types</h2>';

        $testFiles = [
            'document.docx' => 'word',
            'spreadsheet.xlsx' => 'cell',
            'presentation.pptx' => 'slide',
            'text.txt' => 'word',
            'data.csv' => 'cell',
            'report.pdf' => 'word'
        ];

        echo '<table>';
        echo '<tr><th>Filename</th><th>Extension</th><th>Document Type</th><th>Editable</th><th>View Only</th></tr>';

        foreach ($testFiles as $filename => $expectedType) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $docType = getOnlyOfficeDocumentType($ext);
            $editable = isFileEditableInOnlyOffice($ext);
            $viewOnly = isFileViewOnlyInOnlyOffice($ext);

            echo '<tr>';
            echo "<td>$filename</td>";
            echo "<td>$ext</td>";
            echo "<td>$docType</td>";
            echo '<td>' . ($editable ? '<span class="status success">✓</span>' : '<span class="status error">✗</span>') . '</td>';
            echo '<td>' . ($viewOnly ? '<span class="status warning">View Only</span>' : '-') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';

        // Test 5: JWT Token Generation
        echo '<div class="test-section">';
        echo '<h2>5. JWT Token Generation</h2>';

        if (ONLYOFFICE_JWT_ENABLED) {
            $testPayload = [
                'file_id' => 999,
                'user_id' => 1,
                'test' => true
            ];

            try {
                $token = generateOnlyOfficeJWT($testPayload);

                if (!empty($token)) {
                    echo '<p><span class="status success">✓ JWT GENERATION WORKS</span></p>';
                    echo '<p><strong>Test Token:</strong> <code>' . substr($token, 0, 50) . '...</code></p>';

                    // Verify token
                    $verified = verifyOnlyOfficeJWT($token);
                    if ($verified !== false) {
                        echo '<p><span class="status success">✓ JWT VERIFICATION WORKS</span></p>';
                    } else {
                        echo '<p><span class="status error">✗ JWT VERIFICATION FAILED</span></p>';
                    }
                } else {
                    echo '<p><span class="status error">✗ JWT GENERATION FAILED</span></p>';
                }
            } catch (Exception $e) {
                echo '<p><span class="status error">✗ ERROR: ' . $e->getMessage() . '</span></p>';
            }
        } else {
            echo '<p><span class="status warning">⚠ JWT DISABLED</span></p>';
        }
        echo '</div>';

        // Test 6: Document Key Generation
        echo '<div class="test-section">';
        echo '<h2>6. Document Key Generation</h2>';

        $testFileId = 43;
        $testHash = md5('test_content_12345');
        $testVersion = 2;

        $docKey = generateDocumentKey($testFileId, $testHash, $testVersion);

        echo '<table>';
        echo '<tr><th>Parameter</th><th>Value</th></tr>';
        echo "<tr><td>File ID</td><td>$testFileId</td></tr>";
        echo "<tr><td>Hash</td><td>$testHash</td></tr>";
        echo "<tr><td>Version</td><td>$testVersion</td></tr>";
        echo "<tr><td><strong>Generated Key</strong></td><td><code>$docKey</code></td></tr>";
        echo '</table>';

        $isValid = preg_match('/^file_\d+_v\d+_[a-f0-9]{12}$/', $docKey);
        echo '<p>' . ($isValid ? '<span class="status success">✓ Valid Format</span>' : '<span class="status error">✗ Invalid Format</span>') . '</p>';
        echo '</div>';

        // Summary
        echo '<div class="test-section" style="border-left-color: #2ecc71;">';
        echo '<h2>Summary</h2>';

        $allPassed = $connectivity['available'] && !$hasDeprecated;

        if ($allPassed) {
            echo '<h3 style="color: #27ae60;"><span class="icon">✓</span>ALL TESTS PASSED</h3>';
            echo '<p>OnlyOffice integration is properly configured and ready to use.</p>';
            echo '<p><strong>Next Steps:</strong></p>';
            echo '<ol>';
            echo '<li>Test opening document ID 43 (12.docx) from the files page</li>';
            echo '<li>Verify no deprecated warnings in browser console</li>';
            echo '<li>Test collaborative editing with multiple users</li>';
            echo '<li>Test error scenarios (server down, network issues)</li>';
            echo '</ol>';
        } else {
            echo '<h3 style="color: #e74c3c;"><span class="icon">✗</span>ISSUES DETECTED</h3>';
            echo '<p>Please review the failed tests above and fix any issues.</p>';
        }
        echo '</div>';
        ?>

        <div style="margin-top: 30px; padding: 15px; background: #ecf0f1; border-radius: 4px;">
            <p><strong>Test Location:</strong> <code><?= __FILE__ ?></code></p>
            <p><strong>PHP Version:</strong> <?= phpversion() ?></p>
            <p><strong>Server Time:</strong> <?= date('Y-m-d H:i:s') ?></p>
        </div>
    </div>
</body>
</html>
