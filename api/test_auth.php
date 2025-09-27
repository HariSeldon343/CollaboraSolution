<?php
/**
 * Script di test per l'API di autenticazione
 *
 * Esegue test automatici delle funzionalità dell'API auth.php
 * Verifica tutti gli endpoint e i casi d'uso principali
 *
 * @author CollaboraNexio Team
 * @version 1.0.0
 */

// Configurazione
$base_url = 'http://localhost/CollaboraNexio/api/auth.php';
$test_email = 'test@example.com';
$test_password = 'Test123!';
$cookie_jar = tempnam(sys_get_temp_dir(), 'cookie_');

// Colori per output console
$colors = [
    'green' => "\033[0;32m",
    'red' => "\033[0;31m",
    'yellow' => "\033[0;33m",
    'blue' => "\033[0;34m",
    'reset' => "\033[0m"
];

/**
 * Esegue una richiesta HTTP all'API
 *
 * @param string $action Azione da eseguire
 * @param string $method Metodo HTTP (GET/POST)
 * @param array|null $data Dati da inviare (per POST)
 * @param bool $use_cookies Se usare i cookie salvati
 * @return array Risposta decodificata
 */
function apiRequest(string $action, string $method = 'GET', ?array $data = null, bool $use_cookies = true): array {
    global $base_url, $cookie_jar;

    $url = $base_url . '?action=' . $action;

    $ch = curl_init($url);

    // Configurazione base
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    // Gestione cookie per mantenere la sessione
    if ($use_cookies) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_jar);
    }

    // Configurazione per metodo POST
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            $json_data = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json_data)
            ]);
        }
    }

    // Esecuzione richiesta
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Gestione errori cURL
    if ($error) {
        return [
            'success' => false,
            'error' => $error,
            'http_code' => $http_code
        ];
    }

    // Decodifica risposta JSON
    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'error' => 'Invalid JSON response: ' . json_last_error_msg(),
            'http_code' => $http_code,
            'raw_response' => $response
        ];
    }

    // Aggiungi codice HTTP alla risposta
    $decoded['http_code'] = $http_code;

    return $decoded;
}

/**
 * Stampa risultato del test
 *
 * @param string $test_name Nome del test
 * @param bool $success Esito del test
 * @param string $details Dettagli del risultato
 */
function printResult(string $test_name, bool $success, string $details = ''): void {
    global $colors;

    $status = $success
        ? $colors['green'] . '✓ PASS' . $colors['reset']
        : $colors['red'] . '✗ FAIL' . $colors['reset'];

    echo sprintf(
        "[%s] %s%s%s%s\n",
        $status,
        $colors['blue'],
        $test_name,
        $colors['reset'],
        $details ? " - {$details}" : ''
    );
}

/**
 * Stampa intestazione sezione
 *
 * @param string $title Titolo della sezione
 */
function printSection(string $title): void {
    global $colors;

    echo "\n" . $colors['yellow'] . str_repeat('=', 60) . $colors['reset'] . "\n";
    echo $colors['yellow'] . $title . $colors['reset'] . "\n";
    echo $colors['yellow'] . str_repeat('=', 60) . $colors['reset'] . "\n";
}

/**
 * Test principale
 */
function runTests(): void {
    global $test_email, $test_password, $cookie_jar;

    echo "\n";
    printSection('TEST API AUTENTICAZIONE COLLABORANEXIO');

    // Array per tracciare i risultati
    $test_results = [];

    // ===== TEST 1: Verifica endpoint senza action =====
    printSection('1. TEST ENDPOINT BASE');

    $response = apiRequest('', 'GET', null, false);
    $test_success = isset($response['success']) && !$response['success'] && $response['http_code'] === 400;
    $test_results[] = $test_success;
    printResult(
        'Endpoint senza action',
        $test_success,
        $test_success ? 'Risponde con errore 400 come previsto' : 'Risposta inattesa'
    );

    // ===== TEST 2: Check autenticazione (non autenticato) =====
    printSection('2. TEST CHECK NON AUTENTICATO');

    $response = apiRequest('check', 'GET', null, false);
    $test_success = isset($response['success']) &&
                   $response['success'] === true &&
                   isset($response['data']['authenticated']) &&
                   $response['data']['authenticated'] === false;
    $test_results[] = $test_success;
    printResult(
        'Check senza autenticazione',
        $test_success,
        $test_success ? 'Utente non autenticato correttamente rilevato' : 'Errore nel check'
    );

    // ===== TEST 3: Login con metodo errato =====
    printSection('3. TEST METODI HTTP');

    $response = apiRequest('login', 'GET', null, false);
    $test_success = !$response['success'] && $response['http_code'] === 405;
    $test_results[] = $test_success;
    printResult(
        'Login con GET invece di POST',
        $test_success,
        $test_success ? 'Errore 405 Method Not Allowed' : 'Metodo non validato correttamente'
    );

    // ===== TEST 4: Login con parametri mancanti =====
    printSection('4. TEST VALIDAZIONE PARAMETRI');

    $response = apiRequest('login', 'POST', [], false);
    $test_success = !$response['success'] && $response['http_code'] === 400;
    $test_results[] = $test_success;
    printResult(
        'Login senza parametri',
        $test_success,
        $test_success ? 'Errore 400 per parametri mancanti' : 'Validazione fallita'
    );

    $response = apiRequest('login', 'POST', ['email' => $test_email], false);
    $test_success = !$response['success'] && $response['http_code'] === 400;
    $test_results[] = $test_success;
    printResult(
        'Login senza password',
        $test_success,
        $test_success ? 'Errore 400 per password mancante' : 'Validazione fallita'
    );

    // ===== TEST 5: Login con email non valida =====
    printSection('5. TEST VALIDAZIONE EMAIL');

    $response = apiRequest('login', 'POST', ['email' => 'not-an-email', 'password' => $test_password], false);
    $test_success = !$response['success'] && $response['http_code'] === 400;
    $test_results[] = $test_success;
    printResult(
        'Login con email malformata',
        $test_success,
        $test_success ? 'Email non valida rilevata' : 'Validazione email fallita'
    );

    // ===== TEST 6: Login con credenziali errate =====
    printSection('6. TEST CREDENZIALI ERRATE');

    $response = apiRequest('login', 'POST', [
        'email' => 'wrong@example.com',
        'password' => 'wrongpassword'
    ], false);
    $test_success = !$response['success'] && $response['http_code'] === 401;
    $test_results[] = $test_success;
    printResult(
        'Login con credenziali errate',
        $test_success,
        $test_success ? 'Errore 401 Unauthorized' : 'Autenticazione non gestita correttamente'
    );

    // ===== TEST 7: Rate Limiting =====
    printSection('7. TEST RATE LIMITING');

    echo "Simulazione di multipli tentativi falliti...\n";
    $attempts_made = 0;
    $rate_limited = false;

    for ($i = 1; $i <= 7; $i++) {
        $response = apiRequest('login', 'POST', [
            'email' => 'raterlimit@example.com',
            'password' => 'wrongpass'
        ], false);

        if ($response['http_code'] === 429) {
            $rate_limited = true;
            echo "  → Rate limit attivato dopo {$attempts_made} tentativi\n";
            break;
        }
        $attempts_made++;
        echo "  → Tentativo {$i}: " . ($response['success'] ? 'successo' : 'fallito') . "\n";
        usleep(100000); // 100ms tra tentativi
    }

    $test_success = $rate_limited && $attempts_made <= 5;
    $test_results[] = $test_success;
    printResult(
        'Rate limiting dopo 5 tentativi',
        $test_success,
        $test_success ? 'Rate limit funzionante (429 Too Many Requests)' : 'Rate limit non attivo'
    );

    // ===== TEST 8: Login simulato con successo =====
    printSection('8. TEST LOGIN SUCCESSO (SIMULATO)');

    echo "NOTA: Per un test completo di login, assicurarsi che esista un utente di test nel database.\n";
    echo "Email: {$test_email}\n";
    echo "Password: {$test_password}\n";

    $response = apiRequest('login', 'POST', [
        'email' => $test_email,
        'password' => $test_password
    ], true);

    if ($response['success']) {
        printResult('Login riuscito', true, 'Sessione creata con successo');

        // Test check dopo login
        $check_response = apiRequest('check', 'GET', null, true);
        $test_success = $check_response['success'] &&
                       $check_response['data']['authenticated'] === true;
        $test_results[] = $test_success;
        printResult(
            'Check dopo login',
            $test_success,
            $test_success ? 'Utente autenticato' : 'Sessione non mantenuta'
        );

        // Test dettagli sessione
        $session_response = apiRequest('session', 'GET', null, true);
        $test_success = $session_response['success'] && isset($session_response['data']['session_id']);
        $test_results[] = $test_success;
        printResult(
            'Recupero dettagli sessione',
            $test_success,
            $test_success ? 'Dettagli sessione recuperati' : 'Errore recupero sessione'
        );

        // Test logout
        $logout_response = apiRequest('logout', 'POST', null, true);
        $test_success = $logout_response['success'];
        $test_results[] = $test_success;
        printResult(
            'Logout',
            $test_success,
            $test_success ? 'Logout completato' : 'Errore durante logout'
        );

        // Verifica che dopo logout non sia più autenticato
        $check_after_logout = apiRequest('check', 'GET', null, true);
        $test_success = $check_after_logout['success'] &&
                       $check_after_logout['data']['authenticated'] === false;
        $test_results[] = $test_success;
        printResult(
            'Check dopo logout',
            $test_success,
            $test_success ? 'Sessione terminata correttamente' : 'Sessione ancora attiva'
        );
    } else {
        printResult(
            'Login riuscito',
            false,
            'Utente test non presente nel database. Creare utente di test per test completo.'
        );
        echo "\nPer creare un utente di test, eseguire:\n";
        echo "INSERT INTO users (email, password, first_name, last_name, role, tenant_id)\n";
        echo "VALUES ('{$test_email}', '" . password_hash($test_password, PASSWORD_DEFAULT) . "', 'Test', 'User', 'user', 1);\n";
    }

    // ===== TEST 9: Session senza autenticazione =====
    printSection('9. TEST ACCESSO PROTETTO');

    $response = apiRequest('session', 'GET', null, false);
    $test_success = !$response['success'] && $response['http_code'] === 401;
    $test_results[] = $test_success;
    printResult(
        'Accesso a session senza auth',
        $test_success,
        $test_success ? 'Accesso negato (401)' : 'Endpoint non protetto'
    );

    // ===== TEST 10: CORS Headers =====
    printSection('10. TEST HEADERS SICUREZZA');

    $ch = curl_init($base_url . '?action=check');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    $response = curl_exec($ch);
    curl_close($ch);

    $headers_found = [
        'Content-Type: application/json' => false,
        'X-Content-Type-Options: nosniff' => false,
        'X-Frame-Options: DENY' => false,
        'X-XSS-Protection: 1; mode=block' => false,
        'Access-Control-Allow-Origin' => false
    ];

    foreach ($headers_found as $header => &$found) {
        if (stripos($response, $header) !== false) {
            $found = true;
        }
    }

    $all_headers_present = !in_array(false, $headers_found, true);
    $test_results[] = $all_headers_present;

    foreach ($headers_found as $header => $found) {
        printResult(
            "Header: " . explode(':', $header)[0],
            $found,
            $found ? 'Presente' : 'Mancante'
        );
    }

    // ===== RIEPILOGO =====
    printSection('RIEPILOGO TEST');

    $total_tests = count($test_results);
    $passed_tests = array_sum($test_results);
    $failed_tests = $total_tests - $passed_tests;

    $success_rate = $total_tests > 0 ? round(($passed_tests / $total_tests) * 100, 1) : 0;

    echo sprintf(
        "\nTest completati: %d\n",
        $total_tests
    );
    echo sprintf(
        "%sTest passati: %d%s\n",
        $colors['green'],
        $passed_tests,
        $colors['reset']
    );
    echo sprintf(
        "%sTest falliti: %d%s\n",
        $failed_tests > 0 ? $colors['red'] : $colors['green'],
        $failed_tests,
        $colors['reset']
    );
    echo sprintf(
        "\nTasso di successo: %s%.1f%%%s\n",
        $success_rate >= 80 ? $colors['green'] : ($success_rate >= 60 ? $colors['yellow'] : $colors['red']),
        $success_rate,
        $colors['reset']
    );

    // Pulizia file temporaneo cookie
    if (file_exists($cookie_jar)) {
        unlink($cookie_jar);
    }
}

// Esecuzione test
if (php_sapi_name() === 'cli') {
    // Esecuzione da linea di comando
    runTests();
    echo "\n";
} else {
    // Esecuzione da browser
    header('Content-Type: text/plain; charset=utf-8');
    echo "TEST API AUTENTICAZIONE - Eseguire da CLI per output colorato\n";
    echo str_repeat('=', 60) . "\n\n";

    // Disabilita colori per output browser
    $colors = array_fill_keys(array_keys($colors), '');

    runTests();
}