<?php
/**
 * API Endpoint: Update User
 * Updates an existing user's information
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

    // Only admins can update users (or users can update themselves)
    $userId = intval($_POST['user_id'] ?? 0);
    $isSelfUpdate = ($userId === $currentUserId);

    if (!$isSelfUpdate && !in_array($currentUserRole, ['super_admin', 'tenant_admin'])) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Non hai i permessi per modificare utenti']));
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
        ob_clean();
        http_response_code(400);
        die(json_encode(['error' => 'Errori di validazione', 'details' => $errors]));
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
        ob_clean();
        http_response_code(404);
        die(json_encode(['error' => 'Utente non trovato']));
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
            ob_clean();
            http_response_code(409);
            die(json_encode(['error' => 'Email già utilizzata da un altro utente']));
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
            ob_clean();
            http_response_code(404);
            die(json_encode(['error' => 'Azienda non trovata']));
        }

        if (!in_array($tenant['status'], ['active', 'trial'])) {
            ob_clean();
            http_response_code(400);
            die(json_encode(['error' => 'Azienda non attiva']));
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
        ob_clean();
        http_response_code(500);
        die(json_encode(['error' => 'Errore nell\'aggiornamento dell\'utente']));
    }

    // Clean any output buffer
    ob_clean();

    // Success response
    echo json_encode([
        'success' => true,
        'message' => 'Utente aggiornato con successo'
    ]);
    exit();

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log('Update User PDO Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return user-friendly error
    http_response_code(500);
    echo json_encode(['error' => 'Errore database']);
    exit();

} catch (Exception $e) {
    // Log the error
    error_log('Update User Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return generic error
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno del server']);
    exit();
}