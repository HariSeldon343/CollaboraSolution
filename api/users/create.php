<?php
/**
 * API Endpoint: Create User
 * Creates a new user with proper security and validation
 */

// Include centralized API authentication
require_once '../../includes/api_auth.php';

// Initialize API environment (session, headers, error handling)
initializeApiEnvironment();

try {
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiError('Metodo non consentito', 405);
    }

    // Include required files
    require_once '../../config.php';
    require_once '../../includes/db.php';

    // Verify authentication
    verifyApiAuthentication();

    // Get current user info
    $userInfo = getApiUserInfo();
    $currentUserId = $userInfo['user_id'];
    $currentUserRole = $userInfo['role'];
    $currentTenantId = $userInfo['tenant_id'];

    // Verify CSRF token (checks headers, GET, POST automatically)
    verifyApiCsrfToken();

    // Only admins can create users (checks for admin role or higher)
    if (!hasApiRole('admin')) {
        apiError('Non hai i permessi per creare utenti', 403);
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
    if ($currentUserRole === 'admin' && $tenantId !== $currentTenantId) {
        $errors[] = 'Non puoi creare utenti in altre aziende';
    }

    if (!empty($errors)) {
        apiError('Errori di validazione', 400, ['details' => $errors]);
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
        apiError('Email già registrata', 409);
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