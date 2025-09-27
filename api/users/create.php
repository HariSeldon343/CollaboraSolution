<?php
/**
 * API Endpoint: Create User
 * Creates a new user with proper security and validation
 */

// Suppress all PHP warnings/notices from being output
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// Start output buffering to catch any unexpected output
ob_start();

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON headers immediately
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_clean();
        http_response_code(405);
        die(json_encode(['error' => 'Metodo non consentito']));
    }

    // Include required files
    require_once '../../config.php';
    require_once '../../includes/db.php';
    require_once '../../includes/auth.php';

    // Authentication validation
    if (!isset($_SESSION['user_id'])) {
        ob_clean();
        http_response_code(401);
        die(json_encode(['error' => 'Non autorizzato']));
    }

    // Get current user info
    $currentUserId = $_SESSION['user_id'];
    $currentUserRole = $_SESSION['role'] ?? 'user';
    $currentTenantId = $_SESSION['tenant_id'] ?? null;

    // Only admins can create users
    if (!in_array($currentUserRole, ['super_admin', 'tenant_admin'])) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Non hai i permessi per creare utenti']));
    }

    // CSRF validation
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido']));
    }

    // Get and validate input
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';
    $tenantId = intval($_POST['tenant_id'] ?? $currentTenantId);
    $isActive = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : true;

    // Validation
    $errors = [];
    if (empty($name)) {
        $errors[] = 'Nome richiesto';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email non valida';
    }
    if (strlen($password) < 8) {
        $errors[] = 'La password deve contenere almeno 8 caratteri';
    }
    if (!in_array($role, ['super_admin', 'tenant_admin', 'manager', 'user', 'guest'])) {
        $errors[] = 'Ruolo non valido';
    }
    if ($tenantId <= 0) {
        $errors[] = 'Azienda non valida';
    }

    // Tenant admins can only create users in their own tenant
    if ($currentUserRole === 'tenant_admin' && $tenantId !== $currentTenantId) {
        $errors[] = 'Non puoi creare utenti in altre aziende';
    }

    if (!empty($errors)) {
        ob_clean();
        http_response_code(400);
        die(json_encode(['error' => 'Errori di validazione', 'details' => $errors]));
    }

    // Get database instance
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Check if email already exists
    $checkQuery = "SELECT COUNT(*) as count FROM users WHERE email = :email";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bindParam(':email', $email);
    $checkStmt->execute();
    $emailExists = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

    if ($emailExists) {
        ob_clean();
        http_response_code(409);
        die(json_encode(['error' => 'Email già registrata']));
    }

    // Check if tenant exists
    $tenantQuery = "SELECT id, status FROM tenants WHERE id = :tenant_id";
    $tenantStmt = $conn->prepare($tenantQuery);
    $tenantStmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
    $tenantStmt->execute();
    $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        ob_clean();
        http_response_code(404);
        die(json_encode(['error' => 'Azienda non trovata']));
    }

    if (!in_array($tenant['status'], ['active', 'trial'])) {
        ob_clean();
        http_response_code(400);
        die(json_encode(['error' => 'Azienda non attiva']));
    }

    // Hash password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Insert new user
    $insertQuery = "
        INSERT INTO users (
            tenant_id,
            email,
            password_hash,
            name,
            role,
            is_active
        ) VALUES (
            :tenant_id,
            :email,
            :password_hash,
            :name,
            :role,
            :is_active
        )
    ";

    $stmt = $conn->prepare($insertQuery);
    $stmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':password_hash', $passwordHash);
    $stmt->bindParam(':name', $name);
    $stmt->bindParam(':role', $role);
    $stmt->bindParam(':is_active', $isActive, PDO::PARAM_BOOL);

    if (!$stmt->execute()) {
        ob_clean();
        http_response_code(500);
        die(json_encode(['error' => 'Errore nella creazione dell\'utente']));
    }

    $newUserId = $conn->lastInsertId();

    // Clean any output buffer
    ob_clean();

    // Success response
    echo json_encode([
        'success' => true,
        'data' => [
            'user_id' => (int)$newUserId
        ],
        'message' => 'Utente creato con successo'
    ]);
    exit();

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log('Create User PDO Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return user-friendly error
    http_response_code(500);
    echo json_encode(['error' => 'Errore database']);
    exit();

} catch (Exception $e) {
    // Log the error
    error_log('Create User Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return generic error
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno del server']);
    exit();
}