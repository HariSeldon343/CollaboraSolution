<?php
// Initialize session with proper configuration
require_once dirname(__DIR__) . '/includes/session_init.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Includi le dipendenze
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

// Gestisci le azioni
$action = $_GET['action'] ?? '';

// If no action is provided, return an informative response
if (empty($action)) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Authentication API is running',
        'available_actions' => ['login', 'logout', 'check'],
        'method' => $_SERVER['REQUEST_METHOD']
    ]);
    exit();
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Leggi il JSON dal body
    $input = json_decode(file_get_contents('php://input'), true);

    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';

    // Validazione base
    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email e password richiesti']);
        exit;
    }

    try {
        // Connessione database
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        // Cerca l'utente
        $stmt = $pdo->prepare("
            SELECT u.*, t.name as tenant_name, t.code as tenant_code
            FROM users u
            JOIN tenants t ON u.tenant_id = t.id
            WHERE u.email = ? AND u.is_active = 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // Login riuscito
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['tenant_id'] = $user['tenant_id'];
            $_SESSION['tenant_name'] = $user['tenant_name'];

            // Aggiorna last_login
            $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $updateStmt->execute([$user['id']]);

            echo json_encode([
                'success' => true,
                'message' => 'Login effettuato con successo',
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'tenant' => $user['tenant_name']
                ]
            ]);
        } else {
            // Login fallito
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Credenziali non valide']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore server: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logout effettuato']);
    exit;
}

if ($action === 'check') {
    echo json_encode([
        'authenticated' => isset($_SESSION['user_id']),
        'user' => isset($_SESSION['user_id']) ? [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'],
            'role' => $_SESSION['user_role']
        ] : null
    ]);
    exit;
}

// Azione non riconosciuta
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Azione non valida']);
?>