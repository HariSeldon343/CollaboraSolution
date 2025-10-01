<?php
/**
 * API Endpoint: Delete User
 * Deletes a user (hard delete for this simplified version)
 */

// Usa il sistema di autenticazione centralizzato
require_once '../../includes/api_auth.php';
require_once '../../config.php';
require_once '../../includes/db.php';

// Inizializza l'ambiente API
initializeApiEnvironment();

try {
    // Verifica autenticazione
    verifyApiAuthentication();

    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        apiError('Metodo non consentito', 405);
    }

    // Ottieni informazioni utente
    $userInfo = getApiUserInfo();
    $currentUserId = $userInfo['user_id'];
    $currentUserRole = $userInfo['role'];
    $currentTenantId = $userInfo['tenant_id'];

    // Verifica permessi - solo manager, admin e super_admin possono eliminare utenti
    if (!hasApiRole('manager')) {
        ob_clean();
        apiError('Permessi insufficienti per eliminare utenti', 403);
    }

    // Verifica CSRF token
    verifyApiCsrfToken(true);

    // Get and validate input
    $userId = 0;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Check both form data and JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        $userId = intval($_POST['user_id'] ?? $input['user_id'] ?? 0);
    } else {
        // For DELETE request, get from URL or JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        $userId = intval($_GET['id'] ?? $input['user_id'] ?? 0);
    }

    // Validation
    if ($userId <= 0) {
        ob_clean();
        apiError('ID utente non valido', 400);
    }

    // Prevent self-deletion
    if ($userId == $currentUserId) {
        ob_clean();
        apiError('Non puoi eliminare il tuo stesso account', 400);
    }

    // Get database instance
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Check if user exists
    // For super_admin, check across all tenants. For others, check within tenant
    $checkQuery = "SELECT id, email, name, tenant_id, role FROM users WHERE id = :user_id";
    if ($currentUserRole !== 'super_admin') {
        $checkQuery .= " AND tenant_id = :tenant_id";
    }

    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    if ($currentUserRole !== 'super_admin') {
        $checkStmt->bindParam(':tenant_id', $currentTenantId, PDO::PARAM_INT);
    }
    $checkStmt->execute();
    $userToDelete = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$userToDelete) {
        ob_clean();
        apiError('Utente non trovato', 404);
    }

    // Check role hierarchy permissions
    $roleHierarchy = [
        'user' => 1,
        'manager' => 2,
        'admin' => 3,
        'tenant_admin' => 3,
        'super_admin' => 4
    ];

    $currentLevel = $roleHierarchy[$currentUserRole] ?? 1;
    $targetLevel = $roleHierarchy[$userToDelete['role']] ?? 1;

    // Users can only delete users with lower or equal roles
    if ($targetLevel > $currentLevel) {
        ob_clean();
        apiError('Non puoi eliminare un utente con ruolo superiore al tuo', 403);
    }

    // Perform hard delete (no soft delete in this simplified version)
    $deleteQuery = "DELETE FROM users WHERE id = :user_id";
    $deleteStmt = $conn->prepare($deleteQuery);
    $deleteStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);

    if (!$deleteStmt->execute()) {
        ob_clean();
        apiError('Errore durante l\'eliminazione dell\'utente', 500);
    }

    // Success response
    apiSuccess([
        'deleted_user' => [
            'id' => $userToDelete['id'],
            'email' => $userToDelete['email'],
            'name' => $userToDelete['name']
        ]
    ], 'Utente eliminato con successo');

} catch (PDOException $e) {
    logApiError('delete.php', $e);
    apiError('Errore database durante l\'eliminazione', 500);
} catch (Exception $e) {
    logApiError('delete.php', $e);
    apiError('Errore durante l\'eliminazione dell\'utente', 500);
}
?>