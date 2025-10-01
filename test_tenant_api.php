<?php
/**
 * Test script per verificare la funzionalità getTenantList
 * Accedi a questo file nel browser dopo il login come admin o super_admin
 */

session_start();
require_once __DIR__ . '/includes/auth_simple.php';

$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}

$currentUser = $auth->getCurrentUser();

if (!in_array($currentUser['role'], ['admin', 'super_admin'])) {
    die('Devi essere admin o super_admin per testare questa funzionalità');
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test API Tenant List</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 50px auto;
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
        .info {
            background: #e3f2fd;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .success {
            background: #c8e6c9;
            color: #2e7d32;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
        .error {
            background: #ffcdd2;
            color: #c62828;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
        button {
            background: #2196F3;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background: #1976D2;
        }
        pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        table th, table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        table th {
            background: #f5f5f5;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Test API Tenant List</h1>

        <div class="info">
            <strong>Utente corrente:</strong> <?php echo htmlspecialchars($currentUser['email']); ?><br>
            <strong>Ruolo:</strong> <?php echo htmlspecialchars($currentUser['role']); ?><br>
            <strong>Tenant ID:</strong> <?php echo htmlspecialchars($_SESSION['tenant_id']); ?>
        </div>

        <button onclick="testAPI()">Test getTenantList API</button>

        <div id="result"></div>
    </div>

    <script>
        async function testAPI() {
            const resultDiv = document.getElementById('result');
            resultDiv.innerHTML = '<p>Chiamata API in corso...</p>';

            try {
                const response = await fetch('/CollaboraNexio/api/files_tenant_fixed.php?action=get_tenant_list', {
                    method: 'GET',
                    credentials: 'same-origin'
                });

                const responseText = await response.text();
                let result;

                try {
                    result = JSON.parse(responseText);
                } catch (e) {
                    resultDiv.innerHTML = `
                        <div class="error">
                            <h3>Errore: Risposta non è JSON valido</h3>
                            <p><strong>Status Code:</strong> ${response.status}</p>
                            <p><strong>Response Headers:</strong></p>
                            <pre>${Array.from(response.headers.entries()).map(([k,v]) => `${k}: ${v}`).join('\n')}</pre>
                            <p><strong>Response Body:</strong></p>
                            <pre>${escapeHtml(responseText)}</pre>
                        </div>
                    `;
                    return;
                }

                if (response.ok && result.success) {
                    let html = `
                        <div class="success">
                            <h3>✓ API funziona correttamente!</h3>
                            <p><strong>Status Code:</strong> ${response.status}</p>
                        </div>
                        <h3>Tenant disponibili: ${result.data.length}</h3>
                    `;

                    if (result.data.length > 0) {
                        html += `
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nome</th>
                                        <th>Attivo</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                        `;

                        result.data.forEach(tenant => {
                            html += `
                                <tr>
                                    <td>${tenant.id}</td>
                                    <td>${escapeHtml(tenant.name)}</td>
                                    <td>${tenant.is_active === '1' ? '✓ Sì' : '✗ No'}</td>
                                    <td>${tenant.status || 'N/A'}</td>
                                </tr>
                            `;
                        });

                        html += `
                                </tbody>
                            </table>
                        `;
                    }

                    html += `
                        <h3>Raw JSON Response:</h3>
                        <pre>${JSON.stringify(result, null, 2)}</pre>
                    `;

                    resultDiv.innerHTML = html;
                } else {
                    resultDiv.innerHTML = `
                        <div class="error">
                            <h3>✗ Errore API</h3>
                            <p><strong>Status Code:</strong> ${response.status}</p>
                            <p><strong>Errore:</strong> ${result.error || 'Errore sconosciuto'}</p>
                            ${result.details ? `<p><strong>Dettagli:</strong> ${result.details}</p>` : ''}
                        </div>
                        <h3>Raw JSON Response:</h3>
                        <pre>${JSON.stringify(result, null, 2)}</pre>
                    `;
                }
            } catch (error) {
                resultDiv.innerHTML = `
                    <div class="error">
                        <h3>✗ Errore di rete</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>