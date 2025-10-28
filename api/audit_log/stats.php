<?php
/**
 * Audit Log Statistics API Endpoint
 *
 * Returns dashboard statistics for audit logs
 *
 * Method: GET
 * Auth: Required (admin, super_admin)
 *
 * Response:
 * {
 *   "success": true,
 *   "data": {
 *     "events_today": 1234,
 *     "active_users": 45,
 *     "accesses_today": 890,
 *     "modifications_today": 234,
 *     "critical_events": 5,
 *     "total_logs": 125000,
 *     "deletions_stats": {
 *       "total_deletions": 10,
 *       "logs_deleted": 5000
 *     }
 *   }
 * }
 */

declare(strict_types=1);

// Load required dependencies
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';

// 1. Initialize API environment
initializeApiEnvironment();

// 2. IMMEDIATELY verify authentication (BEFORE any operations!)
verifyApiAuthentication();

// 3. Get user info
$userInfo = getApiUserInfo();

// 4. Role-based access control: Only admin and super_admin can view stats
if (!in_array($userInfo['role'], ['admin', 'super_admin'])) {
    api_error('Accesso negato. Solo admin e super_admin possono visualizzare le statistiche.', 403);
}

// 5. Get database instance
$db = Database::getInstance();

try {
    // Determine tenant filter
    $tenant_filter = '';
    $tenant_params = [];

    if ($userInfo['role'] !== 'super_admin') {
        // Regular admin: enforce tenant isolation
        $tenant_filter = 'AND tenant_id = ?';
        $tenant_params[] = $userInfo['tenant_id'];
    }
    // Super admin sees all tenants

    // Statistic 1: Events today (all actions)
    $events_today_query = "
        SELECT COUNT(*) as count
        FROM audit_logs
        WHERE created_at >= CURDATE()
          AND deleted_at IS NULL
          {$tenant_filter}
    ";
    $events_today = (int)$db->fetchOne($events_today_query, $tenant_params)['count'];

    // Statistic 2: Active users (distinct users in last 24 hours)
    $active_users_query = "
        SELECT COUNT(DISTINCT user_id) as count
        FROM audit_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
          AND deleted_at IS NULL
          AND user_id IS NOT NULL
          {$tenant_filter}
    ";
    $active_users = (int)$db->fetchOne($active_users_query, $tenant_params)['count'];

    // Statistic 3: Accesses today (login actions)
    $accesses_today_query = "
        SELECT COUNT(*) as count
        FROM audit_logs
        WHERE created_at >= CURDATE()
          AND deleted_at IS NULL
          AND action IN ('login', 'user_login', 'authentication_success')
          {$tenant_filter}
    ";
    $accesses_today = (int)$db->fetchOne($accesses_today_query, $tenant_params)['count'];

    // Statistic 4: Modifications today (create, update, delete actions)
    $modifications_today_query = "
        SELECT COUNT(*) as count
        FROM audit_logs
        WHERE created_at >= CURDATE()
          AND deleted_at IS NULL
          AND action IN ('create', 'update', 'delete', 'file_uploaded', 'file_deleted', 'file_modified')
          {$tenant_filter}
    ";
    $modifications_today = (int)$db->fetchOne($modifications_today_query, $tenant_params)['count'];

    // Statistic 5: Critical events (severity = critical)
    $critical_events_query = "
        SELECT COUNT(*) as count
        FROM audit_logs
        WHERE severity = 'critical'
          AND deleted_at IS NULL
          {$tenant_filter}
    ";
    $critical_events = (int)$db->fetchOne($critical_events_query, $tenant_params)['count'];

    // Statistic 6: Total active logs (not deleted)
    $total_logs_query = "
        SELECT COUNT(*) as count
        FROM audit_logs
        WHERE deleted_at IS NULL
          {$tenant_filter}
    ";
    $total_logs = (int)$db->fetchOne($total_logs_query, $tenant_params)['count'];

    // Statistic 7: Deletion statistics using stored function
    $deletions_stats = ['total_deletions' => 0, 'logs_deleted' => 0];

    if ($userInfo['role'] === 'super_admin') {
        // Use stored function to get deletion stats
        // Note: Function returns JSON string
        $tenant_id_for_stats = $userInfo['tenant_id'] ?? 1; // Use specific tenant or first tenant

        try {
            $deletion_stats_result = $db->fetchOne(
                'SELECT get_deletion_stats(?) as stats',
                [$tenant_id_for_stats]
            );

            if ($deletion_stats_result && $deletion_stats_result['stats']) {
                $deletion_stats_json = json_decode($deletion_stats_result['stats'], true);
                if ($deletion_stats_json) {
                    $deletions_stats = [
                        'total_deletions' => (int)($deletion_stats_json['total_deletions'] ?? 0),
                        'logs_deleted' => (int)($deletion_stats_json['total_logs_deleted'] ?? 0),
                        'last_deletion_date' => $deletion_stats_json['last_deletion_date'] ?? null,
                        'notifications_sent' => (int)($deletion_stats_json['notifications_sent'] ?? 0),
                        'notifications_failed' => (int)($deletion_stats_json['notifications_failed'] ?? 0)
                    ];
                }
            }
        } catch (Exception $e) {
            // If function doesn't exist or fails, fallback to direct query
            error_log('Deletion stats function failed, using fallback: ' . $e->getMessage());

            $direct_stats = $db->fetchOne("
                SELECT
                    COUNT(*) as total_deletions,
                    COALESCE(SUM(deleted_count), 0) as logs_deleted
                FROM audit_log_deletions
                WHERE tenant_id = ?
            ", [$tenant_id_for_stats]);

            $deletions_stats = [
                'total_deletions' => (int)$direct_stats['total_deletions'],
                'logs_deleted' => (int)$direct_stats['logs_deleted']
            ];
        }
    }

    // Additional stats: Events by severity (today)
    $events_by_severity_query = "
        SELECT
            severity,
            COUNT(*) as count
        FROM audit_logs
        WHERE created_at >= CURDATE()
          AND deleted_at IS NULL
          {$tenant_filter}
        GROUP BY severity
    ";
    $events_by_severity_raw = $db->fetchAll($events_by_severity_query, $tenant_params);

    $events_by_severity = [
        'info' => 0,
        'warning' => 0,
        'error' => 0,
        'critical' => 0
    ];

    foreach ($events_by_severity_raw as $row) {
        $events_by_severity[$row['severity']] = (int)$row['count'];
    }

    // Additional stats: Top actions (today)
    $top_actions_query = "
        SELECT
            action,
            COUNT(*) as count
        FROM audit_logs
        WHERE created_at >= CURDATE()
          AND deleted_at IS NULL
          {$tenant_filter}
        GROUP BY action
        ORDER BY count DESC
        LIMIT 10
    ";
    $top_actions_raw = $db->fetchAll($top_actions_query, $tenant_params);

    $top_actions = array_map(function($row) {
        return [
            'action' => $row['action'],
            'count' => (int)$row['count']
        ];
    }, $top_actions_raw);

    // Build response
    $stats = [
        'events_today' => $events_today,
        'active_users' => $active_users,
        'accesses_today' => $accesses_today,
        'modifications_today' => $modifications_today,
        'critical_events' => $critical_events,
        'total_logs' => $total_logs,
        'deletions_stats' => $deletions_stats,
        'events_by_severity' => $events_by_severity,
        'top_actions' => $top_actions,
        'generated_at' => date('Y-m-d H:i:s')
    ];

    // Success response
    api_success($stats, 'Statistiche recuperate con successo');

} catch (PDOException $e) {
    // Database error
    error_log('Audit Log Stats Error: ' . $e->getMessage());
    api_error('Errore durante il recupero delle statistiche: ' . $e->getMessage(), 500);

} catch (Exception $e) {
    // Generic error
    error_log('Audit Log Stats Error: ' . $e->getMessage());
    api_error('Errore imprevisto: ' . $e->getMessage(), 500);
}
