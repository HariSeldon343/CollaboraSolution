<?php
/**
 * Document Workflow API - Approve Document (Final Approval)
 *
 * Approver gives final approval to a document
 * State transition: in_approvazione → approvato
 * Only the assigned approver can approve
 *
 * Method: POST
 * Input: file_id, comment (optional)
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
                f.file_path,
                f.file_size,
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

    // Check if document is in approval state
    if ($workflow['state'] !== WORKFLOW_STATE_IN_APPROVAL) {
        throw new Exception(
            sprintf(
                'Il documento non è in fase di approvazione. Stato attuale: %s',
                getWorkflowStateLabel($workflow['state'])
            )
        );
    }

    // Check if user is the assigned approver (or admin)
    if ($workflow['current_approver_id'] !== $userId && !in_array($userRole, ['admin', 'super_admin'])) {
        throw new Exception('Solo l\'approvatore assegnato può approvare questo documento.');
    }

    // ============================================
    // CALCULATE WORKFLOW DURATION
    // ============================================

    $workflowDuration = null;
    if ($workflow['submitted_at']) {
        $startTime = strtotime($workflow['submitted_at']);
        $endTime = time();
        $workflowDuration = [
            'seconds' => $endTime - $startTime,
            'days' => floor(($endTime - $startTime) / 86400),
            'hours' => floor((($endTime - $startTime) % 86400) / 3600)
        ];
    }

    // ============================================
    // UPDATE WORKFLOW STATE
    // ============================================

    $updateData = [
        'state' => WORKFLOW_STATE_APPROVED,
        'approved_at' => date('Y-m-d H:i:s'),
        'approved_by_user_id' => $userId,
        'updated_at' => date('Y-m-d H:i:s')
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
        'from_state' => WORKFLOW_STATE_IN_APPROVAL,
        'to_state' => WORKFLOW_STATE_APPROVED,
        'transition_type' => TRANSITION_APPROVE,
        'performed_by_user_id' => $userId,
        'performed_by_role' => USER_ROLE_APPROVER,
        'comment' => $comment,
        'metadata' => buildWorkflowMetadata([
            'approver_name' => $userInfo['user_name'],
            'workflow_duration' => $workflowDuration,
            'rejection_count' => $workflow['rejection_count'] ?? 0
        ]),
        'created_at' => date('Y-m-d H:i:s')
    ];

    $historyId = $db->insert('document_workflow_history', $historyData);

    if (!$historyId) {
        throw new Exception('Impossibile creare entry storica approvazione.');
    }

    // ============================================
    // OPTIONAL: Mark file as approved (add metadata)
    // ============================================

    // You might want to update the files table with approval metadata
    $fileUpdateData = [
        'metadata' => json_encode([
            'workflow_approved' => true,
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => $userId,
            'workflow_id' => $workflow['id']
        ]),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $db->update('files', $fileUpdateData, ['id' => $fileId]);

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

        // Notify creator that document is approved
        if ($workflow['creator_email']) {
            $emailData = [
                'to' => $workflow['creator_email'],
                'to_name' => $workflow['creator_name'],
                'subject' => sprintf('Documento approvato: %s', $workflow['file_name']),
                'template' => 'workflow_final_approved',
                'variables' => [
                    'creator_name' => $workflow['creator_name'],
                    'document_name' => $workflow['file_name'],
                    'approver_name' => $userInfo['user_name'],
                    'validator_name' => $workflow['validator_name'],
                    'comment' => $comment,
                    'workflow_duration_days' => $workflowDuration['days'] ?? 0,
                    'workflow_duration_hours' => $workflowDuration['hours'] ?? 0,
                    'document_url' => sprintf(
                        'https://app.nexiosolution.it/CollaboraNexio/files.php?id=%d',
                        $fileId
                    )
                ]
            ];

            sendWorkflowEmail($emailData);
        }

        // Optionally notify validator as well
        if ($workflow['validator_email'] && $workflow['validator_email'] !== $workflow['creator_email']) {
            $emailData['to'] = $workflow['validator_email'];
            $emailData['to_name'] = $workflow['validator_name'];
            $emailData['variables']['validator_name'] = $workflow['validator_name'];
            $emailData['subject'] = sprintf('Documento approvato: %s', $workflow['file_name']);

            sendWorkflowEmail($emailData);
        }
    } catch (Exception $e) {
        error_log("[EMAIL] Failed to send approval notification: " . $e->getMessage());
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
            'from_state' => WORKFLOW_STATE_IN_APPROVAL,
            'to_state' => WORKFLOW_STATE_APPROVED,
            'approver_name' => $userInfo['user_name'],
            'comment' => $comment,
            'workflow_duration' => $workflowDuration
        ];

        AuditLogger::logGeneric(
            $userId,
            $tenantId,
            TRANSITION_APPROVE,
            'document_workflow',
            $workflow['id'],
            sprintf(
                'Documento "%s" approvato definitivamente',
                $workflow['file_name']
            ),
            $auditData
        );
    } catch (Exception $e) {
        error_log("[AUDIT] Failed to log approval: " . $e->getMessage());
        // Non-blocking - continue
    }

    // ============================================
    // SEND EMAIL NOTIFICATIONS (NON-BLOCKING)
    // ============================================

    try {
        require_once __DIR__ . '/../../../includes/workflow_email_notifier.php';
        WorkflowEmailNotifier::notifyDocumentApproved($fileId, $userId, $tenantId, $comment);
    } catch (Exception $emailEx) {
        error_log("[WORKFLOW_APPROVE] Email notification failed: " . $emailEx->getMessage());
        // DO NOT throw - operation already committed
    }

    // ============================================
    // PREPARE RESPONSE
    // ============================================

    $response = [
        'workflow' => [
            'id' => $workflow['id'],
            'file_id' => $fileId,
            'file_name' => $workflow['file_name'],
            'state' => WORKFLOW_STATE_APPROVED,
            'state_label' => getWorkflowStateLabel(WORKFLOW_STATE_APPROVED),
            'state_color' => getWorkflowStateColor(WORKFLOW_STATE_APPROVED),
            'approval' => [
                'approved_by' => [
                    'id' => $userId,
                    'name' => $userInfo['user_name'],
                    'email' => $userInfo['user_email']
                ],
                'approved_at' => $updateData['approved_at'],
                'comment' => $comment
            ],
            'validator' => [
                'id' => $workflow['current_validator_id'],
                'name' => $workflow['validator_name'],
                'validated_at' => $workflow['validated_at']
            ],
            'creator' => [
                'id' => $workflow['created_by_user_id'],
                'name' => $workflow['creator_name']
            ],
            'workflow_duration' => $workflowDuration,
            'rejection_count' => $workflow['rejection_count'] ?? 0,
            'completion_message' => 'Workflow completato con successo. Il documento è ora approvato e disponibile.'
        ]
    ];

    api_success(
        $response,
        sprintf(
            'Documento "%s" approvato con successo. Workflow completato.',
            $workflow['file_name']
        )
    );

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();  // BEFORE api_error() (BUG-038)
    }

    error_log("[WORKFLOW_APPROVE] Error: " . $e->getMessage());
    api_error('Errore durante approvazione documento: ' . $e->getMessage(), 500);
}

/**
 * Helper function to send workflow emails
 */
function sendWorkflowEmail(array $data): bool {
    try {
        $templatePath = __DIR__ . '/../../../includes/email_templates/workflow/' . $data['template'] . '.html';

        if (!file_exists($templatePath)) {
            $emailContent = sprintf(
                '<p>Gentile %s,</p><p>%s</p><p>Cordiali saluti,<br>Sistema CollaboraNexio</p>',
                $data['variables']['creator_name'] ?? $data['variables']['validator_name'] ?? 'Utente',
                $data['subject']
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