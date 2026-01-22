<?php
/**
 * API Endpoint: Shift Change Requests (Richieste Modifica Turno)
 * Gestione richieste modifica/scambio/annullamento turno
 *
 * @method GET - Lista richieste (pending per manager, proprie per user)
 * @method POST action=create - User crea richiesta modifica
 * @method POST action=approve - Manager approva richiesta
 * @method POST action=reject - Manager rifiuta richiesta
 * @method POST action=cancel - User cancella propria richiesta pending
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

    // Get database instance
    $db = Database::getInstance();

    // Preflight: ensure required tables exist (avoid 500 when migrations are missing)
    $missing = [];
    foreach (['shift_types', 'work_shifts', 'shift_change_requests'] as $tname) {
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

    // Determine request method and action
    $method = $_SERVER['REQUEST_METHOD'];
    $rawBody = cnx_get_raw_request_body();
    $data = json_decode($rawBody, true) ?: [];
    $action = $data['action'] ?? $_GET['action'] ?? 'list';

    // Backward-compatible aliases (JS cache / older clients)
    // - shift_id -> work_shift_id
    // - requested_shift_type_id -> new_shift_type_id
    // - rejection_reason -> manager_notes
    if (is_array($data)) {
        if ((!isset($data['work_shift_id']) || (int)$data['work_shift_id'] <= 0) && isset($data['shift_id'])) {
            $data['work_shift_id'] = $data['shift_id'];
        }
        if ((!isset($data['new_shift_type_id']) || (int)$data['new_shift_type_id'] <= 0) && isset($data['requested_shift_type_id'])) {
            $data['new_shift_type_id'] = $data['requested_shift_type_id'];
        }
        if ((!isset($data['manager_notes']) || trim((string)$data['manager_notes']) === '') && isset($data['rejection_reason'])) {
            $data['manager_notes'] = $data['rejection_reason'];
        }
    }

    // CSRF verification for all operations
    verifyApiCsrfToken();

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

    // Permission enforcement (per-tenant) for approval actions
    $shiftPerms = cnx_get_shift_permissions_for_tenant($db, $targetTenantId);
    $canApproveRequests = $isSuperAdmin || in_array($currentUserRole, $shiftPerms['can_approve_shift_requests_roles'], true);
    if (in_array($action, ['approve', 'reject'], true) && !$canApproveRequests) {
        api_error('Permessi insufficienti per approvare/rifiutare richieste', 403);
    }

    // Route to handler
    switch ($action) {
        case 'list':
            handleList($db, $targetTenantId, $currentUserId, $currentUserRole, $isSuperAdmin);
            break;

        case 'create':
            handleCreate($db, $targetTenantId, $currentUserId, $currentUserRole, $isSuperAdmin, $data);
            break;

        case 'approve':
            handleApprove($db, $targetTenantId, $currentUserId, $currentUserRole, $isSuperAdmin, $data);
            break;

        case 'reject':
            handleReject($db, $targetTenantId, $currentUserId, $currentUserRole, $isSuperAdmin, $data);
            break;

        case 'cancel':
            handleCancel($db, $targetTenantId, $currentUserId, $currentUserRole, $isSuperAdmin, $data);
            break;

        default:
            api_error("Azione non valida: {$action}. Azioni: list, create, approve, reject, cancel", 400);
    }

} catch (PDOException $e) {
    error_log('[Shift Requests API] PDO Error: ' . $e->getMessage());
    api_error('Errore database', 500);

} catch (Throwable $e) {
    error_log('[Shift Requests API] Error: ' . $e->getMessage());
    api_error('Errore interno del server', 500);
}

// ============================================
// HANDLER FUNCTIONS
// ============================================

/**
 * GET: Lista richieste modifica turno
 * - Per user: solo proprie richieste
 * - Per admin/manager: tutte le richieste del tenant (o pending per dashboard)
 */
function handleList(Database $db, int $tenantId, int $userId, string $role, bool $isSuperAdmin): void
{
    $isManager = $isSuperAdmin || in_array($role, ['admin', 'manager']);

    $whereConditions = [
        'scr.tenant_id = ?',
        'scr.deleted_at IS NULL'
    ];
    $params = [$tenantId];

    // Filter by status (optional)
    $statusFilter = $_GET['status'] ?? null;
    if ($statusFilter !== null && $statusFilter !== '') {
        $validStatuses = ['pending', 'approved', 'rejected', 'cancelled', 'expired'];
        if (in_array($statusFilter, $validStatuses)) {
            $whereConditions[] = 'scr.status = ?';
            $params[] = $statusFilter;
        }
    }

    // For regular users: only their own requests
    if (!$isManager) {
        $whereConditions[] = 'scr.requester_id = ?';
        $params[] = $userId;
    } else {
        // Manager can filter by requester
        if (isset($_GET['requester_id']) && $_GET['requester_id'] !== '') {
            $whereConditions[] = 'scr.requester_id = ?';
            $params[] = (int)$_GET['requester_id'];
        }
    }

    // Filter by request_type (optional)
    if (isset($_GET['request_type']) && $_GET['request_type'] !== '') {
        $validTypes = ['change', 'swap', 'cancel'];
        if (in_array($_GET['request_type'], $validTypes)) {
            $whereConditions[] = 'scr.request_type = ?';
            $params[] = $_GET['request_type'];
        }
    }

    $whereClause = implode(' AND ', $whereConditions);

    $query = "
        SELECT
            scr.id,
            scr.tenant_id,
            scr.work_shift_id,
            scr.requester_id,
            scr.request_type,
            scr.new_shift_type_id,
            scr.target_user_id,
            scr.target_work_shift_id,
            scr.reason,
            scr.preferred_date,
            scr.status,
            scr.manager_id,
            scr.decision_at,
            scr.manager_notes,
            scr.target_accepted,
            scr.target_accepted_at,
            scr.target_notes,
            scr.created_at,
            scr.updated_at,
            -- Original shift details
            ws.shift_date AS original_shift_date,
            COALESCE(ws.start_time_override, st.start_time) AS original_start_time,
            COALESCE(ws.end_time_override, st.end_time) AS original_end_time,
            st.name AS original_shift_name,
            st.color AS original_shift_color,
            -- Requester details
            u_req.name AS requester_name,
            u_req.email AS requester_email,
            -- New shift type (for change requests)
            st_new.name AS new_shift_name,
            st_new.color AS new_shift_color,
            -- Target user (for swap requests)
            u_target.name AS target_user_name,
            u_target.email AS target_user_email,
            -- Manager details
            u_mgr.name AS manager_name
        FROM shift_change_requests scr
        INNER JOIN work_shifts ws ON scr.work_shift_id = ws.id
        INNER JOIN shift_types st ON ws.shift_type_id = st.id
        INNER JOIN users u_req ON scr.requester_id = u_req.id
        LEFT JOIN shift_types st_new ON scr.new_shift_type_id = st_new.id
        LEFT JOIN users u_target ON scr.target_user_id = u_target.id
        LEFT JOIN users u_mgr ON scr.manager_id = u_mgr.id
        WHERE {$whereClause}
        ORDER BY
            CASE scr.status WHEN 'pending' THEN 0 ELSE 1 END ASC,
            scr.created_at DESC
    ";

    $requests = $db->fetchAll($query, $params);

    // Format response
    $formattedRequests = [];
    foreach ($requests as $req) {
        $formattedRequests[] = formatRequest($req);
    }

    // Get counts for dashboard (managers only)
    $counts = null;
    if ($isManager) {
        $counts = [
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'total' => count($formattedRequests)
        ];
        foreach ($formattedRequests as $r) {
            $status = $r['status'];
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
    }

    $response = [
        'requests' => $formattedRequests,
        'tenant_id' => $tenantId,
        'total' => count($formattedRequests)
    ];

    if ($counts !== null) {
        $response['counts'] = $counts;
    }

    api_success($response, 'Richieste modifica turno recuperate');
}

/**
 * POST action=create: User crea richiesta modifica turno
 */
function handleCreate(Database $db, int $tenantId, int $userId, string $role, bool $isSuperAdmin, array $data): void
{
    // Validate required fields
    $workShiftId = (int)($data['work_shift_id'] ?? 0);
    $requestType = trim($data['request_type'] ?? '');
    $reason = trim($data['reason'] ?? '');

    if ($workShiftId <= 0) {
        api_error('work_shift_id obbligatorio', 400);
    }

    $validTypes = ['change', 'swap', 'cancel'];
    if (!in_array($requestType, $validTypes)) {
        api_error('request_type non valido. Valori: change, swap, cancel', 400);
    }

    if ($reason === '') {
        api_error('Motivazione obbligatoria', 400);
    }

    if (strlen($reason) > 1000) {
        api_error('Motivazione troppo lunga (max 1000 caratteri)', 400);
    }

    // Verify shift exists, belongs to tenant, and user owns it (or is manager).
    // super_admin users must not be involved in shifts/requests.
    $shift = $db->fetchOne(
        "SELECT ws.*, u.role AS shift_user_role
         FROM work_shifts ws
         INNER JOIN users u ON u.id = ws.user_id AND u.deleted_at IS NULL
         WHERE ws.id = ? AND ws.tenant_id = ? AND ws.deleted_at IS NULL",
        [$workShiftId, $tenantId]
    );

    if (!$shift) {
        api_error('Turno non trovato', 404);
    }
    if (($shift['shift_user_role'] ?? '') === 'super_admin') {
        api_error('Non è possibile creare richieste per turni di utenti super_admin', 400);
    }

    // Regular users can only request changes for their own shifts
    $isManager = $isSuperAdmin || in_array($role, ['admin', 'manager']);
    if (!$isManager && (int)$shift['user_id'] !== $userId) {
        api_error('Puoi richiedere modifiche solo per i tuoi turni', 403);
    }

    // Check if there's already a pending request for this shift
    $existingPending = $db->fetchOne(
        "SELECT id FROM shift_change_requests
         WHERE work_shift_id = ? AND status = 'pending' AND deleted_at IS NULL",
        [$workShiftId]
    );
    if ($existingPending) {
        api_error('Esiste gia una richiesta pendente per questo turno', 409);
    }

    // Type-specific validations
    $newShiftTypeId = null;
    $targetUserId = null;
    $targetWorkShiftId = null;
    $preferredDate = null;

    if ($requestType === 'change') {
        // For change: new_shift_type_id is required
        $newShiftTypeId = (int)($data['new_shift_type_id'] ?? 0);
        if ($newShiftTypeId <= 0) {
            api_error('new_shift_type_id obbligatorio per richiesta cambio', 400);
        }

        // Verify new shift type exists
        $newType = $db->fetchOne(
            "SELECT id FROM shift_types
             WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL AND is_active = 1",
            [$newShiftTypeId, $tenantId]
        );
        if (!$newType) {
            api_error('Tipo turno richiesto non trovato o non attivo', 404);
        }

        // Optional preferred date
        if (isset($data['preferred_date']) && $data['preferred_date'] !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $data['preferred_date']);
            if ($dt) {
                $preferredDate = $data['preferred_date'];
            }
        }
    } elseif ($requestType === 'swap') {
        // For swap: target_user_id required
        $targetUserId = (int)($data['target_user_id'] ?? 0);
        if ($targetUserId <= 0) {
            api_error('target_user_id obbligatorio per richiesta scambio', 400);
        }

        // Cannot swap with yourself
        if ($targetUserId === (int)$shift['user_id']) {
            api_error('Non puoi scambiare il turno con te stesso', 400);
        }

        // Verify target user exists in tenant (and is not super_admin)
        $targetUser = $db->fetchOne(
            "SELECT id, role FROM users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$targetUserId, $tenantId]
        );
        if (!$targetUser) {
            api_error('Utente target non trovato', 404);
        }
        if (($targetUser['role'] ?? '') === 'super_admin') {
            api_error('Non puoi inviare richieste di scambio verso utenti super_admin', 400);
        }

        // Optional: specify which shift to swap with
        if (isset($data['target_work_shift_id']) && $data['target_work_shift_id'] !== '') {
            $targetWorkShiftId = (int)$data['target_work_shift_id'];
            $targetShift = $db->fetchOne(
                "SELECT id FROM work_shifts
                 WHERE id = ? AND tenant_id = ? AND user_id = ? AND deleted_at IS NULL",
                [$targetWorkShiftId, $tenantId, $targetUserId]
            );
            if (!$targetShift) {
                api_error('Turno target non trovato', 404);
            }
        }
    }
    // For cancel: no additional fields needed

    $db->beginTransaction();

    try {
        $insertData = [
            'tenant_id' => $tenantId,
            'work_shift_id' => $workShiftId,
            'requester_id' => $userId,
            'request_type' => $requestType,
            'new_shift_type_id' => $newShiftTypeId,
            'target_user_id' => $targetUserId,
            'target_work_shift_id' => $targetWorkShiftId,
            'reason' => $reason,
            'preferred_date' => $preferredDate,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $requestId = $db->insert('shift_change_requests', $insertData);

        if (!$requestId) {
            throw new Exception('Errore creazione richiesta');
        }

        // Audit log (non-blocking)
        try {
            logRequestAudit($db, $tenantId, $userId, 'shift_request_created', $requestId, null, $insertData);
        } catch (Exception $e) {
            error_log('[Shift Requests] Audit log error: ' . $e->getMessage());
        }

        $db->commit();

        // Notify tenant managers (best-effort, never super_admin)
        try {
            ShiftNotificationHelper::notifyChangeRequestReceived((int)$requestId);
        } catch (Throwable $e) {
            error_log('[Shift Requests] notifyChangeRequestReceived failed: ' . $e->getMessage());
        }

        // Fetch created request with details
        $created = fetchRequestWithDetails($db, $requestId);

        api_success([
            'request' => $created,
            'request_id' => (int)$requestId
        ], 'Richiesta modifica turno creata con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * POST action=approve: Manager approva richiesta
 */
function handleApprove(Database $db, int $tenantId, int $userId, string $role, bool $isSuperAdmin, array $data): void
{
    // Permission check
    $isManager = $isSuperAdmin || in_array($role, ['admin', 'manager']);
    if (!$isManager) {
        api_error('Solo manager/admin possono approvare richieste', 403);
    }

    $requestId = (int)($data['id'] ?? 0);
    if ($requestId <= 0) {
        api_error('ID richiesta obbligatorio', 400);
    }

    // Verify request exists and is pending
    $request = $db->fetchOne(
        "SELECT * FROM shift_change_requests
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$requestId, $tenantId]
    );

    if (!$request) {
        api_error('Richiesta non trovata', 404);
    }

    if ($request['status'] !== 'pending') {
        api_error('La richiesta non e piu pendente', 400);
    }

    // For swap requests, target user must have accepted
    if ($request['request_type'] === 'swap' && $request['target_accepted'] !== 1) {
        api_error('Lo scambio non e stato ancora accettato dall\'utente target', 400);
    }

    $managerNotes = isset($data['manager_notes']) ? trim($data['manager_notes']) : null;

    $db->beginTransaction();

    try {
        // Update request status
        $db->update('shift_change_requests', [
            'status' => 'approved',
            'manager_id' => $userId,
            'decision_at' => date('Y-m-d H:i:s'),
            'manager_notes' => $managerNotes,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $requestId]);

        // Apply the change based on request type
        $workShiftId = (int)$request['work_shift_id'];
        $warnings = [];

        if ($request['request_type'] === 'change') {
            // Load current shift info (needed for overlap check)
            $ws = $db->fetchOne(
                "SELECT id, user_id, shift_date, start_time_override, end_time_override
                 FROM work_shifts
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
                 LIMIT 1",
                [$workShiftId, $tenantId]
            );
            if (!$ws) {
                api_error('Turno originale non trovato', 404);
            }
            $affectedUserId = (int)($ws['user_id'] ?? 0);
            $newDate = (string)($request['preferred_date'] ?: ($ws['shift_date'] ?? ''));

            // New shift type must exist and be active
            $newTypeId = (int)($request['new_shift_type_id'] ?? 0);
            $st = $db->fetchOne(
                "SELECT id, name, start_time, end_time
                 FROM shift_types
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL AND is_active = 1
                 LIMIT 1",
                [$newTypeId, $tenantId]
            );
            if (!$st) {
                api_error('Tipo turno richiesto non trovato o non attivo', 404);
            }

            // Overlap prevention
            $effStart = !empty($ws['start_time_override']) ? (string)$ws['start_time_override'] : (string)($st['start_time'] ?? '');
            $effEnd = !empty($ws['end_time_override']) ? (string)$ws['end_time_override'] : (string)($st['end_time'] ?? '');
            $newInterval = cnx_shift_build_interval($newDate, $effStart, $effEnd);
            $conflict = cnx_shift_find_overlap_conflict($db, $tenantId, $affectedUserId, $newInterval['start'], $newInterval['end'], $workShiftId);
            if ($conflict) {
                api_error(
                    'Conflitto turni: approvazione non possibile (l’utente ha già un turno sovrapposto)',
                    409,
                    $conflict
                );
            }

            // Update shift type (and optionally date)
            $updateShift = [
                'shift_type_id' => $request['new_shift_type_id'],
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $userId
            ];
            if ($request['preferred_date']) {
                $updateShift['shift_date'] = $request['preferred_date'];
            }
            $db->update('work_shifts', $updateShift, ['id' => $workShiftId]);

            // Weekly hours warning (48h threshold) — best-effort
            try {
                $wb = cnx_shift_week_bounds($newInterval['start']);
                $mins = cnx_shift_compute_week_minutes($db, $tenantId, $affectedUserId, $wb['start'], $wb['end'], 0);
                $hours = round($mins / 60, 2);
                if ($hours > 48.0) {
                    $warnings[] = "Attenzione: ore settimanali assegnate {$hours}h (>48h) per user_id={$affectedUserId} (settimana {$wb['start']->format('Y-m-d')})";
                }
            } catch (Throwable $e) {
                // non-blocking
            }

        } elseif ($request['request_type'] === 'swap') {
            // Swap users between shifts
            $targetShiftId = $request['target_work_shift_id'];
            $pair = $db->fetchAll(
                "SELECT
                    ws.id,
                    ws.user_id,
                    ws.shift_date,
                    COALESCE(ws.start_time_override, st.start_time) AS start_time,
                    COALESCE(ws.end_time_override, st.end_time) AS end_time,
                    st.name AS shift_name
                 FROM work_shifts ws
                 INNER JOIN shift_types st ON ws.shift_type_id = st.id
                 WHERE ws.tenant_id = ?
                   AND ws.deleted_at IS NULL
                   AND ws.id IN (?, ?)",
                [$tenantId, $workShiftId, (int)$targetShiftId]
            ) ?: [];

            $a = null; $b = null;
            foreach ($pair as $row) {
                if ((int)($row['id'] ?? 0) === (int)$workShiftId) $a = $row;
                if ((int)($row['id'] ?? 0) === (int)$targetShiftId) $b = $row;
            }
            if (!$a || !$b) {
                api_error('Turni per scambio non trovati', 404);
            }

            $aUser = (int)($a['user_id'] ?? 0);
            $bUser = (int)($b['user_id'] ?? 0);
            if ($aUser <= 0 || $bUser <= 0) {
                api_error('Dati turni non validi', 500);
            }

            // Safety: super_admin must never be assigned to shifts
            $roles = $db->fetchAll(
                "SELECT id, role FROM users WHERE id IN (?, ?) AND deleted_at IS NULL",
                [$aUser, $bUser]
            );
            foreach ($roles as $r) {
                if (($r['role'] ?? '') === 'super_admin') {
                    api_error('Operazione non valida: un utente super_admin risulta assegnato a un turno (non consentito).', 400);
                }
            }

            // Overlap prevention for the swap:
            // - shift A will be assigned to user B (ignore conflict with shift B itself)
            // - shift B will be assigned to user A (ignore conflict with shift A itself)
            $aInterval = cnx_shift_build_interval((string)$a['shift_date'], (string)$a['start_time'], (string)$a['end_time']);
            $bInterval = cnx_shift_build_interval((string)$b['shift_date'], (string)$b['start_time'], (string)$b['end_time']);

            $confB = cnx_shift_find_overlap_conflict($db, $tenantId, $bUser, $aInterval['start'], $aInterval['end'], (int)$workShiftId);
            if ($confB && (int)($confB['conflict_shift_id'] ?? 0) !== (int)$targetShiftId) {
                api_error('Conflitto turni: scambio non possibile (utente target avrebbe sovrapposizione)', 409, $confB);
            }
            $confA = cnx_shift_find_overlap_conflict($db, $tenantId, $aUser, $bInterval['start'], $bInterval['end'], (int)$targetShiftId);
            if ($confA && (int)($confA['conflict_shift_id'] ?? 0) !== (int)$workShiftId) {
                api_error('Conflitto turni: scambio non possibile (utente richiedente avrebbe sovrapposizione)', 409, $confA);
            }

            // Swap user_id
            $db->update('work_shifts', [
                'user_id' => $bUser,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $userId
            ], ['id' => $workShiftId]);

            $db->update('work_shifts', [
                'user_id' => $aUser,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $userId
            ], ['id' => $targetShiftId]);

            // Weekly hours warnings (both users) — best-effort
            try {
                $pairs = [];
                $pairs[] = ['uid' => $bUser, 'wb' => cnx_shift_week_bounds($aInterval['start'])];
                $pairs[] = ['uid' => $aUser, 'wb' => cnx_shift_week_bounds($bInterval['start'])];
                $seen = [];
                foreach ($pairs as $p) {
                    $k = $p['uid'] . '|' . $p['wb']['start']->format('Y-m-d');
                    if (isset($seen[$k])) continue;
                    $seen[$k] = true;
                    $mins = cnx_shift_compute_week_minutes($db, $tenantId, (int)$p['uid'], $p['wb']['start'], $p['wb']['end'], 0);
                    $hours = round($mins / 60, 2);
                    if ($hours > 48.0) {
                        $warnings[] = "Attenzione: ore settimanali assegnate {$hours}h (>48h) per user_id={$p['uid']} (settimana {$p['wb']['start']->format('Y-m-d')})";
                    }
                }
            } catch (Throwable $e) {
                // non-blocking
            }

        } elseif ($request['request_type'] === 'cancel') {
            // Cancel (soft-delete) the shift
            $db->update('work_shifts', [
                'status' => 'cancelled',
                'deleted_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $userId
            ], ['id' => $workShiftId]);
        }

        // Audit log
        try {
            logRequestAudit($db, $tenantId, $userId, 'shift_request_approved', $requestId, $request, [
                'status' => 'approved',
                'manager_notes' => $managerNotes
            ]);
        } catch (Exception $e) {
            error_log('[Shift Requests] Audit log error: ' . $e->getMessage());
        }

        $db->commit();

        // Notify requester (best-effort)
        try {
            ShiftNotificationHelper::notifyRequestApproved((int)$requestId, (int)$userId, (string)($managerNotes ?? ''));
        } catch (Throwable $e) {
            error_log('[Shift Requests] notifyRequestApproved failed: ' . $e->getMessage());
        }

        // Fetch updated request
        $updated = fetchRequestWithDetails($db, $requestId);

        api_success([
            'request' => $updated,
            'warnings' => $warnings,
        ], 'Richiesta approvata con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * POST action=reject: Manager rifiuta richiesta
 */
function handleReject(Database $db, int $tenantId, int $userId, string $role, bool $isSuperAdmin, array $data): void
{
    // Permission check
    $isManager = $isSuperAdmin || in_array($role, ['admin', 'manager']);
    if (!$isManager) {
        api_error('Solo manager/admin possono rifiutare richieste', 403);
    }

    $requestId = (int)($data['id'] ?? 0);
    if ($requestId <= 0) {
        api_error('ID richiesta obbligatorio', 400);
    }

    $managerNotes = trim($data['manager_notes'] ?? '');
    if ($managerNotes === '') {
        api_error('Motivazione rifiuto obbligatoria', 400);
    }

    // Verify request exists and is pending
    $request = $db->fetchOne(
        "SELECT * FROM shift_change_requests
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$requestId, $tenantId]
    );

    if (!$request) {
        api_error('Richiesta non trovata', 404);
    }

    if ($request['status'] !== 'pending') {
        api_error('La richiesta non e piu pendente', 400);
    }

    $db->beginTransaction();

    try {
        $db->update('shift_change_requests', [
            'status' => 'rejected',
            'manager_id' => $userId,
            'decision_at' => date('Y-m-d H:i:s'),
            'manager_notes' => $managerNotes,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $requestId]);

        // Audit log
        try {
            logRequestAudit($db, $tenantId, $userId, 'shift_request_rejected', $requestId, $request, [
                'status' => 'rejected',
                'manager_notes' => $managerNotes
            ]);
        } catch (Exception $e) {
            error_log('[Shift Requests] Audit log error: ' . $e->getMessage());
        }

        $db->commit();

        // Notify requester (best-effort)
        try {
            ShiftNotificationHelper::notifyRequestRejected((int)$requestId, (int)$userId, (string)$managerNotes);
        } catch (Throwable $e) {
            error_log('[Shift Requests] notifyRequestRejected failed: ' . $e->getMessage());
        }

        // Fetch updated request
        $updated = fetchRequestWithDetails($db, $requestId);

        api_success([
            'request' => $updated
        ], 'Richiesta rifiutata');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * POST action=cancel: User cancella propria richiesta pending
 */
function handleCancel(Database $db, int $tenantId, int $userId, string $role, bool $isSuperAdmin, array $data): void
{
    $requestId = (int)($data['id'] ?? 0);
    if ($requestId <= 0) {
        api_error('ID richiesta obbligatorio', 400);
    }

    // Verify request exists
    $request = $db->fetchOne(
        "SELECT * FROM shift_change_requests
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [$requestId, $tenantId]
    );

    if (!$request) {
        api_error('Richiesta non trovata', 404);
    }

    // Only requester can cancel (or manager)
    $isManager = $isSuperAdmin || in_array($role, ['admin', 'manager']);
    if (!$isManager && (int)$request['requester_id'] !== $userId) {
        api_error('Puoi cancellare solo le tue richieste', 403);
    }

    if ($request['status'] !== 'pending') {
        api_error('Solo richieste pendenti possono essere cancellate', 400);
    }

    $db->beginTransaction();

    try {
        $db->update('shift_change_requests', [
            'status' => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $requestId]);

        // Audit log
        try {
            logRequestAudit($db, $tenantId, $userId, 'shift_request_cancelled', $requestId, $request, [
                'status' => 'cancelled'
            ]);
        } catch (Exception $e) {
            error_log('[Shift Requests] Audit log error: ' . $e->getMessage());
        }

        $db->commit();

        api_success([
            'request_id' => $requestId
        ], 'Richiesta cancellata con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Fetch request with full details
 */
function fetchRequestWithDetails(Database $db, int $requestId): ?array
{
    $row = $db->fetchOne("
        SELECT
            scr.id,
            scr.tenant_id,
            scr.work_shift_id,
            scr.requester_id,
            scr.request_type,
            scr.new_shift_type_id,
            scr.target_user_id,
            scr.target_work_shift_id,
            scr.reason,
            scr.preferred_date,
            scr.status,
            scr.manager_id,
            scr.decision_at,
            scr.manager_notes,
            scr.target_accepted,
            scr.target_accepted_at,
            scr.target_notes,
            scr.created_at,
            scr.updated_at,
            ws.shift_date AS original_shift_date,
            COALESCE(ws.start_time_override, st.start_time) AS original_start_time,
            COALESCE(ws.end_time_override, st.end_time) AS original_end_time,
            st.name AS original_shift_name,
            st.color AS original_shift_color,
            u_req.name AS requester_name,
            u_req.email AS requester_email,
            st_new.name AS new_shift_name,
            st_new.color AS new_shift_color,
            u_target.name AS target_user_name,
            u_target.email AS target_user_email,
            u_mgr.name AS manager_name
        FROM shift_change_requests scr
        INNER JOIN work_shifts ws ON scr.work_shift_id = ws.id
        INNER JOIN shift_types st ON ws.shift_type_id = st.id
        INNER JOIN users u_req ON scr.requester_id = u_req.id
        LEFT JOIN shift_types st_new ON scr.new_shift_type_id = st_new.id
        LEFT JOIN users u_target ON scr.target_user_id = u_target.id
        LEFT JOIN users u_mgr ON scr.manager_id = u_mgr.id
        WHERE scr.id = ? AND scr.deleted_at IS NULL
    ", [$requestId]);

    if (!$row) {
        return null;
    }

    return formatRequest($row);
}

/**
 * Format request for API response
 */
function formatRequest(array $row): array
{
    $originalDate = $row['original_shift_date'] ?? null;
    $originalStart = $row['original_start_time'] ?? null;
    $originalEnd = $row['original_end_time'] ?? null;
    $originalName = $row['original_shift_name'] ?? null;

    // Flat fields (legacy/UI-compat): shifts.js expects these at top-level
    $flat = [
        'shift_date' => $originalDate,
        'start_time' => $originalStart,
        'end_time' => $originalEnd,
        'shift_name' => $originalName,
        'user_name' => $row['requester_name'] ?? null,
        // Optional UI helpers
        'target_user_name' => $row['target_user_name'] ?? null,
        'requested_shift_name' => $row['new_shift_name'] ?? null,
    ];

    return array_merge([
        'id' => (int)$row['id'],
        'tenant_id' => (int)$row['tenant_id'],
        'work_shift_id' => (int)$row['work_shift_id'],
        'requester_id' => (int)$row['requester_id'],
        'request_type' => $row['request_type'],
        'status' => $row['status'],
        'reason' => $row['reason'],
        'preferred_date' => $row['preferred_date'],
        'manager_notes' => $row['manager_notes'],
        'decision_at' => $row['decision_at'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        // Original shift
        'original_shift' => [
            'date' => $row['original_shift_date'] ?? null,
            'start_time' => $row['original_start_time'] ?? null,
            'end_time' => $row['original_end_time'] ?? null,
            'name' => $row['original_shift_name'] ?? null,
            'color' => $row['original_shift_color'] ?? '#3B82F6'
        ],
        // Requester
        'requester' => [
            'id' => (int)$row['requester_id'],
            'name' => $row['requester_name'] ?? null,
            'email' => $row['requester_email'] ?? null
        ],
        // For change requests
        'new_shift_type' => $row['new_shift_type_id'] ? [
            'id' => (int)$row['new_shift_type_id'],
            'name' => $row['new_shift_name'] ?? null,
            'color' => $row['new_shift_color'] ?? '#3B82F6'
        ] : null,
        // For swap requests
        'target_user' => $row['target_user_id'] ? [
            'id' => (int)$row['target_user_id'],
            'name' => $row['target_user_name'] ?? null,
            'email' => $row['target_user_email'] ?? null,
            'accepted' => $row['target_accepted'] !== null ? (bool)$row['target_accepted'] : null,
            'accepted_at' => $row['target_accepted_at'] ?? null,
            'notes' => $row['target_notes'] ?? null
        ] : null,
        'target_work_shift_id' => $row['target_work_shift_id'] ? (int)$row['target_work_shift_id'] : null,
        // Manager
        'manager' => $row['manager_id'] ? [
            'id' => (int)$row['manager_id'],
            'name' => $row['manager_name'] ?? null
        ] : null
    ], $flat);
}

/**
 * Log request-related audit entry (non-blocking)
 */
function logRequestAudit(Database $db, int $tenantId, int $userId, string $action, int $entityId, ?array $oldData, ?array $newData): void
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
        'entity_type' => 'shift_change_requests',
        'entity_id' => $entityId,
        'old_values' => $oldData ? json_encode($oldData) : null,
        'new_values' => $newData ? json_encode($newData) : null,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'created_at' => date('Y-m-d H:i:s')
    ]);
}
