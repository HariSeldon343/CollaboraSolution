<?php
/**
 * Test script per verificare l'API di creazione utenti
 * Accedi via browser: http://localhost:8888/CollaboraNexio/test_user_creation_api.php
 */

// Initialize session
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';

$auth = new Auth();

// Check if user is authenticated
if (!$auth->checkAuth()) {
    die('<h1>Non autenticato</h1><p>Devi effettuare il login per testare l\'API.</p><a href="index.php">Login</a>');
}

$currentUser = $auth->getCurrentUser();
$csrfToken = $auth->generateCSRFToken();

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test API Creazione Utenti</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 { color: #333; }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }
        input, select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        button {
            background: #3b82f6;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
        }
        button:hover {
            background: #2563eb;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 4px;
            display: none;
        }
        .result.success {
            background: #d1fae5;
            border: 1px solid #10b981;
            color: #065f46;
        }
        .result.error {
            background: #fee2e2;
            border: 1px solid #ef4444;
            color: #991b1b;
        }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
        }
        .info {
            background: #dbeafe;
            border: 1px solid #3b82f6;
            color: #1e3a8a;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Test API Creazione Utenti</h1>

        <div class="info">
            <strong>Utente corrente:</strong> <?php echo htmlspecialchars($currentUser['name']); ?> (<?php echo htmlspecialchars($currentUser['role']); ?>)<br>
            <strong>Tenant ID:</strong> <?php echo htmlspecialchars($currentUser['tenant_id']); ?><br>
            <strong>CSRF Token:</strong> <code><?php echo substr($csrfToken, 0, 20); ?>...</code>
        </div>

        <form id="testForm">
            <div class="form-group">
                <label>Nome</label>
                <input type="text" name="first_name" value="Test" required>
            </div>

            <div class="form-group">
                <label>Cognome</label>
                <input type="text" name="last_name" value="User" required>
            </div>

            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="test<?php echo time(); ?>@example.com" required>
            </div>

            <div class="form-group">
                <label>Ruolo</label>
                <select name="role" id="role" required>
                    <option value="user">Utente</option>
                    <option value="manager">Manager</option>
                    <option value="admin">Admin</option>
                    <option value="super_admin">Super Admin</option>
                </select>
            </div>

            <div class="form-group" id="tenantGroup">
                <label>Tenant ID</label>
                <input type="number" name="tenant_id" value="1">
            </div>

            <button type="submit">Crea Utente Test</button>
        </form>

        <div id="result" class="result"></div>

        <h3>Console Log:</h3>
        <pre id="console"></pre>
    </div>

    <script>
        const csrfToken = '<?php echo $csrfToken; ?>';
        const consoleEl = document.getElementById('console');
        const resultEl = document.getElementById('result');

        function log(message) {
            console.log(message);
            consoleEl.textContent += message + '\n';
        }

        // Handle role change
        document.getElementById('role').addEventListener('change', function() {
            const tenantGroup = document.getElementById('tenantGroup');
            if (this.value === 'super_admin') {
                tenantGroup.style.display = 'none';
            } else {
                tenantGroup.style.display = 'block';
            }
        });

        document.getElementById('testForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            consoleEl.textContent = '';
            resultEl.style.display = 'none';

            log('=== INIZIO TEST ===');
            log('Timestamp: ' + new Date().toISOString());

            const formData = new FormData(this);
            formData.append('csrf_token', csrfToken);

            // Log form data
            log('\nDati inviati:');
            for (let [key, value] of formData.entries()) {
                log(`  ${key}: ${value}`);
            }

            try {
                log('\nInvio richiesta a: api/users/create_simple.php');
                const response = await fetch('api/users/create_simple.php', {
                    method: 'POST',
                    body: formData
                });

                log(`Risposta HTTP: ${response.status} ${response.statusText}`);
                log(`Content-Type: ${response.headers.get('content-type')}`);

                const responseText = await response.text();
                log(`\nRisposta raw (primi 500 caratteri):\n${responseText.substring(0, 500)}`);

                let data;
                try {
                    data = JSON.parse(responseText);
                    log('\nJSON parsing: ✓ SUCCESSO');
                    log('\nOggetto JSON parsato:');
                    log(JSON.stringify(data, null, 2));
                } catch (jsonError) {
                    log('\nJSON parsing: ✗ ERRORE');
                    log(`Errore: ${jsonError.message}`);
                    throw new Error('Risposta non è JSON valido');
                }

                if (data.success) {
                    resultEl.className = 'result success';
                    resultEl.innerHTML = `
                        <strong>✓ Utente creato con successo!</strong><br>
                        ID: ${data.data.id}<br>
                        Nome: ${data.data.name}<br>
                        Email: ${data.data.email}<br>
                        Ruolo: ${data.data.role}<br>
                        Email inviata: ${data.data.email_sent ? 'Sì' : 'No'}<br>
                        ${data.info ? `Info: ${data.info}` : ''}
                        ${data.warning ? `<br><strong>Warning:</strong> ${data.warning}` : ''}
                    `;
                    log('\n=== TEST COMPLETATO CON SUCCESSO ===');
                } else {
                    resultEl.className = 'result error';
                    resultEl.innerHTML = `
                        <strong>✗ Errore</strong><br>
                        ${data.error || data.message || 'Errore sconosciuto'}
                        ${data.debug ? `<br><br>Debug: ${data.debug}` : ''}
                    `;
                    log('\n=== TEST FALLITO ===');
                    log(`Errore: ${data.error || data.message}`);
                }

                resultEl.style.display = 'block';

            } catch (error) {
                log('\n=== ECCEZIONE ===');
                log(`Tipo: ${error.constructor.name}`);
                log(`Messaggio: ${error.message}`);
                log(`Stack: ${error.stack}`);

                resultEl.className = 'result error';
                resultEl.innerHTML = `<strong>✗ Errore di connessione</strong><br>${error.message}`;
                resultEl.style.display = 'block';
            }
        });
    </script>
</body>
</html>
