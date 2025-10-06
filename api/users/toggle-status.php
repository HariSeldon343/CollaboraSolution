<?php
/**
 * API Endpoint: Toggle User Status
 * Toggles user active/inactive status
 */

// Usa il sistema di autenticazione centralizzato
require_once '../../includes/api_auth.php';
require_once '../../config.php';
require_once '../../includes/db.php';

// Inizializza l'ambiente API
initializeApiEnvironment();

try {
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiError('Metodo non consentito', 405);
    }

    // Verifica autenticazione
    verifyApiAuthentication();

    // Ottieni informazioni utente
    $userInfo = getApiUserInfo();
    $currentUserId = $userInfo['user_id'];
    $currentUserRole = $userInfo['role'];
    $tenant_id = $userInfo['tenant_id'];

    // Permission check - only admins can toggle status
    if (!in_array($currentUserRole, ['super_admin', 'admin'])) {
        apiError('Permessi insufficienti', 403);
    }

    // Verifica CSRF token
    verifyApiCsrfToken(true);

    // Get and validate input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    $userId = intval($input['user_id'] ?? 0);

    // Validation
    if ($userId <= 0) {
        apiError('ID utente non valido', 400);
    }

    // Prevent self status change
    if ($userId == $currentUserId) {
        apiError('Non puoi cambiare il tuo stesso stato', 400);
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
        apiError('Utente non trovato', 404);
    }

    // Check role hierarchy permissions
    $roleHierarchy = ['guest' => 1, 'user' => 2, 'manager' => 3, 'tenant_admin' => 4, 'super_admin' => 5];
    $currentLevel = $roleHierarchy[$currentUserRole] ?? 1;
    $targetLevel = $roleHierarchy[$userToToggle['role']] ?? 1;

    if ($targetLevel >= $currentLevel && $currentUserRole !== 'super_admin') {
        apiError('Non hai i permessi per modificare questo utente', 403);
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
        apiError('Errore nel cambio di stato dell\'utente', 500);
    }

    // Success response
    apiSuccess([
        'new_status' => $newStatus ? 'active' : 'inactive',
        'is_active' => $newStatus
    ], $newStatus ? 'Utente attivato con successo' : 'Utente disattivato con successo');

} catch (PDOException $e) {
    logApiError('toggle-status.php', $e);
    apiError('Errore database', 500);
} catch (Exception $e) {
    logApiError('toggle-status.php', $e);
    apiError('Errore interno del server', 500);
}