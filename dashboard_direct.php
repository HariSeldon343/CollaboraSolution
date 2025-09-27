<?php
session_start();

// Debug session information
$session_debug = [
    'session_id' => session_id(),
    'session_status' => session_status(),
    'session_name' => session_name(),
    'session_save_path' => session_save_path(),
    'session_cookie_params' => session_get_cookie_params(),
    'session_data' => $_SESSION,
    'cookies' => $_COOKIE,
    'time' => date('Y-m-d H:i:s')
];

// Simple direct session check - NO REDIRECT, just show debug info
$is_logged_in = isset($_SESSION['user_id']) && isset($_SESSION['logged_in']);
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
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f3f4f6;
        }
        .header {
            background: white;
            padding: 1rem 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo {
            font-size: 1.5rem;
            font-weight: bold;
            color: #667eea;
        }
        .user-info {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        .logout-btn {
            background: #ef4444;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            text-decoration: none;
        }
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }
        .card {
            background: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .card-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #1f2937;
        }
        .card-description {
            color: #6b7280;
            line-height: 1.5;
            margin-bottom: 1rem;
        }
        .card-link {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            text-decoration: none;
            font-size: 0.875rem;
        }
        .card-link:hover {
            background: #5a67d8;
        }
        .welcome-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 0.5rem;
            margin-bottom: 2rem;
        }
        .welcome-section h1 {
            margin-bottom: 0.5rem;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }
        .stat-card {
            background: rgba(255,255,255,0.2);
            padding: 1rem;
            border-radius: 0.375rem;
            backdrop-filter: blur(10px);
        }
        .stat-value {
            font-size: 1.875rem;
            font-weight: bold;
        }
        .stat-label {
            font-size: 0.875rem;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <!-- Session Debug Panel -->
    <?php if (!$is_logged_in): ?>
    <div style="background: #fee; border: 2px solid #c00; padding: 20px; margin: 20px; border-radius: 8px;">
        <h2 style="color: #c00;">Session Not Active - Not Logged In</h2>
        <p>The session does not contain valid login information.</p>
        <div style="margin: 20px 0;">
            <a href="login_fixed.php" style="display: inline-block; background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Go to Login Page</a>
        </div>
    </div>
    <?php endif; ?>

    <div style="background: #f0f0f0; border: 1px solid #999; padding: 15px; margin: 20px; border-radius: 8px; font-family: monospace; font-size: 12px;">
        <h3>Session Debug Information:</h3>
        <pre><?php echo htmlspecialchars(json_encode($session_debug, JSON_PRETTY_PRINT)); ?></pre>
    </div>

    <header class="header">
        <div class="logo">🚀 CollaboraNexio</div>
        <div class="user-info">
            <?php if ($is_logged_in): ?>
                <span>👤 <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></span>
                <span>📧 <?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?></span>
                <a href="logout.php" class="logout-btn">Logout</a>
            <?php else: ?>
                <span style="color: #c00;">Not logged in</span>
                <a href="login_fixed.php" class="logout-btn">Login</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="container">
        <div class="welcome-section">
            <h1>Benvenuto, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>!</h1>
            <p>Gestisci i tuoi progetti e collabora con il tuo team</p>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value">5</div>
                    <div class="stat-label">Progetti Attivi</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">12</div>
                    <div class="stat-label">Task in Corso</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">8</div>
                    <div class="stat-label">Team Members</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">24</div>
                    <div class="stat-label">File Condivisi</div>
                </div>
            </div>
        </div>

        <h2 style="margin-bottom: 1rem;">Moduli Disponibili</h2>

        <div class="grid">
            <div class="card">
                <div class="card-icon">📁</div>
                <div class="card-title">File Manager</div>
                <div class="card-description">Gestisci e condividi i tuoi documenti in modo sicuro</div>
                <a href="files.php" class="card-link">Apri File Manager</a>
            </div>

            <div class="card">
                <div class="card-icon">📅</div>
                <div class="card-title">Calendario</div>
                <div class="card-description">Organizza eventi e riunioni con il tuo team</div>
                <a href="calendar.php" class="card-link">Apri Calendario</a>
            </div>

            <div class="card">
                <div class="card-icon">✅</div>
                <div class="card-title">Task Manager</div>
                <div class="card-description">Crea e assegna task ai membri del team</div>
                <a href="tasks.php" class="card-link">Apri Task Manager</a>
            </div>

            <div class="card">
                <div class="card-icon">💬</div>
                <div class="card-title">Chat</div>
                <div class="card-description">Comunica in tempo reale con il tuo team</div>
                <a href="chat.php" class="card-link">Apri Chat</a>
            </div>

            <div class="card">
                <div class="card-icon">📊</div>
                <div class="card-title">Report</div>
                <div class="card-description">Analizza le performance del tuo team</div>
                <a href="reports.php" class="card-link">Apri Report</a>
            </div>

            <div class="card">
                <div class="card-icon">⚙️</div>
                <div class="card-title">Impostazioni</div>
                <div class="card-description">Configura il tuo profilo e le preferenze</div>
                <a href="settings.php" class="card-link">Apri Impostazioni</a>
            </div>
        </div>

        <h2 style="margin: 2rem 0 1rem;">Strumenti di Test</h2>

        <div class="grid">
            <div class="card">
                <div class="card-icon">🗄️</div>
                <div class="card-title">Test Database</div>
                <div class="card-description">Verifica la connessione al database</div>
                <a href="test_db.php" class="card-link">Test DB</a>
            </div>

            <div class="card">
                <div class="card-icon">🔌</div>
                <div class="card-title">Test API</div>
                <div class="card-description">Verifica le API di autenticazione</div>
                <a href="api_test.php" class="card-link">Test API</a>
            </div>

            <div class="card">
                <div class="card-icon">ℹ️</div>
                <div class="card-title">PHP Info</div>
                <div class="card-description">Informazioni sulla configurazione PHP</div>
                <a href="test_8888.php" class="card-link">PHP Info</a>
            </div>
        </div>
    </div>
</body>
</html>