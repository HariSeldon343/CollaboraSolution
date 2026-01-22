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
        // BUG-144 FIX: Validate user has access to requested tenant
        // Accept both single-tenant (users.tenant_id) and multi-tenant (user_tenant_access)
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

    // BUG-089 FIX: Super admin can access files across tenants
    // BUG-090 FIX: Column name is 'uploaded_by' not 'created_by'
    if ($userRole === 'super_admin') {
        $file = $db->fetchOne(
            "SELECT id, name AS file_name, uploaded_by, folder_id, tenant_id
             FROM files
             WHERE id = ?
               AND (deleted_at IS NULL OR deleted_at = '')",
            [$fileId]
        );

        if ($file !== false) {
            // Use file's actual tenant, not session tenant
            $tenantId = $file['tenant_id'];
        }
    } else {
        $file = $db->fetchOne(
            "SELECT id, name AS file_name, uploaded_by, folder_id, tenant_id
             FROM files
             WHERE id = ?
               AND tenant_id = ?
               AND (deleted_at IS NULL OR deleted_at = '')",
            [$fileId, $tenantId]
        );
    }

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
        "SELECT id, current_state, current_handler_user_id
         FROM document_workflow
         WHERE file_id = ?
           AND tenant_id = ?
           AND deleted_at IS NULL",
        [$fileId, $tenantId]
    );

    if ($existingWorkflow !== false) {
        // Check if workflow is in a state that allows resubmission
        if (!in_array($existingWorkflow['current_state'], [WORKFLOW_STATE_DRAFT, WORKFLOW_STATE_REJECTED])) {
            throw new Exception(
                sprintf(
                    'Il documento è già nel workflow con stato: %s',
                    getWorkflowStateLabel($existingWorkflow['current_state'])
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

    // BUG-148d+149d FIX: Admin AND Manager are DEFAULT validators/approvers if no explicit workflow_roles record exists
    // Helper function to find available user for role (checks workflow_roles first, then admin/managers)
    $findUserForRole = function($tenantId, $workflowRole) use ($db) {
        // First: Check explicit workflow_roles
        $explicitUser = $db->fetchOne(
            "SELECT user_id
             FROM workflow_roles
             WHERE tenant_id = ?
               AND workflow_role = ?
               AND is_active = 1
               AND deleted_at IS NULL
             ORDER BY created_at ASC
             LIMIT 1",
            [$tenantId, $workflowRole]
        );

        if ($explicitUser !== false) {
            return $explicitUser['user_id'];
        }

        // Fallback: Get first admin/manager without explicit workflow_roles record for this role
        // BUG-149d FIX: Include admin alongside manager as default
        // BUG-149a FIX: Any workflow_roles record (even soft-deleted) means explicitly configured
        $defaultAdmin = $db->fetchOne(
            "SELECT u.id as user_id
             FROM users u
             WHERE u.tenant_id = ?
               AND u.role IN ('admin', 'manager')
               AND u.is_active = 1
               AND u.deleted_at IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM workflow_roles wr
                   WHERE wr.user_id = u.id
                     AND wr.tenant_id = ?
                     AND wr.workflow_role = ?
               )
             ORDER BY FIELD(u.role, 'admin', 'manager'), u.created_at ASC
             LIMIT 1",
            [$tenantId, $tenantId, $workflowRole]
        );

        if ($defaultAdmin !== false) {
            return $defaultAdmin['user_id'];
        }

        return null;
    };

    // Helper function to check if user can act as validator/approver
    $userCanActAsRole = function($userId, $tenantId, $workflowRole) use ($db) {
        // Check explicit workflow_roles
        if (userHasWorkflowRole($userId, $tenantId, $workflowRole)) {
            return true;
        }

        // Check if user is admin/manager without explicit denial (no workflow_roles record)
        // BUG-149d FIX: Include admin alongside manager as default
        // BUG-149a FIX: Any workflow_roles record (even soft-deleted) means explicitly configured
        $isDefaultAdmin = $db->fetchOne(
            "SELECT 1
             FROM users u
             WHERE u.id = ?
               AND u.tenant_id = ?
               AND u.role IN ('admin', 'manager')
               AND u.is_active = 1
               AND u.deleted_at IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM workflow_roles wr
                   WHERE wr.user_id = u.id
                     AND wr.tenant_id = ?
                     AND wr.workflow_role = ?
               )
             LIMIT 1",
            [$userId, $tenantId, $tenantId, $workflowRole]
        );

        return $isDefaultAdmin !== false;
    };

    // If not specified, get first available validator and approver
    if (!$validatorId) {
        $validatorId = $findUserForRole($tenantId, WORKFLOW_ROLE_VALIDATOR);

        if (!$validatorId) {
            throw new Exception('Nessun validatore disponibile nel sistema. Configurare almeno un validatore o aggiungere un manager.');
        }
    } else {
        // Validate specified validator (check explicit roles OR default manager)
        if (!$userCanActAsRole($validatorId, $tenantId, WORKFLOW_ROLE_VALIDATOR)) {
            throw new Exception('Il validatore specificato non ha il ruolo di validatore.');
        }
    }

    if (!$approverId) {
        $approverId = $findUserForRole($tenantId, WORKFLOW_ROLE_APPROVER);

        if (!$approverId) {
            throw new Exception('Nessun approvatore disponibile nel sistema. Configurare almeno un approvatore o aggiungere un manager.');
        }
    } else {
        // Validate specified approver (check explicit roles OR default manager)
        if (!$userCanActAsRole($approverId, $tenantId, WORKFLOW_ROLE_APPROVER)) {
            throw new Exception('L\'approvatore specificato non ha il ruolo di approvatore.');
        }
    }

    // ============================================
    // CREATE OR UPDATE WORKFLOW
    // ============================================

    $workflowData = [
        'tenant_id' => $tenantId,
        'file_id' => $fileId,
        'current_state' => WORKFLOW_STATE_IN_VALIDATION,
        'created_by_user_id' => $file['uploaded_by'],
        'current_handler_user_id' => $validatorId,  // Validator handles first
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
        'from_state' => $existingWorkflow ? $existingWorkflow['current_state'] : WORKFLOW_STATE_DRAFT,
        'to_state' => WORKFLOW_STATE_IN_VALIDATION,
        'transition_type' => TRANSITION_SUBMIT,
        'performed_by_user_id' => $userId,
        'user_role_at_time' => USER_ROLE_CREATOR,
        'comment' => $notes,
        'metadata' => buildWorkflowMetadata([
            'validator_id' => $validatorId,
            'approver_id' => $approverId,
            'operation' => $operation
        ])
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

            // BUG-091 FIX: Commented out - use WorkflowEmailNotifier instead (already implemented)
            // sendWorkflowEmail($emailData);
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
            'document',
            $fileId,
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

    // Get validator and approver details separately (they're not in workflow table)
    $validator = $db->fetchOne(
        "SELECT name, email FROM users WHERE id = ?",
        [$validatorId]
    );

    $approver = $db->fetchOne(
        "SELECT name, email FROM users WHERE id = ?",
        [$approverId]
    );

    $creator = $db->fetchOne(
        "SELECT name, email FROM users WHERE id = ?",
        [$file['uploaded_by']]
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
                'name' => $validator ? $validator['name'] : 'Unknown',
                'email' => $validator ? $validator['email'] : ''
            ],
            'approver' => [
                'id' => $approverId,
                'name' => $approver ? $approver['name'] : 'Unknown',
                'email' => $approver ? $approver['email'] : ''
            ],
            'creator' => [
                'id' => $file['uploaded_by'],
                'name' => $creator ? $creator['name'] : 'Unknown',
                'email' => $creator ? $creator['email'] : ''
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

        // BUG-091 FIX: Use correct sendEmail() function (not EmailSender class)
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

        return sendEmail(
            $data['to'],
            $data['subject'],
            $emailContent,
            '', // Text body optional
            ['context' => ['action' => 'workflow_submit', 'file_id' => $data['file_id'] ?? null]]
        );

    } catch (Exception $e) {
        error_log("[EMAIL] Error sending workflow email: " . $e->getMessage());
        return false;
    }
}