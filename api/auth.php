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

        // Cerca l'utente (con LEFT JOIN per supportare utenti senza tenant)
        $stmt = $pdo->prepare("
            SELECT u.*, t.name as tenant_name, t.code as tenant_code, t.status as tenant_status
            FROM users u
            LEFT JOIN tenants t ON u.tenant_id = t.id
            WHERE u.email = ? AND u.is_active = 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // Check if user can login based on role and tenant assignment
            $canLogin = true;
            $loginMessage = '';

            // Check if stored procedure exists for validation
            $checkProcedure = $pdo->prepare("SELECT COUNT(*) as count FROM information_schema.ROUTINES
                                            WHERE ROUTINE_SCHEMA = 'collaboranexio'
                                            AND ROUTINE_NAME = 'CheckUserLoginAccess'");
            $checkProcedure->execute();
            $procedureExists = $checkProcedure->fetch(PDO::FETCH_ASSOC)['count'] > 0;

            if ($procedureExists) {
                // Use stored procedure to check login access
                $stmt = $pdo->prepare("CALL CheckUserLoginAccess(:user_id, @can_login, @message)");
                $stmt->bindParam(':user_id', $user['id'], PDO::PARAM_INT);
                $stmt->execute();

                $result = $pdo->query("SELECT @can_login as can_login, @message as message")->fetch(PDO::FETCH_ASSOC);
                $canLogin = (bool)$result['can_login'];
                $loginMessage = $result['message'];
            } else {
                // Manual validation if stored procedure doesn't exist
                if (in_array($user['role'], ['super_admin', 'admin'])) {
                    // Admin and Super Admin can always login
                    $canLogin = true;
                } elseif (empty($user['tenant_id'])) {
                    // Regular users and managers need a tenant
                    $canLogin = false;
                    $loginMessage = 'Il tuo account non è associato a nessuna azienda. Contatta l\'amministratore.';
                } elseif ($user['tenant_status'] !== 'active') {
                    // Check if tenant is active
                    $canLogin = false;
                    $loginMessage = 'L\'azienda associata al tuo account non è attiva.';
                }
            }

            if (!$canLogin) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => $loginMessage ?: 'Accesso non consentito'
                ]);
                exit;
            }

            // Login riuscito
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['tenant_id'] = $user['tenant_id'];
            $_SESSION['tenant_name'] = $user['tenant_name'] ?? 'No Company';

            // For admin/super_admin with multiple tenant access, get accessible tenants
            if (in_array($user['role'], ['admin', 'super_admin'])) {
                $tenantStmt = $pdo->prepare("
                    SELECT DISTINCT t.id, t.name
                    FROM tenants t
                    LEFT JOIN user_tenant_access uta ON t.id = uta.tenant_id
                    WHERE (uta.user_id = ? OR ? = 'super_admin')
                    AND t.status = 'active'
                    ORDER BY t.name
                ");
                $tenantStmt->execute([$user['id'], $user['role']]);
                $_SESSION['accessible_tenants'] = $tenantStmt->fetchAll(PDO::FETCH_ASSOC);
            }

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
                    'tenant' => $user['tenant_name'] ?? 'No Company',
                    'has_tenant' => !empty($user['tenant_id']),
                    'accessible_tenants' => $_SESSION['accessible_tenants'] ?? []
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