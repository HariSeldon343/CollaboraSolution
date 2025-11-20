<?php
/**
 * Dashboard API - Get Active Projects
 *
 * Returns active projects with progress percentage,
 * task counts, and status badges
 *
 * Method: GET
 * Parameters:
 *   - tenant_id (optional, for super_admin only)
 *   - limit (optional, default: 10, max: 25)
 * Response: Array of active projects with metadata
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

// Limit parameter (optional)
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($limit < 1 || $limit > 25) {
    $limit = 10;
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Get status badge configuration
 *
 * @param string $status Project status
 * @return array Badge configuration
 */
function getStatusBadge(string $status): array {
    $badges = [
        'planning' => [
            'label' => 'Pianificazione',
            'class' => 'badge-secondary',
            'icon' => 'fas fa-pencil-ruler'
        ],
        'active' => [
            'label' => 'Attivo',
            'class' => 'badge-success',
            'icon' => 'fas fa-play-circle'
        ],
        'on_hold' => [
            'label' => 'In Pausa',
            'class' => 'badge-warning',
            'icon' => 'fas fa-pause-circle'
        ],
        'completed' => [
            'label' => 'Completato',
            'class' => 'badge-primary',
            'icon' => 'fas fa-check-circle'
        ],
        'cancelled' => [
            'label' => 'Annullato',
            'class' => 'badge-danger',
            'icon' => 'fas fa-times-circle'
        ]
    ];

    return $badges[$status] ?? [
        'label' => ucfirst($status),
        'class' => 'badge-light',
        'icon' => 'fas fa-circle'
    ];
}

/**
 * Get priority badge configuration
 *
 * @param string $priority Project priority
 * @return array Badge configuration
 */
function getPriorityBadge(string $priority): array {
    $badges = [
        'critical' => [
            'label' => 'Critica',
            'class' => 'badge-danger',
            'icon' => 'fas fa-fire'
        ],
        'high' => [
            'label' => 'Alta',
            'class' => 'badge-warning',
            'icon' => 'fas fa-exclamation-triangle'
        ],
        'medium' => [
            'label' => 'Media',
            'class' => 'badge-info',
            'icon' => 'fas fa-minus-circle'
        ],
        'low' => [
            'label' => 'Bassa',
            'class' => 'badge-secondary',
            'icon' => 'fas fa-chevron-down'
        ]
    ];

    return $badges[$priority] ?? [
        'label' => ucfirst($priority),
        'class' => 'badge-light',
        'icon' => 'fas fa-circle'
    ];
}

/**
 * Calculate progress color based on percentage
 *
 * @param int $progress Progress percentage
 * @return string CSS color class
 */
function getProgressColor(int $progress): string {
    if ($progress >= 80) {
        return 'progress-bar-success';
    } elseif ($progress >= 60) {
        return 'progress-bar-info';
    } elseif ($progress >= 40) {
        return 'progress-bar-warning';
    } else {
        return 'progress-bar-danger';
    }
}

/**
 * Calculate days remaining until deadline
 *
 * @param string|null $endDate End date string
 * @return array Days info
 */
function calculateDaysRemaining(?string $endDate): array {
    if (empty($endDate)) {
        return [
            'days' => null,
            'label' => 'Nessuna scadenza',
            'class' => 'text-muted'
        ];
    }

    $end = strtotime($endDate);
    $now = time();
    $diff = $end - $now;
    $days = floor($diff / 86400);

    if ($days < 0) {
        return [
            'days' => abs($days),
            'label' => abs($days) . ' giorni di ritardo',
            'class' => 'text-danger'
        ];
    } elseif ($days === 0) {
        return [
            'days' => 0,
            'label' => 'Scade oggi',
            'class' => 'text-danger font-weight-bold'
        ];
    } elseif ($days <= 7) {
        return [
            'days' => $days,
            'label' => $days . ' ' . ($days === 1 ? 'giorno' : 'giorni'),
            'class' => 'text-warning'
        ];
    } elseif ($days <= 30) {
        return [
            'days' => $days,
            'label' => $days . ' giorni',
            'class' => 'text-info'
        ];
    } else {
        return [
            'days' => $days,
            'label' => $days . ' giorni',
            'class' => 'text-success'
        ];
    }
}

// ============================================
// FETCH ACTIVE PROJECTS
// ============================================

try {
    // Start defensive transaction management (BUG-070 Phase 3 pattern)
    if ($db->inTransaction()) {
        error_log('[DASHBOARD API] Warning: Transaction already active at start');
        $db->rollback();
    }

    // Query active projects with calculated progress
    $activeProjectsSql = "
        SELECT
            p.id,
            p.name,
            p.description,
            p.status,
            p.priority,
            p.start_date,
            p.end_date,
            p.progress_percentage,
            p.created_at,
            p.updated_at,
            u.name as owner_name,
            u.avatar as owner_avatar,
            u.email as owner_email,
            -- Task counts for progress calculation
            COALESCE(
                (SELECT COUNT(*) FROM tasks t
                 WHERE t.project_id = p.id
                   AND t.status = 'done'
                   AND (t.deleted_at IS NULL OR t.deleted_at = '')),
                0
            ) as completed_tasks_count,
            COALESCE(
                (SELECT COUNT(*) FROM tasks t
                 WHERE t.project_id = p.id
                   AND (t.deleted_at IS NULL OR t.deleted_at = '')),
                0
            ) as total_tasks_count,
            -- Calculate progress (prefer manual progress_percentage if set)
            CASE
                WHEN p.progress_percentage > 0 THEN p.progress_percentage
                WHEN (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND (t.deleted_at IS NULL OR t.deleted_at = '')) = 0 THEN 0
                ELSE ROUND(
                    (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status = 'done' AND (t.deleted_at IS NULL OR t.deleted_at = '')) * 100.0 /
                    (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND (t.deleted_at IS NULL OR t.deleted_at = '')),
                    0
                )
            END as calculated_progress,
            -- Count team members
            (SELECT COUNT(DISTINCT pm.user_id)
             FROM project_members pm
             WHERE pm.project_id = p.id
               AND (pm.deleted_at IS NULL OR pm.deleted_at = '')
            ) as team_members_count
        FROM projects p
        LEFT JOIN users u ON p.owner_id = u.id
        WHERE p.tenant_id = ?
          AND p.status IN ('active', 'planning', 'on_hold')
          AND (p.deleted_at IS NULL OR p.deleted_at = '')
        ORDER BY
            FIELD(p.priority, 'critical', 'high', 'medium', 'low'),
            p.end_date ASC
        LIMIT ?
    ";

    $projects = $db->fetchAll($activeProjectsSql, [$tenantId, $limit]);

    // Format projects for frontend
    $formattedProjects = [];
    foreach ($projects as $project) {
        // Determine final progress value
        $progress = (int)($project['progress_percentage'] > 0
            ? $project['progress_percentage']
            : $project['calculated_progress']);

        // Get badge configurations
        $statusBadge = getStatusBadge($project['status']);
        $priorityBadge = getPriorityBadge($project['priority']);
        $daysRemaining = calculateDaysRemaining($project['end_date']);

        $formattedProjects[] = [
            'id' => (int)$project['id'],
            'name' => $project['name'],
            'description' => $project['description'] ?? '',
            'status' => $project['status'],
            'status_badge' => $statusBadge,
            'priority' => $project['priority'],
            'priority_badge' => $priorityBadge,
            'progress_percentage' => $progress,
            'progress_color' => getProgressColor($progress),
            'tasks' => [
                'completed' => (int)$project['completed_tasks_count'],
                'total' => (int)$project['total_tasks_count'],
                'label' => $project['completed_tasks_count'] . '/' . $project['total_tasks_count'] . ' completati'
            ],
            'dates' => [
                'start' => $project['start_date'],
                'end' => $project['end_date'],
                'start_formatted' => $project['start_date'] ? date('d/m/Y', strtotime($project['start_date'])) : null,
                'end_formatted' => $project['end_date'] ? date('d/m/Y', strtotime($project['end_date'])) : null,
                'days_remaining' => $daysRemaining
            ],
            'owner' => [
                'name' => $project['owner_name'] ?? 'Non assegnato',
                'email' => $project['owner_email'] ?? null,
                'avatar' => $project['owner_avatar'] ?? null
            ],
            'team_members_count' => (int)$project['team_members_count'],
            'created_at' => $project['created_at'],
            'updated_at' => $project['updated_at']
        ];
    }

    // Get summary statistics
    $summaryStats = [
        'total_active' => count($formattedProjects),
        'critical_priority' => count(array_filter($formattedProjects, fn($p) => $p['priority'] === 'critical')),
        'high_priority' => count(array_filter($formattedProjects, fn($p) => $p['priority'] === 'high')),
        'overdue' => count(array_filter($formattedProjects, fn($p) => $p['dates']['days_remaining']['days'] !== null && $p['dates']['days_remaining']['days'] < 0)),
        'average_progress' => count($formattedProjects) > 0
            ? round(array_sum(array_column($formattedProjects, 'progress_percentage')) / count($formattedProjects))
            : 0
    ];

    // Metadata
    $metadata = [
        'total_projects' => count($formattedProjects),
        'limit' => $limit,
        'tenant_id' => $tenantId,
        'summary' => $summaryStats,
        'generated_at' => date('Y-m-d H:i:s')
    ];

    // API Response with named key (BUG-066 pattern)
    api_success([
        'projects' => $formattedProjects,
        'metadata' => $metadata
    ], 'Progetti attivi caricati con successo');

} catch (Exception $e) {
    error_log('[DASHBOARD API] Error loading active projects: ' . $e->getMessage());

    // Defensive rollback if transaction is active
    if ($db->inTransaction()) {
        $db->rollback();
    }

    api_error('Errore durante il caricamento dei progetti attivi', 500);
}