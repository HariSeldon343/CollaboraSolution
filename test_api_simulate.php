<?php
/**
 * Simula una richiesta all'API files
 */

echo "=== SIMULAZIONE CHIAMATA API FILES ===\n\n";

// Simula ambiente web
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET['folder_id'] = '';

// Inizializza sessione prima di includere files
require_once __DIR__ . '/includes/session_init.php';

// Simula utente autenticato
$_SESSION['user_id'] = 1;
$_SESSION['tenant_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

echo "Sessione configurata:\n";
echo "  - user_id: " . $_SESSION['user_id'] . "\n";
echo "  - tenant_id: " . $_SESSION['tenant_id'] . "\n";
echo "  - role: " . $_SESSION['role'] . "\n\n";

echo "Chiamata API: GET /api/files.php\n";
echo "----------------------------------------\n";

// Cattura l'output JSON
ob_start();

try {
    // Includi l'API - questo dovrebbe produrre output JSON
    require __DIR__ . '/api/files.php';
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Eccezione catturata',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

$output = ob_get_clean();

// Decodifica e mostra il risultato
echo "Risposta API:\n";
$response = json_decode($output, true);

if ($response === null) {
    echo "ERRORE: Risposta non è JSON valido\n";
    echo "Output raw:\n";
    echo $output . "\n";
} else {
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    if (isset($response['success']) && $response['success']) {
        echo "\n✓ API risponde correttamente!\n";
        if (isset($response['data'])) {
            echo "  - Files trovati: " . count($response['data']) . "\n";
        }
        if (isset($response['pagination'])) {
            echo "  - Totale files: " . $response['pagination']['total'] . "\n";
            echo "  - Pagina: " . $response['pagination']['page'] . "/" . $response['pagination']['pages'] . "\n";
        }
    } else {
        echo "\n✗ API ha restituito un errore\n";
        if (isset($response['error'])) {
            echo "  - Errore: " . $response['error'] . "\n";
        }
    }
}

echo "\n=== FINE TEST ===\n";