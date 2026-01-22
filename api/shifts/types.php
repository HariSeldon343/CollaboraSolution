<?php
/**
 * API Endpoint: Shift Types (Tipi Turno) CRUD
 * Gestisce i tipi di turno per tenant (es: "Mattina 6-14", "Pomeriggio 14-22")
 *
 * @method GET - Lista tipi turno attivi per tenant
 * @method POST action=create - Crea nuovo tipo turno (admin/manager/super_admin)
 * @method POST action=update - Modifica tipo turno
 * @method POST action=delete - Soft delete tipo turno
 *
 * @version 1.0.0
 * @since 2025-12-19
 * @author CollaboraNexio Team (php-multitenant-architect)
 */

declare(strict_types=1);

// Include centralized API authentication
require_once __DIR__ . '/../../includes/api_auth.php';

// Initialize API environment (session, headers, error handling)
initializeApiEnvironment();

// Disable caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    // Include required files
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/shift_permissions_helper.php';

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

    // Get database instance
    $db = Database::getInstance();

    // Preflight: ensure required tables exist (avoid 500 when migrations are missing)
    $tableShiftTypes = $db->fetchOne(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'shift_types'
         LIMIT 1"
    );
    if (!$tableShiftTypes) {
        api_error('Setup turni non configurato: applicare Migration 22 (tabelle turni mancanti).', 503, [
            'missing_tables' => ['shift_types'],
            'hint' => '/CollaboraNexio/tools/apply_migration_22_work_shifts_feature.php',
        ]);
    }

    // Determine request method and action
    $method = $_SERVER['REQUEST_METHOD'];
    $rawBody = cnx_get_raw_request_body();
    $data = json_decode($rawBody, true) ?: [];
    $action = $data['action'] ?? $_GET['action'] ?? 'list';

    // For write operations, verify CSRF
    if ($method === 'POST' || in_array($action, ['create', 'update', 'delete'])) {
        verifyApiCsrfToken();
    }

    // Determine target tenant_id
    $targetTenantId = (int)($data['tenant_id'] ?? $_GET['tenant_id'] ?? 0);
    if ($targetTenantId <= 0) {
        if ($isSuperAdmin && isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null) {
            $targetTenantId = (int)$_SESSION['company_filter_id'];
        } else {
            $targetTenantId = $currentTenantId;
        }
    }

    if ($targetTenantId <= 0) {
        api_error('tenant_id richiesto', 400);
    }

    // Authorization check: only super_admin can query other tenants
    if (!$isSuperAdmin && $targetTenantId !== $currentTenantId) {
        $utaHasDeletedAt = $db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_tenant_access'
               AND COLUMN_NAME = 'deleted_at'
             LIMIT 1"
        );
        $utaWhere = $utaHasDeletedAt ? " AND deleted_at IS NULL" : "";
        $hasAccess = $db->fetchOne(
            "SELECT 1 FROM user_tenant_access
             WHERE user_id = ? AND tenant_id = ?" . $utaWhere . "
             LIMIT 1",
            [$currentUserId, $targetTenantId]
        );
        if (!$hasAccess) {
            api_error('Accesso negato al tenant richiesto', 403);
        }
    }

    // Verify tenant exists and has shift management enabled (BUG-156 pattern)
    $tenantQuery = "SELECT id, name FROM tenants WHERE id = ? AND deleted_at IS NULL";

    // Feature-detect has_shift_management column
    $hasShiftManagementCol = $db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'tenants'
           AND COLUMN_NAME = 'has_shift_management'"
    );

    $tenant = $db->fetchOne($tenantQuery, [$targetTenantId]);
    if (!$tenant) {
        api_error('Azienda non trovata', 404);
    }

    // Permission enforcement (per-tenant): only allowed roles can manage shift types
    $shiftPerms = cnx_get_shift_permissions_for_tenant($db, $targetTenantId);
    $canManageShifts = $isSuperAdmin || in_array($currentUserRole, $shiftPerms['can_manage_shifts_roles'], true);
    if ($action !== 'list' && !$canManageShifts) {
        api_error('Permessi insufficienti per gestire i turni', 403);
    }

    // Route to appropriate handler
    switch ($action) {
        case 'list':
            handleList($db, $targetTenantId, $currentUserRole, $isSuperAdmin);
            break;

        case 'create':
            handleCreate($db, $targetTenantId, $currentUserId, $currentUserRole, $isSuperAdmin, $data);
            break;

        case 'update':
            handleUpdate($db, $targetTenantId, $currentUserId, $currentUserRole, $isSuperAdmin, $data);
            break;

        case 'delete':
            handleDelete($db, $targetTenantId, $currentUserId, $currentUserRole, $isSuperAdmin, $data);
            break;

        default:
            api_error("Azione non valida: {$action}. Azioni disponibili: list, create, update, delete", 400);
    }

} catch (PDOException $e) {
    error_log('[Shift Types API] PDO Error: ' . $e->getMessage());
    api_error('Errore database', 500);

} catch (Throwable $e) {
    error_log('[Shift Types API] Error: ' . $e->getMessage());
    api_error('Errore interno del server', 500);
}

// ============================================
// HANDLER FUNCTIONS
// ============================================

/**
 * GET: Lista tipi turno per tenant
 */
function handleList(Database $db, int $tenantId, string $role, bool $isSuperAdmin): void
{
    $includeInactive = isset($_GET['include_inactive']) &&
                       filter_var($_GET['include_inactive'], FILTER_VALIDATE_BOOLEAN);

    $whereConditions = [
        'st.tenant_id = ?',
        'st.deleted_at IS NULL'
    ];
    $params = [$tenantId];

    if (!$includeInactive) {
        $whereConditions[] = 'st.is_active = 1';
    }

    $whereClause = implode(' AND ', $whereConditions);

    $query = "
        SELECT
            st.id,
            st.name,
            st.code,
            st.description,
            st.start_time,
            st.end_time,
            st.duration_minutes,
            st.color,
            st.icon,
            st.sort_order,
            st.is_active,
            st.created_at,
            st.updated_at,
            (
                SELECT COUNT(DISTINCT ws.id)
                FROM work_shifts ws
                WHERE ws.shift_type_id = st.id
                  AND ws.deleted_at IS NULL
                  AND ws.status != 'cancelled'
            ) AS shift_count
        FROM shift_types st
        WHERE {$whereClause}
        ORDER BY st.sort_order ASC, st.name ASC
    ";

    $types = $db->fetchAll($query, $params);

    // Format response
    $formattedTypes = [];
    foreach ($types as $type) {
        $formattedTypes[] = [
            'id' => (int)$type['id'],
            'name' => $type['name'],
            'code' => $type['code'],
            'description' => $type['description'],
            'start_time' => $type['start_time'],
            'end_time' => $type['end_time'],
            'duration_minutes' => $type['duration_minutes'] !== null ? (int)$type['duration_minutes'] : null,
            'color' => $type['color'] ?? '#3B82F6',
            'icon' => $type['icon'],
            'sort_order' => (int)$type['sort_order'],
            'is_active' => (bool)$type['is_active'],
            'shift_count' => (int)$type['shift_count'],
            'created_at' => $type['created_at'],
            'updated_at' => $type['updated_at']
        ];
    }

    api_success([
        'shift_types' => $formattedTypes,
        'tenant_id' => $tenantId,
        'total' => count($formattedTypes)
    ], 'Tipi turno recuperati con successo');
}

/**
 * POST action=create: Crea nuovo tipo turno
 */
function handleCreate(Database $db, int $tenantId, int $userId, string $role, bool $isSuperAdmin, array $data): void
{
    // Permission check: admin, manager, super_admin
    if (!$isSuperAdmin && !in_array($role, ['admin', 'manager'])) {
        api_error('Permessi insufficienti per creare tipi turno', 403);
    }

    // Validate required fields
    $name = trim($data['name'] ?? '');
    $code = trim($data['code'] ?? '');
    $startTime = trim($data['start_time'] ?? '');
    $endTime = trim($data['end_time'] ?? '');

    if ($name === '') {
        api_error('Nome tipo turno obbligatorio', 400);
    }
    if (strlen($name) > 100) {
        api_error('Nome troppo lungo (max 100 caratteri)', 400);
    }
    if ($code === '') {
        api_error('Codice tipo turno obbligatorio', 400);
    }
    if (strlen($code) > 50) {
        api_error('Codice troppo lungo (max 50 caratteri)', 400);
    }
    if ($startTime === '' || $endTime === '') {
        api_error('Orari inizio e fine obbligatori', 400);
    }

    // Validate time format (HH:MM or HH:MM:SS)
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) {
        api_error('Formato orario inizio non valido (HH:MM)', 400);
    }
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
        api_error('Formato orario fine non valido (HH:MM)', 400);
    }

    // Check for duplicate code/name in same tenant (not deleted)
    $existing = $db->fetchOne(
        "SELECT id FROM shift_types
         WHERE tenant_id = ? AND (code = ? OR name = ?) AND deleted_at IS NULL",
        [$tenantId, $code, $name]
    );
    if ($existing) {
        api_error('Esiste gia un tipo turno con questo codice o nome', 409);
    }

    // Optional fields
    $description = isset($data['description']) ? trim($data['description']) : null;
    $color = isset($data['color']) ? trim($data['color']) : '#3B82F6';
    $icon = isset($data['icon']) ? trim($data['icon']) : null;
    $sortOrder = isset($data['sort_order']) ? (int)$data['sort_order'] : 0;
    $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;
    $durationMinutes = isset($data['duration_minutes']) ? (int)$data['duration_minutes'] : null;

    // Validate color format
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        $color = '#3B82F6'; // Default blue
    }

    $db->beginTransaction();

    try {
        $insertData = [
            'tenant_id' => $tenantId,
            'name' => $name,
            'code' => $code,
            'description' => $description,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration_minutes' => $durationMinutes,
            'color' => $color,
            'icon' => $icon,
            'sort_order' => $sortOrder,
            'is_active' => $isActive ? 1 : 0,
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $typeId = $db->insert('shift_types', $insertData);

        if (!$typeId) {
            throw new Exception('Errore creazione tipo turno');
        }

        // Audit log (non-blocking)
        try {
            logShiftAudit($db, $tenantId, $userId, 'shift_type_created', 'shift_types', $typeId, null, $insertData);
        } catch (Exception $e) {
            error_log('[Shift Types] Audit log error: ' . $e->getMessage());
        }

        $db->commit();

        // Fetch created record
        $created = $db->fetchOne(
            "SELECT * FROM shift_types WHERE id = ? AND deleted_at IS NULL",
            [$typeId]
        );

        api_success([
            'shift_type' => formatShiftType($created),
            'shift_type_id' => (int)$typeId
        ], 'Tipo turno creato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * POST action=update: Modifica tipo turno
 */
function handleUpdate(Database $db, int $tenantId, int $userId, string $role, bool $isSuperAdmin, array $data): void
{
    // Permission check
    if (!$isSuperAdmin && !in_array($role, ['admin', 'manager'])) {
        api_error('Permessi insufficienti per modificare tipi turno', 403);
    }

    $typeId = (int)($data['id'] ?? 0);
    if ($typeId <= 0) {
        api_error('ID tipo turno richiesto', 400);
    }

    // Verify shift type exists and belongs to tenant
    $existing = $db->fetchOne(
        "SELECT * FROM shift_types WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$typeId, $tenantId]
    );
    if (!$existing) {
        api_error('Tipo turno non trovato', 404);
    }

    $updateData = ['updated_at' => date('Y-m-d H:i:s')];
    $oldData = $existing;

    // Update allowed fields
    if (isset($data['name'])) {
        $name = trim($data['name']);
        if ($name === '') {
            api_error('Nome tipo turno non puo essere vuoto', 400);
        }
        if (strlen($name) > 100) {
            api_error('Nome troppo lungo (max 100 caratteri)', 400);
        }
        // Check duplicate
        $dup = $db->fetchOne(
            "SELECT id FROM shift_types
             WHERE tenant_id = ? AND name = ? AND id != ? AND deleted_at IS NULL",
            [$tenantId, $name, $typeId]
        );
        if ($dup) {
            api_error('Esiste gia un tipo turno con questo nome', 409);
        }
        $updateData['name'] = $name;
    }

    if (isset($data['code'])) {
        $code = trim($data['code']);
        if ($code === '') {
            api_error('Codice tipo turno non puo essere vuoto', 400);
        }
        if (strlen($code) > 50) {
            api_error('Codice troppo lungo (max 50 caratteri)', 400);
        }
        $dup = $db->fetchOne(
            "SELECT id FROM shift_types
             WHERE tenant_id = ? AND code = ? AND id != ? AND deleted_at IS NULL",
            [$tenantId, $code, $typeId]
        );
        if ($dup) {
            api_error('Esiste gia un tipo turno con questo codice', 409);
        }
        $updateData['code'] = $code;
    }

    if (isset($data['description'])) {
        $updateData['description'] = trim($data['description']) ?: null;
    }

    if (isset($data['start_time'])) {
        $startTime = trim($data['start_time']);
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) {
            api_error('Formato orario inizio non valido (HH:MM)', 400);
        }
        $updateData['start_time'] = $startTime;
    }

    if (isset($data['end_time'])) {
        $endTime = trim($data['end_time']);
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
            api_error('Formato orario fine non valido (HH:MM)', 400);
        }
        $updateData['end_time'] = $endTime;
    }

    if (isset($data['duration_minutes'])) {
        $updateData['duration_minutes'] = $data['duration_minutes'] !== null ? (int)$data['duration_minutes'] : null;
    }

    if (isset($data['color'])) {
        $color = trim($data['color']);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $updateData['color'] = $color;
        }
    }

    if (isset($data['icon'])) {
        $updateData['icon'] = trim($data['icon']) ?: null;
    }

    if (isset($data['sort_order'])) {
        $updateData['sort_order'] = (int)$data['sort_order'];
    }

    if (isset($data['is_active'])) {
        $updateData['is_active'] = $data['is_active'] ? 1 : 0;
    }

    $db->beginTransaction();

    try {
        $db->update('shift_types', $updateData, ['id' => $typeId]);

        // Audit log (non-blocking)
        try {
            logShiftAudit($db, $tenantId, $userId, 'shift_type_updated', 'shift_types', $typeId, $oldData, $updateData);
        } catch (Exception $e) {
            error_log('[Shift Types] Audit log error: ' . $e->getMessage());
        }

        $db->commit();

        // Fetch updated record
        $updated = $db->fetchOne(
            "SELECT * FROM shift_types WHERE id = ? AND deleted_at IS NULL",
            [$typeId]
        );

        api_success([
            'shift_type' => formatShiftType($updated)
        ], 'Tipo turno aggiornato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * POST action=delete: Soft delete tipo turno
 */
function handleDelete(Database $db, int $tenantId, int $userId, string $role, bool $isSuperAdmin, array $data): void
{
    // Permission check
    if (!$isSuperAdmin && !in_array($role, ['admin', 'manager'])) {
        api_error('Permessi insufficienti per eliminare tipi turno', 403);
    }

    $typeId = (int)($data['id'] ?? 0);
    if ($typeId <= 0) {
        api_error('ID tipo turno richiesto', 400);
    }

    // Verify shift type exists and belongs to tenant
    $existing = $db->fetchOne(
        "SELECT * FROM shift_types WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$typeId, $tenantId]
    );
    if (!$existing) {
        api_error('Tipo turno non trovato', 404);
    }

    // Check if there are active shifts using this type
    $activeShifts = $db->fetchOne(
        "SELECT COUNT(*) as cnt FROM work_shifts
         WHERE shift_type_id = ? AND deleted_at IS NULL AND status NOT IN ('cancelled', 'completed')",
        [$typeId]
    );
    if ((int)($activeShifts['cnt'] ?? 0) > 0) {
        api_error('Impossibile eliminare: esistono turni attivi con questo tipo', 409);
    }

    $db->beginTransaction();

    try {
        $db->update('shift_types', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $typeId]);

        // Audit log (non-blocking)
        try {
            logShiftAudit($db, $tenantId, $userId, 'shift_type_deleted', 'shift_types', $typeId, $existing, null);
        } catch (Exception $e) {
            error_log('[Shift Types] Audit log error: ' . $e->getMessage());
        }

        $db->commit();

        api_success([
            'shift_type_id' => $typeId
        ], 'Tipo turno eliminato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Format shift type for API response
 */
function formatShiftType(?array $type): ?array
{
    if (!$type) {
        return null;
    }
    return [
        'id' => (int)$type['id'],
        'name' => $type['name'],
        'code' => $type['code'],
        'description' => $type['description'],
        'start_time' => $type['start_time'],
        'end_time' => $type['end_time'],
        'duration_minutes' => $type['duration_minutes'] !== null ? (int)$type['duration_minutes'] : null,
        'color' => $type['color'] ?? '#3B82F6',
        'icon' => $type['icon'],
        'sort_order' => (int)$type['sort_order'],
        'is_active' => (bool)$type['is_active'],
        'created_at' => $type['created_at'],
        'updated_at' => $type['updated_at']
    ];
}

/**
 * Log shift-related audit entry (non-blocking)
 */
function logShiftAudit(Database $db, int $tenantId, int $userId, string $action, string $entityType, int $entityId, ?array $oldData, ?array $newData): void
{
    // Check if audit_log table exists
    $tableExists = $db->fetchOne(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'"
    );

    if (!$tableExists) {
        return;
    }

    $db->insert('audit_log', [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'old_values' => $oldData ? json_encode($oldData) : null,
        'new_values' => $newData ? json_encode($newData) : null,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'created_at' => date('Y-m-d H:i:s')
    ]);
}
