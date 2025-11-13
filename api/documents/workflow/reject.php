<?php
/**
 * Document Workflow API - Reject Document
 *
 * Rejects a document at any stage (validator or approver)
 * State transition: any → rifiutato
 * Comment is REQUIRED for rejection
 *
 * Method: POST
 * Input: file_id, comment (REQUIRED)
 * Response: workflow object
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

// Only POST method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    api_error('Metodo non consentito. Usare POST.', 405);
}

// ============================================
// INPUT VALIDATION
// ============================================

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    api_error('Dati JSON non validi: ' . json_last_error_msg(), 400);
}

// Extract parameters
$fileId = isset($input['file_id']) ? (int)$input['file_id'] : null;
$comment = $input['comment'] ?? null;

// Validate file_id
if (!$fileId || $fileId <= 0) {
    api_error('file_id richiesto e deve essere positivo.', 400);
}

// Validate rejection reason (REQUIRED)
$validationResult = validateRejectionReason($comment);
if (!$validationResult['valid']) {
    api_error($validationResult['error'], 400);
}

// ============================================
// DATABASE OPERATIONS
// ============================================

$db->beginTransaction();

try {
    // ============================================
    // GET WORKFLOW AND VALIDATE PERMISSIONS
    // ============================================

    $workflow = $db->fetchOne(
        "SELECT dw.*,
                f.file_name,
                uv.name as validator_name,
                uv.email as validator_email,
                ua.name as approver_name,
                ua.email as approver_email,
                uc.name as creator_name,
                uc.email as creator_email
         FROM document_workflow dw
         INNER JOIN files f ON dw.file_id = f.id
         LEFT JOIN users uv ON dw.current_validator_id = uv.id
         LEFT JOIN users ua ON dw.current_approver_id = ua.id
         LEFT JOIN users uc ON dw.created_by_user_id = uc.id
         WHERE dw.file_id = ?
           AND dw.tenant_id = ?
           AND dw.deleted_at IS NULL",
        [$fileId, $tenantId]
    );

    if ($workflow === false) {
        throw new Exception('Workflow non trovato per questo documento.');
    }

    // Check if document can be rejected from current state
    if (in_array($workflow['current_state'], [WORKFLOW_STATE_APPROVED, WORKFLOW_STATE_REJECTED])) {
        throw new Exception(
            sprintf(
                'Il documento non può essere rifiutato dallo stato: %s',
                getWorkflowStateLabel($workflow['current_state'])
            )
        );
    }

    // Determine user role in workflow
    $workflowUserRole = null;
    $canReject = false;

    // Check if user is validator
    if ($workflow['current_state'] === WORKFLOW_STATE_IN_VALIDATION &&
        $workflow['current_validator_id'] === $userId) {
        $workflowUserRole = USER_ROLE_VALIDATOR;
        $canReject = true;
    }

    // Check if user is approver
    elseif ($workflow['current_state'] === WORKFLOW_STATE_IN_APPROVAL &&
            $workflow['current_approver_id'] === $userId) {
        $workflowUserRole = USER_ROLE_APPROVER;
        $canReject = true;
    }

    // Admins can always reject
    elseif (in_array($userRole, ['admin', 'super_admin'])) {
        $workflowUserRole = USER_ROLE_ADMIN;
        $canReject = true;
    }

    if (!$canReject) {
        throw new Exception('Non hai i permessi per rifiutare questo documento nello stato corrente.');
    }

    // ============================================
    // COUNT PREVIOUS REJECTIONS
    // ============================================

    $rejectionCount = $db->fetchOne(
        "SELECT COUNT(*) as count
         FROM document_workflow_history
         WHERE workflow_id = ?
           AND transition_type = ?",
        [$workflow['id'], TRANSITION_REJECT]
    );

    $previousRejections = (int)$rejectionCount['count'];

    // ============================================
    // UPDATE WORKFLOW STATE
    // ============================================

    $updateData = [
        'current_state' => WORKFLOW_STATE_REJECTED,
        'rejected_at' => date('Y-m-d H:i:s'),
        'rejected_by_user_id' => $userId,
        'rejection_count' => $previousRejections + 1,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    // Clear validation/approval timestamps on rejection
    if ($workflow['current_state'] === WORKFLOW_STATE_IN_VALIDATION) {
        $updateData['validated_at'] = null;
        $updateData['validated_by_user_id'] = null;
    } elseif ($workflow['current_state'] === WORKFLOW_STATE_IN_APPROVAL) {
        $updateData['approved_at'] = null;
        $updateData['approved_by_user_id'] = null;
    }

    $updated = $db->update(
        'document_workflow',
        $updateData,
        ['id' => $workflow['id']]
    );

    if (!$updated) {
        throw new Exception('Impossibile aggiornare stato workflow.');
    }

    // ============================================
    // CREATE HISTORY ENTRY
    // ============================================

    $historyData = [
        'tenant_id' => $tenantId,
        'workflow_id' => $workflow['id'],
        'file_id' => $fileId,
        'from_state' => $workflow['current_state'],
        'to_state' => WORKFLOW_STATE_REJECTED,
        'transition_type' => TRANSITION_REJECT,
        'performed_by_user_id' => $userId,
        'performed_by_role' => $workflowUserRole,
        'comment' => trim($comment),
        'metadata' => buildWorkflowMetadata([
            'rejection_reason' => trim($comment),
            'rejected_by' => $userInfo['user_name'],
            'rejection_count' => $previousRejections + 1,
            'rejected_at_state' => $workflow['current_state']
        ]),
        'created_at' => date('Y-m-d H:i:s')
    ];

    $historyId = $db->insert('document_workflow_history', $historyData);

    if (!$historyId) {
        throw new Exception('Impossibile creare entry storica rifiuto.');
    }

    // ============================================
    // COMMIT TRANSACTION (BUG-038/039/045)
    // ============================================

    if (!$db->commit()) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        throw new Exception('Impossibile confermare la transazione.');
    }

    // ============================================
    // SEND EMAIL NOTIFICATION TO CREATOR
    // ============================================

    try {
        if ($workflow['creator_email']) {
            require_once __DIR__ . '/../../../includes/mailer.php';

            $emailData = [
                'to' => $workflow['creator_email'],
                'to_name' => $workflow['creator_name'],
                'subject' => sprintf('Documento rifiutato: %s', $workflow['file_name']),
                'template' => 'workflow_rejected',
                'variables' => [
                    'creator_name' => $workflow['creator_name'],
                    'document_name' => $workflow['file_name'],
                    'rejected_by' => $userInfo['user_name'],
                    'rejected_role' => $workflowUserRole === USER_ROLE_VALIDATOR ? 'Validatore' : 'Approvatore',
                    'rejection_reason' => trim($comment),
                    'rejection_count' => $previousRejections + 1,
                    'workflow_url' => sprintf(
                        'https://app.nexiosolution.it/CollaboraNexio/workflow.php?file_id=%d',
                        $fileId
                    )
                ]
            ];

            sendWorkflowEmail($emailData);

            // If rejected multiple times, send warning
            if ($previousRejections + 1 >= MAX_REJECTION_WARNING_THRESHOLD) {
                $emailData['subject'] = sprintf(
                    'ATTENZIONE: Documento rifiutato %d volte - %s',
                    $previousRejections + 1,
                    $workflow['file_name']
                );
                $emailData['template'] = 'workflow_rejection_warning';
                $emailData['variables']['warning'] = true;

                sendWorkflowEmail($emailData);
            }
        }
    } catch (Exception $e) {
        error_log("[EMAIL] Failed to send rejection notification: " . $e->getMessage());
        // Non-blocking - continue
    }

    // ============================================
    // AUDIT LOGGING (BUG-029/030)
    // ============================================

    try {
        require_once __DIR__ . '/../../../includes/audit_helper.php';

        $auditData = [
            'workflow_id' => $workflow['id'],
            'file_id' => $fileId,
            'file_name' => $workflow['file_name'],
            'from_state' => $workflow['current_state'],
            'to_state' => WORKFLOW_STATE_REJECTED,
            'rejected_by' => $userInfo['user_name'],
            'rejection_reason' => trim($comment),
            'rejection_count' => $previousRejections + 1
        ];

        AuditLogger::logGeneric(
            $userId,
            $tenantId,
            TRANSITION_REJECT,
            'document_workflow',
            $workflow['id'],
            sprintf(
                'Documento "%s" rifiutato da %s: %s',
                $workflow['file_name'],
                $workflowUserRole === USER_ROLE_VALIDATOR ? 'validatore' : 'approvatore',
                substr(trim($comment), 0, 50) . (strlen(trim($comment)) > 50 ? '...' : '')
            ),
            $auditData
        );
    } catch (Exception $e) {
        error_log("[AUDIT] Failed to log rejection: " . $e->getMessage());
        // Non-blocking - continue
    }

    // ============================================
    // PREPARE RESPONSE
    // ============================================

    $response = [
        'workflow' => [
            'id' => $workflow['id'],
            'file_id' => $fileId,
            'file_name' => $workflow['file_name'],
            'state' => WORKFLOW_STATE_REJECTED,
            'state_label' => getWorkflowStateLabel(WORKFLOW_STATE_REJECTED),
            'state_color' => getWorkflowStateColor(WORKFLOW_STATE_REJECTED),
            'rejection' => [
                'reason' => trim($comment),
                'rejected_by' => [
                    'id' => $userId,
                    'name' => $userInfo['user_name'],
                    'email' => $userInfo['user_email'],
                    'role' => $workflowUserRole === USER_ROLE_VALIDATOR ? 'Validatore' : 'Approvatore'
                ],
                'rejected_at' => $updateData['rejected_at'],
                'rejection_count' => $previousRejections + 1,
                'rejected_from_state' => $workflow['current_state']
            ],
            'creator' => [
                'id' => $workflow['created_by_user_id'],
                'name' => $workflow['creator_name'],
                'email' => $workflow['creator_email']
            ],
            'next_action' => 'Il creatore può modificare il documento e reinviarlo per validazione'
        ]
    ];

    // Add warning if rejected multiple times
    if ($previousRejections + 1 >= MAX_REJECTION_WARNING_THRESHOLD) {
        $response['workflow']['warning'] = sprintf(
            'Attenzione: Questo documento è stato rifiutato %d volte',
            $previousRejections + 1
        );
    }

    // ============================================
    // SEND EMAIL NOTIFICATIONS (NON-BLOCKING)
    // ============================================

    try {
        require_once __DIR__ . '/../../../includes/workflow_email_notifier.php';
        WorkflowEmailNotifier::notifyDocumentRejected(
            $fileId,
            $userId,
            $tenantId,
            $workflow['current_state'],  // Pass current state to determine email recipients
            $comment
        );
    } catch (Exception $emailEx) {
        error_log("[WORKFLOW_REJECT] Email notification failed: " . $emailEx->getMessage());
        // DO NOT throw - operation already committed
    }

    api_success(
        $response,
        sprintf(
            'Documento "%s" rifiutato. Il creatore è stato notificato.',
            $workflow['file_name']
        )
    );

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();  // BEFORE api_error() (BUG-038)
    }

    error_log("[WORKFLOW_REJECT] Error: " . $e->getMessage());
    api_error('Errore durante rifiuto documento: ' . $e->getMessage(), 500);
}

/**
 * Helper function to send workflow emails
 */
function sendWorkflowEmail(array $data): bool {
    try {
        $templatePath = __DIR__ . '/../../../includes/email_templates/workflow/' . $data['template'] . '.html';

        if (!file_exists($templatePath)) {
            $emailContent = sprintf(
                '<p>Gentile %s,</p><p>%s</p>%s<p>Cordiali saluti,<br>Sistema CollaboraNexio</p>',
                $data['variables']['creator_name'] ?? 'Utente',
                $data['subject'],
                isset($data['variables']['rejection_reason'])
                    ? '<p><strong>Motivo del rifiuto:</strong><br>' . htmlspecialchars($data['variables']['rejection_reason']) . '</p>'
                    : ''
            );
        } else {
            $emailContent = file_get_contents($templatePath);
            foreach ($data['variables'] as $key => $value) {
                $emailContent = str_replace('{{' . $key . '}}', $value ?? '', $emailContent);
            }
        }

        require_once __DIR__ . '/../../../includes/mailer.php';
        $emailSender = new EmailSender();
        return $emailSender->send(
            $data['to'],
            $data['to_name'],
            $data['subject'],
            $emailContent
        );

    } catch (Exception $e) {
        error_log("[EMAIL] Error sending workflow email: " . $e->getMessage());
        return false;
    }
}