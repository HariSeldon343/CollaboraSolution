<?php
/**
 * API Endpoint: Delete User
 * Deletes a user (hard delete for this simplified version)
 */

// Suppress all PHP warnings/notices from being output
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// Start output buffering to catch any unexpected output
ob_start();

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON headers immediately
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    // Include required files
    require_once '../../config.php';
    require_once '../../includes/db.php';

    // Authentication validation
    if (!isset($_SESSION['user_id'])) {
        ob_clean();
        http_response_code(401);
        die(json_encode(['error' => 'Non autorizzato']));
    }

    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        ob_clean();
        http_response_code(405);
        die(json_encode(['error' => 'Metodo non consentito']));
    }

    // Get current user from session
    $currentUserId = $_SESSION['user_id'];
    $currentUserRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'user';
    $currentTenantId = $_SESSION['tenant_id'] ?? null;

    // Permission check - managers, admins and super_admins can delete users
    $allowedRoles = ['manager', 'admin', 'tenant_admin', 'super_admin'];
    if (!in_array($currentUserRole, $allowedRoles)) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Permessi insufficienti per eliminare utenti']));
    }

    // CSRF validation
    // Get CSRF token from various sources
    $csrfToken = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // For POST request, check both body and header
        $input = json_decode(file_get_contents('php://input'), true);
        $csrfToken = $_POST['csrf_token'] ?? $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    } else {
        // For DELETE request, check header
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }

    if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido']));
    }

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
        http_response_code(400);
        die(json_encode(['error' => 'ID utente non valido']));
    }

    // Prevent self-deletion
    if ($userId == $currentUserId) {
        ob_clean();
        http_response_code(400);
        die(json_encode(['error' => 'Non puoi eliminare il tuo stesso account']));
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
        http_response_code(404);
        die(json_encode(['error' => 'Utente non trovato']));
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
        http_response_code(403);
        die(json_encode(['error' => 'Non puoi eliminare un utente con ruolo superiore al tuo']));
    }

    // Perform hard delete (no soft delete in this simplified version)
    $deleteQuery = "DELETE FROM users WHERE id = :user_id";
    $deleteStmt = $conn->prepare($deleteQuery);
    $deleteStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);

    if (!$deleteStmt->execute()) {
        ob_clean();
        http_response_code(500);
        die(json_encode(['error' => 'Errore durante l\'eliminazione dell\'utente']));
    }

    // Clean any output buffer
    ob_clean();

    // Success response
    echo json_encode([
        'success' => true,
        'message' => 'Utente eliminato con successo',
        'deleted_user' => [
            'id' => $userToDelete['id'],
            'email' => $userToDelete['email'],
            'name' => $userToDelete['name']
        ]
    ]);
    exit();

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log('Delete User PDO Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return user-friendly error
    http_response_code(500);
    echo json_encode(['error' => 'Errore database durante l\'eliminazione']);
    exit();

} catch (Exception $e) {
    // Log the error
    error_log('Delete User Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return generic error
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit();
}
?>