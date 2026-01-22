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

    // TENANT_ROLES: Get tenant_role_id parameter
    $tenantRoleId = isset($_POST['tenant_role_id']) ? intval($_POST['tenant_role_id']) : null;
    if ($tenantRoleId === 0) {
        $tenantRoleId = null;
    }

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

    // TENANT_ROLES: Managers can only create users with role='user'
    if ($currentUserRole === 'manager') {
        if (!in_array($role, ['user'])) {
            $errors[] = 'I manager possono creare solo utenti con ruolo "user"';
        }
        // Force same tenant for managers
        $tenantId = $currentTenantId;
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

    // TENANT_ROLES: Check if tenant has custom roles and validate tenant_role_id
    $tenantHasCustomRoles = false;
    $tenantHasRolesQuery = "SELECT has_custom_roles FROM tenants WHERE id = :tenant_id";
    $tenantHasRolesStmt = $conn->prepare($tenantHasRolesQuery);
    $tenantHasRolesStmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
    $tenantHasRolesStmt->execute();
    $tenantRolesResult = $tenantHasRolesStmt->fetch(PDO::FETCH_ASSOC);
    if ($tenantRolesResult) {
        $tenantHasCustomRoles = (bool)$tenantRolesResult['has_custom_roles'];
    }

    // TENANT_ROLES: Validate tenant_role_id if provided
    $validatedTenantRoleId = null;
    if ($tenantRoleId !== null) {
        // Verify role exists, belongs to same tenant, is active, and not deleted
        $roleCheckQuery = "
            SELECT id FROM tenant_roles
            WHERE id = :role_id
              AND tenant_id = :tenant_id
              AND is_active = 1
              AND deleted_at IS NULL
        ";
        $roleCheckStmt = $conn->prepare($roleCheckQuery);
        $roleCheckStmt->bindParam(':role_id', $tenantRoleId, PDO::PARAM_INT);
        $roleCheckStmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
        $roleCheckStmt->execute();
        $validRole = $roleCheckStmt->fetch(PDO::FETCH_ASSOC);

        if (!$validRole) {
            apiError('Ruolo aziendale non valido o non appartiene a questa azienda', 400);
        }

        $validatedTenantRoleId = (int)$tenantRoleId;
    }

    // TENANT_ROLES: If tenant has custom roles but none was provided, this is allowed
    // (user can be created without a business role and assigned later)

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

    // TENANT_ROLES: Create or update user_tenant_access record with tenant_role_id
    if ($validatedTenantRoleId !== null) {
        // Check if user_tenant_access record already exists
        $utaCheckQuery = "
            SELECT id FROM user_tenant_access
            WHERE user_id = :user_id
              AND tenant_id = :tenant_id
              AND deleted_at IS NULL
        ";
        $utaCheckStmt = $conn->prepare($utaCheckQuery);
        $utaCheckStmt->bindParam(':user_id', $newUserId, PDO::PARAM_INT);
        $utaCheckStmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
        $utaCheckStmt->execute();
        $existingUta = $utaCheckStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUta) {
            // Update existing record
            $utaUpdateQuery = "
                UPDATE user_tenant_access
                SET tenant_role_id = :tenant_role_id,
                    updated_at = NOW()
                WHERE id = :uta_id
            ";
            $utaUpdateStmt = $conn->prepare($utaUpdateQuery);
            $utaUpdateStmt->bindParam(':tenant_role_id', $validatedTenantRoleId, PDO::PARAM_INT);
            $utaUpdateStmt->bindParam(':uta_id', $existingUta['id'], PDO::PARAM_INT);
            $utaUpdateStmt->execute();
        } else {
            // Create new user_tenant_access record
            $utaInsertQuery = "
                INSERT INTO user_tenant_access (
                    user_id,
                    tenant_id,
                    tenant_role_id,
                    granted_by,
                    granted_at,
                    created_at,
                    updated_at
                ) VALUES (
                    :user_id,
                    :tenant_id,
                    :tenant_role_id,
                    :granted_by,
                    NOW(),
                    NOW(),
                    NOW()
                )
            ";
            $utaInsertStmt = $conn->prepare($utaInsertQuery);
            $utaInsertStmt->bindParam(':user_id', $newUserId, PDO::PARAM_INT);
            $utaInsertStmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
            $utaInsertStmt->bindParam(':tenant_role_id', $validatedTenantRoleId, PDO::PARAM_INT);
            $utaInsertStmt->bindParam(':granted_by', $currentUserId, PDO::PARAM_INT);
            $utaInsertStmt->execute();
        }
    }

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
                'is_active' => $isActive,
                'tenant_role_id' => $validatedTenantRoleId
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
            'email_sent' => $emailSent,
            'tenant_role_id' => $validatedTenantRoleId
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