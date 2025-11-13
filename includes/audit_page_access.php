<?php
/**
 * CollaboraNexio - Page Access Tracking Middleware
 *
 * Lightweight middleware for tracking page accesses across dashboard pages.
 * Should be called AFTER session_init.php and authentication checks.
 *
 * @version 1.0.0
 * @date 2025-10-27
 *
 * USAGE:
 * require_once __DIR__ . '/includes/audit_page_access.php';
 * trackPageAccess('dashboard'); // or 'files', 'tasks', 'tickets', etc.
 *
 * PERFORMANCE:
 * - Overhead: < 5ms per page load
 * - Non-blocking: Page loads even if tracking fails
 * - Cached database connection reuse
 *
 * STANDARDS:
 * - Only track authenticated pages (requires session)
 * - Multi-tenant isolation enforced
 * - BUG-029 pattern: Non-blocking, explicit error logging
 */

/**
 * Track page access for current user
 *
 * @param string $pageName Page name (e.g., 'dashboard', 'files', 'tasks')
 * @return bool True if tracked successfully, false otherwise
 */
function trackPageAccess($pageName)
{
    // Check if session is initialized
    if (session_status() !== PHP_SESSION_ACTIVE) {
        error_log("[PAGE ACCESS TRACKING] Session not active, skipping tracking for page: $pageName");
        return false;
    }

    // Check if user is authenticated
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['tenant_id'])) {
        // Not authenticated - skip tracking (this is normal for login page, etc.)
        return false;
    }

    // Get user context from session
    $userId = $_SESSION['user_id'];
    $tenantId = $_SESSION['tenant_id'];

    // Load AuditLogger
    try {
        require_once __DIR__ . '/audit_helper.php';

        // Track page access using AuditLogger
        return AuditLogger::logPageAccess($userId, $tenantId, $pageName);

    } catch (Exception $e) {
        // Non-blocking: Page should load even if tracking fails
        error_log("[PAGE ACCESS TRACKING FAILURE] Error: " . $e->getMessage());
        error_log("[PAGE ACCESS TRACKING FAILURE] Page: $pageName, User ID: $userId, Tenant ID: $tenantId");
        return false;
    }
}

/**
 * Get page name from current script filename
 * Automatically detects page name based on PHP_SELF
 *
 * @return string Page name without .php extension
 */
function getCurrentPageName()
{
    $scriptName = basename($_SERVER['PHP_SELF'], '.php');
    return $scriptName;
}

/**
 * Track current page automatically
 * Convenience function that auto-detects page name
 *
 * @return bool
 */
function trackCurrentPage()
{
    $pageName = getCurrentPageName();
    return trackPageAccess($pageName);
}
