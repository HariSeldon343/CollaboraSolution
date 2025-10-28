<?php
/**
 * CollaboraNexio - Logout Handler
 * Handles user logout and session destruction
 */

// Initialize session with proper configuration
require_once __DIR__ . '/includes/session_init.php';

// Audit log - Track logout BEFORE destroying session
if (isset($_SESSION['user_id']) && isset($_SESSION['tenant_id'])) {
    try {
        require_once __DIR__ . '/includes/audit_helper.php';
        AuditLogger::logLogout($_SESSION['user_id'], $_SESSION['tenant_id']);
    } catch (Exception $e) {
        error_log("[AUDIT LOG FAILURE] Logout tracking failed: " . $e->getMessage());
    }
}

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: index.php");
exit();
?>