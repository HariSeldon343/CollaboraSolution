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
$tenantIdFromUser = (int)($userInfo['tenant_id'] ?? 0);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$companyFilterId = (isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null) ? (int)$_SESSION['company_filter_id'] : 0;
$tenantId = $companyFilterId > 0 ? $companyFilterId : $tenantIdFromUser;
$userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
$userRole = (string)($userInfo['role'] ?? 'user');

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
            "SELECT id, name, uploaded_by, folder_id, file_size, mime_type, tenant_id, is_folder
             FROM files
             WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '')",
            [$fileId]
        );
        if ($file && $file['tenant_id']) {
            $tenantId = (int)$file['tenant_id'];
        }
    } else {
        // If Company Filter is active (single tenant), keep strict tenant filtering.
        // If Company Filter is "Tutte le aziende" (company_filter_id is NULL), resolve tenant_id from the file row (admin/super_admin UX).
        $file = false;

        if ($companyFilterId > 0) {
            $file = $db->fetchOne(
                "SELECT id, name, uploaded_by, folder_id, file_size, mime_type, tenant_id, is_folder
                 FROM files
                 WHERE id = ?
                   AND tenant_id = ?
                   AND (deleted_at IS NULL OR deleted_at = '')",
                [$fileId, $tenantId]
            );
        } else {
            // First try strict tenant (backward compat for users/managers)
            if ($tenantId > 0) {
        $file = $db->fetchOne(
            "SELECT id, name, uploaded_by, folder_id, file_size, mime_type, tenant_id, is_folder
             FROM files
             WHERE id = ?
               AND tenant_id = ?
               AND (deleted_at IS NULL OR deleted_at = '')",
            [$fileId, $tenantId]
        );
            }

            // Admin (and sessions with missing/incorrect tenant) fallback: resolve from file row cross-tenant, then verify access.
            if ($file === false && in_array($userRole, ['admin'], true)) {
                $file = $db->fetchOne(
                    "SELECT id, name, uploaded_by, folder_id, file_size, mime_type, tenant_id, is_folder
                     FROM files
                     WHERE id = ?
                       AND (deleted_at IS NULL OR deleted_at = '')",
                    [$fileId]
                );
                if ($file && !empty($file['tenant_id'])) {
                    $resolvedTenantId = (int)$file['tenant_id'];
                    $hasTenantAccess = ($tenantIdFromUser > 0 && $tenantIdFromUser === $resolvedTenantId);
                    if (!$hasTenantAccess) {
                        $uta = $db->fetchOne(
                            "SELECT 1 FROM user_tenant_access WHERE user_id = ? AND tenant_id = ? LIMIT 1",
                            [$userId, $resolvedTenantId]
                        );
                        $hasTenantAccess = (bool)$uta;
                    }
                    if (!$hasTenantAccess) {
                        api_error('Non hai accesso a questo file.', 403);
                    }
                    $tenantId = $resolvedTenantId;
                }
            }
        }
    }

    if ($file === false) {
        api_error('File non trovato o accesso negato.', 404);
    }

    // Access rule for workflow status:
    // - super_admin: handled above (cross-tenant)
    // - same-tenant users: readonly status is allowed (no actions unless selected)
    // - if tenant cannot be resolved, fall back to strict check
    if ($userRole !== 'super_admin') {
        $fileTenantId = isset($file['tenant_id']) ? (int)$file['tenant_id'] : 0;
        if ($tenantId > 0 && $fileTenantId > 0 && $fileTenantId !== $tenantId) {
            api_error('Non hai accesso a questo file.', 403);
        }
    }

    // ============================================
    // GET CURRENT WORKFLOW
    // ============================================

    $isFolder = isset($file['is_folder']) && (int)$file['is_folder'] === 1;

    $workflow = false;

    if (!$isFolder) {
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
                            uc.id as creator_id,
                            uc.name as creator_name,
                            uc.email as creator_email
                     FROM document_workflow dw
                     LEFT JOIN users uc ON dw.created_by_user_id = uc.id
                     WHERE dw.file_id = ?
                       AND dw.tenant_id = ?
                       AND dw.deleted_at IS NULL",
                    [$fileId, $tenantId]
                );

                // Determine selected validator/approver for this document from history metadata (source of truth)
                if ($workflow !== false) {
                    $selected = getSelectedWorkflowParticipants((int)$tenantId, (int)$fileId);
                    $validatorId = $selected['validator_id'] ?? null;
                    $approverId = $selected['approver_id'] ?? null;

                    $workflow['validator_id'] = $validatorId ? (int)$validatorId : null;
                    $workflow['approver_id'] = $approverId ? (int)$approverId : null;

                    if ($workflow['validator_id']) {
                        $u = $db->fetchOne("SELECT id, name, email FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1", [$workflow['validator_id']]);
                        $workflow['validator_name'] = $u ? ($u['name'] ?? null) : null;
                        $workflow['validator_email'] = $u ? ($u['email'] ?? null) : null;
                    } else {
                        $workflow['validator_name'] = null;
                        $workflow['validator_email'] = null;
                    }

                    if ($workflow['approver_id']) {
                        $u = $db->fetchOne("SELECT id, name, email FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1", [$workflow['approver_id']]);
                        $workflow['approver_name'] = $u ? ($u['name'] ?? null) : null;
                        $workflow['approver_email'] = $u ? ($u['email'] ?? null) : null;
                    } else {
                        $workflow['approver_name'] = null;
                        $workflow['approver_email'] = null;
                    }
                }
            }
        } catch (Exception $e) {
            // Table doesn't exist or query failed - treat as no workflow
            $workflow = false;
        }
    }

    // ============================================
    // PREPARE RESPONSE DATA
    // ============================================

    $response = [
        'file' => [
            'id' => $fileId,
            'name' => $file['name'] ?? $file['file_name'] ?? '',
            'size' => isset($file['file_size']) ? (int)$file['file_size'] : 0,
            'mime_type' => $file['mime_type'] ?? null,
            'creator_id' => (int)$file['uploaded_by'],
            'is_creator' => (int)$file['uploaded_by'] === (int)$userId,
            'is_folder' => $isFolder
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
        if ($isFolder) {
            $response['message'] = 'I workflow sono disponibili solo per i documenti.';
            $response['can_start_workflow'] = false;
        } else {
            // Check if user can start workflow
            $response['can_start_workflow'] = (
                $file['uploaded_by'] == $userId ||
                in_array($userRole, ['manager', 'admin', 'super_admin'])
            );

            if ($response['can_start_workflow']) {
                // Frontend expects array of strings (see filemanager_enhanced.js renderSidebarWorkflowActions)
                $response['available_actions'][] = 'submit';
            }

            $response['message'] = 'Nessun workflow attivo per questo documento';
        }

    } else {
        // ============================================
        // WORKFLOW EXISTS - ANALYZE CURRENT STATE
        // ============================================

        $currentState = $workflow['current_state'];

        // Determine user's role in workflow
        $userRoleInWorkflow = null;
        if ($workflow['creator_id'] == $userId) {
            $userRoleInWorkflow = 'creator';
        }
        if (!empty($workflow['validator_id']) && (int)$workflow['validator_id'] == (int)$userId) {
            $userRoleInWorkflow = $userRoleInWorkflow ? 'creator_and_validator' : 'validator';
        }
        if (!empty($workflow['approver_id']) && (int)$workflow['approver_id'] == (int)$userId) {
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

        // Determine last action (BUG-074 FIX: removed non-existent validated_by_name, rejected_by_name, approved_by_name fields)
        if ($workflow['rejected_at']) {
            $response['workflow']['last_action'] = [
                'type' => 'rejection',
                'at' => $workflow['rejected_at']
            ];
        } elseif ($workflow['approved_at']) {
            $response['workflow']['last_action'] = [
                'type' => 'approval',
                'at' => $workflow['approved_at']
            ];
        } elseif ($workflow['validated_at']) {
            $response['workflow']['last_action'] = [
                'type' => 'validation',
                'at' => $workflow['validated_at']
            ];
        }

        // ============================================
        // DETERMINE AVAILABLE ACTIONS
        // ============================================

        $availableActions = [];

        // Only selected participants (or admin/super_admin) can act; others see readonly status.
        $isCreator = ($workflow['creator_id'] == $userId);
        $isValidator = !empty($workflow['validator_id']) && (int)$workflow['validator_id'] == (int)$userId;
        $isApprover = !empty($workflow['approver_id']) && (int)$workflow['approver_id'] == (int)$userId;
        $revertTarget = getWorkflowRevertTarget($currentState, $userRole, $isCreator, $isValidator, $isApprover);

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
                if ((!empty($workflow['validator_id']) && (int)$workflow['validator_id'] == (int)$userId) || in_array($userRole, ['admin', 'super_admin'])) {
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
                if ((!empty($workflow['approver_id']) && (int)$workflow['approver_id'] == (int)$userId) || in_array($userRole, ['admin', 'super_admin'])) {
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

        // Revert/recall action for authorized roles (manager/admin/super_admin/creator or validator/approver step-back)
        if ($revertTarget && $currentState !== WORKFLOW_STATE_DRAFT) {
            $label = ($revertTarget === WORKFLOW_STATE_DRAFT) ? 'Riapri in Bozza' : 'Riporta allo step precedente';
            $availableActions[] = [
                'action' => 'recall',
                'label' => $label,
                'description' => 'Riporta il documento allo stato precedente',
                'endpoint' => '/api/documents/workflow/recall.php',
                'method' => 'POST'
            ];
        }

        // BUG-084 FIX: Removed view_history from available_actions
        // User already has dedicated "Visualizza Storico" button at modal bottom (line 845 document_workflow_v2.js)
        // view_history action had no frontend handler in showActionModal(), causing debug-style button with literal text
        // Keeping workflow actions limited to state-changing operations: submit, validate, approve, reject, recall

        // BUG-083 FIX: Extract action names for frontend compatibility
        // Frontend expects array of STRINGS ["validate", "reject", ...] not array of OBJECTS
        // This ensures sidebar buttons render correctly (actionConfigs[action] lookup works)
        $actionNames = array_map(function($action) {
            return $action['action'];
        }, $availableActions);

        // BUG-150c FIX: Remove duplicate actions (e.g., 'recall' added by both creator check and getWorkflowRevertTarget)
        // This prevents duplicate buttons in the sidebar
        $actionNames = array_values(array_unique($actionNames));

        // Keep both formats for backward compatibility and future use
        $response['available_actions'] = $actionNames;  // ✅ Array of strings for button rendering
        $response['available_actions_detailed'] = $availableActions;  // Full objects with metadata

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