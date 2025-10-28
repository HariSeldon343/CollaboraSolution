<?php
/**
 * API Endpoint: Update User
 * Updates an existing user's information
 *
 * @version 2.0.0 - Refactored to use centralized api_auth.php
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

    // Verify CSRF token
    verifyApiCsrfToken();

    // Only admins can update users (or users can update themselves)
    $userId = intval($_POST['user_id'] ?? 0);
    $isSelfUpdate = ($userId === $currentUserId);

    if (!$isSelfUpdate && !hasApiRole('admin')) {
        apiError('Non hai i permessi per modificare utenti', 403);
    }

    // Get and validate input
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? null;
    $tenantId = intval($_POST['tenant_id'] ?? $currentTenantId);

    // Validation
    $errors = [];
    if ($userId <= 0) {
        $errors[] = 'ID utente non valido';
    }
    if (empty($name)) {
        $errors[] = 'Nome richiesto';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email non valida';
    }
    if (!empty($password) && strlen($password) < 8) {
        $errors[] = 'La password deve contenere almeno 8 caratteri';
    }

    // Role can only be changed by admins
    if ($role !== null) {
        if ($isSelfUpdate) {
            $errors[] = 'Non puoi cambiare il tuo ruolo';
        } elseif (!in_array($role, ['super_admin', 'tenant_admin', 'manager', 'user', 'guest'])) {
            $errors[] = 'Ruolo non valido';
        }
    }

    // Tenant can only be changed by super_admin
    if ($tenantId !== $currentTenantId && $currentUserRole !== 'super_admin') {
        $errors[] = 'Non puoi spostare utenti in altre aziende';
    }

    if (!empty($errors)) {
        apiError('Errori di validazione', 400, ['details' => $errors]);
    }

    // Get database instance
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Check if user exists and has permission to edit
    $checkQuery = "SELECT id, email, tenant_id, role FROM users WHERE id = :user_id";
    if ($currentUserRole !== 'super_admin') {
        $checkQuery .= " AND tenant_id = :tenant_id";
    }

    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    if ($currentUserRole !== 'super_admin') {
        $checkStmt->bindParam(':tenant_id', $currentTenantId, PDO::PARAM_INT);
    }
    $checkStmt->execute();
    $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingUser) {
        apiError('Utente non trovato', 404);
    }

    // Check if email is being changed and if new email already exists
    if ($email !== $existingUser['email']) {
        $emailCheckQuery = "SELECT COUNT(*) as count FROM users WHERE email = :email AND id != :user_id";
        $emailCheckStmt = $conn->prepare($emailCheckQuery);
        $emailCheckStmt->bindParam(':email', $email);
        $emailCheckStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $emailCheckStmt->execute();
        $emailExists = $emailCheckStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

        if ($emailExists) {
            apiError('Email già utilizzata da un altro utente', 409);
        }
    }

    // Build update query
    $updateFields = [];
    $params = [':user_id' => $userId];

    // Always update name and email
    $updateFields[] = 'name = :name';
    $updateFields[] = 'email = :email';
    $params[':name'] = $name;
    $params[':email'] = $email;

    // Update tenant if changed (only super_admin)
    if ($currentUserRole === 'super_admin' && $tenantId !== $existingUser['tenant_id']) {
        // Verify tenant exists
        $tenantQuery = "SELECT id, status FROM tenants WHERE id = :tenant_id";
        $tenantStmt = $conn->prepare($tenantQuery);
        $tenantStmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
        $tenantStmt->execute();
        $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

        if (!$tenant) {
            apiError('Azienda non trovata', 404);
        }

        if (!in_array($tenant['status'], ['active', 'trial'])) {
            apiError('Azienda non attiva', 400);
        }

        $updateFields[] = 'tenant_id = :tenant_id';
        $params[':tenant_id'] = $tenantId;
    }

    // Update role if provided and allowed
    if ($role !== null && !$isSelfUpdate) {
        $updateFields[] = 'role = :role';
        $params[':role'] = $role;
    }

    // Update password if provided
    if (!empty($password)) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $updateFields[] = 'password_hash = :password_hash';
        $params[':password_hash'] = $passwordHash;
    }

    // Execute update
    $updateQuery = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :user_id";
    $updateStmt = $conn->prepare($updateQuery);

    foreach ($params as $key => $value) {
        if (in_array($key, [':user_id', ':tenant_id'])) {
            $updateStmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $updateStmt->bindValue($key, $value);
        }
    }

    if (!$updateStmt->execute()) {
        apiError('Errore nell\'aggiornamento dell\'utente', 500);
    }

    // Audit log - Track user update
    try {
        require_once '../../includes/audit_helper.php';

        // Build old/new values for comparison
        $oldValues = [
            'name' => $existingUser['name'] ?? '',
            'email' => $existingUser['email'],
            'role' => $existingUser['role'],
            'tenant_id' => $existingUser['tenant_id']
        ];

        $newValues = [
            'name' => $name,
            'email' => $email
        ];

        if (isset($params[':role'])) {
            $newValues['role'] = $role;
        }
        if (isset($params[':tenant_id'])) {
            $newValues['tenant_id'] = $tenantId;
        }
        if (!empty($password)) {
            $newValues['password'] = '[CHANGED]';
            $oldValues['password'] = '[REDACTED]';
        }

        AuditLogger::logUpdate(
            $currentUserId,
            $currentTenantId,
            'user',
            $userId,
            "Updated user: $email",
            $oldValues,
            $newValues,
            !empty($password) ? 'warning' : 'info'
        );
    } catch (Exception $e) {
        error_log("[AUDIT LOG FAILURE] User update tracking failed: " . $e->getMessage());
    }

    // Success response
    apiSuccess(null, 'Utente aggiornato con successo');

} catch (PDOException $e) {
    // Log the actual error for debugging
    logApiError('Update User PDO', $e);
    apiError('Errore database', 500);

} catch (Exception $e) {
    // Log the error
    logApiError('Update User', $e);
    apiError('Errore interno del server', 500);
}