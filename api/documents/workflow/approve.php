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
$userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
$userRole = (string)($userInfo['role'] ?? 'user');

verifyApiCsrfToken();

// BUG-088 FIX: Initialize database BEFORE multi-tenant context (needed for all code paths)
require_once __DIR__ . '/../../../includes/db.php';
$db = Database::getInstance();

// ============================================
// MULTI-TENANT CONTEXT HANDLING (BUG-087)
// ============================================
// Parse JSON input early to get tenant_id
$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    api_error('Dati JSON non validi: ' . json_last_error_msg(), 400);
}

// BUG-087 FIX: Accept tenant_id from frontend for multi-tenant navigation
// Same pattern as BUG-072 fix for role assignments
$requestedTenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : null;

if ($requestedTenantId !== null) {
    if ($userRole === 'super_admin') {
        $tenantId = $requestedTenantId;
    } else {
        $sessionTenantId = (int)($userInfo['tenant_id'] ?? 0);
        $hasAccess = ($requestedTenantId === $sessionTenantId && $sessionTenantId > 0);

        if (!$hasAccess) {
            $userTenant = $db->fetchOne(
                "SELECT 1 as ok
                 FROM users
                 WHERE id = ?
                   AND tenant_id = ?
                   AND (deleted_at IS NULL OR deleted_at = '')
                 LIMIT 1",
                [$userId, $requestedTenantId]
            );
            if ($userTenant) $hasAccess = true;
        }

        if (!$hasAccess) {
            $uta = $db->fetchOne(
                "SELECT 1 as ok
                 FROM user_tenant_access
                 WHERE user_id = ? AND tenant_id = ? AND deleted_at IS NULL
                 LIMIT 1",
                [$userId, $requestedTenantId]
            );
            if ($uta) $hasAccess = true;
        }

        // BUG-144 FIX: Removed user_companies table check (table does not exist)
        // Access is already checked via user_tenant_access table above
        if (!$hasAccess && $userRole === 'admin') {
            // Admin access already checked via user_tenant_access
            // No additional check needed
        }

        if ($hasAccess) {
            $tenantId = $requestedTenantId;
        } else {
            if ($db->inTransaction()) $db->rollback();
            api_error('Non hai accesso a questo tenant', 403);
        }
    }
} else {
    $tenantId = $userInfo['tenant_id'];
}

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
// (JSON input already parsed above for tenant_id handling)

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

    // BUG-089 FIX: Super admin can access workflows across tenants
    // BUG-091 FIX: Column name is 'name' not 'file_name', remove non-existent validator/approver columns
    if ($userRole === 'super_admin') {
        $workflow = $db->fetchOne(
            "SELECT dw.*,
                    f.name AS file_name,
                    f.file_path,
                    f.file_size,
                    uc.name as creator_name,
                    uc.email as creator_email
             FROM document_workflow dw
             INNER JOIN files f ON dw.file_id = f.id
             LEFT JOIN users uc ON dw.created_by_user_id = uc.id
             WHERE dw.file_id = ?
               AND (dw.deleted_at IS NULL OR dw.deleted_at = '')",
            [$fileId]
        );

        if ($workflow !== false) {
            // Use workflow's actual tenant, not session tenant
            $tenantId = $workflow['tenant_id'];
        }
    } else {
        $workflow = $db->fetchOne(
            "SELECT dw.*,
                    f.name AS file_name,
                    f.file_path,
                    f.file_size,
                    uc.name as creator_name,
                    uc.email as creator_email
             FROM document_workflow dw
             INNER JOIN files f ON dw.file_id = f.id
             LEFT JOIN users uc ON dw.created_by_user_id = uc.id
             WHERE dw.file_id = ?
               AND dw.tenant_id = ?
               AND (dw.deleted_at IS NULL OR dw.deleted_at = '')",
            [$fileId, $tenantId]
        );
    }

    if ($workflow === false) {
        throw new Exception('Workflow non trovato per questo documento.');
    }

    // Check if document is in approval state
    if ($workflow['current_state'] !== WORKFLOW_STATE_IN_APPROVAL) {
        throw new Exception(
            sprintf(
                'Il documento non è in fase di approvazione. Stato attuale: %s',
                getWorkflowStateLabel($workflow['current_state'])
            )
        );
    }

    // Enforce: only the SELECTED approver for this document (or admin/super_admin) can approve.
    if (!in_array($userRole, ['admin', 'super_admin'], true)) {
        $selected = getSelectedWorkflowParticipants((int)$tenantId, (int)$fileId);
        $selectedApproverId = (int)($selected['approver_id'] ?? 0);
        if ($selectedApproverId <= 0 || $selectedApproverId !== (int)$userId) {
            throw new Exception('Solo l’approvatore selezionato per questo documento può approvare.');
        }
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

    // BUG-092 FIX: approved_by_user_id column doesn't exist (tracked in history table)
    $updateData = [
        'current_state' => WORKFLOW_STATE_APPROVED,
        'approved_at' => date('Y-m-d H:i:s'),
        'current_handler_user_id' => $userId,  // Update handler to approver
        'updated_at' => date('Y-m-d H:i:s')
    ];

    // BUG-095 FIX: Include tenant_id in WHERE to ensure multi-tenant isolation
    $updated = $db->update(
        'document_workflow',
        $updateData,
        [
            'id' => $workflow['id'],
            'tenant_id' => $tenantId,
            'file_id' => $fileId  // Additional safety check
        ]
    );

    if (!$updated) {
        // Log detailed error for debugging
        error_log("[WORKFLOW_APPROVE] Update failed - workflow_id: {$workflow['id']}, tenant: $tenantId, file: $fileId");
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
        'user_role_at_time' => USER_ROLE_APPROVER,
        'comment' => $comment,
        'metadata' => buildWorkflowMetadata([
            'approver_name' => $userInfo['user_name'],
            'workflow_duration' => $workflowDuration,
            'rejection_count' => $workflow['rejection_count'] ?? 0
        ])
    ];

    $historyId = $db->insert('document_workflow_history', $historyData);

    if (!$historyId) {
        throw new Exception('Impossibile creare entry storica approvazione.');
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

        // BUG-094 FIX: validator_name is NOT in SELECT query (commented out line 302)
        // Email notifications are already sent by WorkflowEmailNotifier below (line 371)
        /*
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
                    'validator_name' => $workflow['validator_name'],  // ❌ NOT in SELECT
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
        */

        // BUG-094 FIX: validator_email and validator_name are NOT in SELECT query
        // These fields don't exist in workflow table - validators come from workflow_roles
        // Email notifications are already sent by WorkflowEmailNotifier below (line 371)
        /*
        // Optionally notify validator as well
        if ($workflow['validator_email'] && $workflow['validator_email'] !== $workflow['creator_email']) {
            $emailData['to'] = $workflow['validator_email'];
            $emailData['to_name'] = $workflow['validator_name'];
            $emailData['variables']['validator_name'] = $workflow['validator_name'];
            $emailData['subject'] = sprintf('Documento approvato: %s', $workflow['file_name']);

            sendWorkflowEmail($emailData);
        }
        */
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

    // BUG-091 FIX: Remove references to non-existent validator columns
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
            'validated_at' => $workflow['validated_at'],
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
        // Wrap content-only templates with the shared Nexio layout
        if (!empty($emailContent) && function_exists('cnx_email_is_full_document') && !cnx_email_is_full_document($emailContent)) {
            $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
            $tenantName = $data['variables']['tenant_name'] ?? '';
            $emailContent = renderEmailLayout($data['subject'] ?? 'Notifica workflow', $emailContent, [
                'BASE_URL' => $baseUrl,
                'TENANT_NAME' => (string)$tenantName,
                'YEAR' => date('Y')
            ], ['brandColor' => '#1a2332']);
        }

        // BUG-082 FIX: Use global sendEmail() function (EmailSender::send() doesn't exist)
        return sendEmail(
            $data['to'],
            $data['subject'],
            $emailContent
        );

    } catch (Exception $e) {
        error_log("[EMAIL] Error sending workflow email: " . $e->getMessage());
        return false;
    }
}