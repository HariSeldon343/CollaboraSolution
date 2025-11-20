<?php
/**
 * Dashboard API - Get Recent Activity
 *
 * Returns recent activity events from audit_logs table
 * with formatted timestamps and entity names
 *
 * Method: GET
 * Parameters:
 *   - tenant_id (optional, for super_admin only)
 *   - limit (optional, default: 10, max: 50)
 * Response: Array of recent activity events
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
if ($limit < 1 || $limit > 50) {
    $limit = 10;
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Format timestamp to relative time in Italian
 *
 * @param string $timestamp Timestamp string
 * @return string Formatted relative time
 */
function formatRelativeTime(string $timestamp): string {
    $time = strtotime($timestamp);
    $diff = time() - $time;

    if ($diff < 60) {
        return 'Adesso';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' ' . ($minutes == 1 ? 'minuto' : 'minuti') . ' fa';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' ' . ($hours == 1 ? 'ora' : 'ore') . ' fa';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' ' . ($days == 1 ? 'giorno' : 'giorni') . ' fa';
    } else {
        // More than a week, show date
        return date('d/m/Y', $time);
    }
}

/**
 * Format action label in Italian
 *
 * @param string $action Action type
 * @param string $entityType Entity type
 * @return string Formatted action label
 */
function formatActionLabel(string $action, string $entityType): string {
    $labels = [
        'create' => [
            'project' => 'ha creato il progetto',
            'task' => 'ha creato il task',
            'user' => 'ha aggiunto l\'utente',
            'file' => 'ha caricato il file',
            'default' => 'ha creato'
        ],
        'update' => [
            'project' => 'ha aggiornato il progetto',
            'task' => 'ha aggiornato il task',
            'user' => 'ha modificato l\'utente',
            'file' => 'ha modificato il file',
            'default' => 'ha aggiornato'
        ],
        'delete' => [
            'project' => 'ha eliminato il progetto',
            'task' => 'ha eliminato il task',
            'user' => 'ha rimosso l\'utente',
            'file' => 'ha eliminato il file',
            'default' => 'ha eliminato'
        ],
        'complete' => [
            'project' => 'ha completato il progetto',
            'task' => 'ha completato il task',
            'default' => 'ha completato'
        ],
        'login' => [
            'default' => 'ha effettuato l\'accesso'
        ],
        'logout' => [
            'default' => 'ha effettuato il logout'
        ]
    ];

    $actionLabels = $labels[$action] ?? ['default' => $action];
    return $actionLabels[$entityType] ?? $actionLabels['default'] ?? $action;
}

/**
 * Get icon class for entity type
 *
 * @param string $entityType Entity type
 * @return string Icon class for frontend
 */
function getEntityIcon(string $entityType): string {
    $icons = [
        'project' => 'fas fa-project-diagram',
        'task' => 'fas fa-tasks',
        'user' => 'fas fa-user',
        'file' => 'fas fa-file',
        'document' => 'fas fa-file-alt',
        'folder' => 'fas fa-folder',
        'comment' => 'fas fa-comment',
        'default' => 'fas fa-circle'
    ];

    return $icons[$entityType] ?? $icons['default'];
}

/**
 * Get badge class based on action
 *
 * @param string $action Action type
 * @return string Badge class for frontend
 */
function getActionBadgeClass(string $action): string {
    $badges = [
        'create' => 'badge-success',
        'update' => 'badge-info',
        'delete' => 'badge-danger',
        'complete' => 'badge-primary',
        'login' => 'badge-secondary',
        'logout' => 'badge-secondary',
        'default' => 'badge-light'
    ];

    return $badges[$action] ?? $badges['default'];
}

// ============================================
// FETCH RECENT ACTIVITIES
// ============================================

try {
    // Start defensive transaction management (BUG-070 Phase 3 pattern)
    if ($db->inTransaction()) {
        error_log('[DASHBOARD API] Warning: Transaction already active at start');
        $db->rollback();
    }

    // Query recent activities with entity names
    $recentActivitiesSql = "
        SELECT
            al.id,
            al.action,
            al.entity_type,
            al.entity_id,
            al.description,
            al.created_at,
            u.name as user_name,
            u.avatar as user_avatar,
            CASE
                WHEN al.entity_type = 'project' THEN (
                    SELECT p.name FROM projects p
                    WHERE p.id = al.entity_id
                    AND (p.deleted_at IS NULL OR p.deleted_at = '')
                    LIMIT 1
                )
                WHEN al.entity_type = 'task' THEN (
                    SELECT t.title FROM tasks t
                    WHERE t.id = al.entity_id
                    AND (t.deleted_at IS NULL OR t.deleted_at = '')
                    LIMIT 1
                )
                WHEN al.entity_type = 'user' THEN (
                    SELECT u2.name FROM users u2
                    WHERE u2.id = al.entity_id
                    AND (u2.deleted_at IS NULL OR u2.deleted_at = '')
                    LIMIT 1
                )
                WHEN al.entity_type = 'file' THEN (
                    SELECT f.name FROM files f
                    WHERE f.id = al.entity_id
                    AND (f.deleted_at IS NULL OR f.deleted_at = '')
                    LIMIT 1
                )
                ELSE NULL
            END as entity_name
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE al.tenant_id = ?
          AND al.entity_type IN ('project', 'task', 'user', 'file', 'document', 'folder')
          AND al.action IN ('create', 'update', 'delete', 'complete', 'login', 'logout')
          AND (al.deleted_at IS NULL OR al.deleted_at = '')
        ORDER BY al.created_at DESC
        LIMIT ?
    ";

    $activities = $db->fetchAll($recentActivitiesSql, [$tenantId, $limit]);

    // Format activities for frontend
    $formattedActivities = [];
    foreach ($activities as $activity) {
        // Skip if critical data is missing
        if (empty($activity['user_name'])) {
            continue;
        }

        $formattedActivities[] = [
            'id' => (int)$activity['id'],
            'action' => $activity['action'],
            'action_label' => formatActionLabel($activity['action'], $activity['entity_type']),
            'action_badge_class' => getActionBadgeClass($activity['action']),
            'entity_type' => $activity['entity_type'],
            'entity_id' => (int)$activity['entity_id'],
            'entity_name' => $activity['entity_name'] ?? 'N/A',
            'entity_icon' => getEntityIcon($activity['entity_type']),
            'description' => $activity['description'] ?? '',
            'user_name' => $activity['user_name'],
            'user_avatar' => $activity['user_avatar'] ?? null,
            'created_at' => $activity['created_at'],
            'time_ago' => formatRelativeTime($activity['created_at']),
            'formatted_date' => date('d/m/Y H:i', strtotime($activity['created_at']))
        ];
    }

    // Metadata
    $metadata = [
        'total_activities' => count($formattedActivities),
        'limit' => $limit,
        'tenant_id' => $tenantId,
        'generated_at' => date('Y-m-d H:i:s')
    ];

    // API Response with named key (BUG-066 pattern)
    api_success([
        'activities' => $formattedActivities,
        'metadata' => $metadata
    ], 'Attività recenti caricate con successo');

} catch (Exception $e) {
    error_log('[DASHBOARD API] Error loading recent activities: ' . $e->getMessage());

    // Defensive rollback if transaction is active
    if ($db->inTransaction()) {
        $db->rollback();
    }

    api_error('Errore durante il caricamento delle attività recenti', 500);
}