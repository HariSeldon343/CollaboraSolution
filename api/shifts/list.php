<?php
/**
 * API Endpoint: Work Shifts List (Lista Turni)
 * Recupera i turni assegnati con filtri per calendario e utente
 *
 * @method GET - Lista turni per tenant/utente con range date
 *
 * Query Parameters:
 * - tenant_id (int, optional): Target tenant (super_admin puo vedere tutti)
 * - start_date (date, required): Data inizio range (Y-m-d)
 * - end_date (date, required): Data fine range (Y-m-d)
 * - user_id (int, optional): Filtra per utente specifico
 * - status (string, optional): Filtra per status (scheduled, confirmed, etc.)
 * - shift_type_id (int, optional): Filtra per tipo turno
 *
 * @response {
 *   success: true,
 *   data: {
 *     shifts: [{id, shift_date, start_time, end_time, shift_name, color, user_name, status, ...}],
 *     tenant_id: int,
 *     total: int
 *   }
 * }
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

    // Verify CSRF
    verifyApiCsrfToken();

    // Get database instance
    $db = Database::getInstance();
    require_once __DIR__ . '/../../includes/shift_permissions_helper.php';

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

    // Determine target tenant_id
    $targetTenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
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

    // For admin/manager, listing all shifts is considered a management capability and can be disabled per-tenant.
    $shiftPerms = cnx_get_shift_permissions_for_tenant($db, $targetTenantId);
    $canManageShifts = $isSuperAdmin || in_array($currentUserRole, $shiftPerms['can_manage_shifts_roles'], true);

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

    // Validate date range parameters
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;

    if (!$startDate || !$endDate) {
        api_error('start_date e end_date obbligatori', 400);
    }

    // Validate date format
    $startDt = DateTime::createFromFormat('Y-m-d', $startDate);
    $endDt = DateTime::createFromFormat('Y-m-d', $endDate);

    if (!$startDt || !$endDt) {
        api_error('Formato data non valido (Y-m-d)', 400);
    }

    // Limit range to max 3 months for performance
    $interval = $startDt->diff($endDt);
    if ($interval->days > 93) {
        api_error('Range date massimo 3 mesi', 400);
    }

    // Build query conditions
    $whereConditions = [
        'ws.tenant_id = ?',
        'ws.deleted_at IS NULL',
        'ws.shift_date >= ?',
        'ws.shift_date <= ?'
    ];
    $params = [$targetTenantId, $startDate, $endDate];

    // Dashboard safety: when mine=1 is passed, ALWAYS restrict to current user
    // (prevents leaking other users' shifts in dashboard widgets regardless of role).
    $mineOnly = isset($_GET['mine']) && ($_GET['mine'] === '1' || $_GET['mine'] === 'true');
    if ($mineOnly) {
        $whereConditions[] = 'ws.user_id = ?';
        $params[] = $currentUserId;
    } else {
    // For regular users: only their own shifts
    // For admin/manager: if management is disabled, restrict to own shifts too.
    if (!$isSuperAdmin && (!in_array($currentUserRole, ['admin', 'manager'], true) || !$canManageShifts)) {
        $whereConditions[] = 'ws.user_id = ?';
        $params[] = $currentUserId;
    } else {
        // Admin/manager can filter by user_id
        if (isset($_GET['user_id']) && $_GET['user_id'] !== '') {
            $filterUserId = (int)$_GET['user_id'];
            if ($filterUserId > 0) {
                $whereConditions[] = 'ws.user_id = ?';
                $params[] = $filterUserId;
            }
        }
    }
    }

    // Optional status filter
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $validStatuses = ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'];
        $filterStatus = $_GET['status'];
        if (in_array($filterStatus, $validStatuses)) {
            $whereConditions[] = 'ws.status = ?';
            $params[] = $filterStatus;
        }
    }

    // Optional shift_type_id filter
    if (isset($_GET['shift_type_id']) && $_GET['shift_type_id'] !== '') {
        $filterTypeId = (int)$_GET['shift_type_id'];
        if ($filterTypeId > 0) {
            $whereConditions[] = 'ws.shift_type_id = ?';
            $params[] = $filterTypeId;
        }
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Main query with JOIN to shift_types and users
    $query = "
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
            st.icon AS shift_icon,
            u.name AS user_name,
            u.email AS user_email
        FROM work_shifts ws
        INNER JOIN shift_types st ON ws.shift_type_id = st.id
        INNER JOIN users u ON ws.user_id = u.id
        WHERE {$whereClause}
        ORDER BY ws.shift_date ASC, start_time ASC, u.name ASC
    ";

    $shifts = $db->fetchAll($query, $params);

    // Format response for calendar compatibility
    $formattedShifts = [];
    foreach ($shifts as $shift) {
        // Build datetime for calendar events
        $shiftDate = $shift['shift_date'];
        $startTime = $shift['start_time'];
        $endTime = $shift['end_time'];

        // Handle overnight shifts (end_time < start_time)
        $startDateTime = $shiftDate . ' ' . $startTime;
        if ($endTime < $startTime) {
            // Shift ends next day
            $nextDay = date('Y-m-d', strtotime($shiftDate . ' +1 day'));
            $endDateTime = $nextDay . ' ' . $endTime;
        } else {
            $endDateTime = $shiftDate . ' ' . $endTime;
        }

        $formattedShifts[] = [
            'id' => (int)$shift['id'],
            'tenant_id' => (int)$shift['tenant_id'],
            'shift_type_id' => (int)$shift['shift_type_id'],
            'user_id' => (int)$shift['user_id'],
            'shift_date' => $shiftDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'start_datetime' => $startDateTime,
            'end_datetime' => $endDateTime,
            'has_override' => ($shift['start_time_override'] !== null || $shift['end_time_override'] !== null),
            'status' => $shift['status'],
            'notes' => $shift['notes'],
            'user_notes' => $shift['user_notes'],
            'actual_start_time' => $shift['actual_start_time'],
            'actual_end_time' => $shift['actual_end_time'],
            'shift_name' => $shift['shift_name'],
            'shift_code' => $shift['shift_code'],
            'color' => $shift['shift_color'] ?? '#3B82F6',
            'icon' => $shift['shift_icon'],
            'user_name' => $shift['user_name'],
            'user_email' => $shift['user_email'],
            'created_at' => $shift['created_at'],
            'updated_at' => $shift['updated_at'],
            // Calendar event compatible fields
            'title' => $shift['shift_name'] . ' - ' . $shift['user_name'],
            'start' => $startDateTime,
            'end' => $endDateTime,
            'backgroundColor' => $shift['shift_color'] ?? '#3B82F6',
            'borderColor' => $shift['shift_color'] ?? '#3B82F6',
            'extendedProps' => [
                'type' => 'work_shift',
                'status' => $shift['status'],
                'user_id' => (int)$shift['user_id'],
                'shift_type_id' => (int)$shift['shift_type_id']
            ]
        ];
    }

    // Get summary stats (optional, for dashboard)
    $includeSummary = isset($_GET['include_summary']) &&
                      filter_var($_GET['include_summary'], FILTER_VALIDATE_BOOLEAN);

    $summary = null;
    if ($includeSummary) {
        $summary = [
            'total_shifts' => count($formattedShifts),
            'by_status' => [],
            'by_type' => []
        ];

        // Count by status
        $statusCounts = [];
        $typeCounts = [];
        foreach ($formattedShifts as $s) {
            $status = $s['status'];
            $typeName = $s['shift_name'];
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $typeCounts[$typeName] = ($typeCounts[$typeName] ?? 0) + 1;
        }
        $summary['by_status'] = $statusCounts;
        $summary['by_type'] = $typeCounts;
    }

    $response = [
        'shifts' => $formattedShifts,
        'tenant_id' => $targetTenantId,
        'date_range' => [
            'start' => $startDate,
            'end' => $endDate
        ],
        'total' => count($formattedShifts)
    ];

    if ($summary !== null) {
        $response['summary'] = $summary;
    }

    api_success($response, 'Turni recuperati con successo');

} catch (PDOException $e) {
    error_log('[Shifts List API] PDO Error: ' . $e->getMessage());
    api_error('Errore database', 500);

} catch (Throwable $e) {
    error_log('[Shifts List API] Error: ' . $e->getMessage());
    api_error('Errore interno del server', 500);
}
