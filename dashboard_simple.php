<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in'])) {
    header('Location: index_simple.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollaboraNexio - Dashboard</title>
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
        }
        .header {
            background: rgba(255,255,255,0.95);
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .logout-btn {
            background: #ef4444;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
        }
        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .welcome-card {
            background: white;
            padding: 40px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .welcome-card h1 {
            color: #333;
            margin-bottom: 20px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        .info-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .info-card h3 {
            color: #667eea;
            margin-bottom: 10px;
        }
        .info-card p {
            color: #666;
            line-height: 1.5;
        }
        .modules {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .module-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            text-align: center;
            border-radius: 8px;
            text-decoration: none;
            transition: transform 0.2s;
        }
        .module-btn:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">🚀 CollaboraNexio</div>
            <div class="user-info">
                <span>👤 <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></span>
                <span>🏢 <?php echo htmlspecialchars($_SESSION['tenant_name'] ?? 'Default'); ?></span>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="welcome-card">
            <h1>Benvenuto, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>!</h1>
            <p>Sei connesso con successo a CollaboraNexio</p>

            <div class="info-grid">
                <div class="info-card">
                    <h3>📊 Informazioni Sessione</h3>
                    <p>
                        <strong>Email:</strong> <?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?><br>
                        <strong>Ruolo:</strong> <?php echo htmlspecialchars($_SESSION['user_role'] ?? ''); ?><br>
                        <strong>Tenant ID:</strong> <?php echo htmlspecialchars($_SESSION['tenant_id'] ?? ''); ?><br>
                        <strong>Session ID:</strong> <?php echo substr(session_id(), 0, 10) . '...'; ?>
                    </p>
                </div>

                <div class="info-card">
                    <h3>🔧 Stato Sistema</h3>
                    <p>
                        <strong>PHP Version:</strong> <?php echo PHP_VERSION; ?><br>
                        <strong>Server:</strong> Apache on port 8888<br>
                        <strong>Database:</strong> MySQL Connected<br>
                        <strong>Environment:</strong> Development
                    </p>
                </div>

                <div class="info-card">
                    <h3>⏰ Tempo Sessione</h3>
                    <p>
                        <strong>Login Time:</strong> <?php echo date('H:i:s', $_SESSION['login_time'] ?? time()); ?><br>
                        <strong>Session Lifetime:</strong> <?php echo SESSION_LIFETIME / 60; ?> minutes<br>
                        <strong>Current Time:</strong> <?php echo date('H:i:s'); ?><br>
                        <strong>Time Zone:</strong> <?php echo date_default_timezone_get(); ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="welcome-card">
            <h2>📦 Moduli Disponibili</h2>
            <div class="modules">
                <a href="files.php" class="module-btn">📁 File Manager</a>
                <a href="calendar.php" class="module-btn">📅 Calendar</a>
                <a href="tasks.php" class="module-btn">✅ Tasks</a>
                <a href="chat.php" class="module-btn">💬 Chat</a>
                <a href="reports.php" class="module-btn">📊 Reports</a>
                <a href="settings.php" class="module-btn">⚙️ Settings</a>
            </div>
        </div>

        <div class="welcome-card">
            <h2>🔍 Test e Debug</h2>
            <div class="modules">
                <a href="test_db.php" class="module-btn">🗄️ Test Database</a>
                <a href="api_test.php" class="module-btn">🔌 Test API</a>
                <a href="phpinfo.php" class="module-btn">ℹ️ PHP Info</a>
                <a href="test_8888.php" class="module-btn">🌐 Test Port 8888</a>
            </div>
        </div>
    </div>
</body>
</html>