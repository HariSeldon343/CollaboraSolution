<?php
/**
 * Test diretto dell'API create_v2.php
 * Esegui via browser: http://localhost:8888/CollaboraNexio/test_create_user_api.php
 */

session_start();

// Simula autenticazione admin
$_SESSION['user_id'] = 1;
$_SESSION['tenant_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$api_url = 'http://localhost:8888/CollaboraNexio/api/users/create_v2.php';

// Dati test
$test_data = [
    'first_name' => 'Test',
    'last_name' => 'User_' . time(),
    'email' => 'test_' . time() . '@example.com',
    'role' => 'user',
    'tenant_id' => 1,
    'csrf_token' => $_SESSION['csrf_token']
];

?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Create User API</title>
    <style>
        body { font-family: monospace; padding: 20px; }
        pre { background: #f4f4f4; padding: 10px; }
        .success { color: green; }
        .error { color: red; }
    </style>
</head>
<body>
    <h1>Test Create User API</h1>

    <h2>Dati di Test:</h2>
    <pre><?php echo json_encode($test_data, JSON_PRETTY_PRINT); ?></pre>

    <h2>Test con cURL:</h2>
    <?php
    // Test con cURL
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($test_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-CSRF-Token: ' . $_SESSION['csrf_token']
    ]);
    curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    echo "<p>HTTP Code: <strong>$http_code</strong></p>";

    if ($error) {
        echo "<p class='error'>cURL Error: $error</p>";
    }

    echo "<h3>Response:</h3>";
    echo "<pre>";

    // Prova a decodificare come JSON
    $json = json_decode($response, true);
    if ($json !== null) {
        echo json_encode($json, JSON_PRETTY_PRINT);
    } else {
        // Se non è JSON valido, mostra il response raw
        echo htmlspecialchars($response);
    }
    echo "</pre>";
    ?>

    <h2>Test con Fetch JavaScript:</h2>
    <button onclick="testWithFetch()">Test con Fetch</button>
    <div id="fetch-result"></div>

    <script>
    async function testWithFetch() {
        const data = <?php echo json_encode($test_data); ?>;
        // Cambia email per evitare duplicati
        data.email = 'test_js_' + Date.now() + '@example.com';

        try {
            const response = await fetch('<?php echo $api_url; ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?php echo $_SESSION['csrf_token']; ?>'
                },
                credentials: 'same-origin',
                body: JSON.stringify(data)
            });

            const result = await response.text();
            let displayResult;

            try {
                const json = JSON.parse(result);
                displayResult = JSON.stringify(json, null, 2);
            } catch {
                displayResult = result;
            }

            document.getElementById('fetch-result').innerHTML = `
                <p>Status: <strong>${response.status}</strong></p>
                <pre>${displayResult}</pre>
            `;
        } catch (error) {
            document.getElementById('fetch-result').innerHTML = `
                <p class="error">Error: ${error.message}</p>
            `;
        }
    }
    </script>

    <h2>Test Diretto PHP (include):</h2>
    <?php
    // Test diretto includendo il file
    echo "<pre>";

    // Cattura l'output
    ob_start();

    // Simula richiesta POST
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf_token'];

    // Cambia email per evitare duplicati
    $test_data['email'] = 'test_direct_' . time() . '@example.com';

    // Simula input JSON
    $temp_input = 'php://input';
    $input_data = json_encode($test_data);

    // Non possiamo sovrascrivere php://input direttamente, quindi usiamo $_POST
    $_POST = $test_data;

    try {
        // Salva gli handler di errore attuali
        $old_error_handler = set_error_handler(function($errno, $errstr, $errfile, $errline) {
            echo "PHP Error [$errno]: $errstr in $errfile:$errline\n";
            return true;
        });

        // Include il file API
        include __DIR__ . '/api/users/create_v2.php';

    } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "Trace:\n" . $e->getTraceAsString() . "\n";
    } catch (Error $e) {
        echo "Fatal Error: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "Trace:\n" . $e->getTraceAsString() . "\n";
    } finally {
        // Ripristina l'handler di errore
        if (isset($old_error_handler)) {
            restore_error_handler();
        }
    }

    $output = ob_get_clean();

    // Mostra l'output
    if (!empty($output)) {
        echo "Output:\n";
        echo htmlspecialchars($output);
    } else {
        echo "Nessun output catturato";
    }

    echo "</pre>";
    ?>
</body>
</html>