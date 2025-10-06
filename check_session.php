<?php
session_start();

// NO REDIRECTS AT ALL - Just show session state

// Gather complete session information
$session_info = [
    'session_active' => session_status() === PHP_SESSION_ACTIVE,
    'session_id' => session_id(),
    'session_status' => session_status(),
    'session_status_text' => [
        0 => 'PHP_SESSION_DISABLED',
        1 => 'PHP_SESSION_NONE',
        2 => 'PHP_SESSION_ACTIVE'
    ][session_status()] ?? 'UNKNOWN',
    'session_name' => session_name(),
    'session_save_path' => session_save_path(),
    'session_cache_expire' => session_cache_expire(),
    'session_cache_limiter' => session_cache_limiter(),
    'session_module_name' => session_module_name(),
    'session_cookie_params' => session_get_cookie_params(),
    'logged_in' => isset($_SESSION['logged_in']) && $_SESSION['logged_in'],
    'has_user_id' => isset($_SESSION['user_id']),
    'session_data' => $_SESSION,
    'session_data_count' => count($_SESSION),
    'cookies' => $_COOKIE,
    'cookies_count' => count($_COOKIE),
    'server_info' => [
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'http_host' => $_SERVER['HTTP_HOST'] ?? 'Unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
        'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'Unknown',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
        'session_use_cookies' => ini_get('session.use_cookies'),
        'session_use_only_cookies' => ini_get('session.use_only_cookies'),
        'session_use_trans_sid' => ini_get('session.use_trans_sid'),
        'session_cookie_httponly' => ini_get('session.cookie_httponly'),
        'session_cookie_secure' => ini_get('session.cookie_secure'),
        'session_cookie_samesite' => ini_get('session.cookie_samesite'),
        'session_gc_maxlifetime' => ini_get('session.gc_maxlifetime'),
        'session_cookie_lifetime' => ini_get('session.cookie_lifetime')
    ],
    'timestamp' => date('Y-m-d H:i:s'),
    'timestamp_unix' => time()
];

// Check login status
$is_logged_in = isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Check - CollaboraNexio</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f3f4f6;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            color: #1f2937;
            margin-bottom: 10px;
            font-size: 32px;
        }
        .status-banner {
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            font-size: 18px;
            font-weight: 600;
            text-align: center;
        }
        .status-banner.logged-in {
            background: #c6f6d5;
            color: #22543d;
            border: 2px solid #9ae6b4;
        }
        .status-banner.not-logged-in {
            background: #fed7d7;
            color: #742a2a;
            border: 2px solid #fc8181;
        }
        .info-section {
            background: white;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .info-section h2 {
            color: #4a5568;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .info-item {
            padding: 10px;
            background: #f7fafc;
            border-radius: 6px;
            border-left: 4px solid #667eea;
        }
        .info-label {
            font-weight: 600;
            color: #4a5568;
            display: inline-block;
            margin-bottom: 5px;
        }
        .info-value {
            color: #2d3748;
            word-break: break-all;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .info-value.true {
            color: #48bb78;
            font-weight: 600;
        }
        .info-value.false {
            color: #f56565;
            font-weight: 600;
        }
        .debug-panel {
            background: #1a202c;
            color: #a0aec0;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 500px;
            overflow-y: auto;
        }
        .debug-panel h3 {
            color: #48bb78;
            margin-bottom: 10px;
        }
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            margin: 0;
        }
        .actions {
            display: flex;
            gap: 15px;
            margin: 30px 0;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-block;
            border: 2px solid transparent;
        }
        .btn-primary {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .btn-primary:hover {
            background: #5a67d8;
            border-color: #5a67d8;
        }
        .btn-secondary {
            background: white;
            color: #4a5568;
            border-color: #cbd5e0;
        }
        .btn-secondary:hover {
            background: #f7fafc;
            border-color: #a0aec0;
        }
        .btn-danger {
            background: #f56565;
            color: white;
            border-color: #f56565;
        }
        .btn-danger:hover {
            background: #e53e3e;
            border-color: #e53e3e;
        }
        .quick-info {
            background: #ebf8ff;
            border: 1px solid #90cdf4;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .quick-info-item {
            display: inline-block;
            margin-right: 30px;
            margin-bottom: 10px;
        }
        .session-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .session-indicator.active {
            background: #48bb78;
            animation: pulse 2s infinite;
        }
        .session-indicator.inactive {
            background: #f56565;
        }
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(72, 187, 120, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(72, 187, 120, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(72, 187, 120, 0);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Session Check - CollaboraNexio</h1>
        <p style="color: #718096; margin-bottom: 20px;">
            Real-time session status without any redirects
        </p>

        <!-- Status Banner -->
        <?php if ($is_logged_in): ?>
            <div class="status-banner logged-in">
                <span class="session-indicator active"></span>
                SESSION ACTIVE - User is logged in
            </div>
        <?php else: ?>
            <div class="status-banner not-logged-in">
                <span class="session-indicator inactive"></span>
                NO ACTIVE SESSION - User is not logged in
            </div>
        <?php endif; ?>

        <!-- Quick Info -->
        <div class="quick-info">
            <div class="quick-info-item">
                <strong>Session ID:</strong> <?php echo htmlspecialchars(substr(session_id(), 0, 16)); ?>...
            </div>
            <div class="quick-info-item">
                <strong>Status:</strong> <?php echo $session_info['session_status_text']; ?>
            </div>
            <div class="quick-info-item">
                <strong>Variables:</strong> <?php echo $session_info['session_data_count']; ?>
            </div>
            <div class="quick-info-item">
                <strong>Cookies:</strong> <?php echo $session_info['cookies_count']; ?>
            </div>
            <div class="quick-info-item">
                <strong>Time:</strong> <?php echo $session_info['timestamp']; ?>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="actions">
            <?php if ($is_logged_in): ?>
                <a href="dashboard_direct.php" class="btn btn-primary">Go to Dashboard</a>
                <a href="logout.php" class="btn btn-danger">Logout</a>
            <?php else: ?>
                <a href="login_fixed.php" class="btn btn-primary">Go to Login</a>
                <a href="dashboard_direct.php" class="btn btn-secondary">Try Dashboard (will show debug)</a>
            <?php endif; ?>
            <button onclick="location.reload();" class="btn btn-secondary">Refresh Page</button>
            <button onclick="testSession();" class="btn btn-secondary">Test Session API</button>
        </div>

        <!-- Session Data -->
        <?php if ($is_logged_in && !empty($_SESSION)): ?>
        <div class="info-section">
            <h2>User Session Data</h2>
            <div class="info-grid">
                <?php if (isset($_SESSION['user_id'])): ?>
                <div class="info-item">
                    <div class="info-label">User ID:</div>
                    <div class="info-value"><?php echo htmlspecialchars($_SESSION['user_id']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['user_name'])): ?>
                <div class="info-item">
                    <div class="info-label">Name:</div>
                    <div class="info-value"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['user_email'])): ?>
                <div class="info-item">
                    <div class="info-label">Email:</div>
                    <div class="info-value"><?php echo htmlspecialchars($_SESSION['user_email']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['user_role'])): ?>
                <div class="info-item">
                    <div class="info-label">Role:</div>
                    <div class="info-value"><?php echo htmlspecialchars($_SESSION['user_role']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['tenant_name'])): ?>
                <div class="info-item">
                    <div class="info-label">Tenant:</div>
                    <div class="info-value"><?php echo htmlspecialchars($_SESSION['tenant_name']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['login_time'])): ?>
                <div class="info-item">
                    <div class="info-label">Login Time:</div>
                    <div class="info-value"><?php echo date('Y-m-d H:i:s', $_SESSION['login_time']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Technical Info -->
        <div class="info-section">
            <h2>Technical Session Information</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Session Active:</div>
                    <div class="info-value <?php echo $session_info['session_active'] ? 'true' : 'false'; ?>">
                        <?php echo $session_info['session_active'] ? 'TRUE' : 'FALSE'; ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Has User ID:</div>
                    <div class="info-value <?php echo $session_info['has_user_id'] ? 'true' : 'false'; ?>">
                        <?php echo $session_info['has_user_id'] ? 'TRUE' : 'FALSE'; ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Logged In Flag:</div>
                    <div class="info-value <?php echo $session_info['logged_in'] ? 'true' : 'false'; ?>">
                        <?php echo $session_info['logged_in'] ? 'TRUE' : 'FALSE'; ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Session Name:</div>
                    <div class="info-value"><?php echo htmlspecialchars($session_info['session_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Save Path:</div>
                    <div class="info-value"><?php echo htmlspecialchars($session_info['session_save_path']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Module:</div>
                    <div class="info-value"><?php echo htmlspecialchars($session_info['session_module_name']); ?></div>
                </div>
            </div>
        </div>

        <!-- Server Configuration -->
        <div class="info-section">
            <h2>Server Configuration</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">PHP Version:</div>
                    <div class="info-value"><?php echo htmlspecialchars($session_info['server_info']['php_version']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Use Cookies:</div>
                    <div class="info-value"><?php echo htmlspecialchars($session_info['server_info']['session_use_cookies']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Cookie Lifetime:</div>
                    <div class="info-value"><?php echo htmlspecialchars($session_info['server_info']['session_cookie_lifetime']); ?> seconds</div>
                </div>
                <div class="info-item">
                    <div class="info-label">GC Max Lifetime:</div>
                    <div class="info-value"><?php echo htmlspecialchars($session_info['server_info']['session_gc_maxlifetime']); ?> seconds</div>
                </div>
                <div class="info-item">
                    <div class="info-label">HTTP Only:</div>
                    <div class="info-value"><?php echo htmlspecialchars($session_info['server_info']['session_cookie_httponly']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Same Site:</div>
                    <div class="info-value"><?php echo htmlspecialchars($session_info['server_info']['session_cookie_samesite'] ?: 'Not set'); ?></div>
                </div>
            </div>
        </div>

        <!-- Raw Debug Data -->
        <div class="debug-panel">
            <h3>Complete Session Debug Data (JSON)</h3>
            <pre><?php echo htmlspecialchars(json_encode($session_info, JSON_PRETTY_PRINT)); ?></pre>
        </div>
    </div>

    <script>
        // Test session via API
        async function testSession() {
            try {
                const response = await fetch('auth_api.php?action=session', {
                    credentials: 'include'
                });
                const data = await response.json();

                alert('Session API Response:\n\n' + JSON.stringify(data, null, 2));

                console.log('Session API Response:', data);
            } catch (error) {
                alert('Error testing session API: ' + error.message);
                console.error('Error:', error);
            }
        }

        // Auto-refresh info
        let autoRefreshInterval = null;

        function toggleAutoRefresh() {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
                document.getElementById('autoRefreshBtn').textContent = 'Start Auto-Refresh';
            } else {
                autoRefreshInterval = setInterval(() => {
                    location.reload();
                }, 5000);
                document.getElementById('autoRefreshBtn').textContent = 'Stop Auto-Refresh';
            }
        }
    </script>
</body>
</html>