<?php
/**
 * API: List Tenant Locations
 *
 * Get all locations (sede legale + sedi operative) for a specific tenant
 *
 * Method: GET
 * Auth: Required
 * URL: /api/tenants/{tenant_id}/locations
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

// Load database
require_once '../../../includes/db.php';
$db = Database::getInstance();

try {
    // Get tenant_id from URL
    // Expected URL: /api/tenants/5/locations
    $urlParts = explode('/', trim($_SERVER['REQUEST_URI'], '/'));
    $tenantsIndex = array_search('tenants', $urlParts);

    if ($tenantsIndex === false || !isset($urlParts[$tenantsIndex + 1])) {
        apiError('Invalid URL format. Expected: /api/tenants/{tenant_id}/locations', 400);
    }

    $tenantId = (int)$urlParts[$tenantsIndex + 1];

    if ($tenantId <= 0) {
        apiError('Invalid tenant ID', 400);
    }

    // Verify tenant exists and user has access
    $tenant = $db->fetchOne(
        'SELECT id, denominazione, status
         FROM tenants
         WHERE id = ?
         AND deleted_at IS NULL',
        [$tenantId]
    );

    if (!$tenant) {
        apiError('Tenant not found', 404);
    }

    // Check multi-tenant access
    // Super admin can access any tenant
    // Admin can access their tenant(s)
    // Regular users can only access their own tenant
    if ($userInfo['role'] !== 'super_admin') {
        // Check if user belongs to this tenant
        $hasAccess = $db->fetchOne(
            'SELECT COUNT(*) as count
             FROM users
             WHERE id = ?
             AND tenant_id = ?
             AND deleted_at IS NULL',
            [$userInfo['user_id'], $tenantId]
        );

        if (!$hasAccess || $hasAccess['count'] == 0) {
            apiError('Access denied to this tenant', 403);
        }
    }

    // Fetch all locations for this tenant
    $locations = $db->fetchAll(
        'SELECT
            id,
            tenant_id,
            location_type,
            indirizzo,
            civico,
            cap,
            comune,
            provincia,
            telefono,
            email,
            manager_nome,
            manager_user_id,
            is_primary,
            is_active,
            note,
            created_at,
            updated_at
         FROM tenant_locations
         WHERE tenant_id = ?
         AND deleted_at IS NULL
         ORDER BY
             CASE location_type WHEN "sede_legale" THEN 0 ELSE 1 END,
             created_at',
        [$tenantId]
    );

    // Separate sede_legale and sedi_operative
    $sedeLegale = null;
    $sediOperative = [];

    foreach ($locations as $location) {
        // Add formatted address
        $location['indirizzo_completo'] = sprintf(
            '%s %s, %s %s (%s)',
            $location['indirizzo'],
            $location['civico'],
            $location['cap'],
            $location['comune'],
            $location['provincia']
        );

        // Cast boolean fields
        $location['is_primary'] = (bool)$location['is_primary'];
        $location['is_active'] = (bool)$location['is_active'];
        $location['manager_user_id'] = $location['manager_user_id'] ? (int)$location['manager_user_id'] : null;

        if ($location['location_type'] === 'sede_legale') {
            $sedeLegale = $location;
        } else {
            $sediOperative[] = $location;
        }
    }

    // Build response
    $response = [
        'tenant_id' => $tenantId,
        'tenant_name' => $tenant['denominazione'],
        'sede_legale' => $sedeLegale,
        'sedi_operative' => $sediOperative,
        'total_locations' => count($locations),
        'sede_legale_count' => $sedeLegale ? 1 : 0,
        'sedi_operative_count' => count($sediOperative)
    ];

    apiSuccess($response);

} catch (Exception $e) {
    logApiError('tenants/locations/list', $e);
    apiError('Error retrieving tenant locations', 500);
}
