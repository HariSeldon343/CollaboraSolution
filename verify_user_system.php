<?php
/**
 * Script di verifica completo del sistema di creazione utenti
 * Esegui: http://localhost:8888/CollaboraNexio/verify_user_system.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

session_start();

// Simula admin per test
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['tenant_id'] = 1;
    $_SESSION['role'] = 'admin';
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Verifica Sistema Utenti</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 { color: #333; }
        h2 {
            color: #666;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 10px;
        }
        .success {
            color: #4CAF50;
            font-weight: bold;
        }
        .error {
            color: #f44336;
            font-weight: bold;
        }
        .warning {
            color: #ff9800;
            font-weight: bold;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            text-align: left;
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        th { background: #f0f0f0; }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin: 5px;
        }
        .btn:hover { background: #45a049; }
        .test-result {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .test-success { background: #e8f5e9; }
        .test-error { background: #ffebee; }
        .test-warning { background: #fff3e0; }
    </style>
</head>
<body>
    <h1>🔍 Verifica Sistema Gestione Utenti</h1>

    <div class="card">
        <h2>1. Verifica Struttura Database</h2>
        <?php
        try {
            $db = Database::getInstance();

            // Verifica tabella users
            echo "<h3>Tabella Users</h3>";
            $result = $db->query("DESCRIBE users");
            $columns = [];
            echo "<table>";
            echo "<tr><th>Colonna</th><th>Tipo</th><th>Null</th><th>Default</th></tr>";
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $row['Field'];
                echo "<tr>";
                echo "<td>{$row['Field']}</td>";
                echo "<td>{$row['Type']}</td>";
                echo "<td>{$row['Null']}</td>";
                echo "<td>{$row['Default']}</td>";
                echo "</tr>";
            }
            echo "</table>";

            // Verifica colonne critiche
            $required = ['password_reset_token', 'password_reset_expires', 'first_login', 'welcome_email_sent_at'];
            $missing = array_diff($required, $columns);

            if (empty($missing)) {
                echo '<div class="test-result test-success">✅ Tutte le colonne necessarie sono presenti</div>';
            } else {
                echo '<div class="test-result test-error">❌ Colonne mancanti: ' . implode(', ', $missing) . '</div>';
                echo '<p>Esegui: <a href="fix_users_table.php" class="btn">Fix Database</a></p>';
            }

            // Verifica altre tabelle
            $tables = ['tenants', 'user_companies', 'audit_logs'];
            echo "<h3>Altre Tabelle</h3>";
            foreach ($tables as $table) {
                try {
                    $db->query("SELECT 1 FROM $table LIMIT 1");
                    echo '<div class="test-result test-success">✅ Tabella ' . $table . ' presente</div>';
                } catch (Exception $e) {
                    echo '<div class="test-result test-error">❌ Tabella ' . $table . ' mancante</div>';
                }
            }

        } catch (Exception $e) {
            echo '<div class="test-result test-error">❌ Errore database: ' . $e->getMessage() . '</div>';
        }
        ?>
    </div>

    <div class="card">
        <h2>2. Verifica API Endpoints</h2>
        <?php
        $apis = [
            'create_v3.php' => 'api/users/create_v3.php',
            'create_v2.php' => 'api/users/create_v2.php',
            'list.php' => 'api/users/list.php',
            'update.php' => 'api/users/update.php',
            'delete.php' => 'api/users/delete.php'
        ];

        foreach ($apis as $name => $path) {
            $full_path = __DIR__ . '/' . $path;
            if (file_exists($full_path)) {
                $size = filesize($full_path);
                $modified = date('Y-m-d H:i:s', filemtime($full_path));
                echo '<div class="test-result test-success">✅ ' . $name . ' (' . $size . ' bytes, modificato: ' . $modified . ')</div>';
            } else {
                echo '<div class="test-result test-error">❌ ' . $name . ' non trovato</div>';
            }
        }
        ?>
    </div>

    <div class="card">
        <h2>3. Test Creazione Utente (API v3)</h2>
        <button onclick="testCreateUser()" class="btn">Test Creazione Utente</button>
        <div id="create-test-result"></div>
    </div>

    <div class="card">
        <h2>4. Verifica Sessione e Permessi</h2>
        <?php
        echo "<pre>";
        echo "User ID: " . ($_SESSION['user_id'] ?? 'Non impostato') . "\n";
        echo "Tenant ID: " . ($_SESSION['tenant_id'] ?? 'Non impostato') . "\n";
        echo "Role: " . ($_SESSION['role'] ?? 'Non impostato') . "\n";
        echo "CSRF Token: " . substr($_SESSION['csrf_token'] ?? '', 0, 20) . "...\n";
        echo "Session ID: " . session_id() . "\n";
        echo "</pre>";

        if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
            echo '<div class="test-result test-success">✅ Sessione configurata correttamente</div>';
        } else {
            echo '<div class="test-result test-warning">⚠️ Sessione non completamente configurata</div>';
        }
        ?>
    </div>

    <div class="card">
        <h2>5. Verifica Include Files</h2>
        <?php
        $includes = [
            'config.php',
            'includes/db.php',
            'includes/api_auth.php',
            'includes/session_init.php',
            'includes/EmailSender.php',
            'includes/auth_simple.php'
        ];

        foreach ($includes as $file) {
            $full_path = __DIR__ . '/' . $file;
            if (file_exists($full_path)) {
                echo '<div class="test-result test-success">✅ ' . $file . ' presente</div>';
            } else {
                echo '<div class="test-result test-error">❌ ' . $file . ' mancante</div>';
            }
        }
        ?>
    </div>

    <div class="card">
        <h2>6. Ultimi Utenti Creati</h2>
        <?php
        try {
            $users = $db->query("
                SELECT id, email, first_name, last_name, role, status, created_at, first_login
                FROM users
                ORDER BY created_at DESC
                LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);

            if ($users) {
                echo "<table>";
                echo "<tr><th>ID</th><th>Email</th><th>Nome</th><th>Ruolo</th><th>Status</th><th>Primo Login</th><th>Creato</th></tr>";
                foreach ($users as $user) {
                    echo "<tr>";
                    echo "<td>{$user['id']}</td>";
                    echo "<td>{$user['email']}</td>";
                    echo "<td>{$user['first_name']} {$user['last_name']}</td>";
                    echo "<td>{$user['role']}</td>";
                    echo "<td>{$user['status']}</td>";
                    echo "<td>" . ($user['first_login'] ? 'Sì' : 'No') . "</td>";
                    echo "<td>{$user['created_at']}</td>";
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo '<p>Nessun utente trovato</p>';
            }
        } catch (Exception $e) {
            echo '<div class="test-result test-error">❌ Errore: ' . $e->getMessage() . '</div>';
        }
        ?>
    </div>

    <div class="card">
        <h2>Link Utili</h2>
        <a href="utenti.php" class="btn">Gestione Utenti</a>
        <a href="fix_users_table.php" class="btn">Fix Database</a>
        <a href="debug_create_user.php" class="btn">Debug Dettagliato</a>
        <a href="test_create_user_api.php" class="btn">Test API</a>
        <a href="dashboard.php" class="btn">Dashboard</a>
    </div>

    <script>
    async function testCreateUser() {
        const resultDiv = document.getElementById('create-test-result');
        resultDiv.innerHTML = '<p>Test in corso...</p>';

        const testData = {
            first_name: 'Test',
            last_name: 'User_' + Date.now(),
            email: 'test_' + Date.now() + '@example.com',
            role: 'user',
            tenant_id: 1,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        };

        try {
            const response = await fetch('api/users/create_v3.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?php echo $_SESSION['csrf_token']; ?>'
                },
                credentials: 'same-origin',
                body: JSON.stringify(testData)
            });

            const result = await response.json();

            let html = '<div class="test-result ' + (result.success ? 'test-success' : 'test-error') + '">';
            html += '<h4>Response (Status: ' + response.status + '):</h4>';
            html += '<pre>' + JSON.stringify(result, null, 2) + '</pre>';
            html += '</div>';

            if (result.success) {
                html += '<p class="success">✅ Test completato con successo! User ID: ' + result.user_id + '</p>';
            } else {
                html += '<p class="error">❌ Test fallito: ' + (result.error || 'Errore sconosciuto') + '</p>';
            }

            resultDiv.innerHTML = html;
        } catch (error) {
            resultDiv.innerHTML = '<div class="test-result test-error"><p class="error">❌ Errore: ' + error.message + '</p></div>';
        }
    }
    </script>
</body>
</html>