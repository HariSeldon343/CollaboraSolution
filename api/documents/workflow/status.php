<?php
/**
 * Document Workflow API - Get Current Workflow Status
 *
 * Retrieves the current workflow status and available actions for the user
 * Available to all users with access to the file
 *
 * Method: GET
 * Input: file_id
 * Response: Current workflow state + available actions for current user
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

// No CSRF required for GET requests (read-only operation)

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
// GET FILE AND WORKFLOW STATUS
// ============================================

try {
    // Get file details (handle super_admin cross-tenant access)
    if ($userRole === 'super_admin') {
        $file = $db->fetchOne(
            "SELECT id, file_name, uploaded_by, folder_id, file_size, mime_type, tenant_id
             FROM files
             WHERE id = ? AND deleted_at IS NULL",
            [$fileId]
        );
        if ($file && $file['tenant_id']) {
            $tenantId = (int)$file['tenant_id'];
        }
    } else {
        $file = $db->fetchOne(
            "SELECT id, file_name, uploaded_by, folder_id, file_size, mime_type, tenant_id
             FROM files
             WHERE id = ?
               AND tenant_id = ?
               AND deleted_at IS NULL",
            [$fileId, $tenantId]
        );
    }

    if ($file === false) {
        api_error('File non trovato o accesso negato.', 404);
    }

    // Check if user has access to the file
    if (!canUserAccessFile($userId, $userRole, $tenantId, $fileId, $file['uploaded_by'])) {
        api_error('Non hai accesso a questo file.', 403);
    }

    // ============================================
    // GET CURRENT WORKFLOW
    // ============================================

    $workflow = false;
    
    // Check if document_workflow table exists
    try {
        $tableExists = $db->fetchOne(
            "SELECT 1 FROM information_schema.tables 
             WHERE table_schema = DATABASE() 
             AND table_name = 'document_workflow'"
        );
        
        if ($tableExists) {
            $workflow = $db->fetchOne(
                "SELECT dw.*,
                        uv.id as validator_id,
                        uv.display_name as validator_name,
                        uv.email as validator_email,
                        ua.id as approver_id,
                        ua.display_name as approver_name,
                        ua.email as approver_email,
                        uc.id as creator_id,
                        uc.display_name as creator_name,
                        uc.email as creator_email,
                        uvb.display_name as validated_by_name,
                        urb.display_name as rejected_by_name,
                        uab.display_name as approved_by_name
                 FROM document_workflow dw
                 LEFT JOIN users uv ON dw.current_validator_id = uv.id
                 LEFT JOIN users ua ON dw.current_approver_id = ua.id
                 LEFT JOIN users uc ON dw.created_by_user_id = uc.id
                 LEFT JOIN users uvb ON dw.validated_by_user_id = uvb.id
                 LEFT JOIN users urb ON dw.rejected_by_user_id = urb.id
                 LEFT JOIN users uab ON dw.approved_by_user_id = uab.id
                 WHERE dw.file_id = ?
                   AND dw.tenant_id = ?
                   AND dw.deleted_at IS NULL",
                [$fileId, $tenantId]
            );
        }
    } catch (Exception $e) {
        // Table doesn't exist or query failed - treat as no workflow
        $workflow = false;
    }

    // ============================================
    // PREPARE RESPONSE DATA
    // ============================================

    $response = [
        'file' => [
            'id' => $fileId,
            'name' => $file['file_name'],
            'size' => (int)$file['file_size'],
            'mime_type' => $file['mime_type'],
            'creator_id' => (int)$file['uploaded_by'],
            'is_creator' => $file['uploaded_by'] == $userId
        ],
        'workflow_exists' => $workflow !== false,
        'workflow' => null,
        'available_actions' => [],
        'user_role_in_workflow' => null,
        'can_start_workflow' => false
    ];

    // ============================================
    // IF NO WORKFLOW EXISTS
    // ============================================

    if ($workflow === false) {
        // Check if user can start workflow
        $response['can_start_workflow'] = (
            $file['uploaded_by'] == $userId ||
            in_array($userRole, ['manager', 'admin', 'super_admin'])
        );

        if ($response['can_start_workflow']) {
            $response['available_actions'][] = [
                'action' => 'submit',
                'label' => 'Invia per Validazione',
                'description' => 'Avvia il workflow di approvazione',
                'endpoint' => '/api/documents/workflow/submit.php',
                'method' => 'POST',
                'requirements' => [
                    'validator_id' => 'optional',
                    'approver_id' => 'optional',
                    'notes' => 'optional'
                ]
            ];
        }

        $response['message'] = 'Nessun workflow attivo per questo documento';

    } else {
        // ============================================
        // WORKFLOW EXISTS - ANALYZE CURRENT STATE
        // ============================================

        $currentState = $workflow['state'];

        // Determine user's role in workflow
        $userRoleInWorkflow = null;
        if ($workflow['creator_id'] == $userId) {
            $userRoleInWorkflow = 'creator';
        }
        if ($workflow['validator_id'] == $userId) {
            $userRoleInWorkflow = $userRoleInWorkflow ? 'creator_and_validator' : 'validator';
        }
        if ($workflow['approver_id'] == $userId) {
            $userRoleInWorkflow = $userRoleInWorkflow ? $userRoleInWorkflow . '_and_approver' : 'approver';
        }
        if (in_array($userRole, ['admin', 'super_admin'])) {
            $userRoleInWorkflow = 'admin';
        }

        $response['user_role_in_workflow'] = $userRoleInWorkflow;

        // Build workflow status object
        $response['workflow'] = [
            'id' => (int)$workflow['id'],
            'state' => $currentState,
            'state_label' => getWorkflowStateLabel($currentState),
            'state_color' => getWorkflowStateColor($currentState),
            'state_description' => getStateDescription($currentState),
            'progress_percentage' => getProgressPercentage($currentState),
            'rejection_count' => (int)$workflow['rejection_count'],
            'participants' => [
                'creator' => [
                    'id' => (int)$workflow['creator_id'],
                    'name' => $workflow['creator_name'],
                    'email' => $workflow['creator_email']
                ],
                'validator' => [
                    'id' => (int)$workflow['validator_id'],
                    'name' => $workflow['validator_name'],
                    'email' => $workflow['validator_email'],
                    'status' => $workflow['validated_at'] ? 'completed' : ($currentState === WORKFLOW_STATE_IN_VALIDATION ? 'pending' : 'waiting')
                ],
                'approver' => [
                    'id' => (int)$workflow['approver_id'],
                    'name' => $workflow['approver_name'],
                    'email' => $workflow['approver_email'],
                    'status' => $workflow['approved_at'] ? 'completed' : ($currentState === WORKFLOW_STATE_IN_APPROVAL ? 'pending' : 'waiting')
                ]
            ],
            'dates' => [
                'submitted_at' => $workflow['submitted_at'],
                'validated_at' => $workflow['validated_at'],
                'approved_at' => $workflow['approved_at'],
                'rejected_at' => $workflow['rejected_at']
            ],
            'last_action' => null
        ];

        // Determine last action
        if ($workflow['rejected_at']) {
            $response['workflow']['last_action'] = [
                'type' => 'rejection',
                'by' => $workflow['rejected_by_name'],
                'at' => $workflow['rejected_at']
            ];
        } elseif ($workflow['approved_at']) {
            $response['workflow']['last_action'] = [
                'type' => 'approval',
                'by' => $workflow['approved_by_name'],
                'at' => $workflow['approved_at']
            ];
        } elseif ($workflow['validated_at']) {
            $response['workflow']['last_action'] = [
                'type' => 'validation',
                'by' => $workflow['validated_by_name'],
                'at' => $workflow['validated_at']
            ];
        }

        // ============================================
        // DETERMINE AVAILABLE ACTIONS
        // ============================================

        $availableActions = [];

        switch ($currentState) {
            case WORKFLOW_STATE_DRAFT:
                if ($workflow['creator_id'] == $userId || in_array($userRole, ['admin', 'super_admin'])) {
                    $availableActions[] = [
                        'action' => 'submit',
                        'label' => 'Reinvia per Validazione',
                        'description' => 'Reinvia il documento nel workflow',
                        'endpoint' => '/api/documents/workflow/submit.php',
                        'method' => 'POST'
                    ];
                }
                break;

            case WORKFLOW_STATE_IN_VALIDATION:
                if ($workflow['validator_id'] == $userId || in_array($userRole, ['admin', 'super_admin'])) {
                    $availableActions[] = [
                        'action' => 'validate',
                        'label' => 'Valida Documento',
                        'description' => 'Approva e invia per approvazione finale',
                        'endpoint' => '/api/documents/workflow/validate.php',
                        'method' => 'POST'
                    ];

                    $availableActions[] = [
                        'action' => 'reject',
                        'label' => 'Rifiuta Documento',
                        'description' => 'Rifiuta e rinvia al creatore',
                        'endpoint' => '/api/documents/workflow/reject.php',
                        'method' => 'POST',
                        'requires_comment' => true
                    ];
                }

                if ($workflow['creator_id'] == $userId) {
                    $availableActions[] = [
                        'action' => 'recall',
                        'label' => 'Richiama Documento',
                        'description' => 'Richiama il documento dal workflow',
                        'endpoint' => '/api/documents/workflow/recall.php',
                        'method' => 'POST'
                    ];
                }
                break;

            case WORKFLOW_STATE_IN_APPROVAL:
                if ($workflow['approver_id'] == $userId || in_array($userRole, ['admin', 'super_admin'])) {
                    $availableActions[] = [
                        'action' => 'approve',
                        'label' => 'Approva Definitivamente',
                        'description' => 'Approvazione finale del documento',
                        'endpoint' => '/api/documents/workflow/approve.php',
                        'method' => 'POST'
                    ];

                    $availableActions[] = [
                        'action' => 'reject',
                        'label' => 'Rifiuta Documento',
                        'description' => 'Rifiuta e rinvia al creatore',
                        'endpoint' => '/api/documents/workflow/reject.php',
                        'method' => 'POST',
                        'requires_comment' => true
                    ];
                }

                if ($workflow['creator_id'] == $userId) {
                    $availableActions[] = [
                        'action' => 'recall',
                        'label' => 'Richiama Documento',
                        'description' => 'Richiama il documento dal workflow',
                        'endpoint' => '/api/documents/workflow/recall.php',
                        'method' => 'POST'
                    ];
                }
                break;

            case WORKFLOW_STATE_REJECTED:
                if ($workflow['creator_id'] == $userId || in_array($userRole, ['admin', 'super_admin'])) {
                    $availableActions[] = [
                        'action' => 'submit',
                        'label' => 'Reinvia dopo Modifica',
                        'description' => 'Reinvia il documento corretto',
                        'endpoint' => '/api/documents/workflow/submit.php',
                        'method' => 'POST'
                    ];
                }
                break;

            case WORKFLOW_STATE_APPROVED:
                // No actions available - workflow completed
                break;
        }

        // Always allow viewing history if user has access
        $availableActions[] = [
            'action' => 'view_history',
            'label' => 'Visualizza Storia',
            'description' => 'Visualizza la storia completa del workflow',
            'endpoint' => '/api/documents/workflow/history.php',
            'method' => 'GET'
        ];

        $response['available_actions'] = $availableActions;

        // Add next step hint
        $response['next_step'] = getNextStepHint($currentState, $userRoleInWorkflow);
    }

    api_success($response, 'Stato workflow recuperato con successo.');

} catch (Exception $e) {
    error_log("[WORKFLOW_STATUS] Error: " . $e->getMessage());
    api_error('Errore durante recupero stato workflow: ' . $e->getMessage(), 500);
}

/**
 * Get human-readable state description
 */
function getStateDescription(string $state): string {
    $descriptions = [
        WORKFLOW_STATE_DRAFT => 'Il documento è in bozza e può essere inviato per validazione',
        WORKFLOW_STATE_IN_VALIDATION => 'Il documento è in attesa di validazione',
        WORKFLOW_STATE_VALIDATED => 'Il documento è stato validato',
        WORKFLOW_STATE_IN_APPROVAL => 'Il documento è in attesa di approvazione finale',
        WORKFLOW_STATE_APPROVED => 'Il documento è stato approvato definitivamente',
        WORKFLOW_STATE_REJECTED => 'Il documento è stato rifiutato e deve essere modificato'
    ];

    return $descriptions[$state] ?? 'Stato sconosciuto';
}

/**
 * Get progress percentage for state
 */
function getProgressPercentage(string $state): int {
    $progress = [
        WORKFLOW_STATE_DRAFT => 0,
        WORKFLOW_STATE_IN_VALIDATION => 25,
        WORKFLOW_STATE_VALIDATED => 50,
        WORKFLOW_STATE_IN_APPROVAL => 75,
        WORKFLOW_STATE_APPROVED => 100,
        WORKFLOW_STATE_REJECTED => 0
    ];

    return $progress[$state] ?? 0;
}

/**
 * Get next step hint based on state and user role
 */
function getNextStepHint(string $state, ?string $userRole): string {
    if ($state === WORKFLOW_STATE_APPROVED) {
        return 'Workflow completato. Il documento è approvato.';
    }

    if ($userRole === 'creator') {
        if ($state === WORKFLOW_STATE_REJECTED) {
            return 'Modifica il documento e reinvialo per validazione.';
        }
        if (in_array($state, [WORKFLOW_STATE_IN_VALIDATION, WORKFLOW_STATE_IN_APPROVAL])) {
            return 'Il documento è in revisione. Attendi il feedback.';
        }
    }

    if ($userRole === 'validator' && $state === WORKFLOW_STATE_IN_VALIDATION) {
        return 'Revisiona il documento e decidi se validarlo o rifiutarlo.';
    }

    if ($userRole === 'approver' && $state === WORKFLOW_STATE_IN_APPROVAL) {
        return 'Revisiona il documento per l\'approvazione finale.';
    }

    return 'Visualizza lo stato del workflow e le azioni disponibili.';
}