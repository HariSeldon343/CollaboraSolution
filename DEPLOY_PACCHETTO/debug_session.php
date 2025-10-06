<?php
/**
 * Script di Debug Sessione per CollaboraNexio
 * Verifica configurazione sessione e cookie
 */

// Include session_init.php per configurare sessione correttamente
require_once __DIR__ . '/includes/session_init.php';

// Ottieni informazioni sulla sessione
$sessionInfo = [
    'session_id' => session_id(),
    'session_name' => session_name(),
    'session_status' => session_status(),
    'session_status_text' => session_status() === PHP_SESSION_ACTIVE ? 'ACTIVE' :
                             (session_status() === PHP_SESSION_NONE ? 'NONE' : 'DISABLED'),
    'cookie_params' => session_get_cookie_params(),
    'session_data' => $_SESSION ?? [],
    'headers_sent' => headers_sent(),
    'server_info' => [
        'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'N/A',
        'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? 'N/A',
        'HTTPS' => isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : 'N/A',
        'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'N/A',
        'HTTP_COOKIE' => $_SERVER['HTTP_COOKIE'] ?? 'N/A',
    ],
    'php_session_config' => [
        'session.cookie_lifetime' => ini_get('session.cookie_lifetime'),
        'session.cookie_path' => ini_get('session.cookie_path'),
        'session.cookie_domain' => ini_get('session.cookie_domain'),
        'session.cookie_secure' => ini_get('session.cookie_secure'),
        'session.cookie_httponly' => ini_get('session.cookie_httponly'),
        'session.cookie_samesite' => ini_get('session.cookie_samesite'),
        'session.use_only_cookies' => ini_get('session.use_only_cookies'),
        'session.use_strict_mode' => ini_get('session.use_strict_mode'),
        'session.gc_maxlifetime' => ini_get('session.gc_maxlifetime'),
    ]
];

// Determina se utente è autenticato
$isAuthenticated = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

// Stile CSS inline per il debug
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Sessione - CollaboraNexio</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .header h1 {
            color: #1a202c;
            font-size: 28px;
            margin-bottom: 10px;
        }
        .status {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        .status.success { background: #10b981; color: white; }
        .status.error { background: #ef4444; color: white; }
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .card h2 {
            color: #1a202c;
            font-size: 20px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
        }
        .info-item {
            padding: 12px;
            background: #f9fafb;
            border-radius: 8px;
            border-left: 3px solid #667eea;
        }
        .info-item .label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .info-item .value {
            font-size: 14px;
            color: #1a202c;
            font-weight: 500;
            word-break: break-all;
        }
        .info-item .value.code {
            font-family: 'Courier New', monospace;
            background: #1a202c;
            color: #10b981;
            padding: 8px;
            border-radius: 4px;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert.warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }
        .alert.info {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            color: #1e40af;
        }
        .actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: transform 0.2s;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn-primary { background: #667eea; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-secondary { background: #6b7280; color: white; }
        pre {
            background: #1a202c;
            color: #10b981;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🔍 Debug Sessione CollaboraNexio</h1>
            <p style="color: #6b7280; margin-top: 8px;">
                Verifica configurazione sessione e cookie per risolvere errori 401
            </p>
            <div style="margin-top: 15px;">
                <span class="status <?php echo $isAuthenticated ? 'success' : 'error'; ?>">
                    <?php echo $isAuthenticated ? '✓ Autenticato' : '✗ Non Autenticato'; ?>
                </span>
            </div>
        </div>

        <!-- Avvisi -->
        <?php if (!$isAuthenticated): ?>
        <div class="alert warning">
            <strong>⚠️ ATTENZIONE:</strong> La sessione non contiene dati di autenticazione.
            Questo potrebbe essere il motivo degli errori 401. Effettua il login prima di testare le API.
        </div>
        <?php endif; ?>

        <?php if ($_SERVER['HTTP_HOST'] !== 'app.nexiosolution.it'): ?>
        <div class="alert info">
            <strong>ℹ️ NOTA:</strong> Stai testando in ambiente: <code><?php echo htmlspecialchars($_SERVER['HTTP_HOST']); ?></code>
        </div>
        <?php endif; ?>

        <!-- Informazioni Sessione -->
        <div class="card">
            <h2>📋 Informazioni Sessione</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="label">Session ID</div>
                    <div class="value code"><?php echo htmlspecialchars($sessionInfo['session_id']); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Session Name</div>
                    <div class="value"><?php echo htmlspecialchars($sessionInfo['session_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Session Status</div>
                    <div class="value"><?php echo htmlspecialchars($sessionInfo['session_status_text']); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">User ID</div>
                    <div class="value"><?php echo htmlspecialchars($_SESSION['user_id'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Tenant ID</div>
                    <div class="value"><?php echo htmlspecialchars($_SESSION['tenant_id'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Role</div>
                    <div class="value"><?php echo htmlspecialchars($_SESSION['role'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <!-- Configurazione Cookie -->
        <div class="card">
            <h2>🍪 Configurazione Cookie</h2>
            <div class="info-grid">
                <?php foreach ($sessionInfo['cookie_params'] as $key => $value): ?>
                <div class="info-item">
                    <div class="label"><?php echo htmlspecialchars($key); ?></div>
                    <div class="value"><?php echo htmlspecialchars(is_bool($value) ? ($value ? 'true' : 'false') : (string)$value); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Configurazione PHP -->
        <div class="card">
            <h2>⚙️ Configurazione PHP Session</h2>
            <div class="info-grid">
                <?php foreach ($sessionInfo['php_session_config'] as $key => $value): ?>
                <div class="info-item">
                    <div class="label"><?php echo htmlspecialchars($key); ?></div>
                    <div class="value"><?php echo htmlspecialchars((string)$value); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Informazioni Server -->
        <div class="card">
            <h2>🖥️ Informazioni Server</h2>
            <div class="info-grid">
                <?php foreach ($sessionInfo['server_info'] as $key => $value): ?>
                <div class="info-item">
                    <div class="label"><?php echo htmlspecialchars($key); ?></div>
                    <div class="value"><?php echo htmlspecialchars((string)$value); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Dati Sessione Completi -->
        <div class="card">
            <h2>📦 Dati Sessione Completi (JSON)</h2>
            <pre><?php echo json_encode($sessionInfo['session_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?></pre>
        </div>

        <!-- Azioni -->
        <div class="card">
            <h2>🛠️ Azioni</h2>
            <div class="actions">
                <button class="btn btn-primary" onclick="window.location.reload()">🔄 Ricarica</button>
                <button class="btn btn-secondary" onclick="window.location.href='dashboard.php'">📊 Dashboard</button>
                <button class="btn btn-danger" onclick="clearCookies()">🗑️ Cancella Cookie e Ricarica</button>
            </div>
        </div>
    </div>

    <script>
        function clearCookies() {
            // Cancella tutti i cookie
            document.cookie.split(";").forEach(function(c) {
                document.cookie = c.replace(/^ +/, "")
                    .replace(/=.*/, "=;expires=" + new Date().toUTCString() + ";path=/");
            });

            // Cancella localStorage e sessionStorage
            localStorage.clear();
            sessionStorage.clear();

            alert('Cookie cancellati! La pagina verrà ricaricata.');
            window.location.reload();
        }
    </script>
</body>
</html>
