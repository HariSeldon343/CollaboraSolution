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
require_once __DIR__ . '/../../../includes/workflow_email_notifier.php';
require_once __DIR__ . '/../../../config.php';

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

    // BUG-089 FIX: Super admin can access workflows across tenants
    // BUG-091 FIX: Column name is 'name' not 'file_name', remove non-existent validator/approver columns
    if ($userRole === 'super_admin') {
        $workflow = $db->fetchOne(
            "SELECT dw.*,
                    f.name AS file_name,
                    f.uploaded_by,
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
                    f.uploaded_by,
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

    // Selected participants from last submit
    $selected = getSelectedWorkflowParticipants((int)$tenantId, (int)$fileId);
    $isCreator = ($workflow['created_by_user_id'] == $userId || $workflow['uploaded_by'] == $userId);
    $isValidator = !empty($selected['validator_id']) && (int)$selected['validator_id'] === (int)$userId;
    $isApprover = !empty($selected['approver_id']) && (int)$selected['approver_id'] === (int)$userId;

    // Determine target state based on role
    $targetState = getWorkflowRevertTarget(
        $workflow['current_state'],
        $userRole,
        $isCreator,
        $isValidator,
        $isApprover
    );

    if ($targetState === null) {
        throw new Exception('Non hai i permessi per riportare indietro questo documento.');
    }

    // ============================================
    // UPDATE WORKFLOW STATE
    // ============================================

    $previousState = $workflow['current_state'];

    // BUG-093 FIX: Remove non-existent columns (validated_by_user_id, approved_by_user_id, rejected_by_user_id)
    // These columns don't exist in document_workflow table - only tracked in history table
    $updateData = [
        'current_state' => $targetState,
        'current_handler_user_id' => $workflow['created_by_user_id'],  // Reset to creator/owner
        'updated_at' => date('Y-m-d H:i:s')
    ];

    // Clear approval/validation data when rolling back
    if (in_array($targetState, [WORKFLOW_STATE_DRAFT, WORKFLOW_STATE_IN_VALIDATION], true)) {
        $updateData['validated_at'] = null;
        $updateData['approved_at'] = null;
        $updateData['rejected_at'] = null;
    } elseif ($targetState === WORKFLOW_STATE_IN_APPROVAL) {
        $updateData['approved_at'] = null;
    }

    $updated = $db->update(
        'document_workflow',
        $updateData,
        ['id' => $workflow['id']]  // Simple WHERE by primary key
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
        'to_state' => $targetState,
        'transition_type' => TRANSITION_RECALL,
        'performed_by_user_id' => $userId,
        'user_role_at_time' => $userRole,
        'comment' => $reason,
        'metadata' => buildWorkflowMetadata([
            'recall_reason' => $reason,
            'recalled_from_state' => $previousState,
            'target_state' => $targetState
        ])
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
    // SEND EMAIL NOTIFICATIONS (ONLY managers)
    // ============================================
    try {
        $notifier = new WorkflowEmailNotifier((int)$tenantId);
        $managers = WorkflowEmailNotifier::getTenantManagers((int)$tenantId);

        $recipients = [];
        foreach ($managers as $m) {
            $recipients[] = ['id' => $m['id'], 'name' => $m['name'], 'email' => $m['email']];
        }

        // Deduplicate by email
        $recipients = array_values(array_reduce($recipients, function($carry, $item) {
            $carry[$item['email']] = $item;
            return $carry;
        }, []));

        $tenantRow = $db->fetchOne("SELECT name FROM tenants WHERE id = ?", [$tenantId]);
        $tenantName = $tenantRow['name'] ?? 'Nexio';
        $documentUrl = rtrim(BASE_URL, '/') . '/files.php?file_id=' . $fileId;

        $templatePath = __DIR__ . '/../../../includes/email_templates/workflow/workflow_state_changed_info.html';
        if (file_exists($templatePath)) {
            $template = file_get_contents($templatePath);
            foreach ($recipients as $r) {
                $rep = [
                    '{{USER_NAME}}' => htmlspecialchars($r['name'] ?? 'Utente'),
                    '{{FILENAME}}' => htmlspecialchars($workflow['file_name'] ?? ('Documento #' . $fileId)),
                    '{{STATE_LABEL}}' => getWorkflowStateLabel($targetState),
                    '{{ACTOR_NAME}}' => htmlspecialchars($userInfo['user_name'] ?? ''),
                    '{{CHANGE_DATE}}' => date('d/m/Y H:i'),
                    '{{DOCUMENT_URL}}' => $documentUrl,
                    '{{TENANT_NAME}}' => htmlspecialchars($tenantName),
                    '{{BASE_URL}}' => BASE_URL,
                    '{{YEAR}}' => date('Y')
                ];
                $body = str_replace(array_keys($rep), array_values($rep), $template);
                // Wrap content-only templates with the shared Nexio layout
                if (!empty($body) && function_exists('cnx_email_is_full_document') && !cnx_email_is_full_document($body)) {
                    $body = renderEmailLayout('Aggiornamento workflow', $body, [
                        'BASE_URL' => BASE_URL,
                        'TENANT_NAME' => (string)$tenantName,
                        'YEAR' => date('Y')
                    ], ['brandColor' => '#1a2332']);
                }
                sendEmail($r['email'], 'Aggiornamento workflow: riportato in ' . getWorkflowStateLabel($targetState), $body, '', [
                    'context' => [
                        'action' => 'workflow_state_changed_info',
                        'tenant_id' => $tenantId,
                        'user_id' => $r['id'],
                        'file_id' => $fileId,
                        'new_state' => $targetState
                    ]
                ]);
            }
        }
    } catch (Exception $e) {
        error_log('[WORKFLOW_RECALL] Email notify error: ' . $e->getMessage());
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

    // BUG-091 FIX: Removed notified section (validator_name/approver_name not retrieved)

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