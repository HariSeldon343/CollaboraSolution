<?php
/**
 * Emergency Access - Minimal login page with no dependencies
 * Use this when the main application is not working
 */

// Basic error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session with basic settings
session_start();

// Direct database configuration (modify these values as needed)
define('EMERGENCY_DB_HOST', 'localhost');
define('EMERGENCY_DB_NAME', 'collaboranexio');
define('EMERGENCY_DB_USER', 'root');
define('EMERGENCY_DB_PASS', '');

// Admin credentials for emergency access (change these!)
define('EMERGENCY_ADMIN_USER', 'admin');
define('EMERGENCY_ADMIN_PASS', 'admin123'); // Change this immediately!

$message = '';
$message_type = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'emergency_login') {
        // Emergency admin login
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if ($username === EMERGENCY_ADMIN_USER && $password === EMERGENCY_ADMIN_PASS) {
            $_SESSION['emergency_admin'] = true;
            $_SESSION['user_id'] = 999999; // Special emergency admin ID
            $_SESSION['username'] = 'Emergency Admin';
            $_SESSION['role'] = 'admin';
            $message = 'Emergency access granted! You are logged in as Emergency Admin.';
            $message_type = 'success';
        } else {
            $message = 'Invalid emergency credentials!';
            $message_type = 'error';
        }
    } elseif ($action === 'db_login') {
        // Database login attempt
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        try {
            $pdo = new PDO(
                'mysql:host=' . EMERGENCY_DB_HOST . ';dbname=' . EMERGENCY_DB_NAME . ';charset=utf8mb4',
                EMERGENCY_DB_USER,
                EMERGENCY_DB_PASS
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Try to find user in database
            $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'] ?? 'user';
                $message = 'Login successful! Welcome back, ' . htmlspecialchars($user['username']);
                $message_type = 'success';
            } else {
                $message = 'Invalid username or password!';
                $message_type = 'error';
            }
        } catch (PDOException $e) {
            $message = 'Database error: ' . htmlspecialchars($e->getMessage());
            $message_type = 'error';
        }
    } elseif ($action === 'create_admin') {
        // Create new admin user
        if (isset($_SESSION['emergency_admin'])) {
            $new_username = $_POST['new_username'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $new_email = $_POST['new_email'] ?? '';

            if (strlen($new_username) >= 3 && strlen($new_password) >= 6) {
                try {
                    $pdo = new PDO(
                        'mysql:host=' . EMERGENCY_DB_HOST . ';dbname=' . EMERGENCY_DB_NAME . ';charset=utf8mb4',
                        EMERGENCY_DB_USER,
                        EMERGENCY_DB_PASS
                    );
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, created_at) VALUES (?, ?, ?, 'admin', NOW())");
                    $stmt->execute([$new_username, $new_email, $hashed_password]);

                    $message = 'New admin user created successfully!';
                    $message_type = 'success';
                } catch (PDOException $e) {
                    $message = 'Failed to create user: ' . htmlspecialchars($e->getMessage());
                    $message_type = 'error';
                }
            } else {
                $message = 'Username must be at least 3 characters and password at least 6 characters!';
                $message_type = 'error';
            }
        } else {
            $message = 'You must be logged in as emergency admin to create users!';
            $message_type = 'error';
        }
    } elseif ($action === 'test_db') {
        // Test database connection
        try {
            $pdo = new PDO(
                'mysql:host=' . EMERGENCY_DB_HOST . ';dbname=' . EMERGENCY_DB_NAME . ';charset=utf8mb4',
                EMERGENCY_DB_USER,
                EMERGENCY_DB_PASS
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->query("SELECT VERSION() as version, DATABASE() as db");
            $info = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $message = 'Database connected! MySQL ' . $info['version'] . ', Database: ' . $info['db'] . ', Tables: ' . implode(', ', $tables);
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = 'Database connection failed: ' . htmlspecialchars($e->getMessage());
            $message_type = 'error';
        }
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: emergency_access.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emergency Access - CollaboraNexio</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 100%;
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 14px;
        }

        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px;
            border-radius: 5px;
        }

        .warning strong {
            color: #856404;
        }

        .content {
            padding: 30px;
        }

        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid #e0e0e0;
        }

        .tab {
            padding: 10px 20px;
            background: none;
            border: none;
            color: #666;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
        }

        .tab:hover {
            color: #667eea;
        }

        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: #6c757d;
            margin-top: 10px;
        }

        .btn-danger {
            background: #dc3545;
        }

        .info-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }

        .info-box h3 {
            color: #333;
            font-size: 16px;
            margin-bottom: 10px;
        }

        .info-box p {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }

        .status {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #e8f5e9;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #4caf50;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(76, 175, 80, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(76, 175, 80, 0);
            }
        }

        .logout-link {
            display: inline-block;
            margin-top: 20px;
            color: #dc3545;
            text-decoration: none;
            font-weight: 600;
        }

        .logout-link:hover {
            text-decoration: underline;
        }

        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚨 Emergency Access</h1>
            <p>Bypass normal authentication when main system is down</p>
        </div>

        <?php if (!isset($_SESSION['user_id'])): ?>

        <div class="warning">
            <strong>⚠️ Warning:</strong> This is an emergency access page. Use only when the main application is not working. All actions are logged.
        </div>

        <div class="content">
            <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
            <?php endif; ?>

            <div class="tabs">
                <button class="tab active" onclick="showTab('emergency')">Emergency Login</button>
                <button class="tab" onclick="showTab('database')">Database Login</button>
                <button class="tab" onclick="showTab('test')">Test Connection</button>
            </div>

            <!-- Emergency Login Tab -->
            <div id="emergency" class="tab-content active">
                <form method="POST">
                    <input type="hidden" name="action" value="emergency_login">

                    <div class="form-group">
                        <label>Emergency Username</label>
                        <input type="text" name="username" value="admin" required>
                    </div>

                    <div class="form-group">
                        <label>Emergency Password</label>
                        <input type="password" name="password" placeholder="Default: admin123" required>
                    </div>

                    <button type="submit" class="btn">Emergency Login</button>
                </form>

                <div class="info-box">
                    <h3>Default Credentials</h3>
                    <p>Username: <code>admin</code><br>
                    Password: <code>admin123</code><br>
                    <small>Change these in the PHP file immediately!</small></p>
                </div>
            </div>

            <!-- Database Login Tab -->
            <div id="database" class="tab-content">
                <form method="POST">
                    <input type="hidden" name="action" value="db_login">

                    <div class="form-group">
                        <label>Username or Email</label>
                        <input type="text" name="username" required>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" required>
                    </div>

                    <button type="submit" class="btn">Login via Database</button>
                </form>

                <div class="info-box">
                    <h3>Database Settings</h3>
                    <p>Host: <code><?php echo EMERGENCY_DB_HOST; ?></code><br>
                    Database: <code><?php echo EMERGENCY_DB_NAME; ?></code><br>
                    User: <code><?php echo EMERGENCY_DB_USER; ?></code></p>
                </div>
            </div>

            <!-- Test Connection Tab -->
            <div id="test" class="tab-content">
                <form method="POST">
                    <input type="hidden" name="action" value="test_db">
                    <button type="submit" class="btn">Test Database Connection</button>
                </form>

                <div class="info-box">
                    <h3>Connection Test</h3>
                    <p>Click the button above to test if the database connection is working. This will show you the MySQL version, database name, and available tables.</p>
                </div>
            </div>
        </div>

        <?php else: ?>

        <div class="content">
            <div class="status">
                <div class="status-dot"></div>
                <span>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> (Role: <?php echo htmlspecialchars($_SESSION['role'] ?? 'user'); ?>)</span>
            </div>

            <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['emergency_admin'])): ?>
            <h2>Create New Admin User</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_admin">

                <div class="form-group">
                    <label>New Username</label>
                    <input type="text" name="new_username" required minlength="3">
                </div>

                <div class="form-group">
                    <label>New Email</label>
                    <input type="email" name="new_email" required>
                </div>

                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" required minlength="6">
                </div>

                <button type="submit" class="btn">Create Admin User</button>
            </form>
            <?php endif; ?>

            <div class="info-box">
                <h3>Quick Actions</h3>
                <p>
                    <a href="/" class="btn btn-secondary" style="display: inline-block; text-decoration: none; padding: 10px 20px;">Go to Main Application</a><br><br>
                    <a href="diagnostic.php" class="btn btn-secondary" style="display: inline-block; text-decoration: none; padding: 10px 20px;">System Diagnostic</a>
                </p>
            </div>

            <a href="?logout=1" class="logout-link">Logout</a>
        </div>

        <?php endif; ?>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tabs
            const tabs = document.querySelectorAll('.tab-content');
            tabs.forEach(tab => tab.classList.remove('active'));

            // Remove active class from all tab buttons
            const buttons = document.querySelectorAll('.tab');
            buttons.forEach(btn => btn.classList.remove('active'));

            // Show selected tab
            document.getElementById(tabName).classList.add('active');

            // Add active class to clicked button
            event.target.classList.add('active');
        }
    </script>
</body>
</html>