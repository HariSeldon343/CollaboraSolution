<?php
/**
 * Dashboard API - Get Main Statistics
 *
 * Returns the 4 main dashboard statistics in a single API call:
 * - Active projects count
 * - Completed tasks count (last 30 days)
 * - Upcoming deadlines (next 7 days)
 * - Active team members count
 *
 * Method: GET
 * Parameters:
 *   - tenant_id (optional, for super_admin only)
 *   - period (optional: 7d, 30d, this_month) default: 30d
 * Response: Dashboard statistics object
 *
 * @package CollaboraNexio
 * @subpackage Dashboard API
 * @version 1.0.0
 * @since 2025-11-16
 */

declare(strict_types=1);

// API Authentication (BUG-011 pattern)
require_once __DIR__ . '/../../includes/api_auth.php';

initializeApiEnvironment();

// Force no-cache headers (BUG-040)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

verifyApiAuthentication();  // IMMEDIATELY after init

$userInfo = getApiUserInfo();
$userId = $userInfo['user_id'];  // BUG-070: both 'id' and 'user_id' required
$userRole = $userInfo['role'];

// No CSRF required for GET requests (read-only operation)

// Database connection
require_once __DIR__ . '/../../includes/db.php';
$db = Database::getInstance();

// ============================================
// REQUEST VALIDATION
// ============================================

// Only GET method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Metodo non consentito', 405);
}

// Multi-tenant parameter pattern (BUG-066)
$requestedTenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : null;

if ($requestedTenantId !== null) {
    if ($userRole === 'super_admin') {
        $tenantId = $requestedTenantId;
    } else {
        // Validate via user_tenant_access
        $accessCheck = $db->fetchOne(
            "SELECT COUNT(*) as cnt FROM user_tenant_access
             WHERE user_id = ? AND tenant_id = ?
             AND (deleted_at IS NULL OR deleted_at = '')",  // BUG-090: check both NULL and empty string
            [$userId, $requestedTenantId]
        );
        if ($accessCheck && $accessCheck['cnt'] > 0) {
            $tenantId = $requestedTenantId;
        } else {
            api_error('Non hai accesso a questo tenant', 403);
        }
    }
} else {
    $tenantId = $userInfo['tenant_id'];
}

// Period parameter (optional)
$period = isset($_GET['period']) ? $_GET['period'] : '30d';
$validPeriods = ['7d', '30d', 'this_month'];
if (!in_array($period, $validPeriods)) {
    $period = '30d';
}

// ============================================
// FETCH STATISTICS
// ============================================

try {
    // Start defensive transaction management (BUG-070 Phase 3 pattern)
    if ($db->inTransaction()) {
        error_log('[DASHBOARD API] Warning: Transaction already active at start');
        $db->rollback();
    }

    // CARD 1: Active Projects Count
    $activeProjectsSql = "
        SELECT COUNT(*) as active_projects
        FROM projects
        WHERE tenant_id = ?
          AND status NOT IN ('completed', 'cancelled')
          AND (deleted_at IS NULL OR deleted_at = '')
    ";
    $activeProjectsResult = $db->fetchOne($activeProjectsSql, [$tenantId]);
    $activeProjects = $activeProjectsResult ? (int)$activeProjectsResult['active_projects'] : 0;

    // CARD 2: Completed Tasks Count (based on period)
    $completedTasksSql = "";
    $completedTasksParams = [$tenantId];

    if ($period === 'this_month') {
        $completedTasksSql = "
            SELECT COUNT(*) as completed_tasks
            FROM tasks
            WHERE tenant_id = ?
              AND status = 'done'
              AND MONTH(completed_at) = MONTH(NOW())
              AND YEAR(completed_at) = YEAR(NOW())
              AND (deleted_at IS NULL OR deleted_at = '')
        ";
    } else {
        $days = ($period === '7d') ? 7 : 30;
        $completedTasksSql = "
            SELECT COUNT(*) as completed_tasks
            FROM tasks
            WHERE tenant_id = ?
              AND status = 'done'
              AND completed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND (deleted_at IS NULL OR deleted_at = '')
        ";
        $completedTasksParams[] = $days;
    }

    $completedTasksResult = $db->fetchOne($completedTasksSql, $completedTasksParams);
    $completedTasks = $completedTasksResult ? (int)$completedTasksResult['completed_tasks'] : 0;

    // CARD 3: Upcoming Deadlines (next 7 days + overdue)
    $upcomingDeadlinesSql = "
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN due_date < NOW() THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN due_date >= NOW() AND due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as upcoming
        FROM tasks
        WHERE tenant_id = ?
          AND status NOT IN ('done', 'cancelled')
          AND due_date IS NOT NULL
          AND due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
          AND (deleted_at IS NULL OR deleted_at = '')
    ";
    $deadlinesResult = $db->fetchOne($upcomingDeadlinesSql, [$tenantId]);
    $upcomingDeadlines = $deadlinesResult ? (int)$deadlinesResult['total'] : 0;
    $overdueCount = $deadlinesResult ? (int)$deadlinesResult['overdue'] : 0;
    $upcomingCount = $deadlinesResult ? (int)$deadlinesResult['upcoming'] : 0;

    // CARD 4: Active Team Members (including multi-tenant access)
    $activeMembersSql = "
        SELECT COUNT(DISTINCT u.id) as active_members
        FROM users u
        LEFT JOIN user_tenant_access uta ON u.id = uta.user_id
        WHERE (u.tenant_id = ? OR uta.tenant_id = ?)
          AND u.is_active = 1
          AND (u.deleted_at IS NULL OR u.deleted_at = '')
          AND (uta.deleted_at IS NULL OR uta.deleted_at = '' OR uta.user_id IS NULL)
    ";
    $activeMembersResult = $db->fetchOne($activeMembersSql, [$tenantId, $tenantId]);
    $activeMembers = $activeMembersResult ? (int)$activeMembersResult['active_members'] : 0;

    // Additional metadata for frontend
    $metadata = [
        'period' => $period,
        'period_label' => match($period) {
            '7d' => 'Ultimi 7 giorni',
            '30d' => 'Ultimi 30 giorni',
            'this_month' => 'Questo mese',
            default => 'Ultimi 30 giorni'
        },
        'tenant_id' => $tenantId,
        'generated_at' => date('Y-m-d H:i:s')
    ];

    // API Response with named key (BUG-066 pattern)
    api_success([
        'stats' => [
            'active_projects' => $activeProjects,
            'completed_tasks' => $completedTasks,
            'upcoming_deadlines' => $upcomingDeadlines,
            'upcoming_deadlines_detail' => [
                'total' => $upcomingDeadlines,
                'overdue' => $overdueCount,
                'upcoming' => $upcomingCount
            ],
            'active_members' => $activeMembers
        ],
        'metadata' => $metadata
    ], 'Statistiche dashboard caricate con successo');

} catch (Exception $e) {
    error_log('[DASHBOARD API] Error loading stats: ' . $e->getMessage());

    // Defensive rollback if transaction is active
    if ($db->inTransaction()) {
        $db->rollback();
    }

    api_error('Errore durante il caricamento delle statistiche', 500);
}