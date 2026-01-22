<?php
/**
 * API Endpoint: Update Tenant Role (Ruolo Aziendale)
 * Updates an existing custom business role for a tenant
 *
 * @method POST
 * @body {
 *   role_id: int (required - ID of role to update),
 *   tenant_id: int (optional - for super_admin cross-tenant access),
 *   name: string (optional - display name),
 *   description: string (optional),
 *   color: string (optional - hex color),
 *   icon: string (optional),
 *   sort_order: int (optional),
 *   is_active: bool (optional)
 * }
 *
 * @note code cannot be changed after creation
 *
 * @response {
 *   success: true,
 *   data: {
 *     role: {id, name, code, description, color, icon, sort_order, is_active, user_count},
 *     tenant_id: int
 *   },
 *   message: string
 * }
 *
 * @version 1.0.0
 * @since 2025-12-15
 * @author CollaboraNexio Team
 */

declare(strict_types=1);

// Include centralized API authentication
require_once __DIR__ . '/../../includes/api_auth.php';

// Initialize API environment (session, headers, error handling)
initializeApiEnvironment();

// Disable caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Metodo non consentito', 405);
    }

    // Include required files
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/db.php';

    // Verify authentication
    verifyApiAuthentication();

    // Get current user info from session
    $userInfo = getApiUserInfo();
    $currentUserId = (int)($userInfo['user_id'] ?? 0);
    $currentUserRole = $userInfo['role'] ?? 'user';
    $currentTenantId = (int)($userInfo['tenant_id'] ?? 0);

    // Enhanced super_admin detection (BUG-146 pattern)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $isSuperAdmin = (
        ($currentUserRole === 'super_admin') ||
        (($_SESSION['role'] ?? '') === 'super_admin') ||
        (($_SESSION['user_role'] ?? '') === 'super_admin')
    );

    // Fast role gate (detailed per-tenant permission check happens after loading tenant)
    if (!in_array($currentUserRole, ['admin', 'manager', 'super_admin'], true) && !$isSuperAdmin) {
        api_error('Permessi insufficienti per modificare ruoli aziendali', 403);
    }

    // Verify CSRF token
    verifyApiCsrfToken();

    // Get database instance
    $db = Database::getInstance();

    // Parse input (support both JSON and form-data)
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    // Validate role_id
    $roleId = isset($input['role_id']) ? (int)$input['role_id'] : 0;
    // Also accept 'id' for convenience
    if ($roleId <= 0 && isset($input['id'])) {
        $roleId = (int)$input['id'];
    }

    if ($roleId <= 0) {
        api_error('role_id richiesto', 400);
    }

    // Get existing role
    $existingRole = $db->fetchOne(
        "SELECT tr.*, t.name as tenant_name
         FROM tenant_roles tr
         JOIN tenants t ON t.id = tr.tenant_id
         WHERE tr.id = ?
           AND tr.deleted_at IS NULL",
        [$roleId]
    );

    if (!$existingRole) {
        api_error('Ruolo non trovato', 404);
    }

    $targetTenantId = (int)$existingRole['tenant_id'];

    // Tenant-role management permission (migration 59):
    // - super_admin always allowed
    // - admin/manager allowed only if enabled per-tenant via tenants.tenant_role_management_roles
    if (!$isSuperAdmin) {
        $hasManagePermCol = false;
        try {
            $col = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'tenants'
                   AND COLUMN_NAME = 'tenant_role_management_roles'
                 LIMIT 1"
            );
            $hasManagePermCol = ((int)($col['ok'] ?? 0) === 1);
        } catch (Exception $e) {
            $hasManagePermCol = false;
        }

        $allowed = [];
        if ($hasManagePermCol) {
            $t = $db->fetchOne(
                "SELECT tenant_role_management_roles
                 FROM tenants
                 WHERE id = ?
                   AND deleted_at IS NULL
                 LIMIT 1",
                [$targetTenantId]
            );
            $raw = $t['tenant_role_management_roles'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $allowed = array_map('strval', $decoded);
            }
        }

        if (!in_array($currentUserRole, $allowed, true)) {
            api_error('Non hai i permessi per modificare ruoli aziendali (abilita i permessi in “Gestione Ruoli Aziendali”)', 403);
        }
    }

    // Authorization check: only super_admin can modify roles for other tenants
    if (!$isSuperAdmin && $targetTenantId !== $currentTenantId) {
        // Check user_tenant_access for multi-tenant membership
        $hasAccess = $db->fetchOne(
            "SELECT 1
             FROM user_tenant_access
             WHERE user_id = ?
               AND tenant_id = ?
               AND deleted_at IS NULL",
            [$currentUserId, $targetTenantId]
        );

        if (!$hasAccess) {
            api_error('Non puoi modificare ruoli di altre aziende', 403);
        }

        // Even with access, must be admin role
        if ($currentUserRole !== 'admin') {
            api_error('Solo admin possono modificare ruoli aziendali', 403);
        }
    }

    // Build update data
    $updateData = [];
    $oldValues = [];
    $newValues = [];

    // Name (optional)
    if (isset($input['name'])) {
        $name = trim((string)$input['name']);

        if (empty($name)) {
            api_error('Nome del ruolo non puo essere vuoto', 400);
        }

        if (mb_strlen($name) > 100) {
            api_error('Nome del ruolo troppo lungo (max 100 caratteri)', 400);
        }

        // Check for duplicate name (exclude current role)
        $duplicateName = $db->fetchOne(
            "SELECT id FROM tenant_roles
             WHERE tenant_id = ?
               AND name = ?
               AND id != ?
               AND deleted_at IS NULL",
            [$targetTenantId, $name, $roleId]
        );

        if ($duplicateName) {
            api_error('Esiste gia un ruolo con questo nome in questa azienda', 409);
        }

        if ($name !== $existingRole['name']) {
            $updateData['name'] = $name;
            $oldValues['name'] = $existingRole['name'];
            $newValues['name'] = $name;
        }
    }

    // Description (optional)
    if (array_key_exists('description', $input)) {
        $description = $input['description'] !== null ? trim((string)$input['description']) : null;

        if ($description !== $existingRole['description']) {
            $updateData['description'] = $description;
            $oldValues['description'] = $existingRole['description'];
            $newValues['description'] = $description;
        }
    }

    // Color (optional)
    if (isset($input['color'])) {
        $color = trim((string)$input['color']);

        // Validate hex color format
        if (!empty($color) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            api_error('Formato colore non valido (usa formato #RRGGBB)', 400);
        }

        if ($color !== $existingRole['color']) {
            $updateData['color'] = $color;
            $oldValues['color'] = $existingRole['color'];
            $newValues['color'] = $color;
        }
    }

    // Icon (optional)
    if (array_key_exists('icon', $input)) {
        $icon = $input['icon'] !== null ? trim((string)$input['icon']) : null;

        if (!empty($icon) && mb_strlen($icon) > 50) {
            api_error('Icona troppo lunga (max 50 caratteri)', 400);
        }

        if ($icon !== $existingRole['icon']) {
            $updateData['icon'] = $icon;
            $oldValues['icon'] = $existingRole['icon'];
            $newValues['icon'] = $icon;
        }
    }

    // Sort order (optional)
    if (isset($input['sort_order'])) {
        $sortOrder = max(0, (int)$input['sort_order']);

        if ($sortOrder !== (int)$existingRole['sort_order']) {
            $updateData['sort_order'] = $sortOrder;
            $oldValues['sort_order'] = (int)$existingRole['sort_order'];
            $newValues['sort_order'] = $sortOrder;
        }
    }

    // Is active (optional)
    if (isset($input['is_active'])) {
        $isActive = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN);
        $wasActive = (bool)$existingRole['is_active'];

        if ($isActive !== $wasActive) {
            $updateData['is_active'] = $isActive ? 1 : 0;
            $oldValues['is_active'] = $wasActive;
            $newValues['is_active'] = $isActive;
        }
    }

    // Check if there are any changes
    if (empty($updateData)) {
        // No changes - return current role state
        $userCount = $db->fetchOne(
            "SELECT COUNT(DISTINCT user_id) as cnt
             FROM user_tenant_access
             WHERE tenant_role_id = ?
               AND deleted_at IS NULL",
            [$roleId]
        );

        api_success([
            'role' => [
                'id' => (int)$existingRole['id'],
                'name' => $existingRole['name'],
                'code' => $existingRole['code'],
                'description' => $existingRole['description'],
                'color' => $existingRole['color'] ?? '#6366f1',
                'icon' => $existingRole['icon'],
                'sort_order' => (int)$existingRole['sort_order'],
                'is_active' => (bool)$existingRole['is_active'],
                'user_count' => (int)($userCount['cnt'] ?? 0),
                'updated_at' => $existingRole['updated_at']
            ],
            'tenant_id' => $targetTenantId,
            'changes' => false
        ], 'Nessuna modifica rilevata');
    }

    // Add updated_at
    $now = date('Y-m-d H:i:s');
    $updateData['updated_at'] = $now;

    // Begin transaction
    $db->beginTransaction();

    try {
        // Update role
        $result = $db->update('tenant_roles', $updateData, ['id' => $roleId]);

        // If role was deactivated, check if we need to update tenant has_custom_roles
        if (isset($updateData['is_active']) && !$updateData['is_active']) {
            // Count remaining active roles
            $activeCount = $db->fetchOne(
                "SELECT COUNT(*) as cnt
                 FROM tenant_roles
                 WHERE tenant_id = ?
                   AND is_active = 1
                   AND deleted_at IS NULL",
                [$targetTenantId]
            );

            if ((int)($activeCount['cnt'] ?? 0) === 0) {
                // No more active roles - update tenant flag
                $db->update('tenants', [
                    'has_custom_roles' => 0,
                    'updated_at' => $now
                ], [
                    'id' => $targetTenantId
                ]);
            }
        }

        // If role was activated, ensure tenant has_custom_roles is set
        if (isset($updateData['is_active']) && $updateData['is_active']) {
            $db->update('tenants', [
                'has_custom_roles' => 1,
                'updated_at' => $now
            ], [
                'id' => $targetTenantId
            ]);
        }

        $db->commit();

        // Get updated role with user count
        $updatedRole = $db->fetchOne(
            "SELECT tr.*
             FROM tenant_roles tr
             WHERE tr.id = ?",
            [$roleId]
        );

        $userCount = $db->fetchOne(
            "SELECT COUNT(DISTINCT user_id) as cnt
             FROM user_tenant_access
             WHERE tenant_role_id = ?
               AND deleted_at IS NULL",
            [$roleId]
        );

        // Audit log
        try {
            require_once __DIR__ . '/../../includes/audit_helper.php';
            AuditLogger::logUpdate(
                $currentUserId,
                $targetTenantId,
                'tenant_role',
                $roleId,
                "Updated tenant role: {$updatedRole['name']} ({$updatedRole['code']})",
                $oldValues,
                $newValues
            );
        } catch (Exception $e) {
            error_log('[AUDIT] Tenant role update audit failed: ' . $e->getMessage());
        }

        // Success response
        api_success([
            'role' => [
                'id' => (int)$updatedRole['id'],
                'name' => $updatedRole['name'],
                'code' => $updatedRole['code'],
                'description' => $updatedRole['description'],
                'color' => $updatedRole['color'] ?? '#6366f1',
                'icon' => $updatedRole['icon'],
                'sort_order' => (int)$updatedRole['sort_order'],
                'is_active' => (bool)$updatedRole['is_active'],
                'user_count' => (int)($userCount['cnt'] ?? 0),
                'updated_at' => $updatedRole['updated_at']
            ],
            'tenant_id' => $targetTenantId,
            'changes' => true
        ], 'Ruolo aziendale aggiornato con successo');

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        throw $e;
    }

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log('[Tenant Roles Update] PDO Error: ' . $e->getMessage());

    // Return user-friendly error
    api_error('Errore database', 500);

} catch (Throwable $e) {
    // Log the error
    error_log('[Tenant Roles Update] Error: ' . $e->getMessage());

    // Return error message
    api_error($e->getMessage() ?: 'Errore interno del server', 500);
}
