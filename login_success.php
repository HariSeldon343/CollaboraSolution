<?php
session_start();

// Verifica se l'utente è loggato
$is_logged_in = isset($_SESSION['user_id']) && isset($_SESSION['logged_in']);

// Raccogli informazioni di debug sulla sessione
$session_debug = [
    'session_id' => session_id(),
    'session_status' => session_status(),
    'session_name' => session_name(),
    'session_save_path' => session_save_path(),
    'session_cookie_params' => session_get_cookie_params(),
    'session_data' => $_SESSION,
    'cookies' => $_COOKIE,
    'server' => [
        'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? '',
        'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? '',
        'PHP_SELF' => $_SERVER['PHP_SELF'] ?? '',
        'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? ''
    ],
    'time' => date('Y-m-d H:i:s')
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollaboraNexio - Login Success</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            max-width: 800px;
            width: 100%;
            padding: 40px;
        }
        h1 {
            color: #2d3748;
            text-align: center;
            margin-bottom: 20px;
            font-size: 28px;
        }
        .status-box {
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: center;
        }
        .status-box.success {
            background: #c6f6d5;
            color: #22543d;
            border: 2px solid #9ae6b4;
        }
        .status-box.error {
            background: #fed7d7;
            color: #742a2a;
            border: 2px solid #fc8181;
        }
        .user-info {
            background: #f7fafc;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .user-info h2 {
            color: #4a5568;
            margin-bottom: 15px;
            font-size: 20px;
        }
        .info-row {
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            display: inline-block;
            width: 150px;
            font-weight: 600;
            color: #4a5568;
        }
        .info-value {
            color: #2d3748;
        }
        .debug-panel {
            background: #1a202c;
            color: #a0aec0;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 400px;
            overflow-y: auto;
        }
        .debug-panel h3 {
            color: #48bb78;
            margin-bottom: 10px;
        }
        .actions {
            display: flex;
            gap: 15px;
            margin: 30px 0;
            justify-content: center;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-block;
            text-align: center;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }
        .btn-secondary:hover {
            background: #cbd5e0;
        }
        .countdown {
            text-align: center;
            margin: 20px 0;
            color: #718096;
            font-size: 14px;
        }
        .warning-box {
            background: #fef5e7;
            border: 2px solid #f8c471;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            color: #935116;
        }
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>CollaboraNexio - Login Status</h1>

        <?php if ($is_logged_in): ?>
            <div class="status-box success">
                <h2>✓ Login Successful!</h2>
                <p>Your session has been successfully created.</p>
            </div>

            <div class="user-info">
                <h2>User Information</h2>
                <div class="info-row">
                    <span class="info-label">User ID:</span>
                    <span class="info-value"><?php echo htmlspecialchars($_SESSION['user_id'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Name:</span>
                    <span class="info-value"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value"><?php echo htmlspecialchars($_SESSION['user_email'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Role:</span>
                    <span class="info-value"><?php echo htmlspecialchars($_SESSION['user_role'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Tenant:</span>
                    <span class="info-value"><?php echo htmlspecialchars($_SESSION['tenant_name'] ?? 'N/A'); ?> (ID: <?php echo htmlspecialchars($_SESSION['tenant_id'] ?? 'N/A'); ?>)</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Login Time:</span>
                    <span class="info-value"><?php echo isset($_SESSION['login_time']) ? date('Y-m-d H:i:s', $_SESSION['login_time']) : 'N/A'; ?></span>
                </div>
            </div>

            <div class="countdown">
                No automatic redirect - click the button below to proceed to the dashboard
            </div>

            <div class="actions">
                <a href="dashboard.php" class="btn btn-primary">Go to Dashboard</a>
                <a href="check_session.php" class="btn btn-secondary">Check Session</a>
                <a href="logout.php" class="btn btn-secondary">Logout</a>
            </div>

        <?php else: ?>
            <div class="status-box error">
                <h2>✗ Not Logged In</h2>
                <p>No active session found. Please log in first.</p>
            </div>

            <div class="warning-box">
                <strong>Session Issue Detected:</strong><br>
                The session does not contain valid login information. This could mean:
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li>You haven't logged in yet</li>
                    <li>Your session has expired</li>
                    <li>Session cookies are not being saved properly</li>
                    <li>There's a session configuration issue</li>
                </ul>
            </div>

            <div class="actions">
                <a href="index.php" class="btn btn-primary">Go to Login</a>
                <a href="check_session.php" class="btn btn-secondary">Check Session</a>
            </div>
        <?php endif; ?>

        <div class="debug-panel">
            <h3>Session Debug Information:</h3>
            <pre><?php echo htmlspecialchars(json_encode($session_debug, JSON_PRETTY_PRINT)); ?></pre>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <p style="color: #718096; font-size: 12px;">
                Current Time: <?php echo date('Y-m-d H:i:s'); ?><br>
                Session ID: <?php echo htmlspecialchars(session_id()); ?><br>
                PHP Session Status: <?php echo session_status(); ?>
                (0=Disabled, 1=None, 2=Active)
            </p>
        </div>
    </div>
</body>
</html>