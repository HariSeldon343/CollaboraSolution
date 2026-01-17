<?php
/**
 * Page Access Check - Visibility Control
 * Include questo file all'inizio di ogni pagina per verificare la visibilità
 * basata sul ruolo utente e configurazioni di sistema
 *
 * Usage:
 * require_once __DIR__ . '/includes/page_access_check.php';
 * checkPageAccess('dashboard'); // page name without .php extension
 */

if (!defined('PAGE_ACCESS_CHECK_LOADED')) {
    define('PAGE_ACCESS_CHECK_LOADED', true);

    require_once __DIR__ . '/page_visibility_helper.php';

    /**
     * Check if current user can access the specified page
     * Redirects to dashboard if access denied
     *
     * @param string $pageName Page name (without .php extension)
     * @return void
     */
    function checkPageAccess($pageName) {
        // Get current user from session (preferred) or page scope
        // Many pages already have $currentUser from Auth::getCurrentUser()
        global $currentUser;
        $resolvedUser = $_SESSION['user'] ?? $currentUser ?? null;

        if (!$resolvedUser) {
            // Not authenticated - redirect to login
            header('Location: index.php');
            exit;
        }

        $userRole = $resolvedUser['role'] ?? 'user';
        $tenantId = $resolvedUser['tenant_id'] ?? null;

        // Super admin can access everything
        if ($userRole === 'super_admin') {
            return;
        }

        // Check visibility
        if (!isPageVisibleForRole($pageName, $userRole, $tenantId)) {
            // Access denied - redirect to dashboard
            $userId = $resolvedUser['id'] ?? 'unknown';
            error_log("[PAGE ACCESS DENIED] User: {$userId}, Role: $userRole, Page: $pageName");

            // Set flash message
            $_SESSION['flash_error'] = "Non hai i permessi per accedere a questa pagina.";

            // Redirect to dashboard.
            // IMPORTANT: if the denied page IS the dashboard, redirecting to dashboard would cause an infinite loop.
            if ($pageName === 'dashboard') {
                header('Location: index.php');
            } else {
                header('Location: dashboard.php');
            }
            exit;
        }
    }

    /**
     * Get current page name from $_SERVER['PHP_SELF']
     * @return string Page name without extension
     */
    function getCurrentPageName() {
        $filename = basename($_SERVER['PHP_SELF']);
        return str_replace('.php', '', $filename);
    }

    /**
     * Auto-check current page access if $autoCheck is set
     * Usage: At top of page: $autoCheck = 'dashboard';
     */
    if (isset($autoCheck) && $autoCheck) {
        checkPageAccess($autoCheck);
    }
}
