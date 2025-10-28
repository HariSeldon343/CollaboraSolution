<?php
/**
 * Ticket Statistics API Endpoint
 * GET /api/tickets/stats.php
 *
 * Returns dashboard statistics for tickets
 * - Count by status, category, urgency
 * - Average response/resolution times
 * - Open tickets by assignee
 *
 * RBAC: Super_admin sees all stats, others see their own
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';

// Initialize API environment
initializeApiEnvironment();

// IMMEDIATELY verify authentication (CRITICAL - BUG-011 compliance)
verifyApiAuthentication();

// Get user context
$userInfo = getApiUserInfo();
$db = Database::getInstance();

try {
    // Build base WHERE clause
    $where = ['tenant_id = ?', 'deleted_at IS NULL'];
    $params = [$userInfo['tenant_id']];

    // RBAC: Non-admin users see only their own tickets
    if (!in_array($userInfo['role'], ['admin', 'super_admin'])) {
        $where[] = 'created_by = ?';
        $params[] = $userInfo['user_id'];
    }

    $whereClause = implode(' AND ', $where);

    // Count by status
    $statusCountsSql = "
        SELECT status, COUNT(*) as count
        FROM tickets
        WHERE $whereClause
        GROUP BY status
    ";
    $statusCounts = [];
    $statusResults = $db->fetchAll($statusCountsSql, $params);
    foreach ($statusResults as $row) {
        $statusCounts[$row['status']] = (int)$row['count'];
    }

    // Count by category
    $categoryCountsSql = "
        SELECT category, COUNT(*) as count
        FROM tickets
        WHERE $whereClause
        GROUP BY category
    ";
    $categoryCounts = [];
    $categoryResults = $db->fetchAll($categoryCountsSql, $params);
    foreach ($categoryResults as $row) {
        $categoryCounts[$row['category']] = (int)$row['count'];
    }

    // Count by urgency
    $urgencyCountsSql = "
        SELECT urgency, COUNT(*) as count
        FROM tickets
        WHERE $whereClause
        GROUP BY urgency
    ";
    $urgencyCounts = [];
    $urgencyResults = $db->fetchAll($urgencyCountsSql, $params);
    foreach ($urgencyResults as $row) {
        $urgencyCounts[$row['urgency']] = (int)$row['count'];
    }

    // Average response time (in minutes)
    $avgResponseSql = "
        SELECT AVG(first_response_time) as avg_time
        FROM tickets
        WHERE $whereClause
          AND first_response_time IS NOT NULL
    ";
    $avgResponseResult = $db->fetchOne($avgResponseSql, $params);
    $avgResponseTime = $avgResponseResult['avg_time'] ? round((float)$avgResponseResult['avg_time'], 2) : 0;

    // Average resolution time (in minutes)
    $avgResolutionSql = "
        SELECT AVG(resolution_time_minutes) as avg_time
        FROM tickets
        WHERE $whereClause
          AND resolution_time_minutes IS NOT NULL
    ";
    $avgResolutionResult = $db->fetchOne($avgResolutionSql, $params);
    $avgResolutionTime = $avgResolutionResult['avg_time'] ? round((float)$avgResolutionResult['avg_time'], 2) : 0;

    // Open tickets by assignee (admin+ only)
    $ticketsByAssignee = [];
    if (in_array($userInfo['role'], ['admin', 'super_admin'])) {
        $byAssigneeSql = "
            SELECT
                assigned_to,
                u.name as assignee_name,
                COUNT(*) as count
            FROM tickets t
            LEFT JOIN users u ON t.assigned_to = u.id AND u.deleted_at IS NULL
            WHERE $whereClause
              AND t.status IN ('open', 'in_progress', 'waiting_response')
            GROUP BY assigned_to, u.name
            ORDER BY count DESC
        ";
        $assigneeResults = $db->fetchAll($byAssigneeSql, $params);
        foreach ($assigneeResults as $row) {
            $ticketsByAssignee[] = [
                'user_id' => $row['assigned_to'],
                'name' => $row['assignee_name'] ?? 'Non assegnato',
                'count' => (int)$row['count']
            ];
        }
    }

    // Recent activity (last 7 days)
    $recentActivitySql = "
        SELECT
            DATE(created_at) as date,
            COUNT(*) as count
        FROM tickets
        WHERE $whereClause
          AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ";
    $recentActivity = $db->fetchAll($recentActivitySql, $params);
    foreach ($recentActivity as &$day) {
        $day['count'] = (int)$day['count'];
    }

    // Total tickets
    $totalSql = "SELECT COUNT(*) as total FROM tickets WHERE $whereClause";
    $totalResult = $db->fetchOne($totalSql, $params);
    $totalTickets = (int)$totalResult['total'];

    // Open tickets
    $openWhere = array_merge($where, ["status IN ('open', 'in_progress', 'waiting_response')"]);
    $openWhereClause = implode(' AND ', $openWhere);
    $openSql = "SELECT COUNT(*) as total FROM tickets WHERE $openWhereClause";
    $openResult = $db->fetchOne($openSql, $params);
    $openTickets = (int)$openResult['total'];

    // Resolved tickets
    $resolvedWhere = array_merge($where, ["status = 'resolved'"]);
    $resolvedWhereClause = implode(' AND ', $resolvedWhere);
    $resolvedSql = "SELECT COUNT(*) as total FROM tickets WHERE $resolvedWhereClause";
    $resolvedResult = $db->fetchOne($resolvedSql, $params);
    $resolvedTickets = (int)$resolvedResult['total'];

    // Closed tickets
    $closedWhere = array_merge($where, ["status = 'closed'"]);
    $closedWhereClause = implode(' AND ', $closedWhere);
    $closedSql = "SELECT COUNT(*) as total FROM tickets WHERE $closedWhereClause";
    $closedResult = $db->fetchOne($closedSql, $params);
    $closedTickets = (int)$closedResult['total'];

    // Return response (BUG-022 compliant)
    api_success([
        'summary' => [
            'total' => $totalTickets,
            'open' => $openTickets,
            'resolved' => $resolvedTickets,
            'closed' => $closedTickets
        ],
        'by_status' => $statusCounts,
        'by_category' => $categoryCounts,
        'by_urgency' => $urgencyCounts,
        'avg_response_time_minutes' => $avgResponseTime,
        'avg_resolution_time_hours' => $avgResolutionTime,
        'by_assignee' => $ticketsByAssignee,
        'recent_activity' => $recentActivity
    ], 'Statistiche recuperate con successo');

} catch (Exception $e) {
    error_log("Ticket stats error: " . $e->getMessage());
    api_error('Errore nel recupero delle statistiche: ' . $e->getMessage(), 500);
}
