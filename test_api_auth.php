<?php
/**
 * Test script per verificare che le API funzionino correttamente
 * Esegue test di autenticazione e autorizzazione sugli endpoint API
 */

// Include centralized session initialization
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

// Set content type
header('Content-Type: text/html; charset=utf-8');

// Initialize auth class
$auth = new Auth();

// Check if user is logged in
$isAuthenticated = $auth->checkAuth();
$currentUser = null;
$csrfToken = null;

if ($isAuthenticated) {
    $currentUser = $auth->getCurrentUser();
    $csrfToken = $auth->generateCSRFToken();
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test API Authentication - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        .status {
            margin: 20px 0;
            padding: 15px;
            border-radius: 5px;
        }
        .status.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .status.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        .test-section {
            margin: 30px 0;
        }
        .test-result {
            margin: 10px 0;
            padding: 10px;
            background: #f8f9fa;
            border-left: 4px solid #007bff;
            font-family: 'Courier New', monospace;
        }
        .test-result.success {
            border-left-color: #28a745;
        }
        .test-result.error {
            border-left-color: #dc3545;
        }
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin: 5px;
        }
        button:hover {
            background: #0056b3;
        }
        .info-box {
            background: #e7f3ff;
            border: 1px solid #b6d4fe;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .endpoint-test {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
        }
        .endpoint-test h3 {
            margin-top: 0;
            color: #495057;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Test API Authentication - CollaboraNexio</h1>

        <!-- Authentication Status -->
        <div class="status <?php echo $isAuthenticated ? 'success' : 'warning'; ?>">
            <h3>📊 Stato Autenticazione</h3>
            <?php if ($isAuthenticated): ?>
                <p>✅ <strong>Utente autenticato</strong></p>
                <ul>
                    <li><strong>Nome:</strong> <?php echo htmlspecialchars($currentUser['name']); ?></li>
                    <li><strong>Email:</strong> <?php echo htmlspecialchars($currentUser['email']); ?></li>
                    <li><strong>Ruolo:</strong> <?php echo htmlspecialchars($currentUser['role']); ?></li>
                    <li><strong>Tenant:</strong> <?php echo htmlspecialchars($currentUser['tenant_name']); ?> (ID: <?php echo $currentUser['tenant_id']; ?>)</li>
                    <li><strong>CSRF Token:</strong> <code><?php echo substr($csrfToken, 0, 20); ?>...</code></li>
                </ul>
            <?php else: ?>
                <p>⚠️ <strong>Non autenticato</strong> - <a href="login.php">Accedi</a> per testare le API</p>
            <?php endif; ?>
        </div>

        <!-- Session Information -->
        <div class="info-box">
            <h3>🔐 Informazioni Sessione</h3>
            <ul>
                <li><strong>Session ID:</strong> <code><?php echo substr(session_id(), 0, 20); ?>...</code></li>
                <li><strong>Session Name:</strong> <code><?php echo session_name(); ?></code></li>
                <li><strong>Session Status:</strong> <?php
                    $status = session_status();
                    echo $status === PHP_SESSION_ACTIVE ? '✅ Active' :
                         ($status === PHP_SESSION_DISABLED ? '❌ Disabled' : '⚠️ None');
                ?></li>
                <li><strong>Cookie Domain:</strong> <?php
                    $params = session_get_cookie_params();
                    echo htmlspecialchars($params['domain'] ?: '(default)');
                ?></li>
                <li><strong>Cookie Path:</strong> <?php echo htmlspecialchars($params['path']); ?></li>
                <li><strong>Cookie Secure:</strong> <?php echo $params['secure'] ? '✅ Yes' : '❌ No'; ?></li>
                <li><strong>Cookie HttpOnly:</strong> <?php echo $params['httponly'] ? '✅ Yes' : '❌ No'; ?></li>
            </ul>
        </div>

        <?php if ($isAuthenticated): ?>

        <!-- API Test Buttons -->
        <div class="test-section">
            <h2>🚀 Test API Endpoints</h2>
            <p>Clicca sui pulsanti per testare gli endpoint API con il token CSRF corrente:</p>

            <!-- Test Users List API -->
            <div class="endpoint-test">
                <h3>1. API Users List</h3>
                <p>Endpoint: <code>/api/users/list.php</code></p>
                <button onclick="testApiEndpoint('/api/users/list.php?page=1&search=', 'GET', 'users-list-result')">
                    Test GET Users List
                </button>
                <div id="users-list-result"></div>
            </div>

            <!-- Test Companies List API -->
            <div class="endpoint-test">
                <h3>2. API Companies List</h3>
                <p>Endpoint: <code>/api/companies/list.php</code></p>
                <button onclick="testApiEndpoint('/api/companies/list.php?page=1&search=', 'GET', 'companies-list-result')">
                    Test GET Companies List
                </button>
                <div id="companies-list-result"></div>
            </div>

            <!-- Test Tenants API -->
            <div class="endpoint-test">
                <h3>3. API User Tenants</h3>
                <p>Endpoint: <code>/api/users/tenants.php</code></p>
                <button onclick="testApiEndpoint('/api/users/tenants.php', 'GET', 'tenants-result')">
                    Test GET Tenants
                </button>
                <div id="tenants-result"></div>
            </div>

            <!-- Test Without CSRF Token -->
            <div class="endpoint-test">
                <h3>4. Test senza CSRF Token (dovrebbe fallire)</h3>
                <p>Test di sicurezza: verifica che le API rifiutino richieste senza token CSRF</p>
                <button onclick="testApiWithoutCsrf()">
                    Test Without CSRF
                </button>
                <div id="no-csrf-result"></div>
            </div>
        </div>

        <!-- JavaScript for API Testing -->
        <script>
            const csrfToken = '<?php echo $csrfToken; ?>';
            const baseUrl = '<?php echo BASE_URL; ?>';

            async function testApiEndpoint(endpoint, method, resultDivId) {
                const resultDiv = document.getElementById(resultDivId);
                resultDiv.innerHTML = '<p>⏳ Testing...</p>';

                try {
                    const response = await fetch(baseUrl + endpoint, {
                        method: method,
                        headers: {
                            'X-CSRF-Token': csrfToken,
                            'Accept': 'application/json'
                        },
                        credentials: 'same-origin'
                    });

                    const contentType = response.headers.get('content-type');
                    const responseText = await response.text();

                    let responseData;
                    try {
                        responseData = JSON.parse(responseText);
                    } catch (e) {
                        responseData = { rawResponse: responseText };
                    }

                    const statusClass = response.ok ? 'success' : 'error';
                    const statusIcon = response.ok ? '✅' : '❌';

                    resultDiv.innerHTML = `
                        <div class="test-result ${statusClass}">
                            <p><strong>${statusIcon} Status:</strong> ${response.status} ${response.statusText}</p>
                            <p><strong>Content-Type:</strong> ${contentType || 'Not specified'}</p>
                            <details>
                                <summary>Response Data</summary>
                                <pre>${JSON.stringify(responseData, null, 2)}</pre>
                            </details>
                        </div>
                    `;
                } catch (error) {
                    resultDiv.innerHTML = `
                        <div class="test-result error">
                            <p>❌ <strong>Network Error:</strong> ${error.message}</p>
                        </div>
                    `;
                }
            }

            async function testApiWithoutCsrf() {
                const resultDiv = document.getElementById('no-csrf-result');
                resultDiv.innerHTML = '<p>⏳ Testing without CSRF token...</p>';

                try {
                    const response = await fetch(baseUrl + '/api/users/list.php?page=1', {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json'
                            // Deliberately omitting X-CSRF-Token
                        },
                        credentials: 'same-origin'
                    });

                    const responseText = await response.text();
                    let responseData;
                    try {
                        responseData = JSON.parse(responseText);
                    } catch (e) {
                        responseData = { rawResponse: responseText };
                    }

                    const isExpectedFailure = response.status === 403;
                    const statusClass = isExpectedFailure ? 'success' : 'error';
                    const statusIcon = isExpectedFailure ? '✅' : '❌';
                    const message = isExpectedFailure
                        ? 'Correttamente rifiutato (403 Forbidden)'
                        : 'ATTENZIONE: La richiesta dovrebbe essere stata rifiutata!';

                    resultDiv.innerHTML = `
                        <div class="test-result ${statusClass}">
                            <p><strong>${statusIcon} Test Result:</strong> ${message}</p>
                            <p><strong>Status:</strong> ${response.status} ${response.statusText}</p>
                            <details>
                                <summary>Response Data</summary>
                                <pre>${JSON.stringify(responseData, null, 2)}</pre>
                            </details>
                        </div>
                    `;
                } catch (error) {
                    resultDiv.innerHTML = `
                        <div class="test-result error">
                            <p>❌ <strong>Network Error:</strong> ${error.message}</p>
                        </div>
                    `;
                }
            }
        </script>

        <?php else: ?>
        <div class="info-box">
            <h3>ℹ️ Come testare le API</h3>
            <ol>
                <li>Accedi al sistema usando le credenziali demo</li>
                <li>Una volta autenticato, torna a questa pagina</li>
                <li>Usa i pulsanti di test per verificare ogni endpoint API</li>
            </ol>
            <p><strong>Credenziali demo:</strong></p>
            <ul>
                <li>Email: <code>admin@demo.local</code> - Password: <code>Admin123!</code> (Ruolo Admin)</li>
                <li>Email: <code>manager@demo.local</code> - Password: <code>Admin123!</code> (Ruolo Manager)</li>
                <li>Email: <code>user1@demo.local</code> - Password: <code>Admin123!</code> (Ruolo User)</li>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Environment Information -->
        <div class="info-box">
            <h3>🌍 Informazioni Ambiente</h3>
            <ul>
                <li><strong>Host:</strong> <?php echo htmlspecialchars($_SERVER['HTTP_HOST']); ?></li>
                <li><strong>Environment:</strong> <?php
                    $host = $_SERVER['HTTP_HOST'];
                    echo strpos($host, 'nexiosolution.it') !== false ? '☁️ Production (Cloudflare)' : '💻 Local Development';
                ?></li>
                <li><strong>Debug Mode:</strong> <?php echo defined('DEBUG_MODE') && DEBUG_MODE ? '✅ Enabled' : '❌ Disabled'; ?></li>
                <li><strong>Base URL:</strong> <code><?php echo BASE_URL; ?></code></li>
                <li><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></li>
                <li><strong>Request Headers:</strong>
                    <details>
                        <summary>Show headers</summary>
                        <pre><?php print_r(getallheaders()); ?></pre>
                    </details>
                </li>
            </ul>
        </div>
    </div>
</body>
</html>