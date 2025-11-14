<?php
/**
 * Document Workflow API - Get Workflow History
 *
 * Retrieves the complete workflow history for a document
 * Available to all users with access to the file
 *
 * Method: GET
 * Input: file_id
 * Response: Array of history entries with user details
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
// INPUT VALIDATION
// ============================================

$fileId = isset($_GET['file_id']) ? (int)$_GET['file_id'] : null;

// Validate file_id
if (!$fileId || $fileId <= 0) {
    api_error('file_id richiesto e deve essere positivo.', 400);
}

// ============================================
// CHECK FILE ACCESS
// ============================================

try {
    // Get file details
    $file = $db->fetchOne(
        "SELECT id, file_name, uploaded_by, folder_id
         FROM files
         WHERE id = ?
           AND tenant_id = ?
           AND deleted_at IS NULL",
        [$fileId, $tenantId]
    );

    if ($file === false) {
        api_error('File non trovato nel tenant corrente.', 404);
    }

    // Check if user has access to the file
    if (!canUserAccessFile($userId, $userRole, $tenantId, $fileId, $file['uploaded_by'])) {
        api_error('Non hai accesso a questo file.', 403);
    }

    // ============================================
    // GET WORKFLOW HISTORY
    // ============================================

    $historyQuery = "SELECT
        dwh.*,
        dwh.user_role_at_time as performed_by_role,
        u_performed.name as performed_by_name,
        u_performed.email as performed_by_email,
        u_performed.profile_image as performed_by_avatar,

        -- Calculate duration between transitions
        TIMESTAMPDIFF(SECOND,
            LAG(dwh.created_at) OVER (ORDER BY dwh.created_at),
            dwh.created_at
        ) as duration_seconds,

        -- Get workflow state labels
        CASE dwh.from_state
            WHEN 'bozza' THEN 'Bozza'
            WHEN 'in_validazione' THEN 'In Validazione'
            WHEN 'validato' THEN 'Validato'
            WHEN 'in_approvazione' THEN 'In Approvazione'
            WHEN 'approvato' THEN 'Approvato'
            WHEN 'rifiutato' THEN 'Rifiutato'
            ELSE dwh.from_state
        END as from_state_label,

        CASE dwh.to_state
            WHEN 'bozza' THEN 'Bozza'
            WHEN 'in_validazione' THEN 'In Validazione'
            WHEN 'validato' THEN 'Validato'
            WHEN 'in_approvazione' THEN 'In Approvazione'
            WHEN 'approvato' THEN 'Approvato'
            WHEN 'rifiutato' THEN 'Rifiutato'
            ELSE dwh.to_state
        END as to_state_label

    FROM document_workflow_history dwh
    LEFT JOIN users u_performed ON dwh.performed_by_user_id = u_performed.id
    WHERE dwh.file_id = ?
      AND dwh.tenant_id = ?
    ORDER BY dwh.created_at DESC";

    $history = $db->fetchAll($historyQuery, [$fileId, $tenantId]);

    // ============================================
    // GET CURRENT WORKFLOW STATE
    // ============================================

    $currentWorkflow = $db->fetchOne(
        "SELECT dw.*,
                uc.name as creator_name,
                uh.name as handler_name
         FROM document_workflow dw
         LEFT JOIN users uc ON dw.created_by_user_id = uc.id
         LEFT JOIN users uh ON dw.current_handler_user_id = uh.id
         WHERE dw.file_id = ?
           AND dw.tenant_id = ?
           AND dw.deleted_at IS NULL",
        [$fileId, $tenantId]
    );

    // ============================================
    // FORMAT RESPONSE DATA
    // ============================================

    $formattedHistory = [];
    $timeline = [];

    foreach ($history as $entry) {
        // Parse metadata
        $metadata = parseWorkflowMetadata($entry['metadata']);

        // Format entry
        $formattedEntry = [
            'id' => (int)$entry['id'],
            'from_state' => $entry['from_state'],
            'from_state_label' => $entry['from_state_label'],
            'from_state_color' => getWorkflowStateColor($entry['from_state']),
            'to_state' => $entry['to_state'],
            'new_state' => $entry['to_state'],  // ALIAS for JavaScript compatibility
            'to_state_label' => $entry['to_state_label'],
            'to_state_color' => getWorkflowStateColor($entry['to_state']),
            'transition_type' => $entry['transition_type'],
            'action' => $entry['transition_type'],  // ALIAS for JavaScript compatibility
            'transition_label' => getTransitionLabel($entry['transition_type']),
            'performed_by' => null,
            'comment' => $entry['comment'],
            'created_at' => $entry['created_at'],
            'ip_address' => $entry['ip_address'] ?? 'N/A',  // Missing property from database
            'duration' => null,
            'metadata' => $metadata
        ];

        // Add user details if available
        if ($entry['performed_by_user_id']) {
            $formattedEntry['performed_by'] = [
                'id' => (int)$entry['performed_by_user_id'],
                'name' => $entry['performed_by_name'],
                'email' => $entry['performed_by_email'],
                'avatar' => $entry['performed_by_avatar'],
                'role' => $entry['performed_by_role']
            ];
            // Flat properties for easy access
            $formattedEntry['user_name'] = $entry['performed_by_name'];
            $formattedEntry['user_role'] = $entry['performed_by_role'] ?? 'user';
        } else {
            // System transition
            $formattedEntry['performed_by'] = [
                'name' => 'Sistema',
                'role' => 'system'
            ];
            // Flat properties for easy access
            $formattedEntry['user_name'] = 'Sistema';
            $formattedEntry['user_role'] = 'system';
        }

        // Format duration
        if ($entry['duration_seconds'] !== null) {
            $hours = floor($entry['duration_seconds'] / 3600);
            $minutes = floor(($entry['duration_seconds'] % 3600) / 60);

            if ($hours > 24) {
                $days = floor($hours / 24);
                $formattedEntry['duration'] = sprintf('%d giorni, %d ore', $days, $hours % 24);
            } elseif ($hours > 0) {
                $formattedEntry['duration'] = sprintf('%d ore, %d minuti', $hours, $minutes);
            } else {
                $formattedEntry['duration'] = sprintf('%d minuti', $minutes);
            }
            $formattedEntry['duration_seconds'] = (int)$entry['duration_seconds'];
        }

        $formattedHistory[] = $formattedEntry;

        // Build timeline entry
        $timeline[] = [
            'timestamp' => $entry['created_at'],
            'event' => $this->buildTimelineEvent($entry),
            'type' => $entry['transition_type']
        ];
    }

    // ============================================
    // CALCULATE STATISTICS
    // ============================================

    $statistics = [
        'total_transitions' => count($history),
        'total_rejections' => 0,
        'total_duration' => null,
        'average_transition_time' => null,
        'current_state' => null,
        'completion_percentage' => 0
    ];

    // Count rejections
    foreach ($history as $entry) {
        if ($entry['transition_type'] === TRANSITION_REJECT) {
            $statistics['total_rejections']++;
        }
    }

    // Calculate total duration
    if ($currentWorkflow && $currentWorkflow['submitted_at']) {
        $startTime = strtotime($currentWorkflow['submitted_at']);

        if ($currentWorkflow['current_state'] === WORKFLOW_STATE_APPROVED && $currentWorkflow['approved_at']) {
            $endTime = strtotime($currentWorkflow['approved_at']);
        } else {
            $endTime = time();
        }

        $totalSeconds = $endTime - $startTime;
        $statistics['total_duration'] = [
            'days' => floor($totalSeconds / 86400),
            'hours' => floor(($totalSeconds % 86400) / 3600),
            'minutes' => floor(($totalSeconds % 3600) / 60)
        ];

        // Average transition time
        if (count($history) > 1) {
            $statistics['average_transition_time'] = floor($totalSeconds / (count($history) - 1));
        }
    }

    // Current state and completion
    if ($currentWorkflow) {
        $statistics['current_state'] = $currentWorkflow['current_state'];
        $statistics['current_state_label'] = getWorkflowStateLabel($currentWorkflow['current_state']);
        $statistics['current_state_color'] = getWorkflowStateColor($currentWorkflow['current_state']);

        // Calculate completion percentage
        $stateProgress = [
            WORKFLOW_STATE_DRAFT => 0,
            WORKFLOW_STATE_IN_VALIDATION => 25,
            WORKFLOW_STATE_VALIDATED => 50,
            WORKFLOW_STATE_IN_APPROVAL => 75,
            WORKFLOW_STATE_APPROVED => 100,
            WORKFLOW_STATE_REJECTED => 0
        ];

        $statistics['completion_percentage'] = $stateProgress[$currentWorkflow['current_state']] ?? 0;
    }

    // ============================================
    // PREPARE RESPONSE (BUG-040 pattern)
    // ============================================

    $response = [
        'history' => array_reverse($formattedHistory), // Chronological order
        'timeline' => array_reverse($timeline),
        'statistics' => $statistics,
        'file' => [
            'id' => $fileId,
            'name' => $file['file_name']
        ]
    ];

    // Add current workflow info if exists
    if ($currentWorkflow) {
        $response['current_workflow'] = [
            'id' => (int)$currentWorkflow['id'],
            'state' => $currentWorkflow['current_state'],
            'state_label' => getWorkflowStateLabel($currentWorkflow['current_state']),
            'state_color' => getWorkflowStateColor($currentWorkflow['current_state']),
            'validator' => $currentWorkflow['validator_name'],
            'approver' => $currentWorkflow['approver_name'],
            'creator' => $currentWorkflow['creator_name'],
            'submitted_at' => $currentWorkflow['submitted_at'],
            'validated_at' => $currentWorkflow['validated_at'],
            'approved_at' => $currentWorkflow['approved_at'],
            'rejected_at' => $currentWorkflow['rejected_at']
        ];
    }

    api_success($response, 'Storia workflow caricata con successo.');

} catch (Exception $e) {
    error_log("[WORKFLOW_HISTORY] Error: " . $e->getMessage());
    api_error('Errore durante recupero storia workflow: ' . $e->getMessage(), 500);
}

/**
 * Build a human-readable timeline event description
 */
function buildTimelineEvent(array $entry): string {
    $user = $entry['performed_by_name'] ?? 'Sistema';

    switch ($entry['transition_type']) {
        case TRANSITION_SUBMIT:
            return sprintf('%s ha inviato il documento per validazione', $user);
        case TRANSITION_VALIDATE:
            return sprintf('%s ha validato il documento', $user);
        case TRANSITION_REJECT:
            return sprintf('%s ha rifiutato il documento', $user);
        case TRANSITION_APPROVE:
            return sprintf('%s ha approvato definitivamente il documento', $user);
        case TRANSITION_RECALL:
            return sprintf('%s ha richiamato il documento', $user);
        case 'auto_transition':
            return 'Transizione automatica del sistema';
        default:
            return sprintf('Transizione da %s a %s', $entry['from_state'], $entry['to_state']);
    }
}