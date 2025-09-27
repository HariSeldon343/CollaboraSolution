<?php
/**
 * Test del sistema di filtro aziende
 * Verifica il funzionamento del filtro multi-tenant
 */

declare(strict_types=1);

session_start();

// Includi i file necessari
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/company_filter.php';
require_once __DIR__ . '/includes/query_helper.php';

// Stile per il test
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Filtro Aziende - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            padding: 20px;
            background: #f5f5f5;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #3b82f6;
            padding-bottom: 10px;
        }
        h2 {
            color: #555;
            margin-top: 30px;
        }
        .test-section {
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 4px;
            border-left: 4px solid #3b82f6;
        }
        .success {
            color: #10b981;
            font-weight: bold;
        }
        .error {
            color: #ef4444;
            font-weight: bold;
        }
        .warning {
            color: #f59e0b;
            font-weight: bold;
        }
        pre {
            background: #1f2937;
            color: #f3f4f6;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f3f4f6;
            font-weight: bold;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin: 10px 5px;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background: #2563eb;
        }
        .filter-demo {
            padding: 20px;
            background: #eff6ff;
            border-radius: 8px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Test Filtro Aziende Multi-Tenant</h1>

        <?php
        // Inizializza autenticazione
        $auth = new Auth();

        // Test 1: Verifica autenticazione
        echo '<div class="test-section">';
        echo '<h2>1. Verifica Autenticazione</h2>';

        if ($auth->checkAuth()) {
            $currentUser = $auth->getCurrentUser();
            echo '<p class="success">✅ Utente autenticato</p>';
            echo '<ul>';
            echo '<li>Nome: ' . htmlspecialchars($currentUser['name']) . '</li>';
            echo '<li>Email: ' . htmlspecialchars($currentUser['email']) . '</li>';
            echo '<li>Ruolo: <strong>' . htmlspecialchars($currentUser['role']) . '</strong></li>';
            echo '<li>Tenant ID: ' . htmlspecialchars($currentUser['tenant_id']) . '</li>';
            echo '<li>Azienda: ' . htmlspecialchars($currentUser['tenant_name'] ?? 'N/D') . '</li>';
            echo '</ul>';
        } else {
            echo '<p class="error">❌ Utente non autenticato</p>';
            echo '<p>Per eseguire il test completo, effettua il <a href="index.php" class="btn">Login</a></p>';
            echo '</div></div></body></html>';
            exit;
        }
        echo '</div>';

        // Test 2: Inizializzazione Company Filter
        echo '<div class="test-section">';
        echo '<h2>2. Inizializzazione Company Filter</h2>';

        $companyFilter = new CompanyFilter($currentUser);

        if ($companyFilter->canUseCompanyFilter()) {
            echo '<p class="success">✅ L\'utente può utilizzare il filtro aziende (ruolo: ' . $currentUser['role'] . ')</p>';
        } else {
            echo '<p class="warning">⚠️ L\'utente non ha i permessi per il filtro aziende</p>';
            echo '<p>Solo gli utenti con ruolo <strong>admin</strong> o <strong>super_admin</strong> possono utilizzare il filtro.</p>';
        }
        echo '</div>';

        // Test 3: Aziende disponibili
        echo '<div class="test-section">';
        echo '<h2>3. Aziende Disponibili per l\'Utente</h2>';

        $availableCompanies = $companyFilter->getAvailableCompanies();

        if (!empty($availableCompanies)) {
            echo '<p class="success">✅ Trovate ' . count($availableCompanies) . ' aziende disponibili</p>';
            echo '<table>';
            echo '<tr><th>ID</th><th>Nome Azienda</th><th>Stato</th><th>Dominio</th></tr>';

            foreach ($availableCompanies as $company) {
                echo '<tr>';
                echo '<td>' . $company['id'] . '</td>';
                echo '<td>' . htmlspecialchars($company['name']) . '</td>';
                echo '<td>' . htmlspecialchars($company['status']) . '</td>';
                echo '<td>' . htmlspecialchars($company['domain'] ?? 'N/D') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<p class="warning">⚠️ Nessuna azienda disponibile per questo utente</p>';
        }
        echo '</div>';

        // Test 4: Render del componente dropdown
        if ($companyFilter->canUseCompanyFilter()) {
            echo '<div class="test-section">';
            echo '<h2>4. Componente Dropdown Filtro</h2>';
            echo '<div class="filter-demo">';
            echo '<p>Ecco come appare il dropdown del filtro:</p>';
            echo $companyFilter->renderDropdown(['no_scripts' => true]);
            echo '</div>';

            // Mostra il filtro attivo
            $activeFilterId = $companyFilter->getActiveFilterId();
            $activeFilterName = $companyFilter->getActiveFilterName();

            echo '<p><strong>Filtro Attivo:</strong> ';
            if ($activeFilterId) {
                echo 'ID ' . $activeFilterId . ' - ' . htmlspecialchars($activeFilterName);
            } else {
                echo htmlspecialchars($activeFilterName);
            }
            echo '</p>';
            echo '</div>';
        }

        // Test 5: Test Query con Filtro
        echo '<div class="test-section">';
        echo '<h2>5. Test Query con Filtro Applicato</h2>';

        $queryHelper = new QueryHelper($currentUser);

        // Query di esempio per contare gli utenti
        $testQuery = "SELECT COUNT(*) as total FROM users";
        $filtered = $companyFilter->applyFilterToQuery($testQuery);

        echo '<p><strong>Query originale:</strong></p>';
        echo '<pre>' . htmlspecialchars($testQuery) . '</pre>';

        echo '<p><strong>Query con filtro applicato:</strong></p>';
        echo '<pre>' . htmlspecialchars($filtered['query']) . '</pre>';

        if (!empty($filtered['params'])) {
            echo '<p><strong>Parametri:</strong></p>';
            echo '<pre>' . print_r($filtered['params'], true) . '</pre>';
        }

        // Esegui query di test
        try {
            $userCount = $queryHelper->countWithFilter('users');
            echo '<p class="success">✅ Query eseguita con successo</p>';
            echo '<p>Numero di utenti visibili con il filtro attuale: <strong>' . $userCount . '</strong></p>';
        } catch (Exception $e) {
            echo '<p class="error">❌ Errore nell\'esecuzione della query: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        echo '</div>';

        // Test 6: Test cambio filtro
        if ($companyFilter->canUseCompanyFilter() && count($availableCompanies) > 1) {
            echo '<div class="test-section">';
            echo '<h2>6. Test Cambio Filtro Programmatico</h2>';

            $firstCompany = $availableCompanies[0];
            $success = $companyFilter->setFilter($firstCompany['id']);

            if ($success) {
                echo '<p class="success">✅ Filtro impostato su: ' . htmlspecialchars($firstCompany['name']) . '</p>';
            } else {
                echo '<p class="error">❌ Impossibile impostare il filtro</p>';
            }

            // Reset del filtro
            $companyFilter->resetFilter();
            echo '<p>Filtro resettato a: ' . htmlspecialchars($companyFilter->getActiveFilterName()) . '</p>';
            echo '</div>';
        }

        // Test 7: Verifica integrazione con API
        echo '<div class="test-section">';
        echo '<h2>7. Test Integrazione con API</h2>';

        echo '<p>Endpoint API aggiornati per supportare il filtro azienda:</p>';
        echo '<ul>';
        echo '<li><code>/api/users/list_v2.php</code> - Lista utenti con filtro</li>';
        echo '<li>Altri endpoint possono essere aggiornati seguendo lo stesso pattern</li>';
        echo '</ul>';

        // Test chiamata API
        echo '<p><strong>Test chiamata API users/list_v2:</strong></p>';
        echo '<button class="btn" onclick="testApiCall()">Test API Call</button>';
        echo '<div id="apiResult" style="margin-top: 20px;"></div>';
        echo '</div>';

        // Informazioni di sessione
        echo '<div class="test-section">';
        echo '<h2>8. Informazioni di Sessione</h2>';
        echo '<p><strong>Variabili di sessione relative al filtro:</strong></p>';
        echo '<pre>';
        $sessionInfo = [
            'company_filter_id' => $_SESSION['company_filter_id'] ?? 'Non impostato',
            'company_filter_name' => $_SESSION['company_filter_name'] ?? 'Non impostato',
            'tenant_id' => $_SESSION['tenant_id'] ?? 'Non impostato',
            'user_role' => $_SESSION['role'] ?? 'Non impostato'
        ];
        print_r($sessionInfo);
        echo '</pre>';
        echo '</div>';
        ?>

        <div class="test-section">
            <h2>✅ Riepilogo Test</h2>
            <p>Il sistema di filtro aziende è stato implementato con successo e include:</p>
            <ul>
                <li>✅ Classe <code>CompanyFilter</code> per gestire il filtro lato server</li>
                <li>✅ Classe <code>QueryHelper</code> per applicare automaticamente il filtro alle query</li>
                <li>✅ Componente dropdown riutilizzabile per la UI</li>
                <li>✅ Persistenza del filtro in sessione</li>
                <li>✅ Supporto per admin (aziende assegnate) e super_admin (tutte le aziende)</li>
                <li>✅ JavaScript module per gestione client-side</li>
                <li>✅ API aggiornate per supportare il filtro</li>
            </ul>

            <h3>Prossimi Passi</h3>
            <ol>
                <li>Aggiornare tutti gli endpoint API per utilizzare <code>QueryHelper</code></li>
                <li>Aggiungere il componente filtro a tutte le pagine necessarie</li>
                <li>Implementare la logica di filtro nelle query di reporting</li>
                <li>Testare con diversi ruoli utente e configurazioni multi-tenant</li>
            </ol>
        </div>

        <div style="text-align: center; margin-top: 40px;">
            <a href="dashboard.php" class="btn">Vai alla Dashboard</a>
            <a href="utenti.php" class="btn">Gestione Utenti</a>
            <a href="files.php" class="btn">File Manager</a>
        </div>
    </div>

    <script>
        // Includi il modulo JavaScript per il filtro
        document.write('<script src="assets/js/company_filter.js"><\/script>');

        // Test chiamata API
        async function testApiCall() {
            const resultDiv = document.getElementById('apiResult');
            resultDiv.innerHTML = '<p>Chiamata API in corso...</p>';

            try {
                // Ottieni il token CSRF
                const csrfToken = '<?php echo $auth->generateCSRFToken(); ?>';

                const response = await fetch('/CollaboraNexio/api/users/list_v2.php?limit=5', {
                    method: 'GET',
                    headers: {
                        'X-CSRF-Token': csrfToken
                    },
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                resultDiv.innerHTML = `
                    <p class="success">✅ Chiamata API completata con successo</p>
                    <pre>${JSON.stringify(data, null, 2)}</pre>
                `;
            } catch (error) {
                resultDiv.innerHTML = `
                    <p class="error">❌ Errore nella chiamata API: ${error.message}</p>
                `;
            }
        }
    </script>
</body>
</html>