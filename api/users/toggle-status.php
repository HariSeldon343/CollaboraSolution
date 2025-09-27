<?php
/**
 * API Endpoint: Toggle User Status
 * Toggles user active/inactive status
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

    // Get current user from session
    $currentUserId = $_SESSION['user_id'];
    $currentUserRole = $_SESSION['role'] ?? 'user';
    $tenant_id = $_SESSION['tenant_id'] ?? null;

    // Permission check - only admins can toggle status
    if (!in_array($currentUserRole, ['super_admin', 'tenant_admin'])) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Permessi insufficienti']));
    }

    // CSRF validation
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido']));
    }

    // Get and validate input
    $userId = intval($_POST['user_id'] ?? 0);

    // Validation
    if ($userId <= 0) {
        ob_clean();
        http_response_code(400);
        die(json_encode(['error' => 'ID utente non valido']));
    }

    // Prevent self status change
    if ($userId == $currentUserId) {
        ob_clean();
        http_response_code(400);
        die(json_encode(['error' => 'Non puoi cambiare il tuo stesso stato']));
    }

    // Get database instance
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Check if user exists and get current status
    // For super_admin, check across all tenants. For others, check within tenant
    $checkQuery = "
        SELECT id, email, name, tenant_id, role, is_active
        FROM users
        WHERE id = :user_id
        " . ($currentUserRole !== 'super_admin' ? "AND tenant_id = :tenant_id" : "") . "
    ";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    if ($currentUserRole !== 'super_admin') {
        $checkStmt->bindParam(':tenant_id', $tenant_id, PDO::PARAM_INT);
    }
    $checkStmt->execute();
    $userToToggle = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$userToToggle) {
        ob_clean();
        http_response_code(404);
        die(json_encode(['error' => 'Utente non trovato']));
    }

    // Check role hierarchy permissions
    $roleHierarchy = ['guest' => 1, 'user' => 2, 'manager' => 3, 'tenant_admin' => 4, 'super_admin' => 5];
    $currentLevel = $roleHierarchy[$currentUserRole] ?? 1;
    $targetLevel = $roleHierarchy[$userToToggle['role']] ?? 1;

    if ($targetLevel >= $currentLevel && $currentUserRole !== 'super_admin') {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Non hai i permessi per modificare questo utente']));
    }

    // Determine new status
    $currentStatus = (bool)$userToToggle['is_active'];
    $newStatus = !$currentStatus;

    // Update user status
    $updateQuery = "UPDATE users SET is_active = :is_active WHERE id = :user_id";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bindParam(':is_active', $newStatus, PDO::PARAM_BOOL);
    $updateStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);

    if (!$updateStmt->execute()) {
        ob_clean();
        http_response_code(500);
        die(json_encode(['error' => 'Errore nel cambio di stato dell\'utente']));
    }

    // Clean any output buffer
    ob_clean();

    // Success response
    echo json_encode([
        'success' => true,
        'message' => $newStatus ? 'Utente attivato con successo' : 'Utente disattivato con successo',
        'new_status' => $newStatus ? 'active' : 'inactive',
        'is_active' => $newStatus
    ]);
    exit();

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log('Toggle User Status PDO Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return user-friendly error
    http_response_code(500);
    echo json_encode(['error' => 'Errore database']);
    exit();

} catch (Exception $e) {
    // Log the error
    error_log('Toggle User Status Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return generic error
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno del server']);
    exit();
}