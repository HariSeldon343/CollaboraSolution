<?php
/**
 * Document Workflow API - Submit Document for Validation
 *
 * Submits a document to start the validation workflow
 * Only the creator of the document can submit it
 * State transition: bozza → in_validazione
 *
 * Method: POST
 * Input: file_id
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
$validatorId = isset($input['validator_id']) ? (int)$input['validator_id'] : null;
$approverId = isset($input['approver_id']) ? (int)$input['approver_id'] : null;
$notes = $input['notes'] ?? null;

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
    // VALIDATION - File exists and user is creator
    // ============================================

    $file = $db->fetchOne(
        "SELECT id, file_name, uploaded_by, folder_id
         FROM files
         WHERE id = ?
           AND tenant_id = ?
           AND deleted_at IS NULL",
        [$fileId, $tenantId]
    );

    if ($file === false) {
        throw new Exception('File non trovato nel tenant corrente.');
    }

    // Check if user is the creator (or admin)
    if ($file['uploaded_by'] !== $userId && !in_array($userRole, ['manager', 'admin', 'super_admin'])) {
        throw new Exception('Solo il creatore del documento può inviarlo per validazione.');
    }

    // ============================================
    // CHECK EXISTING WORKFLOW
    // ============================================

    $existingWorkflow = $db->fetchOne(
        "SELECT id, state, current_validator_id, current_approver_id
         FROM document_workflow
         WHERE file_id = ?
           AND tenant_id = ?
           AND deleted_at IS NULL",
        [$fileId, $tenantId]
    );

    if ($existingWorkflow !== false) {
        // Check if workflow is in a state that allows resubmission
        if (!in_array($existingWorkflow['state'], [WORKFLOW_STATE_DRAFT, WORKFLOW_STATE_REJECTED])) {
            throw new Exception(
                sprintf(
                    'Il documento è già nel workflow con stato: %s',
                    getWorkflowStateLabel($existingWorkflow['state'])
                )
            );
        }

        $workflowId = $existingWorkflow['id'];
        $operation = 'resubmitted';
    } else {
        $workflowId = null;
        $operation = 'submitted';
    }

    // ============================================
    // VALIDATE VALIDATOR AND APPROVER
    // ============================================

    // If not specified, get first available validator and approver
    if (!$validatorId) {
        $firstValidator = $db->fetchOne(
            "SELECT user_id
             FROM workflow_roles
             WHERE tenant_id = ?
               AND workflow_role = ?
               AND is_active = 1
               AND deleted_at IS NULL
             ORDER BY created_at ASC
             LIMIT 1",
            [$tenantId, WORKFLOW_ROLE_VALIDATOR]
        );

        if ($firstValidator === false) {
            throw new Exception('Nessun validatore disponibile nel sistema. Configurare almeno un validatore.');
        }

        $validatorId = $firstValidator['user_id'];
    } else {
        // Validate specified validator
        if (!userHasWorkflowRole($validatorId, $tenantId, WORKFLOW_ROLE_VALIDATOR)) {
            throw new Exception('Il validatore specificato non ha il ruolo di validatore.');
        }
    }

    if (!$approverId) {
        $firstApprover = $db->fetchOne(
            "SELECT user_id
             FROM workflow_roles
             WHERE tenant_id = ?
               AND workflow_role = ?
               AND is_active = 1
               AND deleted_at IS NULL
             ORDER BY created_at ASC
             LIMIT 1",
            [$tenantId, WORKFLOW_ROLE_APPROVER]
        );

        if ($firstApprover === false) {
            throw new Exception('Nessun approvatore disponibile nel sistema. Configurare almeno un approvatore.');
        }

        $approverId = $firstApprover['user_id'];
    } else {
        // Validate specified approver
        if (!userHasWorkflowRole($approverId, $tenantId, WORKFLOW_ROLE_APPROVER)) {
            throw new Exception('L\'approvatore specificato non ha il ruolo di approvatore.');
        }
    }

    // ============================================
    // CREATE OR UPDATE WORKFLOW
    // ============================================

    $workflowData = [
        'tenant_id' => $tenantId,
        'file_id' => $fileId,
        'state' => WORKFLOW_STATE_IN_VALIDATION,
        'created_by_user_id' => $file['uploaded_by'],
        'current_validator_id' => $validatorId,
        'current_approver_id' => $approverId,
        'submitted_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    if ($workflowId) {
        // Update existing workflow
        $updated = $db->update(
            'document_workflow',
            $workflowData,
            ['id' => $workflowId]
        );

        if (!$updated) {
            throw new Exception('Impossibile aggiornare workflow.');
        }
    } else {
        // Create new workflow
        $workflowData['created_at'] = date('Y-m-d H:i:s');

        $workflowId = $db->insert('document_workflow', $workflowData);

        if (!$workflowId) {
            throw new Exception('Impossibile creare workflow.');
        }
    }

    // ============================================
    // CREATE HISTORY ENTRY
    // ============================================

    $historyData = [
        'tenant_id' => $tenantId,
        'workflow_id' => $workflowId,
        'file_id' => $fileId,
        'from_state' => $existingWorkflow ? $existingWorkflow['state'] : WORKFLOW_STATE_DRAFT,
        'to_state' => WORKFLOW_STATE_IN_VALIDATION,
        'transition_type' => TRANSITION_SUBMIT,
        'performed_by_user_id' => $userId,
        'performed_by_role' => USER_ROLE_CREATOR,
        'comment' => $notes,
        'metadata' => buildWorkflowMetadata([
            'validator_id' => $validatorId,
            'approver_id' => $approverId,
            'operation' => $operation
        ]),
        'created_at' => date('Y-m-d H:i:s')
    ];

    $historyId = $db->insert('document_workflow_history', $historyData);

    if (!$historyId) {
        throw new Exception('Impossibile creare entry storica workflow.');
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
        // Get validator details
        $validator = $db->fetchOne(
            "SELECT name, email FROM users WHERE id = ?",
            [$validatorId]
        );

        if ($validator !== false) {
            require_once __DIR__ . '/../../../includes/mailer.php';

            $emailData = [
                'to' => $validator['email'],
                'to_name' => $validator['name'],
                'subject' => sprintf('Nuovo documento da validare: %s', $file['file_name']),
                'template' => 'workflow_submitted_to_validation',
                'variables' => [
                    'validator_name' => $validator['name'],
                    'document_name' => $file['file_name'],
                    'submitter_name' => $userInfo['user_name'],
                    'notes' => $notes,
                    'workflow_url' => sprintf(
                        'https://app.nexiosolution.it/CollaboraNexio/workflow.php?file_id=%d',
                        $fileId
                    )
                ]
            ];

            // Send email (non-blocking)
            sendWorkflowEmail($emailData);
        }
    } catch (Exception $e) {
        error_log("[EMAIL] Failed to send workflow notification: " . $e->getMessage());
        // Non-blocking - continue
    }

    // ============================================
    // AUDIT LOGGING (BUG-029/030)
    // ============================================

    try {
        require_once __DIR__ . '/../../../includes/audit_helper.php';

        $auditData = [
            'workflow_id' => $workflowId,
            'file_id' => $fileId,
            'file_name' => $file['file_name'],
            'from_state' => $existingWorkflow ? $existingWorkflow['state'] : WORKFLOW_STATE_DRAFT,
            'to_state' => WORKFLOW_STATE_IN_VALIDATION,
            'validator_id' => $validatorId,
            'approver_id' => $approverId,
            'notes' => $notes
        ];

        AuditLogger::logGeneric(
            $userId,
            $tenantId,
            TRANSITION_SUBMIT,
            'document_workflow',
            $workflowId,
            sprintf(
                'Documento "%s" inviato per validazione',
                $file['file_name']
            ),
            $auditData
        );
    } catch (Exception $e) {
        error_log("[AUDIT] Failed to log workflow submission: " . $e->getMessage());
        // Non-blocking - continue
    }

    // ============================================
    // SEND EMAIL NOTIFICATIONS (NON-BLOCKING)
    // ============================================

    try {
        require_once __DIR__ . '/../../../includes/workflow_email_notifier.php';
        WorkflowEmailNotifier::notifyDocumentSubmitted($fileId, $userId, $tenantId);
    } catch (Exception $emailEx) {
        error_log("[WORKFLOW_SUBMIT] Email notification failed: " . $emailEx->getMessage());
        // DO NOT throw - operation already committed
    }

    // ============================================
    // GET COMPLETE WORKFLOW DATA
    // ============================================

    $workflow = $db->fetchOne(
        "SELECT
            dw.*,
            uv.name as validator_name,
            uv.email as validator_email,
            ua.name as approver_name,
            ua.email as approver_email,
            uc.name as creator_name,
            uc.email as creator_email
         FROM document_workflow dw
         LEFT JOIN users uv ON dw.current_validator_id = uv.id
         LEFT JOIN users ua ON dw.current_approver_id = ua.id
         LEFT JOIN users uc ON dw.created_by_user_id = uc.id
         WHERE dw.id = ?",
        [$workflowId]
    );

    // ============================================
    // PREPARE RESPONSE
    // ============================================

    $response = [
        'workflow' => [
            'id' => $workflowId,
            'file_id' => $fileId,
            'file_name' => $file['file_name'],
            'state' => WORKFLOW_STATE_IN_VALIDATION,
            'state_label' => getWorkflowStateLabel(WORKFLOW_STATE_IN_VALIDATION),
            'state_color' => getWorkflowStateColor(WORKFLOW_STATE_IN_VALIDATION),
            'validator' => [
                'id' => $validatorId,
                'name' => $workflow['validator_name'],
                'email' => $workflow['validator_email']
            ],
            'approver' => [
                'id' => $approverId,
                'name' => $workflow['approver_name'],
                'email' => $workflow['approver_email']
            ],
            'creator' => [
                'id' => $file['uploaded_by'],
                'name' => $workflow['creator_name'],
                'email' => $workflow['creator_email']
            ],
            'submitted_at' => $workflowData['submitted_at'],
            'notes' => $notes,
            'operation' => $operation
        ]
    ];

    api_success(
        $response,
        sprintf(
            'Documento "%s" inviato per validazione con successo.',
            $file['file_name']
        )
    );

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();  // BEFORE api_error() (BUG-038)
    }

    error_log("[WORKFLOW_SUBMIT] Error: " . $e->getMessage());
    api_error('Errore durante invio documento per validazione: ' . $e->getMessage(), 500);
}

/**
 * Helper function to send workflow emails
 */
function sendWorkflowEmail(array $data): bool {
    try {
        // Load email template
        $templatePath = __DIR__ . '/../../../includes/email_templates/workflow/' . $data['template'] . '.html';

        if (!file_exists($templatePath)) {
            // Create simple template if not exists
            $emailContent = sprintf(
                '<p>Gentile %s,</p><p>%s</p><p>Cordiali saluti,<br>Sistema CollaboraNexio</p>',
                $data['variables']['validator_name'] ?? 'Utente',
                $data['subject']
            );
        } else {
            $emailContent = file_get_contents($templatePath);

            // Replace variables
            foreach ($data['variables'] as $key => $value) {
                $emailContent = str_replace('{{' . $key . '}}', $value, $emailContent);
            }
        }

        // Use existing mailer
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