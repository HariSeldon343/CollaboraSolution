<?php
/**
 * Debug specifico per Folder ID 4
 * Mostra dettagli completi dell'errore
 */

require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/db.php';

$auth = new AuthSimple();
$isAuth = $auth->checkAuth();
$currentUser = $auth->getCurrentUser();

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Folder 4 - CollaboraNexio</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .container { max-width: 1400px; margin: 0 auto; }
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
        pre {
            background: #1a202c;
            color: #10b981;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 13px;
            max-height: 400px;
            overflow-y: auto;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: transform 0.2s;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn-primary { background: #667eea; color: white; }
        .error { background: #fee2e2; border: 2px solid #ef4444; color: #991b1b; padding: 15px; border-radius: 8px; }
        .success { background: #d1fae5; border: 2px solid #10b981; color: #065f46; padding: 15px; border-radius: 8px; }
        .info { background: #e3f2fd; border: 2px solid #3b82f6; color: #1e40af; padding: 15px; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f9fafb; font-weight: 600; color: #1a202c; }
        tr:hover { background: #f9fafb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2>🔍 Debug Dettagliato - Folder ID 4</h2>

            <?php if (!$isAuth): ?>
                <div class="error">
                    <strong>⚠️ Non autenticato!</strong> Fai <a href="index.php">login</a> prima.
                </div>
            <?php else: ?>
                <div class="success">
                    <strong>✅ Autenticato:</strong> <?= htmlspecialchars($currentUser['email']) ?>
                    (Role: <?= htmlspecialchars($currentUser['role']) ?>, Tenant: <?= htmlspecialchars($_SESSION['tenant_id'] ?? 'N/A') ?>)
                </div>
            <?php endif; ?>
        </div>

        <?php if ($isAuth): ?>

        <!-- Verifica esistenza folder -->
        <div class="card">
            <h2>1. Verifica Esistenza Folder ID 4</h2>
            <?php
            try {
                $db = Database::getInstance();
                $pdo = $db->getConnection();

                $stmt = $pdo->prepare("SELECT * FROM folders WHERE id = 4");
                $stmt->execute();
                $folder = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($folder) {
                    echo '<div class="success"><strong>✅ Folder 4 esiste nel database</strong></div>';
                    echo '<table>';
                    echo '<tr><th>Campo</th><th>Valore</th></tr>';
                    foreach ($folder as $key => $value) {
                        echo '<tr><td><strong>' . htmlspecialchars($key) . '</strong></td><td>' . htmlspecialchars($value ?? 'NULL') . '</td></tr>';
                    }
                    echo '</table>';
                } else {
                    echo '<div class="error"><strong>❌ Folder ID 4 NON esiste nel database!</strong></div>';
                    echo '<p style="margin-top: 15px;">Questo è il problema. La cartella con ID 4 non esiste nel database di produzione.</p>';
                }
            } catch (Exception $e) {
                echo '<div class="error"><strong>Errore query:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <!-- Lista tutte le cartelle -->
        <div class="card">
            <h2>2. Tutte le Cartelle nel Database</h2>
            <?php
            try {
                $stmt = $pdo->prepare("SELECT id, name, parent_id, tenant_id, created_by FROM folders ORDER BY id ASC");
                $stmt->execute();
                $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if ($folders) {
                    echo '<div class="info"><strong>Trovate ' . count($folders) . ' cartelle</strong></div>';
                    echo '<table>';
                    echo '<tr><th>ID</th><th>Nome</th><th>Parent ID</th><th>Tenant ID</th><th>Created By</th></tr>';
                    foreach ($folders as $f) {
                        $highlight = ($f['id'] == 4) ? 'style="background: #fef3c7;"' : '';
                        echo '<tr ' . $highlight . '>';
                        echo '<td><strong>' . htmlspecialchars($f['id']) . '</strong></td>';
                        echo '<td>' . htmlspecialchars($f['name']) . '</td>';
                        echo '<td>' . htmlspecialchars($f['parent_id'] ?? 'NULL') . '</td>';
                        echo '<td>' . htmlspecialchars($f['tenant_id']) . '</td>';
                        echo '<td>' . htmlspecialchars($f['created_by']) . '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                } else {
                    echo '<div class="error">Nessuna cartella trovata nel database</div>';
                }
            } catch (Exception $e) {
                echo '<div class="error"><strong>Errore query:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <!-- Contenuto folder 4 se esiste -->
        <div class="card">
            <h2>3. Contenuto Folder ID 4 (Files)</h2>
            <?php
            try {
                $stmt = $pdo->prepare("SELECT id, name, size_bytes, mime_type, folder_id, tenant_id FROM files WHERE folder_id = 4");
                $stmt->execute();
                $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if ($files) {
                    echo '<div class="info"><strong>Trovati ' . count($files) . ' file nella cartella 4</strong></div>';
                    echo '<table>';
                    echo '<tr><th>ID</th><th>Nome</th><th>Size</th><th>MIME Type</th><th>Tenant ID</th></tr>';
                    foreach ($files as $f) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($f['id']) . '</td>';
                        echo '<td>' . htmlspecialchars($f['name']) . '</td>';
                        echo '<td>' . number_format($f['size_bytes']) . ' bytes</td>';
                        echo '<td>' . htmlspecialchars($f['mime_type']) . '</td>';
                        echo '<td>' . htmlspecialchars($f['tenant_id']) . '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                } else {
                    echo '<div class="info">Nessun file nella cartella 4 (normale se la cartella è vuota)</div>';
                }
            } catch (Exception $e) {
                echo '<div class="error"><strong>Errore query:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <!-- Test API diretta -->
        <div class="card">
            <h2>4. Test API Diretta</h2>
            <button class="btn btn-primary" onclick="testAPI()">Testa API files_tenant_fixed.php</button>
            <div id="apiResult" style="margin-top: 15px;"></div>
        </div>

        <!-- Permessi utente -->
        <div class="card">
            <h2>5. Permessi Utente Corrente</h2>
            <?php
            try {
                // Verifica accessible_tenants per l'utente corrente
                $user_id = $_SESSION['user_id'] ?? null;
                $tenant_id = $_SESSION['tenant_id'] ?? null;
                $role = $_SESSION['role'] ?? null;

                echo '<div class="info">';
                echo '<strong>User ID:</strong> ' . htmlspecialchars($user_id) . '<br>';
                echo '<strong>Tenant ID:</strong> ' . htmlspecialchars($tenant_id) . '<br>';
                echo '<strong>Role:</strong> ' . htmlspecialchars($role) . '<br>';
                echo '</div>';

                // Se admin o super_admin, mostra tutti i tenant accessibili
                if (in_array($role, ['admin', 'super_admin'])) {
                    $stmt = $pdo->prepare("SELECT tenant_id FROM user_tenant_access WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    $accessible = $stmt->fetchAll(PDO::FETCH_COLUMN);

                    echo '<div class="info" style="margin-top: 10px;">';
                    echo '<strong>Tenant Accessibili:</strong> ' . implode(', ', $accessible);
                    echo '</div>';
                }
            } catch (Exception $e) {
                echo '<div class="error"><strong>Errore:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <?php endif; ?>
    </div>

    <script>
        async function testAPI() {
            const resultDiv = document.getElementById('apiResult');
            resultDiv.innerHTML = '<div style="color: #6b7280;">🔄 Caricamento...</div>';

            try {
                const response = await fetch('/CollaboraNexio/api/files_tenant_fixed.php?action=list&folder_id=4&search=');
                const text = await response.text();

                let html = '<div style="margin-top: 15px;">';
                html += '<strong>HTTP Status:</strong> ' + response.status + ' ' + response.statusText + '<br>';
                html += '<strong>Content-Type:</strong> ' + response.headers.get('content-type') + '<br><br>';

                if (response.ok) {
                    try {
                        const data = JSON.parse(text);
                        html += '<div class="success"><strong>✅ Risposta OK</strong></div>';
                        html += '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
                    } catch (e) {
                        html += '<div class="error"><strong>Risposta non-JSON:</strong></div>';
                        html += '<pre>' + text + '</pre>';
                    }
                } else {
                    html += '<div class="error"><strong>❌ Errore HTTP ' + response.status + '</strong></div>';
                    html += '<pre>' + text + '</pre>';
                }
                html += '</div>';

                resultDiv.innerHTML = html;
            } catch (error) {
                resultDiv.innerHTML = '<div class="error">❌ Errore: ' + error.message + '</div>';
            }
        }
    </script>
</body>
</html>
