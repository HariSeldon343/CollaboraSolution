<?php
/**
 * API: Update Tenant Location
 *
 * Update an existing location
 *
 * Method: PUT
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

    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        apiError('Invalid JSON data', 400);
    }

    // Build update data (only fields provided)
    $updateData = [];
    $errors = [];

    // Address fields
    if (isset($input['indirizzo'])) {
        if (empty($input['indirizzo'])) {
            $errors[] = 'Indirizzo cannot be empty';
        } else {
            $updateData['indirizzo'] = trim($input['indirizzo']);
        }
    }

    if (isset($input['civico'])) {
        if (empty($input['civico'])) {
            $errors[] = 'Civico cannot be empty';
        } else {
            $updateData['civico'] = trim($input['civico']);
        }
    }

    if (isset($input['cap'])) {
        if (!preg_match('/^\d{5}$/', $input['cap'])) {
            $errors[] = 'CAP must be exactly 5 digits';
        } else {
            $updateData['cap'] = trim($input['cap']);
        }
    }

    if (isset($input['comune'])) {
        if (empty($input['comune'])) {
            $errors[] = 'Comune cannot be empty';
        } else {
            $updateData['comune'] = trim($input['comune']);
        }
    }

    if (isset($input['provincia'])) {
        if (strlen($input['provincia']) !== 2) {
            $errors[] = 'Provincia must be exactly 2 characters';
        } else {
            $updateData['provincia'] = strtoupper(trim($input['provincia']));
        }
    }

    // Contact fields
    if (isset($input['telefono'])) {
        $updateData['telefono'] = !empty($input['telefono']) ? trim($input['telefono']) : null;
    }

    if (isset($input['email'])) {
        if (!empty($input['email']) && !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        } else {
            $updateData['email'] = !empty($input['email']) ? trim($input['email']) : null;
        }
    }

    // Manager fields
    if (isset($input['manager_nome'])) {
        $updateData['manager_nome'] = !empty($input['manager_nome']) ? trim($input['manager_nome']) : null;
    }

    if (isset($input['manager_user_id'])) {
        if (!empty($input['manager_user_id'])) {
            $managerId = (int)$input['manager_user_id'];
            $managerExists = $db->fetchOne(
                'SELECT id, role FROM users WHERE id = ? AND deleted_at IS NULL',
                [$managerId]
            );

            if (!$managerExists) {
                $errors[] = 'Manager user not found';
            } elseif (!in_array($managerExists['role'], ['manager', 'admin', 'super_admin'])) {
                $errors[] = 'User must have manager role or higher';
            } else {
                $updateData['manager_user_id'] = $managerId;
            }
        } else {
            $updateData['manager_user_id'] = null;
        }
    }

    // Flags
    if (isset($input['is_primary'])) {
        // Only allow changing is_primary for sede_legale
        if ($location['location_type'] !== 'sede_legale') {
            $errors[] = 'Cannot set is_primary for sede_operativa';
        } else {
            $updateData['is_primary'] = (bool)$input['is_primary'];
        }
    }

    if (isset($input['is_active'])) {
        $updateData['is_active'] = (bool)$input['is_active'];
    }

    // Note
    if (isset($input['note'])) {
        $updateData['note'] = !empty($input['note']) ? trim($input['note']) : null;
    }

    // Return validation errors
    if (!empty($errors)) {
        apiError('Validation failed: ' . implode('; ', $errors), 400, ['errors' => $errors]);
    }

    // Check if there's anything to update
    if (empty($updateData)) {
        apiError('No fields to update', 400);
    }

    // Update in transaction
    $db->beginTransaction();

    try {
        // Store old values for audit
        $oldValues = $location;

        // Update location
        $db->update('tenant_locations', $updateData, ['id' => $locationId]);

        // Log audit
        $db->insert('audit_logs', [
            'tenant_id' => $tenantId,
            'user_id' => $userInfo['user_id'],
            'action' => 'location_updated',
            'resource_type' => 'tenant_location',
            'resource_id' => (string)$locationId,
            'old_values' => json_encode($oldValues),
            'new_values' => json_encode($updateData),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        $db->commit();

        // Fetch updated location
        $updatedLocation = $db->fetchOne(
            'SELECT * FROM tenant_locations WHERE id = ?',
            [$locationId]
        );

        apiSuccess([
            'location_id' => $locationId,
            'tenant_id' => $tenantId,
            'location' => $updatedLocation
        ], 'Location updated successfully');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logApiError('tenants/locations/update', $e);
    apiError('Error updating location', 500);
}
