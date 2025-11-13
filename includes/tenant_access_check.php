<?php
/**
 * Tenant Access Check Middleware
 *
 * Implements OpenSpec COLLAB-2025-002
 * Controls platform access based on active tenant availability
 *
 * @version 1.0.0
 * @package CollaboraNexio
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Count active tenants in the system
 *
 * @return int Number of active tenants (where deleted_at IS NULL)
 * @throws Exception If database query fails
 */
function countActiveTenants(): int {
    try {
        $db = Database::getInstance();

        $count = $db->count('tenants', ['deleted_at' => null]);

        return (int)$count;
    } catch (Exception $e) {
        error_log("Error counting active tenants: " . $e->getMessage());
        throw new Exception("Impossibile verificare le aziende attive");
    }
}

/**
 * Check if a user can access the platform based on tenant availability
 *
 * Logic:
 * - Super admins always have access regardless of tenant count
 * - All other roles require at least one active tenant
 *
 * @param int $userId User ID to check access for
 * @param string $userRole User role (user, manager, admin, super_admin)
 * @return array Associative array with:
 *               - can_access (bool): Whether user can access platform
 *               - reason (string): Explanation message
 *               - tenants_count (int): Number of active tenants
 * @throws Exception If database operations fail
 */
function checkPlatformAccess(int $userId, string $userRole): array {
    try {
        // Count active tenants
        $tenantsCount = countActiveTenants();

        // Super admins always have access
        if ($userRole === 'super_admin') {
            return [
                'can_access' => true,
                'reason' => 'Accesso consentito: super amministratore',
                'tenants_count' => $tenantsCount
            ];
        }

        // All other roles need at least one active tenant
        if ($tenantsCount === 0) {
            return [
                'can_access' => false,
                'reason' => 'Accesso negato: non hai aziende associate',
                'tenants_count' => 0
            ];
        }

        // Active tenants exist and user is not super_admin
        return [
            'can_access' => true,
            'reason' => 'Accesso consentito: aziende attive presenti',
            'tenants_count' => $tenantsCount
        ];

    } catch (Exception $e) {
        error_log("Error checking platform access for user {$userId}: " . $e->getMessage());
        throw new Exception("Impossibile verificare l'accesso alla piattaforma");
    }
}

/**
 * Require tenant access or block with redirect
 *
 * This function should be called in pages that require active tenants.
 * If access is denied, it redirects to index.php with an error message.
 *
 * @param int $userId Current user ID
 * @param string $userRole Current user role
 * @return void Exits script if access denied
 * @throws Exception If access check fails
 */
function requireTenantAccess(int $userId, string $userRole): void {
    try {
        $accessCheck = checkPlatformAccess($userId, $userRole);

        if (!$accessCheck['can_access']) {
            // Start session if not already started
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            // Set error message in session
            $_SESSION['error_message'] = $accessCheck['reason'];

            // Redirect to login page
            header('Location: /CollaboraNexio/index.php');
            exit;
        }
    } catch (Exception $e) {
        // Log error and redirect with generic message
        error_log("Tenant access check failed: " . $e->getMessage());

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['error_message'] = 'Errore durante la verifica dell\'accesso';
        header('Location: /CollaboraNexio/index.php');
        exit;
    }
}
