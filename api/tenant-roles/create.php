<?php
/**
 * API Endpoint: Create Tenant Role (Ruolo Aziendale)
 * Creates a new custom business role for a tenant
 *
 * @method POST
 * @body {
 *   tenant_id: int (optional - uses session tenant if not provided),
 *   name: string (required - display name),
 *   code: string (optional - auto-generated from name if not provided),
 *   description: string (optional),
 *   color: string (optional - hex color, default '#6366f1'),
 *   icon: string (optional),
 *   sort_order: int (optional - default 0),
 *   is_active: bool (optional - default true)
 * }
 *
 * @response {
 *   success: true,
 *   data: {
 *     role: {id, name, code, description, color, icon, sort_order, is_active},
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

    // Tenant-role management permission:
    // - super_admin always allowed
    // - admin/manager allowed only if enabled per-tenant via tenants.tenant_role_management_roles
    $canManageTenantRoles = (bool)$isSuperAdmin;

    // Verify CSRF token
    verifyApiCsrfToken();

    // Get database instance
    $db = Database::getInstance();

    // Parse input (support both JSON and form-data)
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    // Determine target tenant_id
    $targetTenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : 0;

    // If no tenant_id specified, use session company filter (for super_admin) or current tenant
    if ($targetTenantId <= 0) {
        if ($isSuperAdmin && isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null) {
            $targetTenantId = (int)$_SESSION['company_filter_id'];
        } else {
            $targetTenantId = $currentTenantId;
        }
    }

    // Validate tenant_id
    if ($targetTenantId <= 0) {
        api_error('tenant_id richiesto', 400);
    }

    // Verify tenant exists
    // Schema drift: optional management permissions column (migration 59)
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

    $tenant = $db->fetchOne(
        "SELECT id, name, has_custom_roles" . ($hasManagePermCol ? ", tenant_role_management_roles" : "") . "
         FROM tenants
         WHERE id = ?
           AND deleted_at IS NULL",
        [$targetTenantId]
    );

    if (!$tenant) {
        api_error('Azienda non trovata', 404);
    }

    // Authorization check: allow admin (multi-tenant) to manage roles only within tenants they can access.
    // Manager is limited to their own tenant.
    if (!$isSuperAdmin && $targetTenantId !== $currentTenantId) {
        $hasAccess = $db->fetchOne(
            "SELECT 1
             FROM user_tenant_access
             WHERE user_id = ?
               AND tenant_id = ?
               AND deleted_at IS NULL
             LIMIT 1",
            [$currentUserId, $targetTenantId]
        );
        if (!$hasAccess) {
            api_error('Non puoi creare ruoli per altre aziende', 403);
        }
        if ($currentUserRole !== 'admin') {
            api_error('Solo admin possono creare ruoli per altre aziende', 403);
        }
    }

    // Evaluate per-tenant permission (default: only super_admin)
    if (!$canManageTenantRoles) {
        if (!in_array($currentUserRole, ['admin', 'manager'], true)) {
            api_error('Permessi insufficienti per creare ruoli aziendali', 403);
        }
        $allowedRoles = [];
        if ($hasManagePermCol) {
            $raw = $tenant['tenant_role_management_roles'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $allowedRoles = array_map('strval', $decoded);
            }
        }
        $canManageTenantRoles = in_array($currentUserRole, $allowedRoles, true);
        if (!$canManageTenantRoles) {
            api_error('Non hai i permessi per creare ruoli aziendali (abilita i permessi in “Gestione Ruoli Aziendali”)', 403);
        }
    }

    // Validate required fields
    $name = isset($input['name']) ? trim((string)$input['name']) : '';

    if (empty($name)) {
        api_error('Nome del ruolo richiesto', 400);
    }

    if (mb_strlen($name) > 100) {
        api_error('Nome del ruolo troppo lungo (max 100 caratteri)', 400);
    }

    // Auto-generate code from name if not provided
    $code = isset($input['code']) ? trim((string)$input['code']) : '';
    if (empty($code)) {
        // Generate code: lowercase, no accents, underscores for spaces
        $code = generateRoleCode($name);
    } else {
        // Validate provided code
        $code = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $code));
    }

    if (empty($code)) {
        api_error('Codice del ruolo non valido', 400);
    }

    if (mb_strlen($code) > 50) {
        api_error('Codice del ruolo troppo lungo (max 50 caratteri)', 400);
    }

    // Optional fields
    $description = isset($input['description']) ? trim((string)$input['description']) : null;
    $color = isset($input['color']) ? trim((string)$input['color']) : '#6366f1';
    $icon = isset($input['icon']) ? trim((string)$input['icon']) : null;
    $sortOrder = isset($input['sort_order']) ? max(0, (int)$input['sort_order']) : 0;
    $isActive = isset($input['is_active']) ? filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN) : true;

    // Validate color format (hex)
    if (!empty($color) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        $color = '#6366f1'; // Default to indigo if invalid
    }

    // Validate icon length
    if (!empty($icon) && mb_strlen($icon) > 50) {
        api_error('Icona troppo lunga (max 50 caratteri)', 400);
    }

    // Check for duplicate name or code in same tenant (excluding soft-deleted)
    $existingName = $db->fetchOne(
        "SELECT id FROM tenant_roles
         WHERE tenant_id = ?
           AND name = ?
           AND deleted_at IS NULL",
        [$targetTenantId, $name]
    );

    if ($existingName) {
        api_error('Esiste gia un ruolo con questo nome in questa azienda', 409);
    }

    $existingCode = $db->fetchOne(
        "SELECT id FROM tenant_roles
         WHERE tenant_id = ?
           AND code = ?
           AND deleted_at IS NULL",
        [$targetTenantId, $code]
    );

    if ($existingCode) {
        api_error('Esiste gia un ruolo con questo codice in questa azienda', 409);
    }

    // Begin transaction
    $db->beginTransaction();

    try {
        // Insert new role
        $now = date('Y-m-d H:i:s');
        $roleId = $db->insert('tenant_roles', [
            'tenant_id' => $targetTenantId,
            'name' => $name,
            'code' => $code,
            'description' => $description,
            'color' => $color,
            'icon' => $icon,
            'sort_order' => $sortOrder,
            'is_active' => $isActive ? 1 : 0,
            'created_by' => $currentUserId,
            'created_at' => $now,
            'updated_at' => $now
        ]);

        if (!$roleId) {
            throw new Exception('Errore nella creazione del ruolo');
        }

        // Update tenant has_custom_roles flag if this is first active role
        if (!$tenant['has_custom_roles'] && $isActive) {
            $db->update('tenants', [
                'has_custom_roles' => 1,
                'updated_at' => $now
            ], [
                'id' => $targetTenantId
            ]);
        }

        $db->commit();

        // Audit log
        try {
            require_once __DIR__ . '/../../includes/audit_helper.php';
            AuditLogger::logCreate(
                $currentUserId,
                $targetTenantId,
                'tenant_role',
                $roleId,
                "Created tenant role: $name ($code)",
                [
                    'name' => $name,
                    'code' => $code,
                    'tenant_id' => $targetTenantId,
                    'is_active' => $isActive
                ]
            );
        } catch (Exception $e) {
            error_log('[AUDIT] Tenant role creation audit failed: ' . $e->getMessage());
            // Don't throw - audit failure should not break functionality
        }

        // Success response
        api_success([
            'role' => [
                'id' => (int)$roleId,
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'color' => $color,
                'icon' => $icon,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
                'created_at' => $now,
                'user_count' => 0
            ],
            'tenant_id' => $targetTenantId,
            'tenant_has_custom_roles' => true
        ], 'Ruolo aziendale creato con successo');

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        throw $e;
    }

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log('[Tenant Roles Create] PDO Error: ' . $e->getMessage());

    // Return user-friendly error
    api_error('Errore database', 500);

} catch (Throwable $e) {
    // Log the error
    error_log('[Tenant Roles Create] Error: ' . $e->getMessage());

    // Return error message
    api_error($e->getMessage() ?: 'Errore interno del server', 500);
}

/**
 * Generate a role code from the name
 * Converts to lowercase, removes accents, replaces spaces with underscores
 *
 * @param string $name Role name
 * @return string Generated code
 */
function generateRoleCode(string $name): string
{
    // Convert to lowercase
    $code = mb_strtolower($name, 'UTF-8');

    // Remove accents (Italian specific)
    $accents = [
        'a' => ['à', 'á', 'â', 'ã', 'ä', 'å'],
        'e' => ['è', 'é', 'ê', 'ë'],
        'i' => ['ì', 'í', 'î', 'ï'],
        'o' => ['ò', 'ó', 'ô', 'õ', 'ö'],
        'u' => ['ù', 'ú', 'û', 'ü']
    ];

    foreach ($accents as $replacement => $chars) {
        $code = str_replace($chars, $replacement, $code);
    }

    // Replace spaces and special chars with underscore
    $code = preg_replace('/[^a-z0-9]+/', '_', $code);

    // Remove leading/trailing underscores
    $code = trim($code, '_');

    // Limit length
    if (mb_strlen($code) > 50) {
        $code = mb_substr($code, 0, 50);
    }

    return $code;
}
