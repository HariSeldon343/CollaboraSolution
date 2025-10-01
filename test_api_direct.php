<?php
/**
 * Test diretto dell'API di creazione utenti
 * Simula esattamente cosa invia il form JavaScript
 */

// Inizializza sessione e autenticazione
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';

$auth = new Auth();

// Login come admin
if (!$auth->checkAuth()) {
    die("Non autenticato. Effettua il login prima.");
}

$currentUser = $auth->getCurrentUser();
$csrfToken = $auth->generateCSRFToken();

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Test Diretto API Creazione Utenti</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .test-section { margin: 30px 0; padding: 20px; background: #f9f9f9; border-radius: 5px; }
        button { background: #4CAF50; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; margin: 5px; }
        button:hover { background: #45a049; }
        .result { margin-top: 20px; padding: 15px; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .debug { background: #e3f2fd; color: #0d47a1; border: 1px solid #90caf9; }
        pre { background: white; padding: 10px; border-radius: 3px; overflow-x: auto; }
        .info { background: #fff3cd; color: #856404; padding: 10px; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Test Diretto API Creazione Utenti</h1>

        <div class="info">
            <p><strong>Utente corrente:</strong> <?= htmlspecialchars($currentUser['name'] ?? 'Unknown') ?></p>
            <p><strong>Ruolo:</strong> <?= htmlspecialchars($currentUser['role'] ?? 'Unknown') ?></p>
            <p><strong>Tenant ID:</strong> <?= htmlspecialchars($_SESSION['tenant_id'] ?? 'Unknown') ?></p>
            <p><strong>CSRF Token:</strong> <?= substr($csrfToken, 0, 20) ?>...</p>
        </div>

        <div class="test-section">
            <h2>1. Test Creazione User Standard</h2>
            <p>Crea un utente normale con tenant_id singolo</p>
            <button onclick="testCreateUser()">Test User Standard</button>
            <div id="result1"></div>
        </div>

        <div class="test-section">
            <h2>2. Test Creazione Manager</h2>
            <p>Crea un manager con tenant_id singolo</p>
            <button onclick="testCreateManager()">Test Manager</button>
            <div id="result2"></div>
        </div>

        <div class="test-section">
            <h2>3. Test Creazione Admin</h2>
            <p>Crea un admin con accesso multi-tenant</p>
            <button onclick="testCreateAdmin()">Test Admin Multi-Tenant</button>
            <div id="result3"></div>
        </div>

        <div class="test-section">
            <h2>4. Test Creazione Super Admin</h2>
            <p>Crea un super admin senza specificare tenant</p>
            <button onclick="testCreateSuperAdmin()">Test Super Admin</button>
            <div id="result4"></div>
        </div>

        <div class="test-section">
            <h2>5. Test Errore - User senza Tenant</h2>
            <p>Dovrebbe fallire perché manca tenant_id</p>
            <button onclick="testErrorNoTenant()">Test Errore</button>
            <div id="result5"></div>
        </div>

        <div class="test-section">
            <h2>6. Test con Debug Endpoint</h2>
            <p>Usa endpoint di debug per vedere esattamente cosa viene inviato</p>
            <button onclick="testDebugEndpoint()">Test Debug</button>
            <div id="result6"></div>
        </div>
    </div>

    <script>
        const csrfToken = '<?= $csrfToken ?>';
        const timestamp = Date.now();

        async function makeRequest(endpoint, data, resultId) {
            const formData = new FormData();

            // Aggiungi tutti i dati al FormData
            for (const key in data) {
                if (Array.isArray(data[key])) {
                    // Per array (tenant_ids)
                    data[key].forEach(value => {
                        formData.append(key + '[]', value);
                    });
                } else {
                    formData.append(key, data[key]);
                }
            }

            // Aggiungi sempre il CSRF token
            formData.append('csrf_token', csrfToken);

            try {
                console.log('Sending request to:', endpoint);
                console.log('Data:', data);

                const response = await fetch(endpoint, {
                    method: 'POST',
                    body: formData
                });

                const responseText = await response.text();
                console.log('Raw response:', responseText);

                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (e) {
                    result = { error: 'Invalid JSON response', raw: responseText };
                }

                const resultDiv = document.getElementById(resultId);

                if (result.success) {
                    resultDiv.className = 'result success';
                    resultDiv.innerHTML = `
                        <strong>✓ Successo!</strong>
                        <pre>${JSON.stringify(result, null, 2)}</pre>
                    `;
                } else if (result.debug) {
                    resultDiv.className = 'result debug';
                    resultDiv.innerHTML = `
                        <strong>Debug Info:</strong>
                        <pre>${JSON.stringify(result, null, 2)}</pre>
                    `;
                } else {
                    resultDiv.className = 'result error';
                    resultDiv.innerHTML = `
                        <strong>✗ Errore:</strong> ${result.error || result.message || 'Errore sconosciuto'}
                        <pre>${JSON.stringify(result, null, 2)}</pre>
                    `;
                }

                return result;
            } catch (error) {
                const resultDiv = document.getElementById(resultId);
                resultDiv.className = 'result error';
                resultDiv.innerHTML = `
                    <strong>✗ Errore di rete:</strong> ${error.message}
                `;
                console.error('Request error:', error);
            }
        }

        function testCreateUser() {
            const data = {
                first_name: 'Test',
                last_name: 'User' + timestamp,
                email: 'testuser' + timestamp + '@test.local',
                role: 'user',
                tenant_id: '1'
            };
            makeRequest('api/users/create_simple.php', data, 'result1');
        }

        function testCreateManager() {
            const data = {
                first_name: 'Test',
                last_name: 'Manager' + timestamp,
                email: 'testmanager' + timestamp + '@test.local',
                role: 'manager',
                tenant_id: '1'
            };
            makeRequest('api/users/create_simple.php', data, 'result2');
        }

        function testCreateAdmin() {
            const data = {
                first_name: 'Test',
                last_name: 'Admin' + timestamp,
                email: 'testadmin' + timestamp + '@test.local',
                role: 'admin',
                tenant_ids: ['1', '2']  // Array di tenant IDs
            };
            makeRequest('api/users/create_simple.php', data, 'result3');
        }

        function testCreateSuperAdmin() {
            const data = {
                first_name: 'Test',
                last_name: 'SuperAdmin' + timestamp,
                email: 'testsuperadmin' + timestamp + '@test.local',
                role: 'super_admin'
                // Nessun tenant_id richiesto
            };
            makeRequest('api/users/create_simple.php', data, 'result4');
        }

        function testErrorNoTenant() {
            const data = {
                first_name: 'Test',
                last_name: 'ErrorUser' + timestamp,
                email: 'testerror' + timestamp + '@test.local',
                role: 'user'
                // Manca tenant_id - dovrebbe dare errore
            };
            makeRequest('api/users/create_simple.php', data, 'result5');
        }

        function testDebugEndpoint() {
            const data = {
                first_name: 'Debug',
                last_name: 'Test' + timestamp,
                email: 'debugtest' + timestamp + '@test.local',
                role: 'user',
                tenant_id: '1'
            };
            makeRequest('api/users/debug_create.php', data, 'result6');
        }
    </script>
</body>
</html>