<?php
/**
 * API: Delete Tenant Location
 *
 * Soft delete a location (cannot delete primary sede_legale)
 *
 * Method: DELETE
 * Auth: Admin or Super Admin
 * CSRF: Required
 * URL: /api/tenants/{tenant_id}/locations/{location_id}
 *
 * @author Database Architect
 * @version 1.0.0
 */

declare(strict_types=1);

// Initialize API environment
require_once '../../../includes/api_auth.php';
initializeApiEnvironment();

// Verify authentication
verifyApiAuthentication();
$userInfo = getApiUserInfo();

// Verify CSRF token
verifyApiCsrfToken();

// Require admin role
requireApiRole('admin');

// Load database
require_once '../../../includes/db.php';
$db = Database::getInstance();

try {
    // Parse URL: /api/tenants/{tenant_id}/locations/{location_id}
    $urlParts = explode('/', trim($_SERVER['REQUEST_URI'], '/'));
    $tenantsIndex = array_search('tenants', $urlParts);
    $locationsIndex = array_search('locations', $urlParts);

    if ($tenantsIndex === false || !isset($urlParts[$tenantsIndex + 1]) ||
        $locationsIndex === false || !isset($urlParts[$locationsIndex + 1])) {
        apiError('Invalid URL format', 400);
    }

    $tenantId = (int)$urlParts[$tenantsIndex + 1];
    $locationId = (int)$urlParts[$locationsIndex + 1];

    if ($tenantId <= 0 || $locationId <= 0) {
        apiError('Invalid tenant or location ID', 400);
    }

    // Verify location exists and belongs to tenant
    $location = $db->fetchOne(
        'SELECT *
         FROM tenant_locations
         WHERE id = ?
         AND tenant_id = ?
         AND deleted_at IS NULL',
        [$locationId, $tenantId]
    );

    if (!$location) {
        apiError('Location not found', 404);
    }

    // Prevent deletion of primary sede_legale
    if ($location['location_type'] === 'sede_legale' && $location['is_primary']) {
        apiError('Cannot delete primary sede legale. Every tenant must have a sede legale.', 403);
    }

    // Soft delete in transaction
    $db->beginTransaction();

    try {
        // Soft delete (set deleted_at)
        $db->update('tenant_locations', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active' => false
        ], [
            'id' => $locationId
        ]);

        // Log audit
        $db->insert('audit_logs', [
            'tenant_id' => $tenantId,
            'user_id' => $userInfo['user_id'],
            'action' => 'location_deleted',
            'resource_type' => 'tenant_location',
            'resource_id' => (string)$locationId,
            'old_values' => json_encode($location),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        $db->commit();

        apiSuccess([
            'location_id' => $locationId,
            'tenant_id' => $tenantId,
            'deleted_at' => date('Y-m-d H:i:s')
        ], 'Location deleted successfully');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logApiError('tenants/locations/delete', $e);
    apiError('Error deleting location', 500);
}
