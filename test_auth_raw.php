<?php
// Test raw auth_api.php response
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Auth Raw Response</title>
    <style>
        body {
            font-family: monospace;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .section {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        pre {
            background: #f0f0f0;
            padding: 15px;
            overflow-x: auto;
            border-radius: 3px;
        }
        .error {
            background: #fee;
            color: #c00;
            padding: 10px;
            border-radius: 3px;
        }
        .success {
            background: #efe;
            color: #060;
            padding: 10px;
            border-radius: 3px;
        }
        button {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin: 5px;
        }
        button:hover {
            background: #5a67d8;
        }
    </style>
</head>
<body>
    <h1>🔍 Test Raw Auth API Response</h1>

    <div class="section">
        <h2>Test 1: Check Auth API File</h2>
        <?php
        $auth_api_path = __DIR__ . '/auth_api.php';
        if (file_exists($auth_api_path)) {
            echo '<div class="success">✅ auth_api.php exists</div>';
            echo '<pre>Size: ' . filesize($auth_api_path) . ' bytes</pre>';
            echo '<pre>Modified: ' . date('Y-m-d H:i:s', filemtime($auth_api_path)) . '</pre>';
        } else {
            echo '<div class="error">❌ auth_api.php NOT FOUND</div>';
        }
        ?>
    </div>

    <div class="section">
        <h2>Test 2: Direct PHP Include Test</h2>
        <?php
        // Save current session
        $saved_session = $_SESSION ?? [];
        $saved_server = $_SERVER;
        $saved_get = $_GET;
        $saved_post = $_POST;

        // Set up for test
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['action'] = 'check';

        // Capture output
        ob_start();
        $error_output = '';

        // Custom error handler
        set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$error_output) {
            $error_output .= "PHP Error: [$errno] $errstr in $errfile on line $errline\n";
            return true;
        });

        // Try to include auth_api.php
        try {
            include 'auth_api.php';
        } catch (Exception $e) {
            $error_output .= "Exception: " . $e->getMessage() . "\n";
        } catch (Error $e) {
            $error_output .= "Fatal Error: " . $e->getMessage() . "\n";
        }

        $output = ob_get_clean();
        restore_error_handler();

        // Restore original values
        $_SESSION = $saved_session;
        $_SERVER = $saved_server;
        $_GET = $saved_get;
        $_POST = $saved_post;

        if ($error_output) {
            echo '<div class="error">Errors encountered:</div>';
            echo '<pre>' . htmlspecialchars($error_output) . '</pre>';
        }

        echo '<div>Raw output from auth_api.php:</div>';
        echo '<pre>' . htmlspecialchars($output) . '</pre>';

        // Try to parse as JSON
        $json_data = json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo '<div class="success">✅ Valid JSON response</div>';
            echo '<pre>' . json_encode($json_data, JSON_PRETTY_PRINT) . '</pre>';
        } else {
            echo '<div class="error">❌ Invalid JSON: ' . json_last_error_msg() . '</div>';
        }
        ?>
    </div>

    <div class="section">
        <h2>Test 3: AJAX Request Test</h2>
        <button onclick="testCheckSession()">Test Check Session</button>
        <button onclick="testLogin()">Test Login</button>
        <button onclick="testRawFetch()">Test Raw Fetch</button>
        <div id="ajax-result"></div>
    </div>

    <div class="section">
        <h2>Test 4: cURL Test</h2>
        <?php
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://localhost:8888/CollaboraNexio/auth_api.php?action=check");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($error) {
            echo '<div class="error">cURL Error: ' . htmlspecialchars($error) . '</div>';
        } else {
            echo '<div class="success">✅ cURL request successful</div>';

            // Separate headers and body
            $header_size = $info['header_size'];
            $headers = substr($response, 0, $header_size);
            $body = substr($response, $header_size);

            echo '<h3>Response Headers:</h3>';
            echo '<pre>' . htmlspecialchars($headers) . '</pre>';

            echo '<h3>Response Body:</h3>';
            echo '<pre>' . htmlspecialchars($body) . '</pre>';

            // Try to parse body as JSON
            $json_data = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                echo '<div class="success">✅ Valid JSON in body</div>';
            } else {
                echo '<div class="error">❌ Invalid JSON in body: ' . json_last_error_msg() . '</div>';
            }
        }

        echo '<h3>cURL Info:</h3>';
        echo '<pre>' . print_r($info, true) . '</pre>';
        ?>
    </div>

    <script>
        function showResult(html) {
            document.getElementById('ajax-result').innerHTML = html;
        }

        async function testCheckSession() {
            showResult('<div>Testing check session...</div>');
            try {
                const response = await fetch('auth_api.php?action=check', {
                    method: 'GET',
                    credentials: 'same-origin'
                });

                const text = await response.text();
                console.log('Raw response:', text);

                let html = '<h3>Response Status: ' + response.status + '</h3>';
                html += '<h3>Response Headers:</h3><pre>';
                response.headers.forEach((value, key) => {
                    html += key + ': ' + value + '\n';
                });
                html += '</pre>';
                html += '<h3>Response Body (Raw Text):</h3>';
                html += '<pre>' + text.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</pre>';

                try {
                    const json = JSON.parse(text);
                    html += '<div class="success">✅ Valid JSON</div>';
                    html += '<pre>' + JSON.stringify(json, null, 2) + '</pre>';
                } catch (e) {
                    html += '<div class="error">❌ JSON Parse Error: ' + e.message + '</div>';
                }

                showResult(html);
            } catch (error) {
                showResult('<div class="error">Fetch Error: ' + error.message + '</div>');
            }
        }

        async function testLogin() {
            showResult('<div>Testing login...</div>');
            try {
                const response = await fetch('auth_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        email: 'admin@demo.com',
                        password: 'Admin123!'
                    })
                });

                const text = await response.text();
                console.log('Raw response:', text);

                let html = '<h3>Login Response Status: ' + response.status + '</h3>';
                html += '<h3>Raw Response:</h3>';
                html += '<pre>' + text.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</pre>';

                try {
                    const json = JSON.parse(text);
                    html += '<div class="success">✅ Valid JSON</div>';
                    html += '<pre>' + JSON.stringify(json, null, 2) + '</pre>';
                } catch (e) {
                    html += '<div class="error">❌ JSON Parse Error: ' + e.message + '</div>';
                }

                showResult(html);
            } catch (error) {
                showResult('<div class="error">Fetch Error: ' + error.message + '</div>');
            }
        }

        async function testRawFetch() {
            showResult('<div>Testing raw fetch...</div>');

            const xhr = new XMLHttpRequest();
            xhr.open('GET', 'auth_api.php?action=check', true);

            xhr.onload = function() {
                let html = '<h3>XMLHttpRequest Response:</h3>';
                html += '<p>Status: ' + xhr.status + '</p>';
                html += '<p>Response Type: ' + xhr.responseType + '</p>';
                html += '<h3>Response Headers:</h3>';
                html += '<pre>' + xhr.getAllResponseHeaders() + '</pre>';
                html += '<h3>Response Text:</h3>';
                html += '<pre>' + xhr.responseText.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</pre>';
                showResult(html);
            };

            xhr.onerror = function() {
                showResult('<div class="error">XMLHttpRequest Error</div>');
            };

            xhr.send();
        }
    </script>
</body>
</html>