<?php
session_start();

// Test the notifications endpoint
$baseUrl = 'http://localhost:8888/CollaboraNexio/api/notifications/unread';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Test Notifications API</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .test { margin: 20px 0; padding: 10px; border: 1px solid #ddd; }
        .success { background: #d4edda; }
        .error { background: #f8d7da; }
        pre { background: #f4f4f4; padding: 10px; overflow: auto; }
    </style>
</head>
<body>
    <h1>Testing Notifications API Endpoint</h1>

    <div class='test'>
        <h2>Direct PHP Test (using includes)</h2>
        <?php
        // Test if auth is available
        if (file_exists('includes/auth_simple.php')) {
            require_once 'includes/auth_simple.php';
            $auth = new Auth();
            if ($auth->checkAuth()) {
                $user = $auth->getCurrentUser();
                echo "<p class='success'>✓ Authentication working. User: " . htmlspecialchars($user['email']) . "</p>";
                echo "<p>Session data:</p><pre>";
                print_r($_SESSION);
                echo "</pre>";
            } else {
                echo "<p class='error'>✗ Not authenticated. Please login first.</p>";
            }
        } else {
            echo "<p class='error'>✗ Auth file not found</p>";
        }
        ?>
    </div>

    <div class='test'>
        <h2>JavaScript Fetch Test</h2>
        <button onclick='testFetch()'>Test with Fetch API</button>
        <div id='fetchResult'></div>
    </div>

    <div class='test'>
        <h2>AJAX Test</h2>
        <button onclick='testAjax()'>Test with XMLHttpRequest</button>
        <div id='ajaxResult'></div>
    </div>

    <script>
        function testFetch() {
            const resultDiv = document.getElementById('fetchResult');
            resultDiv.innerHTML = 'Loading...';

            fetch('/CollaboraNexio/api/notifications/unread', {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                return response.text();
            })
            .then(text => {
                console.log('Response text:', text);
                try {
                    const data = JSON.parse(text);
                    resultDiv.innerHTML = '<pre class=\"success\">' + JSON.stringify(data, null, 2) + '</pre>';
                } catch(e) {
                    resultDiv.innerHTML = '<pre class=\"error\">Response: ' + text + '</pre>';
                }
            })
            .catch(error => {
                resultDiv.innerHTML = '<pre class=\"error\">Error: ' + error + '</pre>';
            });
        }

        function testAjax() {
            const resultDiv = document.getElementById('ajaxResult');
            resultDiv.innerHTML = 'Loading...';

            const xhr = new XMLHttpRequest();
            xhr.open('GET', '/CollaboraNexio/api/notifications/unread', true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.withCredentials = true;

            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    console.log('XHR Status:', xhr.status);
                    console.log('XHR Response:', xhr.responseText);

                    if (xhr.status === 200) {
                        try {
                            const data = JSON.parse(xhr.responseText);
                            resultDiv.innerHTML = '<pre class=\"success\">' + JSON.stringify(data, null, 2) + '</pre>';
                        } catch(e) {
                            resultDiv.innerHTML = '<pre class=\"error\">Parse error: ' + e + '\\nResponse: ' + xhr.responseText + '</pre>';
                        }
                    } else {
                        resultDiv.innerHTML = '<pre class=\"error\">HTTP ' + xhr.status + ': ' + xhr.responseText + '</pre>';
                    }
                }
            };

            xhr.send();
        }
    </script>
</body>
</html>";
?>