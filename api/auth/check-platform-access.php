<?php
/**
 * Check Platform Access API Endpoint
 *
 * Implements OpenSpec COLLAB-2025-002
 * Returns whether the current user can access the platform based on active tenant availability
 *
 * @version 2.0.0
 * @package CollaboraNexio\API
 */

declare(strict_types=1);

// Initialize API environment (headers, error handling, CORS)
require_once '../../includes/api_auth.php';
initializeApiEnvironment();

// Verify authentication and get user info
verifyApiAuthentication();
$userInfo = getApiUserInfo();

// Load tenant access check functions
require_once '../../includes/tenant_access_check.php';

try {
    // Check platform access for current user
    $accessCheck = checkPlatformAccess(
        (int)$userInfo['user_id'],
        $userInfo['role']
    );

    // Return success response with access information
    apiSuccess($accessCheck, 'Verifica accesso completata');

} catch (Exception $e) {
    // Log error and return generic error message
    error_log("Platform access check failed for user {$userInfo['user_id']}: " . $e->getMessage());
    apiError('Errore durante la verifica dell\'accesso alla piattaforma', 500);
}
