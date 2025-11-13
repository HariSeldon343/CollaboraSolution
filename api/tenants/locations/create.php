<?php
/**
 * API: Create Tenant Location
 *
 * Add a new location (sede operativa) to an existing tenant
 *
 * Method: POST
 * Auth: Admin or Super Admin
 * CSRF: Required
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

// Verify CSRF token
verifyApiCsrfToken();

// Require admin role
requireApiRole('admin');

// Load database
require_once '../../../includes/db.php';
$db = Database::getInstance();

try {
    // Get tenant_id from URL
    $urlParts = explode('/', trim($_SERVER['REQUEST_URI'], '/'));
    $tenantsIndex = array_search('tenants', $urlParts);

    if ($tenantsIndex === false || !isset($urlParts[$tenantsIndex + 1])) {
        apiError('Invalid URL format. Expected: /api/tenants/{tenant_id}/locations', 400);
    }

    $tenantId = (int)$urlParts[$tenantsIndex + 1];

    if ($tenantId <= 0) {
        apiError('Invalid tenant ID', 400);
    }

    // Verify tenant exists
    $tenant = $db->fetchOne(
        'SELECT id, denominazione
         FROM tenants
         WHERE id = ?
         AND deleted_at IS NULL',
        [$tenantId]
    );

    if (!$tenant) {
        apiError('Tenant not found', 404);
    }

    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        apiError('Invalid JSON data', 400);
    }

    // Validation
    $errors = [];

    // 1. Location type (default to sede_operativa)
    $locationType = $input['location_type'] ?? 'sede_operativa';
    if (!in_array($locationType, ['sede_legale', 'sede_operativa'])) {
        $errors[] = 'Location type must be: sede_legale or sede_operativa';
    }

    // 2. Required address fields
    if (empty($input['indirizzo'])) {
        $errors[] = 'Indirizzo is required';
    }
    if (empty($input['civico'])) {
        $errors[] = 'Civico is required';
    }
    if (empty($input['cap'])) {
        $errors[] = 'CAP is required';
    } elseif (!preg_match('/^\d{5}$/', $input['cap'])) {
        $errors[] = 'CAP must be exactly 5 digits';
    }
    if (empty($input['comune'])) {
        $errors[] = 'Comune is required';
    }
    if (empty($input['provincia'])) {
        $errors[] = 'Provincia is required';
    } elseif (strlen($input['provincia']) !== 2) {
        $errors[] = 'Provincia must be exactly 2 characters (e.g., MI, RM)';
    }

    // 3. Check if sede_legale already exists (if trying to add sede_legale)
    if ($locationType === 'sede_legale') {
        $existingSedeLegale = $db->fetchOne(
            'SELECT id
             FROM tenant_locations
             WHERE tenant_id = ?
             AND location_type = "sede_legale"
             AND is_primary = TRUE
             AND deleted_at IS NULL',
            [$tenantId]
        );

        if ($existingSedeLegale) {
            $errors[] = 'Tenant already has a primary sede legale. Update existing or set is_primary=false.';
        }
    }

    // 4. Validate optional fields
    if (!empty($input['email']) && !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }

    if (!empty($input['manager_user_id'])) {
        $managerId = (int)$input['manager_user_id'];
        $managerExists = $db->fetchOne(
            'SELECT id, role
             FROM users
             WHERE id = ?
             AND deleted_at IS NULL',
            [$managerId]
        );

        if (!$managerExists) {
            $errors[] = 'Manager user not found';
        } elseif (!in_array($managerExists['role'], ['manager', 'admin', 'super_admin'])) {
            $errors[] = 'User must have manager role or higher';
        }
    }

    // Return validation errors
    if (!empty($errors)) {
        apiError('Validation failed: ' . implode('; ', $errors), 400, ['errors' => $errors]);
    }

    // Prepare location data
    $locationData = [
        'tenant_id' => $tenantId,
        'location_type' => $locationType,
        'indirizzo' => trim($input['indirizzo']),
        'civico' => trim($input['civico']),
        'cap' => trim($input['cap']),
        'comune' => trim($input['comune']),
        'provincia' => strtoupper(trim($input['provincia'])),
        'telefono' => !empty($input['telefono']) ? trim($input['telefono']) : null,
        'email' => !empty($input['email']) ? trim($input['email']) : null,
        'manager_nome' => !empty($input['manager_nome']) ? trim($input['manager_nome']) : null,
        'manager_user_id' => !empty($input['manager_user_id']) ? (int)$input['manager_user_id'] : null,
        'is_primary' => !empty($input['is_primary']) ? (bool)$input['is_primary'] : false,
        'is_active' => !empty($input['is_active']) ? (bool)$input['is_active'] : true,
        'note' => !empty($input['note']) ? trim($input['note']) : null
    ];

    // Insert in transaction
    $db->beginTransaction();

    try {
        // Insert location
        $locationId = $db->insert('tenant_locations', $locationData);

        // Log audit
        $db->insert('audit_logs', [
            'tenant_id' => $tenantId,
            'user_id' => $userInfo['user_id'],
            'action' => 'location_created',
            'resource_type' => 'tenant_location',
            'resource_id' => (string)$locationId,
            'new_values' => json_encode($locationData),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        $db->commit();

        // Fetch created location
        $location = $db->fetchOne(
            'SELECT * FROM tenant_locations WHERE id = ?',
            [$locationId]
        );

        apiSuccess([
            'location_id' => $locationId,
            'tenant_id' => $tenantId,
            'location' => $location
        ], 'Location created successfully');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logApiError('tenants/locations/create', $e);
    apiError('Error creating location', 500);
}
