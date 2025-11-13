<?php
/**
 * Document Workflow API - Recall Document
 *
 * Creator recalls a document from the workflow
 * State transition: any non-final state → bozza
 * Only the creator can recall
 *
 * Method: POST
 * Input: file_id, reason (optional)
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
$reason = $input['reason'] ?? null;

// Validate file_id
if (!$fileId || $fileId <= 0) {
    api_error('file_id richiesto e deve essere positivo.', 400);
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
                f.uploaded_by,
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

    // Check if document can be recalled from current state
    if (in_array($workflow['current_state'], [WORKFLOW_STATE_APPROVED, WORKFLOW_STATE_DRAFT])) {
        if ($workflow['current_state'] === WORKFLOW_STATE_APPROVED) {
            throw new Exception('Non è possibile richiamare un documento già approvato.');
        } elseif ($workflow['current_state'] === WORKFLOW_STATE_DRAFT) {
            throw new Exception('Il documento è già in stato bozza.');
        }
    }

    // Check if user is the creator (or admin)
    if ($workflow['created_by_user_id'] !== $userId &&
        $workflow['uploaded_by'] !== $userId &&
        !in_array($userRole, ['admin', 'super_admin'])) {
        throw new Exception('Solo il creatore del documento può richiamarlo dal workflow.');
    }

    // ============================================
    // UPDATE WORKFLOW STATE
    // ============================================

    $previousState = $workflow['current_state'];

    $updateData = [
        'current_state' => WORKFLOW_STATE_DRAFT,
        'updated_at' => date('Y-m-d H:i:s'),
        // Clear approval/validation data on recall
        'validated_at' => null,
        'validated_by_user_id' => null,
        'approved_at' => null,
        'approved_by_user_id' => null,
        'rejected_at' => null,
        'rejected_by_user_id' => null
    ];

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
        'from_state' => $previousState,
        'to_state' => WORKFLOW_STATE_DRAFT,
        'transition_type' => TRANSITION_RECALL,
        'performed_by_user_id' => $userId,
        'performed_by_role' => USER_ROLE_CREATOR,
        'comment' => $reason,
        'metadata' => buildWorkflowMetadata([
            'recall_reason' => $reason,
            'recalled_from_state' => $previousState
        ]),
        'created_at' => date('Y-m-d H:i:s')
    ];

    $historyId = $db->insert('document_workflow_history', $historyData);

    if (!$historyId) {
        throw new Exception('Impossibile creare entry storica richiamo.');
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
    // SEND EMAIL NOTIFICATIONS
    // ============================================

    try {
        require_once __DIR__ . '/../../../includes/mailer.php';

        // Notify validator if document was in validation
        if ($previousState === WORKFLOW_STATE_IN_VALIDATION && $workflow['validator_email']) {
            $emailData = [
                'to' => $workflow['validator_email'],
                'to_name' => $workflow['validator_name'],
                'subject' => sprintf('Documento richiamato: %s', $workflow['file_name']),
                'template' => 'workflow_recalled',
                'variables' => [
                    'recipient_name' => $workflow['validator_name'],
                    'document_name' => $workflow['file_name'],
                    'creator_name' => $workflow['creator_name'],
                    'reason' => $reason ?: 'Nessun motivo specificato',
                    'previous_state' => getWorkflowStateLabel($previousState)
                ]
            ];

            sendWorkflowEmail($emailData);
        }

        // Notify approver if document was in approval
        if ($previousState === WORKFLOW_STATE_IN_APPROVAL && $workflow['approver_email']) {
            $emailData = [
                'to' => $workflow['approver_email'],
                'to_name' => $workflow['approver_name'],
                'subject' => sprintf('Documento richiamato: %s', $workflow['file_name']),
                'template' => 'workflow_recalled',
                'variables' => [
                    'recipient_name' => $workflow['approver_name'],
                    'document_name' => $workflow['file_name'],
                    'creator_name' => $workflow['creator_name'],
                    'reason' => $reason ?: 'Nessun motivo specificato',
                    'previous_state' => getWorkflowStateLabel($previousState)
                ]
            ];

            sendWorkflowEmail($emailData);
        }
    } catch (Exception $e) {
        error_log("[EMAIL] Failed to send recall notification: " . $e->getMessage());
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
            'from_state' => $previousState,
            'to_state' => WORKFLOW_STATE_DRAFT,
            'recall_reason' => $reason
        ];

        AuditLogger::logGeneric(
            $userId,
            $tenantId,
            TRANSITION_RECALL,
            'document_workflow',
            $workflow['id'],
            sprintf(
                'Documento "%s" richiamato da stato %s',
                $workflow['file_name'],
                getWorkflowStateLabel($previousState)
            ),
            $auditData
        );
    } catch (Exception $e) {
        error_log("[AUDIT] Failed to log recall: " . $e->getMessage());
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
            'state' => WORKFLOW_STATE_DRAFT,
            'state_label' => getWorkflowStateLabel(WORKFLOW_STATE_DRAFT),
            'state_color' => getWorkflowStateColor(WORKFLOW_STATE_DRAFT),
            'recall' => [
                'recalled_from' => $previousState,
                'recalled_from_label' => getWorkflowStateLabel($previousState),
                'recalled_by' => [
                    'id' => $userId,
                    'name' => $userInfo['user_name'],
                    'email' => $userInfo['user_email']
                ],
                'recalled_at' => date('Y-m-d H:i:s'),
                'reason' => $reason
            ],
            'creator' => [
                'id' => $workflow['created_by_user_id'],
                'name' => $workflow['creator_name']
            ],
            'next_action' => 'Il documento può essere modificato e reinviato per validazione'
        ]
    ];

    // Notify who was previously working on it
    if ($previousState === WORKFLOW_STATE_IN_VALIDATION) {
        $response['workflow']['notified'] = [
            'validator' => $workflow['validator_name']
        ];
    } elseif ($previousState === WORKFLOW_STATE_IN_APPROVAL) {
        $response['workflow']['notified'] = [
            'approver' => $workflow['approver_name']
        ];
    }

    api_success(
        $response,
        sprintf(
            'Documento "%s" richiamato con successo. Ora in stato bozza.',
            $workflow['file_name']
        )
    );

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();  // BEFORE api_error() (BUG-038)
    }

    error_log("[WORKFLOW_RECALL] Error: " . $e->getMessage());
    api_error('Errore durante richiamo documento: ' . $e->getMessage(), 500);
}

/**
 * Helper function to send workflow emails
 */
function sendWorkflowEmail(array $data): bool {
    try {
        $templatePath = __DIR__ . '/../../../includes/email_templates/workflow/' . $data['template'] . '.html';

        if (!file_exists($templatePath)) {
            $emailContent = sprintf(
                '<p>Gentile %s,</p><p>Il documento "%s" è stato richiamato dal workflow dal suo creatore.</p>' .
                '<p><strong>Motivo:</strong> %s</p><p>Cordiali saluti,<br>Sistema CollaboraNexio</p>',
                $data['variables']['recipient_name'] ?? 'Utente',
                $data['variables']['document_name'] ?? 'Documento',
                $data['variables']['reason'] ?? 'Non specificato'
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