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

    // Include EmailSender class
    require_once '../../includes/EmailSender.php';

    // Get and validate input
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    // Password non più richiesta - verrà generato un token
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
    // Password non più validata - verrà impostata dall'utente
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

    // Genera token sicuro per il reset password
    $resetToken = EmailSender::generateSecureToken();
    $resetExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // Password temporanea (l'utente dovrà cambiarla)
    $passwordHash = null; // Nessuna password iniziale

    // Insert new user con token per primo accesso
    $insertQuery = "
        INSERT INTO users (
            tenant_id,
            email,
            password_hash,
            name,
            role,
            is_active,
            password_reset_token,
            password_reset_expires,
            first_login
        ) VALUES (
            :tenant_id,
            :email,
            :password_hash,
            :name,
            :role,
            :is_active,
            :reset_token,
            :reset_expires,
            TRUE
        )
    ";

    $stmt = $conn->prepare($insertQuery);
    $stmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':password_hash', $passwordHash);
    $stmt->bindParam(':name', $name);
    $stmt->bindParam(':role', $role);
    $stmt->bindParam(':is_active', $isActive, PDO::PARAM_BOOL);
    $stmt->bindParam(':reset_token', $resetToken);
    $stmt->bindParam(':reset_expires', $resetExpires);

    if (!$stmt->execute()) {
        ob_clean();
        http_response_code(500);
        die(json_encode(['error' => 'Errore nella creazione dell\'utente']));
    }

    $newUserId = $conn->lastInsertId();

    // Audit log - Track user creation
    try {
        require_once '../../includes/audit_helper.php';
        AuditLogger::logCreate(
            $currentUserId,
            $tenantId,
            'user',
            $newUserId,
            "Created new user: $email",
            [
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'tenant_id' => $tenantId,
                'is_active' => $isActive
            ]
        );
    } catch (Exception $e) {
        error_log("[AUDIT LOG FAILURE] User creation tracking failed: " . $e->getMessage());
    }

    // Ottieni il nome del tenant per l'email
    $tenantQuery = "SELECT name FROM tenants WHERE id = :tenant_id";
    $tenantStmt = $conn->prepare($tenantQuery);
    $tenantStmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
    $tenantStmt->execute();
    $tenantData = $tenantStmt->fetch(PDO::FETCH_ASSOC);
    $tenantName = $tenantData ? ' per ' . $tenantData['name'] : '';

    // Invia email di benvenuto con configurazione da database
    require_once __DIR__ . '/../../includes/email_config.php';
    $emailConfig = getEmailConfigFromDatabase();
    $emailSender = new EmailSender($emailConfig);
    $emailSent = false;
    $emailError = '';

    try {
        $emailSent = $emailSender->sendWelcomeEmail($email, $name, $resetToken, $tenantName);

        if ($emailSent) {
            // Aggiorna timestamp invio email
            $updateEmailQuery = "UPDATE users SET welcome_email_sent_at = NOW() WHERE id = :user_id";
            $updateStmt = $conn->prepare($updateEmailQuery);
            $updateStmt->bindParam(':user_id', $newUserId);
            $updateStmt->execute();
        } else {
            $emailError = 'Utente creato ma email non inviata. L\'utente dovrà richiedere un nuovo link.';
        }
    } catch (Exception $e) {
        error_log('Email sending error: ' . $e->getMessage());
        $emailError = 'Utente creato ma email non inviata. Errore: ' . $e->getMessage();
    }

    // Clean any output buffer
    ob_clean();

    // Success response con info email
    $responseData = [
        'success' => true,
        'data' => [
            'user_id' => (int)$newUserId,
            'email_sent' => $emailSent
        ],
        'message' => 'Utente creato con successo'
    ];

    if ($emailError) {
        $responseData['warning'] = $emailError;
        $responseData['reset_link'] = BASE_URL . '/set_password.php?token=' . urlencode($resetToken);
    } else {
        $responseData['message'] .= '. Email di benvenuto inviata.';
    }

    echo json_encode($responseData);
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