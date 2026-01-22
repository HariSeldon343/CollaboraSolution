<?php
/**
 * API Endpoint: Work Shifts Management (Gestione Turni)
 * CRUD per assegnazione turni a utenti
 *
 * @method POST action=create - Assegna turno a utente
 * @method POST action=update - Modifica turno
 * @method POST action=delete - Soft delete turno
 * @method POST action=bulk_create - Creazione multipla turni (es: settimana intera)
 *
 * @version 1.0.0
 * @since 2025-12-19
 * @author CollaboraNexio Team (php-multitenant-architect)
 */

declare(strict_types=1);

// Include centralized API authentication
require_once __DIR__ . '/../../includes/api_auth.php';

// Initialize API environment
initializeApiEnvironment();

// Disable caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    // Include required files
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/shift_permissions_helper.php';
    require_once __DIR__ . '/../../includes/shift_notification_helper.php';
    require_once __DIR__ . '/../../includes/shift_time_helper.php';

    // Verify authentication
    verifyApiAuthentication();

    // Get current user info
    $userInfo = getApiUserInfo();
    $currentUserId = (int)($userInfo['user_id'] ?? 0);
    $currentUserRole = $userInfo['role'] ?? 'user';
    $currentTenantId = (int)($userInfo['tenant_id'] ?? 0);

    // Enhanced super_admin detection
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $isSuperAdmin = (
        ($currentUserRole === 'super_admin') ||
        (($_SESSION['role'] ?? '') === 'super_admin') ||
        (($_SESSION['user_role'] ?? '') === 'super_admin')
    );

    // Verify CSRF for all POST operations
    verifyApiCsrfToken();

    // Get database instance
    $db = Database::getInstance();

    // Preflight: ensure required tables exist (avoid 500 when migrations are missing)
    $missing = [];
    foreach (['shift_types', 'work_shifts'] as $tname) {
        $ok = $db->fetchOne(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1",
            [$tname]
        );
        if (!$ok) $missing[] = $tname;
    }
    if (!empty($missing)) {
        api_error('Setup turni non configurato: applicare Migration 22 (tabelle turni mancanti).', 503, [
            'missing_tables' => $missing,
            'hint' => '/CollaboraNexio/tools/apply_migration_22_work_shifts_feature.php',
        ]);
    }

    // Parse request
    $rawBody = cnx_get_raw_request_body();
    $data = json_decode($rawBody, true) ?: [];
    $action = $data['action'] ?? 'create';

    // Determine target tenant_id
    $targetTenantId = (int)($data['tenant_id'] ?? 0);
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

    // Authorization check
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

    // Permission enforcement (per-tenant): create/update/delete/bulk require manage permission
    $shiftPerms = cnx_get_shift_permissions_for_tenant($db, $targetTenantId);
    $canManageShifts = $isSuperAdmin || in_array($currentUserRole, $shiftPerms['can_manage_shifts_roles'], true);
    if (!$canManageShifts) {
        api_error('Permessi insufficienti per gestire i turni', 403);
    }

    // Route to handler
    switch ($action) {
        case 'create':
            handleCreate($db, $targetTenantId, $currentUserId, $currentUserRole, $isSuperAdmin, $data);
            break;

        case 'update':
            handleUpdate($db, $targetTenantId, $currentUserId, $currentUserRole, $isSuperAdmin, $data);
            break;

        case 'delete':
            handleDelete($db, $targetTenantId, $currentUserId, $currentUserRole, $isSuperAdmin, $data);
            break;

        case 'bulk_create':
            handleBulkCreate($db, $targetTenantId, $currentUserId, $currentUserRole, $isSuperAdmin, $data);
            break;

        case 'bulk_delete':
            handleBulkDelete($db, $targetTenantId, $currentUserId, $currentUserRole, $isSuperAdmin, $data);
            break;

        default:
            api_error("Azione non valida: {$action}. Azioni: create, update, delete, bulk_create, bulk_delete", 400);
    }

} catch (PDOException $e) {
    error_log('[Shifts Manage API] PDO Error: ' . $e->getMessage());
    api_error('Errore database', 500);

} catch (Throwable $e) {
    error_log('[Shifts Manage API] Error: ' . $e->getMessage());
    api_error('Errore interno del server', 500);
}

// ============================================
// INTERNAL HELPERS
// ============================================

function cnx_shifts_has_table(Database $db, string $table): bool {
    try {
        return (bool)$db->fetchOne(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1",
            [$table]
        );
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_shifts_has_col(Database $db, string $table, string $col): bool {
    try {
        return (bool)$db->fetchOne(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$table, $col]
        );
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Resolve a user row for shifts assignment (tenant-scoped).
 * Allowed when:
 * - users.tenant_id == tenant_id OR
 * - user_tenant_access has (user_id, tenant_id) (when table exists)
 *
 * Returns NULL when user is missing or not allowed in tenant.
 *
 * @return array<string,mixed>|null
 */
function cnx_shifts_user_allowed(Database $db, int $tenantId, int $userId): ?array {
    $tenantId = (int)$tenantId;
    $userId = (int)$userId;
    if ($tenantId <= 0 || $userId <= 0) return null;

    try {
        $u = $db->fetchOne(
            "SELECT id, name, role, tenant_id
             FROM users
             WHERE id = ? AND deleted_at IS NULL
             LIMIT 1",
            [$userId]
        );
        if (!$u) return null;
        if ((int)($u['tenant_id'] ?? 0) === $tenantId) return $u;

        if (!cnx_shifts_has_table($db, 'user_tenant_access')) return null;
        $utaHasDeletedAt = cnx_shifts_has_col($db, 'user_tenant_access', 'deleted_at');
        $utaWhere = $utaHasDeletedAt ? " AND deleted_at IS NULL" : "";
        $has = $db->fetchOne(
            "SELECT 1 FROM user_tenant_access
             WHERE user_id = ? AND tenant_id = ?" . $utaWhere . "
             LIMIT 1",
            [$userId, $tenantId]
        );
        if ($has) return $u;
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

// ============================================
// HANDLER FUNCTIONS
// ============================================

/**
 * POST action=create: Assegna turno a utente
 */
function handleCreate(Database $db, int $tenantId, int $actorId, string $role, bool $isSuperAdmin, array $data): void
{
    // Permission check: admin, manager, super_admin
    if (!$isSuperAdmin && !in_array($role, ['admin', 'manager'])) {
        api_error('Permessi insufficienti per assegnare turni', 403);
    }

    // Validate required fields
    $shiftTypeId = (int)($data['shift_type_id'] ?? 0);
    $userId = (int)($data['user_id'] ?? 0);
    $shiftDate = trim($data['shift_date'] ?? '');

    if ($shiftTypeId <= 0) {
        api_error('shift_type_id obbligatorio', 400);
    }
    if ($userId <= 0) {
        api_error('user_id obbligatorio', 400);
    }
    if ($shiftDate === '') {
        api_error('shift_date obbligatoria', 400);
    }

    // Validate date format
    $dt = DateTime::createFromFormat('Y-m-d', $shiftDate);
    if (!$dt) {
        api_error('Formato data non valido (Y-m-d)', 400);
    }

    // Verify shift type exists and belongs to tenant
    $shiftType = $db->fetchOne(
        "SELECT id, name, start_time, end_time FROM shift_types
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL AND is_active = 1",
        [$shiftTypeId, $tenantId]
    );
    if (!$shiftType) {
        api_error('Tipo turno non trovato o non attivo', 404);
    }

    // Verify user exists and is allowed in tenant:
    // - primary tenant match OR user_tenant_access row (multi-tenant users)
    // - super_admin cannot be assigned to shifts
    $user = cnx_shifts_user_allowed($db, $tenantId, $userId);
    if (!$user) {
        api_error('Utente non trovato nel tenant', 404);
    }
    if (($user['role'] ?? '') === 'super_admin') {
        api_error('Utente super_admin non assegnabile ai turni', 400);
    }

    // Check for duplicate shift (same user, date, type)
    $existing = $db->fetchOne(
        "SELECT id FROM work_shifts
         WHERE tenant_id = ? AND user_id = ? AND shift_date = ? AND shift_type_id = ? AND deleted_at IS NULL",
        [$tenantId, $userId, $shiftDate, $shiftTypeId]
    );
    if ($existing) {
        api_error('Turno gia assegnato per questa data e tipo', 409);
    }

    // Optional fields
    $status = $data['status'] ?? 'scheduled';
    $validStatuses = ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'];
    if (!in_array($status, $validStatuses)) {
        $status = 'scheduled';
    }

    $notes = isset($data['notes']) ? trim($data['notes']) : null;
    $startTimeOverride = isset($data['start_time_override']) ? trim($data['start_time_override']) : null;
    $endTimeOverride = isset($data['end_time_override']) ? trim($data['end_time_override']) : null;
    if ($startTimeOverride !== null && $startTimeOverride === '') $startTimeOverride = null;
    if ($endTimeOverride !== null && $endTimeOverride === '') $endTimeOverride = null;

    // Validate time overrides if provided
    if ($startTimeOverride !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTimeOverride)) {
        api_error('Formato orario inizio override non valido (HH:MM)', 400);
    }
    if ($endTimeOverride !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTimeOverride)) {
        api_error('Formato orario fine override non valido (HH:MM)', 400);
    }

    // Prevent overlapping shifts for the same user (even across midnight)
    $effStart = $startTimeOverride ?: (string)($shiftType['start_time'] ?? '');
    $effEnd = $endTimeOverride ?: (string)($shiftType['end_time'] ?? '');
    $newInterval = cnx_shift_build_interval($shiftDate, $effStart, $effEnd);
    $conflict = cnx_shift_find_overlap_conflict($db, $tenantId, $userId, $newInterval['start'], $newInterval['end'], 0);
    if ($conflict) {
        $cName = (string)($conflict['conflict_shift_name'] ?? '');
        $cS = (string)($conflict['conflict_start_datetime'] ?? '');
        $cE = (string)($conflict['conflict_end_datetime'] ?? '');
        $label = trim(($cName !== '' ? $cName : 'Turno') . ($cS !== '' ? " {$cS}" : '') . ($cE !== '' ? " → {$cE}" : ''));
        api_error(
            'Conflitto turni: sovrapposizione con ' . ($label !== '' ? $label : 'un turno esistente'),
            409,
            $conflict
        );
    }

    $db->beginTransaction();

    try {
        $insertData = [
            'tenant_id' => $tenantId,
            'shift_type_id' => $shiftTypeId,
            'user_id' => $userId,
            'shift_date' => $shiftDate,
            'start_time_override' => $startTimeOverride,
            'end_time_override' => $endTimeOverride,
            'status' => $status,
            'notes' => $notes,
            'created_by' => $actorId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $shiftId = $db->insert('work_shifts', $insertData);

        if (!$shiftId) {
            throw new Exception('Errore creazione turno');
        }

        // Audit log (non-blocking)
        try {
            logShiftAudit($db, $tenantId, $actorId, 'work_shift_created', 'work_shifts', $shiftId, null, $insertData);
        } catch (Exception $e) {
            error_log('[Shifts Manage] Audit log error: ' . $e->getMessage());
        }

        $db->commit();

        // Notify assigned user (best-effort)
        try {
            ShiftNotificationHelper::notifyShiftAssigned((int)$shiftId, (int)$actorId);
        } catch (Throwable $e) {
            error_log('[Shifts Manage] notifyShiftAssigned failed: ' . $e->getMessage());
        }

        // Fetch created shift with full details
        $created = fetchShiftWithDetails($db, $shiftId);

        // Weekly hours check (48h alert threshold) — best-effort warning, never blocks creation
        $warnings = [];
        $weekly = null;
        try {
            $wb = cnx_shift_week_bounds($newInterval['start']);
            $mins = cnx_shift_compute_week_minutes($db, $tenantId, $userId, $wb['start'], $wb['end'], 0);
            $hours = round($mins / 60, 2);
            $weekly = [
                'user_id' => $userId,
                'week_start' => $wb['start']->format('Y-m-d'),
                'hours' => $hours,
                'limit_hours' => 48,
            ];
            if ($hours > 48.0) {
                $warnings[] = "Attenzione: ore settimanali assegnate {$hours}h (>48h) per user_id={$userId} (settimana {$weekly['week_start']})";
            }
        } catch (Throwable $e) {
            // non-blocking
        }

        api_success([
            'shift' => $created,
            'shift_id' => (int)$shiftId,
            'weekly_hours' => $weekly,
            'warnings' => $warnings,
        ], 'Turno assegnato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * POST action=bulk_delete: Soft delete multiple shifts at once
 * Payload: { ids: int[] }
 */
function handleBulkDelete(Database $db, int $tenantId, int $actorId, string $role, bool $isSuperAdmin, array $data): void
{
    if (!$isSuperAdmin && !in_array($role, ['admin', 'manager'], true)) {
        api_error('Permessi insufficienti per eliminare turni', 403);
    }

    $idsRaw = $data['ids'] ?? [];
    if (!is_array($idsRaw) || empty($idsRaw)) {
        api_error('ids richiesto (array)', 400);
    }

    $ids = array_values(array_unique(array_map(static function ($v) {
        return (int)$v;
    }, $idsRaw)));
    $ids = array_values(array_filter($ids, static fn($id) => $id > 0));

    if (empty($ids)) {
        api_error('ids non valido', 400);
    }
    if (count($ids) > 200) {
        api_error('Troppi turni selezionati (max 200)', 400);
    }

    $db->beginTransaction();
    try {
        $now = date('Y-m-d H:i:s');

        // Feature-detection: updated_by might be missing on some DBs
        $hasUpdatedBy = (bool)$db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'work_shifts'
               AND COLUMN_NAME = 'updated_by'
             LIMIT 1"
        );

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Count eligible shifts (same tenant, not already deleted)
        $eligibleRow = $db->fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM work_shifts
             WHERE tenant_id = ?
               AND deleted_at IS NULL
               AND id IN ($placeholders)",
            array_merge([$tenantId], $ids)
        );
        $eligible = (int)($eligibleRow['cnt'] ?? 0);

        // Bulk soft-delete
        $setSql = "deleted_at = ?, updated_at = ?";
        $params = [$now, $now];
        if ($hasUpdatedBy) {
            $setSql .= ", updated_by = ?";
            $params[] = $actorId;
        }

        $sql = "UPDATE work_shifts
                SET $setSql
                WHERE tenant_id = ?
                  AND deleted_at IS NULL
                  AND id IN ($placeholders)";

        $params = array_merge($params, [$tenantId], $ids);
        $stmt = $db->query($sql, $params);
        $deleted = (int)$stmt->rowCount();

        // Also soft-delete/cancel any pending change requests for those shifts (best-effort)
        try {
            $db->query(
                "UPDATE shift_change_requests
                 SET deleted_at = ?, status = 'cancelled'
                 WHERE tenant_id = ?
                   AND status = 'pending'
                   AND deleted_at IS NULL
                   AND work_shift_id IN ($placeholders)",
                array_merge([$now, $tenantId], $ids)
            );
        } catch (Throwable $e) {
            // Non-blocking: don't fail the delete if requests cleanup fails
            error_log('[Shifts Manage] bulk_delete: could not cancel related requests: ' . $e->getMessage());
        }

        $db->commit();

        $skipped = max(0, count($ids) - $deleted);
        api_success([
            'eligible_count' => $eligible,
            'deleted_count' => $deleted,
            'skipped_count' => $skipped
        ], 'Eliminazione multipla completata');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * POST action=update: Modifica turno esistente
 */
function handleUpdate(Database $db, int $tenantId, int $actorId, string $role, bool $isSuperAdmin, array $data): void
{
    // Permission check
    if (!$isSuperAdmin && !in_array($role, ['admin', 'manager'])) {
        api_error('Permessi insufficienti per modificare turni', 403);
    }

    $shiftId = (int)($data['id'] ?? 0);
    if ($shiftId <= 0) {
        api_error('ID turno richiesto', 400);
    }

    // Verify shift exists and belongs to tenant
    $existing = $db->fetchOne(
        "SELECT * FROM work_shifts WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$shiftId, $tenantId]
    );
    if (!$existing) {
        api_error('Turno non trovato', 404);
    }

    $updateData = ['updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $actorId];
    $oldData = $existing;

    // Update shift_type_id
    if (isset($data['shift_type_id'])) {
        $newTypeId = (int)$data['shift_type_id'];
        if ($newTypeId > 0) {
            $typeExists = $db->fetchOne(
                "SELECT id FROM shift_types
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL AND is_active = 1",
                [$newTypeId, $tenantId]
            );
            if (!$typeExists) {
                api_error('Tipo turno non trovato o non attivo', 404);
            }
            $updateData['shift_type_id'] = $newTypeId;
        }
    }

    // Update user_id
    if (isset($data['user_id'])) {
        $newUserId = (int)$data['user_id'];
        if ($newUserId > 0) {
            $userExists = cnx_shifts_user_allowed($db, $tenantId, $newUserId);
            if (!$userExists) {
                api_error('Utente non trovato nel tenant', 404);
            }
            if (($userExists['role'] ?? '') === 'super_admin') {
                api_error('Utente super_admin non assegnabile ai turni', 400);
            }
            $updateData['user_id'] = $newUserId;
        }
    }

    // Update shift_date
    if (isset($data['shift_date'])) {
        $newDate = trim($data['shift_date']);
        $dt = DateTime::createFromFormat('Y-m-d', $newDate);
        if (!$dt) {
            api_error('Formato data non valido (Y-m-d)', 400);
        }
        $updateData['shift_date'] = $newDate;
    }

    // Check for duplicate if changing key fields
    if (isset($updateData['user_id']) || isset($updateData['shift_date']) || isset($updateData['shift_type_id'])) {
        $checkUserId = $updateData['user_id'] ?? $existing['user_id'];
        $checkDate = $updateData['shift_date'] ?? $existing['shift_date'];
        $checkTypeId = $updateData['shift_type_id'] ?? $existing['shift_type_id'];

        $duplicate = $db->fetchOne(
            "SELECT id FROM work_shifts
             WHERE tenant_id = ? AND user_id = ? AND shift_date = ? AND shift_type_id = ?
               AND id != ? AND deleted_at IS NULL",
            [$tenantId, $checkUserId, $checkDate, $checkTypeId, $shiftId]
        );
        if ($duplicate) {
            api_error('Esiste gia un turno per questa combinazione utente/data/tipo', 409);
        }
    }

    // Update status
    if (isset($data['status'])) {
        $validStatuses = ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'];
        if (in_array($data['status'], $validStatuses)) {
            $updateData['status'] = $data['status'];
        }
    }

    // Update time overrides
    if (array_key_exists('start_time_override', $data)) {
        $val = $data['start_time_override'];
        if ($val === null || $val === '') {
            $updateData['start_time_override'] = null;
        } elseif (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', trim($val))) {
            $updateData['start_time_override'] = trim($val);
        }
    }

    if (array_key_exists('end_time_override', $data)) {
        $val = $data['end_time_override'];
        if ($val === null || $val === '') {
            $updateData['end_time_override'] = null;
        } elseif (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', trim($val))) {
            $updateData['end_time_override'] = trim($val);
        }
    }

    // Prevent overlapping shifts for the same user (even across midnight)
    // Skip overlap check if the resulting status is 'cancelled' (not an assigned shift).
    $finalStatus = (string)($updateData['status'] ?? ($existing['status'] ?? 'scheduled'));
    if ($finalStatus !== 'cancelled') {
        $finalUserId = (int)($updateData['user_id'] ?? ($existing['user_id'] ?? 0));
        $finalDate = (string)($updateData['shift_date'] ?? ($existing['shift_date'] ?? ''));
        $finalTypeId = (int)($updateData['shift_type_id'] ?? ($existing['shift_type_id'] ?? 0));
        $finalStartOv = array_key_exists('start_time_override', $updateData) ? ($updateData['start_time_override'] ?? null) : ($existing['start_time_override'] ?? null);
        $finalEndOv = array_key_exists('end_time_override', $updateData) ? ($updateData['end_time_override'] ?? null) : ($existing['end_time_override'] ?? null);
        if ($finalUserId > 0 && $finalTypeId > 0 && $finalDate !== '') {
            $st = $db->fetchOne(
                "SELECT id, name, start_time, end_time
                 FROM shift_types
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
                 LIMIT 1",
                [$finalTypeId, $tenantId]
            );
            if ($st) {
                $effStart = $finalStartOv ? (string)$finalStartOv : (string)($st['start_time'] ?? '');
                $effEnd = $finalEndOv ? (string)$finalEndOv : (string)($st['end_time'] ?? '');
                $newInterval = cnx_shift_build_interval($finalDate, $effStart, $effEnd);
                $conflict = cnx_shift_find_overlap_conflict($db, $tenantId, $finalUserId, $newInterval['start'], $newInterval['end'], $shiftId);
                if ($conflict) {
                    $cName = (string)($conflict['conflict_shift_name'] ?? '');
                    $cS = (string)($conflict['conflict_start_datetime'] ?? '');
                    $cE = (string)($conflict['conflict_end_datetime'] ?? '');
                    $label = trim(($cName !== '' ? $cName : 'Turno') . ($cS !== '' ? " {$cS}" : '') . ($cE !== '' ? " → {$cE}" : ''));
                    api_error(
                        'Conflitto turni: sovrapposizione con ' . ($label !== '' ? $label : 'un turno esistente'),
                        409,
                        $conflict
                    );
                }
            }
        }
    }

    // Update notes
    if (array_key_exists('notes', $data)) {
        $updateData['notes'] = $data['notes'] !== null ? trim($data['notes']) : null;
    }

    if (array_key_exists('user_notes', $data)) {
        $updateData['user_notes'] = $data['user_notes'] !== null ? trim($data['user_notes']) : null;
    }

    // Update actual times
    if (array_key_exists('actual_start_time', $data)) {
        $updateData['actual_start_time'] = $data['actual_start_time'] ?: null;
    }

    if (array_key_exists('actual_end_time', $data)) {
        $updateData['actual_end_time'] = $data['actual_end_time'] ?: null;
    }

    $db->beginTransaction();

    try {
        $db->update('work_shifts', $updateData, ['id' => $shiftId]);

        // Audit log (non-blocking)
        try {
            logShiftAudit($db, $tenantId, $actorId, 'work_shift_updated', 'work_shifts', $shiftId, $oldData, $updateData);
        } catch (Exception $e) {
            error_log('[Shifts Manage] Audit log error: ' . $e->getMessage());
        }

        $db->commit();

        // Notify user about update (best-effort)
        try {
            ShiftNotificationHelper::notifyShiftUpdated((int)$shiftId, (int)$actorId, []);
        } catch (Throwable $e) {
            error_log('[Shifts Manage] notifyShiftUpdated failed: ' . $e->getMessage());
        }

        // Fetch updated shift
        $updated = fetchShiftWithDetails($db, $shiftId);

        // Weekly hours check (48h alert threshold) — best-effort warning, never blocks update
        $warnings = [];
        $weekly = null;
        try {
            // Determine interval start (post-update) for week selection
            $uid = (int)($updateData['user_id'] ?? ($existing['user_id'] ?? 0));
            $d = (string)($updateData['shift_date'] ?? ($existing['shift_date'] ?? ''));
            $typeId = (int)($updateData['shift_type_id'] ?? ($existing['shift_type_id'] ?? 0));
            $st = $db->fetchOne(
                "SELECT start_time, end_time FROM shift_types WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL LIMIT 1",
                [$typeId, $tenantId]
            );
            $startOv = array_key_exists('start_time_override', $updateData) ? ($updateData['start_time_override'] ?? null) : ($existing['start_time_override'] ?? null);
            $endOv = array_key_exists('end_time_override', $updateData) ? ($updateData['end_time_override'] ?? null) : ($existing['end_time_override'] ?? null);
            $effStart = $startOv ? (string)$startOv : (string)($st['start_time'] ?? '');
            $effEnd = $endOv ? (string)$endOv : (string)($st['end_time'] ?? '');
            $interval = cnx_shift_build_interval($d, $effStart, $effEnd);
            $wb = cnx_shift_week_bounds($interval['start']);
            $mins = cnx_shift_compute_week_minutes($db, $tenantId, $uid, $wb['start'], $wb['end'], 0);
            $hours = round($mins / 60, 2);
            $weekly = [
                'user_id' => $uid,
                'week_start' => $wb['start']->format('Y-m-d'),
                'hours' => $hours,
                'limit_hours' => 48,
            ];
            if ($hours > 48.0) {
                $warnings[] = "Attenzione: ore settimanali assegnate {$hours}h (>48h) per user_id={$uid} (settimana {$weekly['week_start']})";
            }
        } catch (Throwable $e) {
            // non-blocking
        }

        api_success([
            'shift' => $updated,
            'weekly_hours' => $weekly,
            'warnings' => $warnings,
        ], 'Turno aggiornato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * POST action=delete: Soft delete turno
 */
function handleDelete(Database $db, int $tenantId, int $actorId, string $role, bool $isSuperAdmin, array $data): void
{
    // Permission check
    if (!$isSuperAdmin && !in_array($role, ['admin', 'manager'])) {
        api_error('Permessi insufficienti per eliminare turni', 403);
    }

    $shiftId = (int)($data['id'] ?? 0);
    if ($shiftId <= 0) {
        api_error('ID turno richiesto', 400);
    }

    // Verify shift exists and belongs to tenant
    $existing = $db->fetchOne(
        "SELECT * FROM work_shifts WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$shiftId, $tenantId]
    );
    if (!$existing) {
        api_error('Turno non trovato', 404);
    }

    $db->beginTransaction();

    try {
        $db->update('work_shifts', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => $actorId
        ], ['id' => $shiftId]);

        // Also soft-delete any pending change requests for this shift
        $db->query(
            "UPDATE shift_change_requests
             SET deleted_at = ?, status = 'cancelled'
             WHERE work_shift_id = ? AND status = 'pending' AND deleted_at IS NULL",
            [date('Y-m-d H:i:s'), $shiftId]
        );

        // Audit log (non-blocking)
        try {
            logShiftAudit($db, $tenantId, $actorId, 'work_shift_deleted', 'work_shifts', $shiftId, $existing, null);
        } catch (Exception $e) {
            error_log('[Shifts Manage] Audit log error: ' . $e->getMessage());
        }

        $db->commit();

        // Notify user about cancellation (best-effort)
        try {
            ShiftNotificationHelper::notifyShiftCancelled((int)$shiftId, (int)$actorId, '');
        } catch (Throwable $e) {
            error_log('[Shifts Manage] notifyShiftCancelled failed: ' . $e->getMessage());
        }

        api_success([
            'shift_id' => $shiftId
        ], 'Turno eliminato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * POST action=bulk_create: Creazione multipla turni
 * Utile per assegnare turni per una settimana intera o pattern ricorrenti
 */
function handleBulkCreate(Database $db, int $tenantId, int $actorId, string $role, bool $isSuperAdmin, array $data): void
{
    // Permission check
    if (!$isSuperAdmin && !in_array($role, ['admin', 'manager'])) {
        api_error('Permessi insufficienti per creare turni in blocco', 403);
    }

    // Validate input: array of shifts or pattern-based creation
    $shifts = $data['shifts'] ?? [];

    // Pattern-based: user_id + shift_type_id + start_date + end_date + days_of_week
    $patternMode = isset($data['start_date']) && isset($data['end_date']) && isset($data['days_of_week']);

    if ($patternMode) {
        $shifts = generateShiftsFromPattern($data, $tenantId);
    }

    if (empty($shifts) || !is_array($shifts)) {
        api_error('Array shifts obbligatorio (o parametri pattern validi)', 400);
    }

    // Limit bulk operations
    if (count($shifts) > 100) {
        api_error('Massimo 100 turni per operazione bulk', 400);
    }

    $db->beginTransaction();

    try {
        $created = [];
        $errors = [];

        // Prefetch shift types and users for speed + overlap checks
        $userIds = [];
        $typeIds = [];
        $minDate = null;
        $maxDate = null;
        foreach ($shifts as $shift) {
            $uid = (int)($shift['user_id'] ?? 0);
            $tid = (int)($shift['shift_type_id'] ?? 0);
            $d = trim((string)($shift['shift_date'] ?? ''));
            if ($uid > 0) $userIds[$uid] = true;
            if ($tid > 0) $typeIds[$tid] = true;
            if ($d !== '') {
                $minDate = $minDate === null ? $d : min($minDate, $d);
                $maxDate = $maxDate === null ? $d : max($maxDate, $d);
            }
        }
        $userIds = array_keys($userIds);
        $typeIds = array_keys($typeIds);

        $shiftTypesById = [];
        if (!empty($typeIds)) {
            $in = implode(',', array_fill(0, count($typeIds), '?'));
            $rows = $db->fetchAll(
                "SELECT id, name, start_time, end_time
                 FROM shift_types
                 WHERE tenant_id = ?
                   AND deleted_at IS NULL
                   AND is_active = 1
                   AND id IN ($in)",
                array_merge([$tenantId], $typeIds)
            ) ?: [];
            foreach ($rows as $r) {
                $shiftTypesById[(int)$r['id']] = $r;
            }
        }

        $usersById = [];
        if (!empty($userIds)) {
            $in = implode(',', array_fill(0, count($userIds), '?'));
            $rows = $db->fetchAll(
                "SELECT id, role, tenant_id
                 FROM users
                 WHERE deleted_at IS NULL
                   AND id IN ($in)",
                $userIds
            ) ?: [];
            foreach ($rows as $r) {
                $usersById[(int)$r['id']] = $r;
            }
        }

        // Multi-tenant user support: allow assigning shifts to users that have an access row for this tenant.
        $utaAllowed = [];
        try {
            if (cnx_shifts_has_table($db, 'user_tenant_access') && !empty($userIds)) {
                $utaHasDeletedAt = cnx_shifts_has_col($db, 'user_tenant_access', 'deleted_at');
                $utaWhere = $utaHasDeletedAt ? " AND deleted_at IS NULL" : "";
                $in = implode(',', array_fill(0, count($userIds), '?'));
                $rows = $db->fetchAll(
                    "SELECT user_id
                     FROM user_tenant_access
                     WHERE tenant_id = ?
                       AND user_id IN ($in)" . $utaWhere,
                    array_merge([$tenantId], $userIds)
                ) ?: [];
                foreach ($rows as $r) {
                    $uid = (int)($r['user_id'] ?? 0);
                    if ($uid > 0) $utaAllowed[$uid] = true;
                }
            }
        } catch (Throwable $e) {
            $utaAllowed = [];
        }

        // Existing shifts for overlap/duplicate detection (date window expanded by 1 day)
        $existingByUser = [];
        $existingKeySet = [];
        if ($minDate !== null && $maxDate !== null && !empty($userIds)) {
            $fromDate = date('Y-m-d', strtotime($minDate . ' -1 day'));
            $toDate = date('Y-m-d', strtotime($maxDate . ' +1 day'));
            $in = implode(',', array_fill(0, count($userIds), '?'));
            $rows = $db->fetchAll(
                "SELECT
                    ws.id,
                    ws.user_id,
                    ws.shift_type_id,
                    ws.shift_date,
                    COALESCE(ws.start_time_override, st.start_time) AS start_time,
                    COALESCE(ws.end_time_override, st.end_time) AS end_time,
                    st.name AS shift_name
                 FROM work_shifts ws
                 INNER JOIN shift_types st ON ws.shift_type_id = st.id
                 WHERE ws.tenant_id = ?
                   AND ws.deleted_at IS NULL
                   AND ws.status != 'cancelled'
                   AND ws.user_id IN ($in)
                   AND ws.shift_date >= ?
                   AND ws.shift_date <= ?",
                array_merge([$tenantId], $userIds, [$fromDate, $toDate])
            ) ?: [];
            foreach ($rows as $r) {
                $uid = (int)($r['user_id'] ?? 0);
                if ($uid <= 0) continue;
                $key = $uid . '|' . (string)($r['shift_date'] ?? '') . '|' . (int)($r['shift_type_id'] ?? 0);
                $existingKeySet[$key] = true;
                $existingByUser[$uid][] = [
                    'id' => (int)($r['id'] ?? 0),
                    'shift_type_id' => (int)($r['shift_type_id'] ?? 0),
                    'shift_name' => (string)($r['shift_name'] ?? ''),
                    'shift_date' => (string)($r['shift_date'] ?? ''),
                    'start_time' => (string)($r['start_time'] ?? ''),
                    'end_time' => (string)($r['end_time'] ?? ''),
                ];
            }
        }

        $newKeySet = [];
        $newIntervalsByUser = []; // user_id => list of ['start'=>DateTime,'end'=>DateTime,'meta'=>array]

        foreach ($shifts as $index => $shift) {
            $shiftTypeId = (int)($shift['shift_type_id'] ?? 0);
            $userId = (int)($shift['user_id'] ?? 0);
            $shiftDate = trim((string)($shift['shift_date'] ?? ''));

            // Basic validation
            if ($shiftTypeId <= 0 || $userId <= 0 || $shiftDate === '') {
                $errors[] = [
                    'index' => $index,
                    'error' => 'Dati incompleti (shift_type_id, user_id, shift_date richiesti)'
                ];
                continue;
            }

            // Validate date
            $dt = DateTime::createFromFormat('Y-m-d', $shiftDate);
            if (!$dt) {
                $errors[] = ['index' => $index, 'error' => 'Formato data non valido'];
                continue;
            }

            // Verify shift type exists
            $shiftType = $shiftTypesById[$shiftTypeId] ?? null;
            if (!$shiftType) {
                $errors[] = ['index' => $index, 'error' => 'Tipo turno non trovato'];
                continue;
            }

            // Verify user exists (super_admin cannot be assigned)
            $user = $usersById[$userId] ?? null;
            if (!$user) {
                $errors[] = ['index' => $index, 'error' => 'Utente non trovato'];
                continue;
            }
            $primaryTenant = (int)($user['tenant_id'] ?? 0);
            if ($primaryTenant !== $tenantId && empty($utaAllowed[$userId])) {
                $errors[] = ['index' => $index, 'error' => 'Utente non trovato nel tenant', 'user_id' => $userId, 'shift_date' => $shiftDate];
                continue;
            }
            if (($user['role'] ?? '') === 'super_admin') {
                $errors[] = ['index' => $index, 'error' => 'Utente super_admin non assegnabile ai turni'];
                continue;
            }

            // Check duplicate (existing DB or within this bulk operation)
            $k = $userId . '|' . $shiftDate . '|' . $shiftTypeId;
            if (isset($existingKeySet[$k]) || isset($newKeySet[$k])) {
                $errors[] = ['index' => $index, 'error' => 'Turno gia esistente', 'shift_date' => $shiftDate];
                continue;
            }

            // Validate time overrides (optional)
            $startOv = isset($shift['start_time_override']) ? trim((string)$shift['start_time_override']) : null;
            $endOv = isset($shift['end_time_override']) ? trim((string)$shift['end_time_override']) : null;
            if ($startOv !== null && $startOv === '') $startOv = null;
            if ($endOv !== null && $endOv === '') $endOv = null;
            if ($startOv !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startOv)) {
                $errors[] = ['index' => $index, 'error' => 'Formato orario inizio override non valido (HH:MM)', 'shift_date' => $shiftDate];
                continue;
            }
            if ($endOv !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endOv)) {
                $errors[] = ['index' => $index, 'error' => 'Formato orario fine override non valido (HH:MM)', 'shift_date' => $shiftDate];
                continue;
            }

            // Overlap prevention (check against existing + new shifts)
            $effStart = $startOv ?: (string)($shiftType['start_time'] ?? '');
            $effEnd = $endOv ?: (string)($shiftType['end_time'] ?? '');
            $interval = cnx_shift_build_interval($shiftDate, $effStart, $effEnd);

            // Existing overlaps
            $conflictFound = null;
            foreach (($existingByUser[$userId] ?? []) as $ex) {
                $exInterval = cnx_shift_build_interval((string)$ex['shift_date'], (string)$ex['start_time'], (string)$ex['end_time']);
                if (cnx_shift_intervals_overlap($interval['start'], $interval['end'], $exInterval['start'], $exInterval['end'])) {
                    $conflictFound = [
                        'conflict_shift_id' => (int)($ex['id'] ?? 0),
                        'conflict_shift_name' => (string)($ex['shift_name'] ?? ''),
                        'conflict_shift_date' => (string)($ex['shift_date'] ?? ''),
                        'conflict_start_time' => (string)($ex['start_time'] ?? ''),
                        'conflict_end_time' => (string)($ex['end_time'] ?? ''),
                        'conflict_start_datetime' => $exInterval['start']->format('Y-m-d H:i:s'),
                        'conflict_end_datetime' => $exInterval['end']->format('Y-m-d H:i:s'),
                    ];
                    break;
                }
            }
            // New overlaps (within this operation)
            if (!$conflictFound) {
                foreach (($newIntervalsByUser[$userId] ?? []) as $nx) {
                    if (cnx_shift_intervals_overlap($interval['start'], $interval['end'], $nx['start'], $nx['end'])) {
                        $conflictFound = [
                            'conflict_shift_id' => 0,
                            'conflict_shift_name' => (string)($nx['meta']['shift_name'] ?? ''),
                            'conflict_shift_date' => (string)($nx['meta']['shift_date'] ?? ''),
                            'conflict_start_datetime' => $nx['start']->format('Y-m-d H:i:s'),
                            'conflict_end_datetime' => $nx['end']->format('Y-m-d H:i:s'),
                        ];
                        break;
                    }
                }
            }
            if ($conflictFound) {
                $errors[] = [
                    'index' => $index,
                    'error' => 'Conflitto turni: l’utente ha già un turno sovrapposto nello stesso intervallo',
                    'shift_date' => $shiftDate,
                    'user_id' => $userId,
                    'conflict' => $conflictFound,
                ];
                continue;
            }

            // Insert
            $insertData = [
                'tenant_id' => $tenantId,
                'shift_type_id' => $shiftTypeId,
                'user_id' => $userId,
                'shift_date' => $shiftDate,
                'start_time_override' => $startOv,
                'end_time_override' => $endOv,
                'status' => $shift['status'] ?? 'scheduled',
                'notes' => isset($shift['notes']) ? trim($shift['notes']) : null,
                'created_by' => $actorId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $shiftId = $db->insert('work_shifts', $insertData);
            if ($shiftId) {
                // Weeks touched by this interval (at most 2, when crossing ISO-week boundary)
                $weekStarts = [];
                try {
                    $wb = cnx_shift_week_bounds($interval['start']);
                    $weekStarts[] = $wb['start']->format('Y-m-d');
                    if ($interval['end']->getTimestamp() > $wb['end']->getTimestamp()) {
                        $weekStarts[] = $wb['end']->format('Y-m-d'); // next week start (Monday)
                    }
                } catch (Throwable $e) {
                    $weekStarts = [];
                }
                $created[] = [
                    'index' => $index,
                    'shift_id' => (int)$shiftId,
                    'shift_date' => $shiftDate,
                    'user_id' => $userId,
                    'week_starts' => $weekStarts,
                ];
                $existingKeySet[$k] = true;
                $newKeySet[$k] = true;
                $newIntervalsByUser[$userId][] = [
                    'start' => $interval['start'],
                    'end' => $interval['end'],
                    'meta' => [
                        'shift_date' => $shiftDate,
                        'shift_name' => (string)($shiftType['name'] ?? ''),
                    ],
                ];
            }
        }

        // Audit log (non-blocking)
        try {
            logShiftAudit($db, $tenantId, $actorId, 'work_shifts_bulk_created', 'work_shifts', 0, null, [
                'created_count' => count($created),
                'error_count' => count($errors)
            ]);
        } catch (Exception $e) {
            error_log('[Shifts Manage] Audit log error: ' . $e->getMessage());
        }

        $db->commit();

        // Notify assigned users (best-effort)
        try {
            $shiftIds = array_values(array_filter(array_map(static function ($c) {
                return isset($c['shift_id']) ? (int)$c['shift_id'] : 0;
            }, $created)));
            $shiftIds = array_values(array_filter($shiftIds, static fn($id) => $id > 0));
            if (!empty($shiftIds)) {
                ShiftNotificationHelper::notifyBulkShiftsAssigned($shiftIds, (int)$actorId);
            }
        } catch (Throwable $e) {
            error_log('[Shifts Manage] notifyBulkShiftsAssigned failed: ' . $e->getMessage());
        }

        // Weekly hours warnings (48h threshold) — best-effort
        $warnings = [];
        try {
            $pairs = [];
            foreach ($created as $c) {
                $uid = (int)($c['user_id'] ?? 0);
                if ($uid <= 0) continue;
                $wkList = $c['week_starts'] ?? [];
                if (!is_array($wkList) || empty($wkList)) {
                    $d = (string)($c['shift_date'] ?? '');
                    $wkList = $d ? [$d] : [];
                }
                foreach ($wkList as $wk0) {
                    $wk0 = trim((string)$wk0);
                    if ($wk0 === '') continue;
                    $tmp = DateTime::createFromFormat('Y-m-d H:i:s', $wk0 . ' 00:00:00');
                    if (!$tmp) continue;
                    $wb = cnx_shift_week_bounds($tmp);
                    $wk = $wb['start']->format('Y-m-d');
                    $pairs[$uid . '|' . $wk] = ['uid' => $uid, 'wb' => $wb];
                }
            }
            foreach ($pairs as $p) {
                $uid = (int)$p['uid'];
                $wb = $p['wb'];
                $mins = cnx_shift_compute_week_minutes($db, $tenantId, $uid, $wb['start'], $wb['end'], 0);
                $hours = round($mins / 60, 2);
                if ($hours > 48.0) {
                    $warnings[] = "Attenzione: ore settimanali assegnate {$hours}h (>48h) per user_id={$uid} (settimana {$wb['start']->format('Y-m-d')})";
                }
            }
        } catch (Throwable $e) {
            // non-blocking
        }

        api_success([
            'created' => $created,
            'created_count' => count($created),
            'errors' => $errors,
            'error_count' => count($errors),
            'warnings' => $warnings,
        ], 'Operazione bulk completata: ' . count($created) . ' turni creati, ' . count($errors) . ' errori');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Generate shifts from pattern (start_date, end_date, days_of_week)
 */
function generateShiftsFromPattern(array $data, int $tenantId): array
{
    $startDate = trim($data['start_date'] ?? '');
    $endDate = trim($data['end_date'] ?? '');
    $daysOfWeek = $data['days_of_week'] ?? []; // Array of 0-6 (0=Sunday, 1=Monday, etc.)
    $shiftTypeId = (int)($data['shift_type_id'] ?? 0);
    $userId = (int)($data['user_id'] ?? 0);
    $userIds = $data['user_ids'] ?? []; // Alternative: multiple users

    if (empty($userIds) && $userId > 0) {
        $userIds = [$userId];
    }

    $startDt = DateTime::createFromFormat('Y-m-d', $startDate);
    $endDt = DateTime::createFromFormat('Y-m-d', $endDate);

    if (!$startDt || !$endDt || $startDt > $endDt) {
        return [];
    }

    // Limit to max 3 months
    $interval = $startDt->diff($endDt);
    if ($interval->days > 93) {
        return [];
    }

    $shifts = [];
    $current = clone $startDt;

    while ($current <= $endDt) {
        $dayOfWeek = (int)$current->format('w'); // 0=Sun, 6=Sat

        if (in_array($dayOfWeek, $daysOfWeek)) {
            foreach ($userIds as $uid) {
                $shifts[] = [
                    'shift_type_id' => $shiftTypeId,
                    'user_id' => (int)$uid,
                    'shift_date' => $current->format('Y-m-d'),
                    'status' => $data['status'] ?? 'scheduled',
                    'notes' => $data['notes'] ?? null
                ];
            }
        }

        $current->modify('+1 day');
    }

    return $shifts;
}

/**
 * Fetch shift with full details (joins)
 */
function fetchShiftWithDetails(Database $db, int $shiftId): ?array
{
    $row = $db->fetchOne("
        SELECT
            ws.id,
            ws.tenant_id,
            ws.shift_type_id,
            ws.user_id,
            ws.shift_date,
            COALESCE(ws.start_time_override, st.start_time) AS start_time,
            COALESCE(ws.end_time_override, st.end_time) AS end_time,
            ws.start_time_override,
            ws.end_time_override,
            ws.status,
            ws.notes,
            ws.user_notes,
            ws.actual_start_time,
            ws.actual_end_time,
            ws.created_at,
            ws.updated_at,
            st.name AS shift_name,
            st.code AS shift_code,
            st.color AS shift_color,
            u.name AS user_name,
            u.email AS user_email
        FROM work_shifts ws
        INNER JOIN shift_types st ON ws.shift_type_id = st.id
        INNER JOIN users u ON ws.user_id = u.id
        WHERE ws.id = ? AND ws.deleted_at IS NULL
    ", [$shiftId]);

    if (!$row) {
        return null;
    }

    return [
        'id' => (int)$row['id'],
        'tenant_id' => (int)$row['tenant_id'],
        'shift_type_id' => (int)$row['shift_type_id'],
        'user_id' => (int)$row['user_id'],
        'shift_date' => $row['shift_date'],
        'start_time' => $row['start_time'],
        'end_time' => $row['end_time'],
        'has_override' => ($row['start_time_override'] !== null || $row['end_time_override'] !== null),
        'status' => $row['status'],
        'notes' => $row['notes'],
        'user_notes' => $row['user_notes'],
        'actual_start_time' => $row['actual_start_time'],
        'actual_end_time' => $row['actual_end_time'],
        'shift_name' => $row['shift_name'],
        'shift_code' => $row['shift_code'],
        'color' => $row['shift_color'] ?? '#3B82F6',
        'user_name' => $row['user_name'],
        'user_email' => $row['user_email'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at']
    ];
}

/**
 * Log shift-related audit entry (non-blocking)
 */
function logShiftAudit(Database $db, int $tenantId, int $userId, string $action, string $entityType, int $entityId, ?array $oldData, ?array $newData): void
{
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
