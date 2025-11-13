<?php
/**
 * Document Workflow API - Dashboard Statistics
 *
 * Provides workflow dashboard statistics and pending actions for current user
 * Available to all authenticated users
 *
 * Method: GET
 * Input: None (tenant-filtered automatically)
 * Response: Counts of documents in each state, pending actions for current user
 *
 * @package CollaboraNexio
 * @subpackage Document Workflow API
 * @version 1.0.0
 * @since 2025-10-29
 */

declare(strict_types=1);

// API Authentication (BUG-011 pattern)
require_once __DIR__ . '/../../../includes/api_auth.php';

initializeApiEnvironment();

// Force no-cache headers (BUG-040)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

verifyApiAuthentication();  // IMMEDIATELY after init

$userInfo = getApiUserInfo();
$tenantId = $userInfo['tenant_id'];
$userId = $userInfo['user_id'];
$userRole = $userInfo['role'];

verifyApiCsrfToken();

// Database connection
require_once __DIR__ . '/../../../includes/db.php';
$db = Database::getInstance();

// Include workflow constants
require_once __DIR__ . '/../../../includes/workflow_constants.php';

// ============================================
// REQUEST VALIDATION
// ============================================

// Only GET method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    api_error('Metodo non consentito. Usare GET.', 405);
}

// ============================================
// PARSE QUERY PARAMETERS
// ============================================

$filterUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$filterState = $_GET['state'] ?? null;
$dateFrom = $_GET['date_from'] ?? null;
$dateTo = $_GET['date_to'] ?? null;
$includeDetails = isset($_GET['include_details']) ? filter_var($_GET['include_details'], FILTER_VALIDATE_BOOLEAN) : false;

// Admin can filter by user, others see only their own
if ($filterUser && $filterUser !== $userId && !in_array($userRole, ['admin', 'super_admin'])) {
    $filterUser = $userId;
}

// Validate state filter
if ($filterState && !in_array($filterState, WORKFLOW_STATES)) {
    api_error('Stato workflow non valido.', 400);
}

// ============================================
// DASHBOARD DATA
// ============================================

try {
    // ============================================
    // 1. OVERALL STATISTICS
    // ============================================

    $statsQuery = "SELECT
        COUNT(*) as total,
        COUNT(CASE WHEN current_state = ? THEN 1 END) as draft_count,
        COUNT(CASE WHEN current_state = ? THEN 1 END) as in_validation_count,
        COUNT(CASE WHEN current_state = ? THEN 1 END) as validated_count,
        COUNT(CASE WHEN current_state = ? THEN 1 END) as in_approval_count,
        COUNT(CASE WHEN current_state = ? THEN 1 END) as approved_count,
        COUNT(CASE WHEN current_state = ? THEN 1 END) as rejected_count,
        AVG(rejection_count) as avg_rejections,
        AVG(CASE
            WHEN approved_at IS NOT NULL
            THEN TIMESTAMPDIFF(HOUR, submitted_at, approved_at)
            ELSE NULL
        END) as avg_completion_hours
    FROM document_workflow
    WHERE tenant_id = ?
      AND deleted_at IS NULL";

    $params = [
        WORKFLOW_STATE_DRAFT,
        WORKFLOW_STATE_IN_VALIDATION,
        WORKFLOW_STATE_VALIDATED,
        WORKFLOW_STATE_IN_APPROVAL,
        WORKFLOW_STATE_APPROVED,
        WORKFLOW_STATE_REJECTED,
        $tenantId
    ];

    // Apply date filters
    if ($dateFrom) {
        $statsQuery .= " AND created_at >= ?";
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo) {
        $statsQuery .= " AND created_at <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }

    $stats = $db->fetchOne($statsQuery, $params);

    // ============================================
    // 2. USER-SPECIFIC PENDING ACTIONS
    // ============================================

    $pendingActions = [];

    // Documents awaiting my validation
    if (userHasWorkflowRole($userId, $tenantId, WORKFLOW_ROLE_VALIDATOR)) {
        $validationQuery = "SELECT
            dw.id,
            dw.file_id,
            f.file_name,
            dw.submitted_at,
            TIMESTAMPDIFF(HOUR, dw.submitted_at, NOW()) as hours_waiting,
            uc.name as creator_name
        FROM document_workflow dw
        INNER JOIN files f ON dw.file_id = f.id
        LEFT JOIN users uc ON dw.created_by_user_id = uc.id
        WHERE dw.tenant_id = ?
          AND dw.current_state = ?
          AND dw.current_validator_id = ?
          AND dw.deleted_at IS NULL
        ORDER BY dw.submitted_at ASC";

        $validationPending = $db->fetchAll($validationQuery, [
            $tenantId,
            WORKFLOW_STATE_IN_VALIDATION,
            $userId
        ]);

        foreach ($validationPending as $doc) {
            $pendingActions[] = [
                'type' => 'validation_required',
                'workflow_id' => (int)$doc['id'],
                'file_id' => (int)$doc['file_id'],
                'file_name' => $doc['file_name'],
                'creator' => $doc['creator_name'],
                'waiting_since' => $doc['submitted_at'],
                'hours_waiting' => (int)$doc['hours_waiting'],
                'priority' => $doc['hours_waiting'] > 48 ? 'high' : ($doc['hours_waiting'] > 24 ? 'medium' : 'low'),
                'action_label' => 'Richiede Validazione',
                'action_url' => '/workflow.php?file_id=' . $doc['file_id']
            ];
        }
    }

    // Documents awaiting my approval
    if (userHasWorkflowRole($userId, $tenantId, WORKFLOW_ROLE_APPROVER)) {
        $approvalQuery = "SELECT
            dw.id,
            dw.file_id,
            f.file_name,
            dw.validated_at,
            TIMESTAMPDIFF(HOUR, dw.validated_at, NOW()) as hours_waiting,
            uc.name as creator_name,
            uv.name as validator_name
        FROM document_workflow dw
        INNER JOIN files f ON dw.file_id = f.id
        LEFT JOIN users uc ON dw.created_by_user_id = uc.id
        LEFT JOIN users uv ON dw.validated_by_user_id = uv.id
        WHERE dw.tenant_id = ?
          AND dw.current_state = ?
          AND dw.current_approver_id = ?
          AND dw.deleted_at IS NULL
        ORDER BY dw.validated_at ASC";

        $approvalPending = $db->fetchAll($approvalQuery, [
            $tenantId,
            WORKFLOW_STATE_IN_APPROVAL,
            $userId
        ]);

        foreach ($approvalPending as $doc) {
            $pendingActions[] = [
                'type' => 'approval_required',
                'workflow_id' => (int)$doc['id'],
                'file_id' => (int)$doc['file_id'],
                'file_name' => $doc['file_name'],
                'creator' => $doc['creator_name'],
                'validator' => $doc['validator_name'],
                'waiting_since' => $doc['validated_at'],
                'hours_waiting' => (int)$doc['hours_waiting'],
                'priority' => $doc['hours_waiting'] > 48 ? 'high' : ($doc['hours_waiting'] > 24 ? 'medium' : 'low'),
                'action_label' => 'Richiede Approvazione',
                'action_url' => '/workflow.php?file_id=' . $doc['file_id']
            ];
        }
    }

    // My documents that were rejected
    $rejectedQuery = "SELECT
        dw.id,
        dw.file_id,
        f.file_name,
        dw.rejected_at,
        dw.rejection_count,
        dwh.comment as rejection_reason,
        ur.name as rejected_by_name
    FROM document_workflow dw
    INNER JOIN files f ON dw.file_id = f.id
    LEFT JOIN users ur ON dw.rejected_by_user_id = ur.id
    LEFT JOIN document_workflow_history dwh ON (
        dwh.workflow_id = dw.id
        AND dwh.to_state = ?
        AND dwh.id = (
            SELECT MAX(id)
            FROM document_workflow_history
            WHERE workflow_id = dw.id
              AND to_state = ?
        )
    )
    WHERE dw.tenant_id = ?
      AND dw.current_state = ?
      AND dw.created_by_user_id = ?
      AND dw.deleted_at IS NULL
    ORDER BY dw.rejected_at DESC";

    $rejectedDocs = $db->fetchAll($rejectedQuery, [
        WORKFLOW_STATE_REJECTED,
        WORKFLOW_STATE_REJECTED,
        $tenantId,
        WORKFLOW_STATE_REJECTED,
        $userId
    ]);

    foreach ($rejectedDocs as $doc) {
        $pendingActions[] = [
            'type' => 'document_rejected',
            'workflow_id' => (int)$doc['id'],
            'file_id' => (int)$doc['file_id'],
            'file_name' => $doc['file_name'],
            'rejected_by' => $doc['rejected_by_name'],
            'rejected_at' => $doc['rejected_at'],
            'rejection_count' => (int)$doc['rejection_count'],
            'rejection_reason' => $doc['rejection_reason'],
            'priority' => $doc['rejection_count'] >= 3 ? 'high' : 'medium',
            'action_label' => 'Richiede Correzione',
            'action_url' => '/files.php?id=' . $doc['file_id']
        ];
    }

    // ============================================
    // 3. RECENT ACTIVITY
    // ============================================

    $recentActivity = [];

    if ($includeDetails) {
        $activityQuery = "SELECT
            dwh.created_at,
            dwh.transition_type,
            dwh.from_state,
            dwh.to_state,
            dwh.comment,
            f.id as file_id,
            f.file_name,
            u.name as user_name,
            u.profile_image
        FROM document_workflow_history dwh
        INNER JOIN files f ON dwh.file_id = f.id
        LEFT JOIN users u ON dwh.performed_by_user_id = u.id
        WHERE dwh.tenant_id = ?
        ORDER BY dwh.created_at DESC
        LIMIT 20";

        $activities = $db->fetchAll($activityQuery, [$tenantId]);

        foreach ($activities as $activity) {
            $recentActivity[] = [
                'timestamp' => $activity['created_at'],
                'type' => $activity['transition_type'],
                'transition_label' => getTransitionLabel($activity['transition_type']),
                'from_state' => $activity['from_state'],
                'from_state_label' => getWorkflowStateLabel($activity['from_state']),
                'to_state' => $activity['to_state'],
                'to_state_label' => getWorkflowStateLabel($activity['to_state']),
                'file_id' => (int)$activity['file_id'],
                'file_name' => $activity['file_name'],
                'user_name' => $activity['user_name'],
                'user_avatar' => $activity['profile_image'],
                'comment' => $activity['comment']
            ];
        }
    }

    // ============================================
    // 4. PERFORMANCE METRICS
    // ============================================

    $performanceQuery = "SELECT
        AVG(CASE
            WHEN validated_at IS NOT NULL AND submitted_at IS NOT NULL
            THEN TIMESTAMPDIFF(HOUR, submitted_at, validated_at)
            ELSE NULL
        END) as avg_validation_hours,
        AVG(CASE
            WHEN approved_at IS NOT NULL AND validated_at IS NOT NULL
            THEN TIMESTAMPDIFF(HOUR, validated_at, approved_at)
            ELSE NULL
        END) as avg_approval_hours,
        COUNT(CASE WHEN approved_at IS NOT NULL THEN 1 END) as completed_count,
        COUNT(CASE WHEN rejection_count > 0 THEN 1 END) as rejected_at_least_once,
        MAX(rejection_count) as max_rejection_count
    FROM document_workflow
    WHERE tenant_id = ?
      AND deleted_at IS NULL";

    $performance = $db->fetchOne($performanceQuery, [$tenantId]);

    // ============================================
    // 5. WORKFLOW ROLE STATISTICS
    // ============================================

    $roleStatsQuery = "SELECT
        (SELECT COUNT(*) FROM workflow_roles
         WHERE tenant_id = ? AND workflow_role = ? AND is_active = 1 AND deleted_at IS NULL) as active_validators,
        (SELECT COUNT(*) FROM workflow_roles
         WHERE tenant_id = ? AND workflow_role = ? AND is_active = 1 AND deleted_at IS NULL) as active_approvers";

    $roleStats = $db->fetchOne($roleStatsQuery, [
        $tenantId, WORKFLOW_ROLE_VALIDATOR,
        $tenantId, WORKFLOW_ROLE_APPROVER
    ]);

    // ============================================
    // PREPARE RESPONSE (BUG-040 pattern)
    // ============================================

    $response = [
        'statistics' => [
            'total_workflows' => (int)$stats['total'],
            'by_state' => [
                'draft' => (int)$stats['draft_count'],
                'in_validation' => (int)$stats['in_validation_count'],
                'validated' => (int)$stats['validated_count'],
                'in_approval' => (int)$stats['in_approval_count'],
                'approved' => (int)$stats['approved_count'],
                'rejected' => (int)$stats['rejected_count']
            ],
            'average_rejections' => round($stats['avg_rejections'] ?? 0, 2),
            'average_completion_hours' => round($stats['avg_completion_hours'] ?? 0, 1)
        ],
        'pending_actions' => $pendingActions,
        'pending_count' => [
            'validation' => count(array_filter($pendingActions, fn($a) => $a['type'] === 'validation_required')),
            'approval' => count(array_filter($pendingActions, fn($a) => $a['type'] === 'approval_required')),
            'rejected' => count(array_filter($pendingActions, fn($a) => $a['type'] === 'document_rejected'))
        ],
        'performance' => [
            'avg_validation_hours' => round($performance['avg_validation_hours'] ?? 0, 1),
            'avg_approval_hours' => round($performance['avg_approval_hours'] ?? 0, 1),
            'completed_workflows' => (int)$performance['completed_count'],
            'workflows_with_rejections' => (int)$performance['rejected_at_least_once'],
            'max_rejection_count' => (int)$performance['max_rejection_count']
        ],
        'workflow_roles' => [
            'active_validators' => (int)$roleStats['active_validators'],
            'active_approvers' => (int)$roleStats['active_approvers']
        ]
    ];

    // Add recent activity if requested
    if ($includeDetails) {
        $response['recent_activity'] = $recentActivity;
    }

    // Add user info
    $response['current_user'] = [
        'id' => $userId,
        'name' => $userInfo['user_name'],
        'role' => $userRole,
        'is_validator' => userHasWorkflowRole($userId, $tenantId, WORKFLOW_ROLE_VALIDATOR),
        'is_approver' => userHasWorkflowRole($userId, $tenantId, WORKFLOW_ROLE_APPROVER)
    ];

    api_success($response, 'Dashboard workflow caricata con successo.');

} catch (Exception $e) {
    error_log("[WORKFLOW_DASHBOARD] Error: " . $e->getMessage());
    api_error('Errore durante caricamento dashboard workflow: ' . $e->getMessage(), 500);
}