<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollaboraNexio - Enhanced Login</title>
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
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 480px;
            padding: 40px;
            position: relative;
        }

        h1 {
            color: #2d3748;
            text-align: center;
            margin-bottom: 10px;
            font-size: 28px;
            font-weight: 600;
        }

        .subtitle {
            color: #718096;
            text-align: center;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            color: #4a5568;
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 14px;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f7fafc;
        }

        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn-primary {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-secondary {
            width: 100%;
            padding: 12px;
            background: #e2e8f0;
            color: #4a5568;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            margin-top: 10px;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
        }

        .message-box {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: none;
        }

        .message-box.show {
            display: block;
        }

        .message-box.success {
            background: #c6f6d5;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }

        .message-box.error {
            background: #fed7d7;
            color: #742a2a;
            border: 1px solid #fc8181;
        }

        .message-box.info {
            background: #bee3f8;
            color: #2c5282;
            border: 1px solid #90cdf4;
        }

        .debug-panel {
            background: #1a202c;
            color: #a0aec0;
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
        }

        .debug-panel h3 {
            color: #48bb78;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .debug-line {
            margin: 4px 0;
            padding: 2px 0;
            border-bottom: 1px solid #2d3748;
        }

        .debug-line.error {
            color: #fc8181;
        }

        .debug-line.success {
            color: #48bb78;
        }

        .debug-line.info {
            color: #63b3ed;
        }

        .demo-info {
            background: #f7fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            margin: 20px 0;
        }

        .demo-info h4 {
            color: #4a5568;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .demo-info code {
            background: #edf2f7;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 13px;
            color: #2d3748;
        }

        .backup-links {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
            text-align: center;
        }

        .backup-links h4 {
            color: #4a5568;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .backup-links a {
            display: inline-block;
            color: #667eea;
            text-decoration: none;
            padding: 8px 16px;
            margin: 4px;
            background: #ebf4ff;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .backup-links a:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }

        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading .spinner {
            display: block;
        }

        .session-info {
            background: #fef5e7;
            border: 1px solid #f8c471;
            border-radius: 8px;
            padding: 12px;
            margin-top: 20px;
            font-size: 12px;
            color: #935116;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>CollaboraNexio</h1>
        <p class="subtitle">Enhanced Login with Debug Mode</p>

        <div id="messageBox" class="message-box"></div>

        <form id="loginForm">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" value="admin@demo.com" required autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" value="Admin123!" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn-primary" id="submitBtn">
                <span id="btnText">Login</span>
                <div class="spinner" id="spinner"></div>
            </button>
        </form>

        <div class="demo-info">
            <h4>Demo Credentials:</h4>
            <div>Email: <code>admin@demo.com</code></div>
            <div>Password: <code>Admin123!</code></div>
        </div>

        <button type="button" class="btn-secondary" onclick="testRedirectMethods()">
            Test All Redirect Methods
        </button>

        <button type="button" class="btn-secondary" onclick="checkSession()">
            Check Current Session
        </button>

        <button type="button" class="btn-secondary" onclick="manualRedirect()">
            Manual Redirect to Dashboard
        </button>

        <div class="backup-links">
            <h4>Direct Navigation Links:</h4>
            <a href="http://localhost:8888/CollaboraNexio/dashboard_direct.php">Dashboard Direct (Full URL)</a>
            <a href="/CollaboraNexio/dashboard_direct.php">Dashboard Direct (Absolute Path)</a>
            <a href="dashboard_direct.php">Dashboard Direct (Relative)</a>
            <a href="dashboard.php">Dashboard Main</a>
            <a href="test_db.php">Test Database</a>
        </div>

        <div class="debug-panel" id="debugPanel">
            <h3>Debug Console:</h3>
            <div id="debugContent"></div>
        </div>

        <div id="sessionInfo" class="session-info" style="display: none;"></div>
    </div>

    <script src="debug_auth.js"></script>
    <script>
        // Initialize debug logger
        const debugLogger = new DebugAuth('debugContent');

        // Show message
        function showMessage(message, type = 'info') {
            const messageBox = document.getElementById('messageBox');
            messageBox.className = `message-box show ${type}`;
            messageBox.textContent = message;

            if (type !== 'error') {
                setTimeout(() => {
                    messageBox.classList.remove('show');
                }, 5000);
            }
        }

        // Test all redirect methods
        function testRedirectMethods() {
            debugLogger.log('Testing all redirect methods...', 'info');

            const targetUrl = 'http://localhost:8888/CollaboraNexio/dashboard_direct.php';

            // Method 1: window.location.href
            debugLogger.log('Method 1: window.location.href', 'info');
            setTimeout(() => {
                debugLogger.log('Attempting: window.location.href = ' + targetUrl, 'success');
                try {
                    window.location.href = targetUrl;
                } catch (e) {
                    debugLogger.log('Method 1 failed: ' + e.message, 'error');
                }
            }, 1000);

            // Method 2: window.location.replace
            setTimeout(() => {
                debugLogger.log('Method 2: window.location.replace', 'info');
                debugLogger.log('Attempting: window.location.replace(' + targetUrl + ')', 'success');
                try {
                    window.location.replace(targetUrl);
                } catch (e) {
                    debugLogger.log('Method 2 failed: ' + e.message, 'error');
                }
            }, 2000);

            // Method 3: window.location.assign
            setTimeout(() => {
                debugLogger.log('Method 3: window.location.assign', 'info');
                debugLogger.log('Attempting: window.location.assign(' + targetUrl + ')', 'success');
                try {
                    window.location.assign(targetUrl);
                } catch (e) {
                    debugLogger.log('Method 3 failed: ' + e.message, 'error');
                }
            }, 3000);

            // Method 4: Create and click anchor
            setTimeout(() => {
                debugLogger.log('Method 4: Creating and clicking anchor element', 'info');
                try {
                    const link = document.createElement('a');
                    link.href = targetUrl;
                    link.click();
                    debugLogger.log('Anchor click executed', 'success');
                } catch (e) {
                    debugLogger.log('Method 4 failed: ' + e.message, 'error');
                }
            }, 4000);

            // Method 5: Form submission
            setTimeout(() => {
                debugLogger.log('Method 5: Form submission redirect', 'info');
                try {
                    const form = document.createElement('form');
                    form.method = 'GET';
                    form.action = targetUrl;
                    document.body.appendChild(form);
                    form.submit();
                    debugLogger.log('Form submission executed', 'success');
                } catch (e) {
                    debugLogger.log('Method 5 failed: ' + e.message, 'error');
                }
            }, 5000);
        }

        // Check session
        async function checkSession() {
            debugLogger.log('Checking current session...', 'info');

            try {
                const response = await fetch('http://localhost:8888/CollaboraNexio/auth_api.php?action=session', {
                    method: 'GET',
                    credentials: 'include',
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                const data = await response.json();
                debugLogger.log('Session response received', 'success');
                debugLogger.log('Session ID: ' + data.session_id, 'info');
                debugLogger.log('Session Data: ' + JSON.stringify(data.session_data, null, 2), 'info');

                const sessionInfo = document.getElementById('sessionInfo');
                sessionInfo.style.display = 'block';
                sessionInfo.innerHTML = `
                    <strong>Current Session:</strong><br>
                    Session ID: ${data.session_id}<br>
                    Status: ${data.session_status}<br>
                    Logged In: ${data.session_data.logged_in ? 'Yes' : 'No'}<br>
                    User: ${data.session_data.user_name || 'Not logged in'}
                `;

            } catch (error) {
                debugLogger.log('Session check failed: ' + error.message, 'error');
            }
        }

        // Manual redirect
        function manualRedirect() {
            debugLogger.log('Manual redirect initiated', 'info');
            const targetUrl = 'http://localhost:8888/CollaboraNexio/dashboard_direct.php';

            showMessage('Redirecting to dashboard...', 'info');
            debugLogger.log('Setting window.location = ' + targetUrl, 'success');

            // Try multiple methods
            try {
                // First attempt
                window.location = targetUrl;
            } catch (e1) {
                debugLogger.log('First attempt failed: ' + e1.message, 'error');
                try {
                    // Second attempt
                    window.location.href = targetUrl;
                } catch (e2) {
                    debugLogger.log('Second attempt failed: ' + e2.message, 'error');
                    // Third attempt
                    window.location.replace(targetUrl);
                }
            }
        }

        // Login form handler
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();

            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');

            // Clear previous messages
            document.getElementById('messageBox').classList.remove('show');

            // Show loading state
            submitBtn.disabled = true;
            submitBtn.classList.add('loading');
            btnText.textContent = 'Logging in...';

            debugLogger.log('Login attempt started', 'info');

            const formData = {
                email: document.getElementById('email').value,
                password: document.getElementById('password').value
            };

            debugLogger.log('Sending credentials to auth_api.php', 'info');
            debugLogger.log('Email: ' + formData.email, 'info');

            try {
                // Make API call with full URL
                const apiUrl = 'http://localhost:8888/CollaboraNexio/auth_api.php';
                debugLogger.log('API URL: ' + apiUrl, 'info');

                const response = await fetch(apiUrl, {
                    method: 'POST',
                    credentials: 'include', // Important for cookies
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(formData)
                });

                debugLogger.log('Response Status: ' + response.status, response.ok ? 'success' : 'error');
                debugLogger.log('Response Headers: ' + JSON.stringify([...response.headers.entries()]), 'info');

                const responseText = await response.text();
                debugLogger.log('Raw Response: ' + responseText.substring(0, 200), 'info');

                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    debugLogger.log('JSON Parse Error: ' + parseError.message, 'error');
                    throw new Error('Invalid JSON response from server');
                }

                debugLogger.log('Parsed Response: ' + JSON.stringify(data), 'info');

                if (data.success) {
                    debugLogger.log('Login successful!', 'success');
                    debugLogger.log('User: ' + data.user.name + ' (' + data.user.email + ')', 'success');
                    debugLogger.log('Redirect URL: ' + data.redirect, 'info');

                    showMessage('Login successful! Redirecting...', 'success');

                    // Try multiple redirect methods
                    const fullRedirectUrl = 'http://localhost:8888/CollaboraNexio/' + data.redirect;

                    // Method 1: Immediate redirect with full URL
                    debugLogger.log('Attempting redirect to: ' + fullRedirectUrl, 'info');

                    setTimeout(() => {
                        debugLogger.log('Executing redirect now...', 'success');

                        try {
                            // Try window.location first
                            window.location = fullRedirectUrl;
                        } catch (e) {
                            debugLogger.log('window.location failed, trying window.location.href', 'error');
                            try {
                                window.location.href = fullRedirectUrl;
                            } catch (e2) {
                                debugLogger.log('window.location.href failed, trying window.location.replace', 'error');
                                window.location.replace(fullRedirectUrl);
                            }
                        }
                    }, 1500);

                    // Fallback: Try again after 3 seconds
                    setTimeout(() => {
                        debugLogger.log('Fallback redirect attempt...', 'info');
                        window.location.href = fullRedirectUrl;
                    }, 3000);

                } else {
                    debugLogger.log('Login failed: ' + data.message, 'error');
                    showMessage(data.message || 'Login failed', 'error');

                    // Reset button
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('loading');
                    btnText.textContent = 'Login';
                }

            } catch (error) {
                debugLogger.log('Network/Fetch Error: ' + error.message, 'error');
                debugLogger.log('Error Stack: ' + error.stack, 'error');
                showMessage('Connection error: ' + error.message, 'error');

                // Reset button
                submitBtn.disabled = false;
                submitBtn.classList.remove('loading');
                btnText.textContent = 'Login';
            }
        });

        // Check session on page load
        window.addEventListener('load', () => {
            debugLogger.log('Page loaded successfully', 'success');
            debugLogger.log('Current URL: ' + window.location.href, 'info');
            debugLogger.log('Browser: ' + navigator.userAgent, 'info');
            checkSession();
        });
    </script>
</body>
</html>