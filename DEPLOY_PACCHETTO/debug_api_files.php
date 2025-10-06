<?php
/**
 * Script di Debug per API Files - CollaboraNexio
 * Cattura e mostra gli errori reali dell'API files_tenant_fixed.php
 */

// Include session init
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';

// Verifica autenticazione
$auth = new AuthSimple();
$isAuth = $auth->checkAuth();
$currentUser = $auth->getCurrentUser();

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug API Files - CollaboraNexio</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .header h1 { color: #1a202c; font-size: 28px; margin-bottom: 10px; }
        .status {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-top: 10px;
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
        .btn-success { background: #10b981; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        pre {
            background: #1a202c;
            color: #10b981;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 13px;
            max-height: 500px;
            overflow-y: auto;
        }
        .result-box {
            background: #f9fafb;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        .error-box {
            background: #fee2e2;
            border: 2px solid #ef4444;
            color: #991b1b;
        }
        .success-box {
            background: #d1fae5;
            border: 2px solid #10b981;
            color: #065f46;
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
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .test-item {
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        .test-item h3 {
            font-size: 16px;
            margin-bottom: 10px;
            color: #1a202c;
        }
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🔍 Debug API Files</h1>
            <p style="color: #6b7280; margin-top: 8px;">
                Diagnostica errori 500 nell'API files_tenant_fixed.php
            </p>
            <div style="margin-top: 15px;">
                <span class="status <?php echo $isAuth ? 'success' : 'error'; ?>">
                    <?php echo $isAuth ? '✓ Autenticato' : '✗ Non Autenticato'; ?>
                </span>
                <?php if ($currentUser): ?>
                <span class="status success">
                    User: <?php echo htmlspecialchars($currentUser['email'] ?? 'N/A'); ?>
                </span>
                <span class="status success">
                    Role: <?php echo htmlspecialchars($currentUser['role'] ?? 'N/A'); ?>
                </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isAuth): ?>
        <div class="alert warning">
            <strong>⚠️ ATTENZIONE:</strong> Non sei autenticato. Fai login prima di testare l'API.
            <a href="index.php" style="color: #92400e; text-decoration: underline;">Vai al Login</a>
        </div>
        <?php endif; ?>

        <!-- Test Controls -->
        <div class="card">
            <h2>🧪 Test API</h2>
            <div class="grid">
                <div class="test-item">
                    <h3>Test 1: Lista Root</h3>
                    <button class="btn btn-primary" onclick="testAPI('', 'test1Result')">
                        Testa Root
                    </button>
                    <div id="test1Result" class="result-box" style="margin-top: 10px; display: none;"></div>
                </div>

                <div class="test-item">
                    <h3>Test 2: Apri Cartella 4</h3>
                    <button class="btn btn-danger" onclick="testAPI('4', 'test2Result')">
                        Testa Folder 4
                    </button>
                    <div id="test2Result" class="result-box" style="margin-top: 10px; display: none;"></div>
                </div>

                <div class="test-item">
                    <h3>Test 3: ID Personalizzato</h3>
                    <input type="number" id="customFolderId" placeholder="Folder ID" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; margin-bottom: 10px;">
                    <button class="btn btn-success" onclick="testAPI(document.getElementById('customFolderId').value, 'test3Result')">
                        Testa Custom
                    </button>
                    <div id="test3Result" class="result-box" style="margin-top: 10px; display: none;"></div>
                </div>
            </div>
        </div>

        <!-- Risultato Dettagliato -->
        <div class="card" id="detailSection" style="display: none;">
            <h2>📊 Dettagli Risposta</h2>
            <div id="detailContent"></div>
        </div>

        <!-- Check Deploy -->
        <div class="card">
            <h2>📦 Verifica Deploy</h2>
            <button class="btn btn-primary" onclick="checkDeploy()">
                Controlla Versione File
            </button>
            <div id="deployResult" class="result-box" style="margin-top: 15px; display: none;"></div>
        </div>

        <!-- Log Errors -->
        <div class="card">
            <h2>🐛 Log Errori PHP</h2>
            <button class="btn btn-primary" onclick="checkLogs()">
                Mostra Ultimi Errori
            </button>
            <div id="logsResult" style="margin-top: 15px; display: none;">
                <pre id="logsContent"></pre>
            </div>
        </div>
    </div>

    <script>
        async function testAPI(folderId, resultId) {
            const resultDiv = document.getElementById(resultId);
            resultDiv.style.display = 'block';
            resultDiv.className = 'result-box';
            resultDiv.innerHTML = '<div class="loading"></div> Caricamento...';

            try {
                const url = `/CollaboraNexio/api/files_tenant_fixed.php?action=list&folder_id=${folderId}&search=`;
                console.log('Testing:', url);

                const response = await fetch(url);
                const text = await response.text();

                // Mostra dettagli
                document.getElementById('detailSection').style.display = 'block';

                let detailHTML = `
                    <div style="margin-bottom: 15px;">
                        <strong>URL:</strong> ${url}<br>
                        <strong>Status:</strong> ${response.status} ${response.statusText}<br>
                        <strong>Content-Type:</strong> ${response.headers.get('content-type')}
                    </div>
                `;

                if (response.ok) {
                    resultDiv.className = 'result-box success-box';
                    try {
                        const data = JSON.parse(text);
                        resultDiv.innerHTML = `
                            <strong>✅ SUCCESSO</strong><br>
                            Items trovati: ${data.data?.items?.length || 0}<br>
                            <button onclick="showJSON(${JSON.stringify(data).replace(/"/g, '&quot;')})">Mostra JSON</button>
                        `;
                        detailHTML += `<pre>${JSON.stringify(data, null, 2)}</pre>`;
                    } catch (e) {
                        resultDiv.innerHTML = `<strong>⚠️ OK ma non JSON</strong><br>${text.substring(0, 200)}`;
                        detailHTML += `<pre>${text}</pre>`;
                    }
                } else {
                    resultDiv.className = 'result-box error-box';
                    resultDiv.innerHTML = `
                        <strong>❌ ERRORE ${response.status}</strong><br>
                        ${text.substring(0, 500)}
                    `;
                    detailHTML += `<h3>Raw Response:</h3><pre>${text}</pre>`;
                }

                document.getElementById('detailContent').innerHTML = detailHTML;

            } catch (error) {
                resultDiv.className = 'result-box error-box';
                resultDiv.innerHTML = `<strong>❌ ERRORE RETE:</strong><br>${error.message}`;
                console.error('Test failed:', error);
            }
        }

        function showJSON(data) {
            alert(JSON.stringify(data, null, 2));
        }

        async function checkDeploy() {
            const resultDiv = document.getElementById('deployResult');
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div class="loading"></div> Verificando...';

            try {
                // Leggi il file per verificare se contiene le correzioni
                const response = await fetch('/CollaboraNexio/api/files_tenant_fixed.php', {
                    method: 'HEAD'
                });

                const lastModified = response.headers.get('last-modified');
                const contentLength = response.headers.get('content-length');

                resultDiv.innerHTML = `
                    <strong>📄 Info File:</strong><br>
                    Last Modified: ${lastModified || 'N/A'}<br>
                    Size: ${contentLength || 'N/A'} bytes<br>
                    <br>
                    <strong>🔍 Controlla:</strong><br>
                    1. Il file contiene "require_once __DIR__ . '/../includes/session_init.php';" all'inizio?<br>
                    2. Il file contiene "\$accessible_tenants = [];" nella funzione listFiles()?<br>
                    <br>
                    <button class="btn btn-primary" onclick="window.open('/CollaboraNexio/api/files_tenant_fixed.php', '_blank')">
                        Visualizza File
                    </button>
                `;
            } catch (error) {
                resultDiv.className = 'result-box error-box';
                resultDiv.innerHTML = `❌ Errore: ${error.message}`;
            }
        }

        async function checkLogs() {
            const resultDiv = document.getElementById('logsResult');
            const logsContent = document.getElementById('logsContent');

            resultDiv.style.display = 'block';
            logsContent.textContent = 'Caricamento logs...';

            // Nota: Questa funzione richiede un endpoint apposito per leggere i log
            logsContent.textContent = `
Per vedere i log PHP:
1. Sul server, controlla: /logs/php_errors.log
2. Oppure usa: tail -f /var/log/apache2/error.log
3. Cerca errori con timestamp recente

Comandi utili:
grep "files_tenant_fixed" /logs/php_errors.log | tail -20
grep "Fatal error" /var/log/apache2/error.log | tail -20
            `;
        }

        // Auto-test al caricamento se autenticato
        <?php if ($isAuth): ?>
        window.addEventListener('DOMContentLoaded', function() {
            console.log('Auto-testing API...');
            // Commenta questa riga se non vuoi il test automatico
            // testAPI('', 'test1Result');
        });
        <?php endif; ?>
    </script>
</body>
</html>
