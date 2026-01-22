<?php
/**
 * WorkflowEmailNotifier - Email notification system for Document Approval Workflow
 *
 * Handles all email notifications related to document workflow transitions
 * and file/folder assignments in CollaboraNexio.
 *
 * @author CollaboraNexio
 * @version 1.0.0
 */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_helper.php';
require_once __DIR__ . '/workflow_constants.php';
require_once __DIR__ . '/email_layout.php';
require_once __DIR__ . '/email_template_renderer.php';

class WorkflowEmailNotifier {
    /**
     * Wrap content-only templates with the shared Nexio email layout.
     * If the template already contains a full HTML document (<html>/<body>), it is returned as-is.
     */
    private static function wrapEmailHtml(string $title, string $htmlBody, string $baseUrl, string $tenantName): string
    {
        if ($htmlBody === '') return '';
        if (cnx_email_is_full_document($htmlBody)) return $htmlBody;

        return renderEmailLayout(
            $title,
            $htmlBody,
            [
                'BASE_URL' => $baseUrl,
                'TENANT_NAME' => $tenantName,
                'YEAR' => date('Y')
            ],
            ['brandColor' => '#1a2332']
        );
    }
    /**
     * Check if a table has a specific column (schema may differ across environments).
     */
    private static function tableHasColumn(Database $db, string $table, string $column): bool {
        try {
            $row = $db->fetchOne(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?",
                [$table, $column]
            );
            return (int)($row['cnt'] ?? 0) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Cached tenant names to avoid repeated lookups.
     *
     * @var array<int,string>
     */
    private static array $tenantNameCache = [];

    /**
     * Preferred tenant name columns ordered by availability.
     *
     * @var array<int,string>|null
     */
    private static ?array $tenantNameColumns = null;

    /**
     * Tenant managers (informational-only recipients).
     * BUG-151 FIX: Changed from private to public - method is called externally from recall.php
     *
     * @return array<int, array{id:int,name:string,email:string}>
     */
    public static function getTenantManagers(int $tenantId): array {
        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT id, name, email
                 FROM users
                 WHERE tenant_id = ?
                   AND role = 'manager'
                   AND (deleted_at IS NULL OR deleted_at = '')
                   AND email IS NOT NULL
                   AND email <> ''
                 ORDER BY id ASC",
                [$tenantId]
            );
            return is_array($rows) ? $rows : [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Send notification when document is submitted for validation
     *
     * @param int $fileId File ID
     * @param int $submitterId User ID who submitted
     * @param int $tenantId Tenant ID
     * @return bool True if all emails sent successfully
     */
    public static function notifyDocumentSubmitted($fileId, $submitterId, $tenantId) {
        try {
            $db = Database::getInstance();

            // Get file details
            $file = $db->fetchOne(
                "SELECT f.id,
                        f.name,
                        f.tenant_id,
                        f.uploaded_by AS creator_id,
                        u.name AS creator_name,
                        u.email AS creator_email
                 FROM files f
                 JOIN users u ON u.id = f.uploaded_by AND u.deleted_at IS NULL
                 WHERE f.id = ? AND f.tenant_id = ? AND (f.deleted_at IS NULL OR f.deleted_at = '')",
                [$fileId, $tenantId]
            );

            if (!$file) {
                error_log("[WORKFLOW_EMAIL] File not found: ID=$fileId");
                return false;
            }

            $tenantName = self::getTenantName((int)$file['tenant_id']);

            // NOTE (2025-12-28): richiesta cliente
            // Per i cambi stato del workflow, devono ricevere email SOLO i manager del tenant.
            // Nessuna email operativa a validator/approver/creator.
            $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
            $documentUrl = $baseUrl . '/files.php?doc=' . $fileId;

            // Common placeholders
            $commonReplacements = [
                '{{FILENAME}}' => htmlspecialchars($file['name']),
                '{{CREATOR_NAME}}' => htmlspecialchars($file['creator_name']),
                '{{SUBMISSION_DATE}}' => date('d/m/Y H:i'),
                '{{DOCUMENT_URL}}' => $documentUrl,
                '{{TENANT_NAME}}' => htmlspecialchars($tenantName),
                '{{BASE_URL}}' => $baseUrl,
                '{{YEAR}}' => date('Y')
            ];

            $success = true;

            // Inform managers (ONLY recipients)
            $managers = self::getTenantManagers((int)$tenantId);
            if (!empty($managers)) {
                $infoTemplatePath = __DIR__ . '/email_templates/workflow/workflow_state_changed_info.html';
                if (file_exists($infoTemplatePath)) {
                    $infoTemplate = file_get_contents($infoTemplatePath);
                    $infoSubject = "Aggiornamento workflow: {$file['name']} → In validazione";

                    foreach ($managers as $m) {
                        $rep = [
                            '{{USER_NAME}}' => htmlspecialchars($m['name'] ?? 'Manager'),
                            '{{FILENAME}}' => htmlspecialchars($file['name']),
                            '{{STATE_LABEL}}' => 'In validazione',
                            '{{ACTOR_NAME}}' => htmlspecialchars($file['creator_name'] ?? ''),
                            '{{CHANGE_DATE}}' => date('d/m/Y H:i'),
                            '{{DOCUMENT_URL}}' => $documentUrl,
                            '{{TENANT_NAME}}' => htmlspecialchars($tenantName),
                            '{{BASE_URL}}' => $baseUrl,
                            '{{YEAR}}' => date('Y')
                        ];

                        $body = str_replace(array_keys($rep), array_values($rep), $infoTemplate);
                        $body = self::wrapEmailHtml($infoSubject, $body, $baseUrl, $tenantName);
                        $ctx = [
                            'action' => 'workflow_state_changed_info',
                            'tenant_id' => $tenantId,
                            'user_id' => $m['id'],
                            'file_id' => $fileId
                        ];
                        sendEmail($m['email'], $infoSubject, $body, '', ['context' => $ctx]);
                    }
                }
            }

            // Log in audit
            if ($success) {
                AuditLogger::logGeneric(
                    $submitterId,
                    $tenantId,
                    'create',
                    'notification',
                    null,
                    "Sent workflow notifications: document_submitted for file $fileId to tenant managers only"
                );
            }

            return $success;

        } catch (Exception $e) {
            error_log("[WORKFLOW_EMAIL] Error in notifyDocumentSubmitted: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification when document is created
     *
     * @param int $fileId File ID
     * @param int $creatorId User ID who created the document
     * @param int $tenantId Tenant ID
     * @return bool True if all emails sent successfully
     */
    public static function notifyDocumentCreated($fileId, $creatorId, $tenantId) {
        try {
            $db = Database::getInstance();

            // Get file info
            $file = $db->fetchOne(
                "SELECT id, name, uploaded_by AS creator_id, tenant_id
                 FROM files
                 WHERE id = ? AND tenant_id = ? AND (deleted_at IS NULL OR deleted_at = '')",
                [$fileId, $tenantId]
            );

            if (!$file) {
                error_log("[WORKFLOW_EMAIL] File not found for creation notification: $fileId");
                return false;
            }

            // Get creator info
            $creator = $db->fetchOne(
                "SELECT id, name, email FROM users WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '')",
                [$creatorId]
            );

            // Get validators (from workflow_roles table)
            $validators = $db->fetchAll(
                "SELECT DISTINCT u.id, u.name, u.email
                 FROM workflow_roles wr
                 INNER JOIN users u ON u.id = wr.user_id AND u.deleted_at IS NULL
                 WHERE wr.tenant_id = ?
                   AND wr.workflow_role = 'validator'
                   AND wr.is_active = 1
                   AND wr.deleted_at IS NULL",
                [$tenantId]
            );

            $tenantName = self::getTenantName($tenantId);

            // Load template
            $templatePath = __DIR__ . '/email_templates/workflow/document_created.html';
            if (!file_exists($templatePath)) {
                error_log("[WORKFLOW_EMAIL] Template not found: $templatePath");
                return false;
            }
            $template = file_get_contents($templatePath);

            $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
            $documentUrl = $baseUrl . '/files.php?file_id=' . $fileId;
            $creationDate = date('d/m/Y H:i');

            $success = true;
            $emailsSent = 0;

            // Send to creator (confirmation)
            if ($creator) {
                $creatorTemplate = str_replace(
                    ['{{USER_NAME}}', '{{FILENAME}}', '{{CREATOR_NAME}}', '{{CREATION_DATE}}',
                     '{{DOCUMENT_URL}}', '{{TENANT_NAME}}', '{{BASE_URL}}', '{{YEAR}}'],
                    [htmlspecialchars($creator['name']),
                     htmlspecialchars($file['name']),
                     htmlspecialchars($creator['name']),
                     $creationDate,
                     $documentUrl,
                     htmlspecialchars($tenantName),
                     $baseUrl,
                     date('Y')],
                    $template
                );
                $creatorTemplate = self::wrapEmailHtml("Documento creato: {$file['name']}", $creatorTemplate, $baseUrl, $tenantName);

                $context = [
                    'action' => 'workflow_document_created',
                    'tenant_id' => $tenantId,
                    'user_id' => $creator['id'],
                    'file_id' => $fileId
                ];

                if (sendEmail($creator['email'], "Documento creato: {$file['name']}", $creatorTemplate, '', ['context' => $context])) {
                    $emailsSent++;
                } else {
                    error_log("[WORKFLOW_EMAIL] Failed to send creation email to creator: " . $creator['email']);
                    $success = false;
                }
            }

            // Send to validators (FYI notification)
            foreach ($validators as $validator) {
                $validatorTemplate = str_replace(
                    ['{{USER_NAME}}', '{{FILENAME}}', '{{CREATOR_NAME}}', '{{CREATION_DATE}}',
                     '{{DOCUMENT_URL}}', '{{TENANT_NAME}}', '{{BASE_URL}}', '{{YEAR}}'],
                    [htmlspecialchars($validator['name']),
                     htmlspecialchars($file['name']),
                     htmlspecialchars($creator['name']),
                     $creationDate,
                     $documentUrl,
                     htmlspecialchars($tenantName),
                     $baseUrl,
                     date('Y')],
                    $template
                );
                $validatorTemplate = self::wrapEmailHtml("Nuovo documento da validare: {$file['name']}", $validatorTemplate, $baseUrl, $tenantName);

                $context = [
                    'action' => 'workflow_document_created',
                    'tenant_id' => $tenantId,
                    'user_id' => $validator['id'],
                    'file_id' => $fileId
                ];

                if (sendEmail($validator['email'], "Nuovo documento da validare: {$file['name']}", $validatorTemplate, '', ['context' => $context])) {
                    $emailsSent++;
                } else {
                    error_log("[WORKFLOW_EMAIL] Failed to send creation email to validator: " . $validator['email']);
                    $success = false;
                }
            }

            // Audit log
            if ($emailsSent > 0) {
                AuditLogger::logGeneric(
                    $creatorId,
                    $tenantId,
                    'create',
                    'notification',
                    null,
                    "Sent workflow notifications: document_created for file $fileId to $emailsSent recipients (1 creator + " . count($validators) . " validators)"
                );
            }

            return $success;

        } catch (Exception $e) {
            error_log("[WORKFLOW_EMAIL] Failed to send document creation notification: " . $e->getMessage());
            // Non-blocking - don't throw
            return false;
        }
    }

    /**
     * Send notification when document is validated
     *
     * @param int $fileId File ID
     * @param int $validatorId User ID who validated
     * @param int $tenantId Tenant ID
     * @param string|null $comment Validation comment
     * @return bool True if all emails sent successfully
     */
    public static function notifyDocumentValidated($fileId, $validatorId, $tenantId, $comment = null) {
        try {
            $db = Database::getInstance();

            // Get file and validator details
            $file = $db->fetchOne(
                "SELECT f.id,
                        f.name,
                        f.tenant_id,
                        f.uploaded_by AS creator_id,
                        creator.name as creator_name,
                        creator.email as creator_email,
                        validator.name as validator_name
                 FROM files f
                 JOIN users creator ON creator.id = f.uploaded_by AND (creator.deleted_at IS NULL OR creator.deleted_at = '')
                 JOIN users validator ON validator.id = ?
                 WHERE f.id = ?
                   AND f.tenant_id = ?
                   AND (f.deleted_at IS NULL OR f.deleted_at = '')",
                [$validatorId, $fileId, $tenantId]
            );

            if (!$file) {
                error_log("[WORKFLOW_EMAIL] File not found: ID=$fileId");
                return false;
            }

            $tenantName = self::getTenantName((int)$file['tenant_id']);

            // Send operational email ONLY to the selected approver for this document
            $selected = getSelectedWorkflowParticipants((int)$tenantId, (int)$fileId);
            $selectedApproverId = (int)($selected['approver_id'] ?? 0);
            if ($selectedApproverId <= 0) {
                error_log("[WORKFLOW_EMAIL] No selected approver found for file $fileId (tenant $tenantId)");
                return false;
            }

            $approver = $db->fetchOne(
                "SELECT id, name, email FROM users WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '') LIMIT 1",
                [$selectedApproverId]
            );
            if (!$approver || empty($approver['email'])) {
                error_log("[WORKFLOW_EMAIL] Selected approver not found or missing email: user_id=$selectedApproverId");
                return false;
            }

            // Load template
            $templatePath = __DIR__ . '/email_templates/workflow/document_validated.html';
            if (!file_exists($templatePath)) {
                error_log("[WORKFLOW_EMAIL] Template not found: $templatePath");
                return false;
            }

            $template = file_get_contents($templatePath);
            $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
            $documentUrl = $baseUrl . '/files.php?doc=' . $fileId;

            // Common placeholders
            $commonReplacements = [
                '{{FILENAME}}' => htmlspecialchars($file['name']),
                '{{VALIDATOR_NAME}}' => htmlspecialchars($file['validator_name']),
                '{{VALIDATION_DATE}}' => date('d/m/Y H:i'),
                '{{DOCUMENT_URL}}' => $documentUrl,
                '{{TENANT_NAME}}' => htmlspecialchars($tenantName),
                '{{BASE_URL}}' => $baseUrl,
                '{{YEAR}}' => date('Y')
            ];

            // Add comment if provided
            if ($comment) {
                $commonReplacements['{{COMMENT}}'] = htmlspecialchars($comment);
                $commonReplacements['{{#HAS_COMMENT}}'] = '';
                $commonReplacements['{{/HAS_COMMENT}}'] = '';
            } else {
                // Remove comment section
                $template = preg_replace('/{{#HAS_COMMENT}}.*?{{\/HAS_COMMENT}}/s', '', $template);
            }

            // NOTE (2025-12-28): richiesta cliente
            // Per i cambi stato del workflow, devono ricevere email SOLO i manager del tenant.
            // Nessuna email operativa a approver/creator.
            $success = true;

            // Inform managers (ONLY recipients)
            $managers = self::getTenantManagers((int)$tenantId);
            if (!empty($managers)) {
                $infoTemplatePath = __DIR__ . '/email_templates/workflow/workflow_state_changed_info.html';
                if (file_exists($infoTemplatePath)) {
                    $infoTemplate = file_get_contents($infoTemplatePath);
                    $infoSubject = "Aggiornamento workflow: {$file['name']} → In approvazione";
                    foreach ($managers as $m) {
                        $rep = [
                            '{{USER_NAME}}' => htmlspecialchars($m['name'] ?? 'Manager'),
                            '{{FILENAME}}' => htmlspecialchars($file['name']),
                            '{{STATE_LABEL}}' => 'In approvazione',
                            '{{ACTOR_NAME}}' => htmlspecialchars($file['validator_name'] ?? ''),
                            '{{CHANGE_DATE}}' => date('d/m/Y H:i'),
                            '{{DOCUMENT_URL}}' => $documentUrl,
                            '{{TENANT_NAME}}' => htmlspecialchars($tenantName),
                            '{{BASE_URL}}' => $baseUrl,
                            '{{YEAR}}' => date('Y')
                        ];
                        $body = str_replace(array_keys($rep), array_values($rep), $infoTemplate);
                        $body = self::wrapEmailHtml($infoSubject, $body, $baseUrl, $tenantName);
                        $ctx = [
                            'action' => 'workflow_state_changed_info',
                            'tenant_id' => $tenantId,
                            'user_id' => $m['id'],
                            'file_id' => $fileId
                        ];
                        sendEmail($m['email'], $infoSubject, $body, '', ['context' => $ctx]);
                    }
                }
            }

            return $success;

        } catch (Exception $e) {
            error_log("[WORKFLOW_EMAIL] Error in notifyDocumentValidated: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification when document is approved
     *
     * @param int $fileId File ID
     * @param int $approverId User ID who approved
     * @param int $tenantId Tenant ID
     * @param string|null $comment Approval comment
     * @return bool True if all emails sent successfully
     */
    public static function notifyDocumentApproved($fileId, $approverId, $tenantId, $comment = null) {
        try {
            $db = Database::getInstance();

            // Get file and approver details
            $file = $db->fetchOne(
                "SELECT f.id,
                        f.name,
                        f.tenant_id,
                        f.uploaded_by AS creator_id,
                        creator.name as creator_name,
                        creator.email as creator_email,
                        approver.name as approver_name
                 FROM files f
                 JOIN users creator ON creator.id = f.uploaded_by AND (creator.deleted_at IS NULL OR creator.deleted_at = '')
                 JOIN users approver ON approver.id = ?
                 WHERE f.id = ?
                   AND f.tenant_id = ?
                   AND (f.deleted_at IS NULL OR f.deleted_at = '')",
                [$approverId, $fileId, $tenantId]
            );

            if (!$file) {
                error_log("[WORKFLOW_EMAIL] File not found: ID=$fileId");
                return false;
            }

            $tenantName = self::getTenantName((int)$file['tenant_id']);

            // NOTE (2025-12-28): richiesta cliente
            // Per i cambi stato del workflow, devono ricevere email SOLO i manager del tenant.
            // (Nessuna email al creator)
            $stakeholders = [];
            foreach (self::getTenantManagers((int)$tenantId) as $m) {
                $m['type'] = 'manager';
                $stakeholders[] = $m;
            }

            // Load template
            $templatePath = __DIR__ . '/email_templates/workflow/document_approved.html';
            if (!file_exists($templatePath)) {
                error_log("[WORKFLOW_EMAIL] Template not found: $templatePath");
                return false;
            }

            $template = file_get_contents($templatePath);
            $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
            $documentUrl = $baseUrl . '/files.php?doc=' . $fileId;

            // Common placeholders
            $commonReplacements = [
                '{{FILENAME}}' => htmlspecialchars($file['name']),
                '{{APPROVER_NAME}}' => htmlspecialchars($file['approver_name']),
                '{{APPROVAL_DATE}}' => date('d/m/Y H:i'),
                '{{DOCUMENT_URL}}' => $documentUrl,
                '{{TENANT_NAME}}' => htmlspecialchars($tenantName),
                '{{BASE_URL}}' => $baseUrl,
                '{{YEAR}}' => date('Y')
            ];

            // Add comment if provided
            if ($comment) {
                $commonReplacements['{{COMMENT}}'] = htmlspecialchars($comment);
                $commonReplacements['{{#HAS_COMMENT}}'] = '';
                $commonReplacements['{{/HAS_COMMENT}}'] = '';
            } else {
                $template = preg_replace('/{{#HAS_COMMENT}}.*?{{\/HAS_COMMENT}}/s', '', $template);
            }

            $success = true;
            $subject = "Documento approvato: " . $file['name'];

            // Send: ONLY managers get FYI-only template.
            foreach ($stakeholders as $recipient) {
                $infoTemplatePath = __DIR__ . '/email_templates/workflow/workflow_state_changed_info.html';
                if (!file_exists($infoTemplatePath)) {
                    continue;
                }
                $infoTemplate = file_get_contents($infoTemplatePath);
                $rep = [
                    '{{USER_NAME}}' => htmlspecialchars($recipient['name'] ?? 'Manager'),
                    '{{FILENAME}}' => htmlspecialchars($file['name']),
                    '{{STATE_LABEL}}' => 'Approvato',
                    '{{ACTOR_NAME}}' => htmlspecialchars($file['approver_name'] ?? ''),
                    '{{CHANGE_DATE}}' => date('d/m/Y H:i'),
                    '{{DOCUMENT_URL}}' => $documentUrl,
                    '{{TENANT_NAME}}' => htmlspecialchars($tenantName),
                    '{{BASE_URL}}' => $baseUrl,
                    '{{YEAR}}' => date('Y')
                ];
                $body = str_replace(array_keys($rep), array_values($rep), $infoTemplate);
                $body = self::wrapEmailHtml("Aggiornamento workflow: {$file['name']} → Approvato", $body, $baseUrl, $tenantName);
                $ctx = [
                    'action' => 'workflow_state_changed_info',
                    'tenant_id' => $tenantId,
                    'user_id' => $recipient['id'],
                    'file_id' => $fileId
                ];
                sendEmail($recipient['email'], "Aggiornamento workflow: {$file['name']} → Approvato", $body, '', ['context' => $ctx]);
            }

            return $success;

        } catch (Exception $e) {
            error_log("[WORKFLOW_EMAIL] Error in notifyDocumentApproved: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification when document is rejected
     *
     * @param int $fileId File ID
     * @param int $rejectorId User ID who rejected
     * @param int $tenantId Tenant ID
     * @param string $currentState Current workflow state (in_validazione or in_approvazione)
     * @param string $comment Rejection reason
     * @return bool True if all emails sent successfully
     */
    public static function notifyDocumentRejected($fileId, $rejectorId, $tenantId, $currentState, $comment) {
        try {
            $db = Database::getInstance();

            // Get file and rejector details
            $file = $db->fetchOne(
                "SELECT f.id,
                        f.name,
                        f.tenant_id,
                        f.uploaded_by AS creator_id,
                        creator.name as creator_name,
                        creator.email as creator_email,
                        rejector.name as rejector_name,
                        rejector.email as rejector_email
                 FROM files f
                 JOIN users creator ON creator.id = f.uploaded_by AND (creator.deleted_at IS NULL OR creator.deleted_at = '')
                 JOIN users rejector ON rejector.id = ?
                 WHERE f.id = ?
                   AND f.tenant_id = ?
                   AND (f.deleted_at IS NULL OR f.deleted_at = '')",
                [$rejectorId, $fileId, $tenantId]
            );

            if (!$file) {
                error_log("[WORKFLOW_EMAIL] File not found: ID=$fileId");
                return false;
            }

            $tenantName = self::getTenantName((int)$file['tenant_id']);

            // NOTE (2025-12-28): richiesta cliente
            // Per i cambi stato del workflow, devono ricevere email SOLO i manager del tenant.
            // Usiamo quindi solo il template info per i manager (niente email al creator).
            $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
            $documentUrl = $baseUrl . '/files.php?doc=' . $fileId;

            // Common placeholders
            $commonReplacements = [
                '{{FILENAME}}' => htmlspecialchars($file['name']),
                '{{REJECTOR_NAME}}' => htmlspecialchars($file['rejector_name']),
                '{{REJECTION_DATE}}' => date('d/m/Y H:i'),
                '{{REJECTION_REASON}}' => htmlspecialchars($comment),
                '{{DOCUMENT_URL}}' => $documentUrl,
                '{{TENANT_NAME}}' => htmlspecialchars($tenantName),
                '{{BASE_URL}}' => $baseUrl,
                '{{YEAR}}' => date('Y'),
                '{{REJECTOR_ROLE}}' => $currentState === 'in_validazione' ? 'validatore' : 'approvatore'
            ];

            $success = true;

            // Inform managers (ONLY recipients)
            $managers = self::getTenantManagers((int)$tenantId);
            if (!empty($managers)) {
                $infoTemplatePath = __DIR__ . '/email_templates/workflow/workflow_state_changed_info.html';
                if (file_exists($infoTemplatePath)) {
                    $infoTemplate = file_get_contents($infoTemplatePath);
                    $infoSubject = "Aggiornamento workflow: {$file['name']} → Rifiutato";
                    foreach ($managers as $m) {
                        $rep = [
                            '{{USER_NAME}}' => htmlspecialchars($m['name'] ?? 'Manager'),
                            '{{FILENAME}}' => htmlspecialchars($file['name']),
                            '{{STATE_LABEL}}' => 'Rifiutato',
                            '{{ACTOR_NAME}}' => htmlspecialchars($file['rejector_name'] ?? ''),
                            '{{CHANGE_DATE}}' => date('d/m/Y H:i'),
                            '{{DOCUMENT_URL}}' => $documentUrl,
                            '{{TENANT_NAME}}' => htmlspecialchars($tenantName),
                            '{{BASE_URL}}' => $baseUrl,
                            '{{YEAR}}' => date('Y')
                        ];
                        $body = str_replace(array_keys($rep), array_values($rep), $infoTemplate);
                        $body = self::wrapEmailHtml($infoSubject, $body, $baseUrl, $tenantName);
                        $ctx = [
                            'action' => 'workflow_state_changed_info',
                            'tenant_id' => $tenantId,
                            'user_id' => $m['id'],
                            'file_id' => $fileId,
                            'rejection_stage' => $currentState
                        ];
                        sendEmail($m['email'], $infoSubject, $body, '', ['context' => $ctx]);
                    }
                }
            }

            return $success;

        } catch (Exception $e) {
            error_log("[WORKFLOW_EMAIL] Error in notifyDocumentRejected: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification when file/folder is assigned
     *
     * @param int $assignmentId Assignment ID
     * @param int $tenantId Tenant ID
     * @return bool True if email sent successfully
     */
    public static function notifyFileAssigned($assignmentId, $tenantId) {
        try {
            $db = Database::getInstance();

            // Detect schema variants
            $faAssignedToCol = self::tableHasColumn($db, 'file_assignments', 'assigned_to_user_id') ? 'assigned_to_user_id' : 'user_id';
            $faAssignedByCol = self::tableHasColumn($db, 'file_assignments', 'assigned_by_user_id') ? 'assigned_by_user_id' : 'assigned_by';
            $faReasonCol = self::tableHasColumn($db, 'file_assignments', 'assignment_reason') ? 'assignment_reason' : 'reason';
            $faHasFolderId = self::tableHasColumn($db, 'file_assignments', 'folder_id');
            $faHasTenantRoleId = self::tableHasColumn($db, 'file_assignments', 'assigned_to_tenant_role_id');

            $filesNameCol = self::tableHasColumn($db, 'files', 'file_name') ? 'file_name' : 'name';
            $foldersNameCol = self::tableHasColumn($db, 'folders', 'folder_name') ? 'folder_name' : 'name';

            // Get assignment details
            $sql = "SELECT fa.*,
                           f.{$filesNameCol} AS file_name,
                           -- user target
                           assignee.id AS assignee_id,
                           assignee.name AS assignee_name,
                           assignee.email AS assignee_email,
                           -- role target (group)
                           tr.id AS role_id,
                           tr.name AS role_name,
                           tr.code AS role_code,
                           tr.color AS role_color,
                           -- assigner
                           assigner.id AS assigner_id,
                           assigner.name AS assigner_name,
                           t.name AS tenant_name
                    FROM file_assignments fa
                    LEFT JOIN files f ON f.id = fa.file_id
                    " . ($faHasFolderId ? "LEFT JOIN folders fo ON fo.id = fa.folder_id" : "") . "
                    LEFT JOIN users assignee ON assignee.id = fa.{$faAssignedToCol}
                    " . ($faHasTenantRoleId ? "LEFT JOIN tenant_roles tr ON tr.id = fa.assigned_to_tenant_role_id" : "") . "
                    JOIN users assigner ON assigner.id = fa.{$faAssignedByCol}
                    JOIN tenants t ON t.id = fa.tenant_id
                    WHERE fa.id = ? AND fa.tenant_id = ? AND fa.deleted_at IS NULL";

            $assignment = $db->fetchOne($sql, [$assignmentId, $tenantId]);

            if (!$assignment) {
                error_log("[WORKFLOW_EMAIL] Assignment not found: ID=$assignmentId");
                return false;
            }

            $isGroupAssignment = $faHasTenantRoleId && !empty($assignment['assigned_to_tenant_role_id']);
            $isUserAssignment = !empty($assignment[$faAssignedToCol]);

            if (!$isGroupAssignment && !$isUserAssignment) {
                error_log("[WORKFLOW_EMAIL] Assignment has no target (user/group): ID=$assignmentId");
                return false;
            }

            // Determine item name and type
            $itemName = '';
            $itemType = '';

            if ($assignment['file_id']) {
                $itemName = $assignment['file_name'];
                $itemType = 'file';
            } elseif ($faHasFolderId && !empty($assignment['folder_id'])) {
                $folder = $db->fetchOne(
                    "SELECT {$foldersNameCol} AS folder_name FROM folders WHERE id = ? AND tenant_id = ?",
                    [$assignment['folder_id'], $tenantId]
                );
                $itemName = $folder ? ($folder['folder_name'] ?? 'Cartella') : 'Cartella';
                $itemType = 'cartella';
            } elseif (!empty($assignment['entity_type']) && $assignment['entity_type'] === 'folder') {
                // Legacy schema: entity_type='folder' with file_id pointing to folders table is not supported here.
                $itemName = 'Cartella';
                $itemType = 'cartella';
            }

            // Load template
            $templatePath = __DIR__ . '/email_templates/workflow/file_assigned.html';
            if (!file_exists($templatePath)) {
                error_log("[WORKFLOW_EMAIL] Template not found: $templatePath");
                return false;
            }

            $template = file_get_contents($templatePath);
            $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
            $filesUrl = $baseUrl . '/files.php';

            // Format expiration date
            $expirationDate = $assignment['expires_at'] ?
                date('d/m/Y', strtotime($assignment['expires_at'])) :
                'Nessuna scadenza';

            $subject = "Ti è stato assegnato un $itemType: $itemName";

            // Send to target(s):
            // - user assignment: single recipient
            // - group assignment: all users currently belonging to that tenant role in this tenant
            $recipients = [];

            if ($isUserAssignment) {
                if (!empty($assignment['assignee_email'])) {
                    $recipients[] = [
                        'id' => (int)($assignment['assignee_id'] ?? $assignment[$faAssignedToCol] ?? 0),
                        'name' => (string)($assignment['assignee_name'] ?? 'Utente'),
                        'email' => (string)$assignment['assignee_email']
                    ];
                }
            } else {
                $roleId = (int)($assignment['assigned_to_tenant_role_id'] ?? 0);
                if ($roleId <= 0) {
                    error_log("[WORKFLOW_EMAIL] Group assignment missing tenant_role_id: assignment_id=$assignmentId");
                    return false;
                }

                // Load group members for this tenant role (supports multi-role memberships via user_tenant_roles)
                $hasUserTenantRoles = false;
                try {
                    $ok = $db->fetchOne(
                        "SELECT 1
                         FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'user_tenant_roles'
                         LIMIT 1"
                    );
                    $hasUserTenantRoles = !empty($ok);
                } catch (Exception $e) {
                    $hasUserTenantRoles = false;
                }

                $rows = $db->fetchAll(
                    "SELECT DISTINCT u.id, u.name, u.email
                     FROM users u
                     WHERE (u.deleted_at IS NULL OR u.deleted_at = '')
                       AND u.email IS NOT NULL
                       AND u.email <> ''
                       AND (
                         EXISTS (
                           SELECT 1
                           FROM user_tenant_access uta
                           WHERE uta.user_id = u.id
                             AND uta.tenant_id = ?
                             AND uta.deleted_at IS NULL
                             AND uta.tenant_role_id = ?
                           LIMIT 1
                         )
                         OR (
                           ? = 1
                           AND EXISTS (
                             SELECT 1
                             FROM user_tenant_roles utr
                             WHERE utr.user_id = u.id
                               AND utr.tenant_id = ?
                               AND utr.deleted_at IS NULL
                               AND utr.tenant_role_id = ?
                             LIMIT 1
                           )
                         )
                       )
                     ORDER BY u.id ASC",
                    [$tenantId, $roleId, ($hasUserTenantRoles ? 1 : 0), $tenantId, $roleId]
                );
                foreach (($rows ?: []) as $r) {
                    $recipients[] = [
                        'id' => (int)($r['id'] ?? 0),
                        'name' => (string)($r['name'] ?? 'Utente'),
                        'email' => (string)($r['email'] ?? '')
                    ];
                }
            }

            // Deduplicate by email
            $recipients = array_values(array_reduce($recipients, function($carry, $item) {
                if (!empty($item['email'])) $carry[$item['email']] = $item;
                return $carry;
            }, []));

            if (empty($recipients)) {
                error_log("[WORKFLOW_EMAIL] No recipients for assignment email: assignment_id=$assignmentId");
                return false;
            }

            $success = true;
            foreach ($recipients as $r) {
                $replacements = [
                    '{{USER_NAME}}' => htmlspecialchars($r['name'] ?? 'Utente'),
                    '{{ITEM_NAME}}' => htmlspecialchars($itemName),
                    '{{ITEM_TYPE}}' => htmlspecialchars($itemType),
                    '{{ASSIGNER_NAME}}' => htmlspecialchars($assignment['assigner_name'] ?? ''),
                    '{{ASSIGNMENT_REASON}}' => htmlspecialchars(($assignment[$faReasonCol] ?? '') ?: 'Nessuna motivazione specificata'),
                    '{{EXPIRATION_DATE}}' => $expirationDate,
                    '{{FILES_URL}}' => $filesUrl,
                    '{{TENANT_NAME}}' => htmlspecialchars((string)($assignment['tenant_name'] ?? '')),
                    '{{BASE_URL}}' => $baseUrl,
                    '{{YEAR}}' => date('Y')
                ];

                $htmlBody = str_replace(array_keys($replacements), array_values($replacements), $template);
                $htmlBody = self::wrapEmailHtml($subject, $htmlBody, $baseUrl, (string)($assignment['tenant_name'] ?? ''));

                $context = [
                    'action' => 'file_assigned',
                    'tenant_id' => $tenantId,
                    'user_id' => $r['id'] ?? null,
                    'assignment_id' => $assignmentId,
                    'assigned_to_type' => $isGroupAssignment ? 'tenant_role' : 'user'
                ];

                $sent = sendEmail($r['email'], $subject, $htmlBody, '', ['context' => $context]);
                if (!$sent) {
                    $success = false;
                    error_log("[WORKFLOW_EMAIL] Failed to send assignment email to: " . $r['email']);
                }
            }

            // Log in audit (best-effort)
            if ($success) {
                try {
                    AuditLogger::logGeneric(
                        (int)($assignment['assigner_id'] ?? $assignment[$faAssignedByCol] ?? 0),
                        $tenantId,
                        'create',
                        'notification',
                        null,
                        "Sent assignment notification for $itemType: $itemName to " . count($recipients) . " recipient(s)"
                    );
                } catch (Exception $e) {
                    // non-blocking
                }
            }

            return $success;

        } catch (Exception $e) {
            error_log("[WORKFLOW_EMAIL] Error in notifyFileAssigned: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification for assignments expiring soon
     *
     * @param int $assignmentId Assignment ID
     * @param int $tenantId Tenant ID
     * @return bool True if emails sent successfully
     */
    public static function notifyAssignmentExpiring($assignmentId, $tenantId) {
        try {
            $db = Database::getInstance();

            // BUG-144c FIX: Get assignment details (removed folder_id join - column doesn't exist)
            $assignment = $db->fetchOne(
                "SELECT fa.*,
                        f.name as file_name,
                        assignee.name as assignee_name, assignee.email as assignee_email,
                        assigner.name as assigner_name, assigner.email as assigner_email,
                        t.name as tenant_name
                 FROM file_assignments fa
                 LEFT JOIN files f ON f.id = fa.file_id
                 JOIN users assignee ON assignee.id = fa.user_id
                 JOIN users assigner ON assigner.id = fa.assigned_by
                 JOIN tenants t ON t.id = fa.tenant_id
                 WHERE fa.id = ? AND fa.tenant_id = ? AND fa.deleted_at IS NULL",
                [$assignmentId, $tenantId]
            );

            if (!$assignment) {
                error_log("[WORKFLOW_EMAIL] Assignment not found: ID=$assignmentId");
                return false;
            }

            // Calculate days remaining
            $expirationDate = new DateTime($assignment['expires_at']);
            $today = new DateTime();
            $interval = $today->diff($expirationDate);
            $daysRemaining = $interval->days;

            // Determine item name and type
            $itemName = '';
            $itemType = '';

            if ($assignment['file_id']) {
                $itemName = $assignment['file_name'];
                $itemType = 'file';
            } elseif ($assignment['folder_id']) {
                $folder = $db->fetchOne(
                    "SELECT name FROM folders WHERE id = ? AND tenant_id = ?",
                    [$assignment['folder_id'], $tenantId]
                );
                $itemName = $folder ? $folder['name'] : 'Cartella';
                $itemType = 'cartella';
            }

            // Load template
            $templatePath = __DIR__ . '/email_templates/workflow/assignment_expiring.html';
            if (!file_exists($templatePath)) {
                error_log("[WORKFLOW_EMAIL] Template not found: $templatePath");
                return false;
            }

            $template = file_get_contents($templatePath);
            $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
            $filesUrl = $baseUrl . '/files.php';

            // Common placeholders
            $commonReplacements = [
                '{{ITEM_NAME}}' => htmlspecialchars($itemName),
                '{{ITEM_TYPE}}' => htmlspecialchars($itemType),
                '{{EXPIRATION_DATE}}' => $expirationDate->format('d/m/Y'),
                '{{DAYS_REMAINING}}' => $daysRemaining,
                '{{FILES_URL}}' => $filesUrl,
                '{{TENANT_NAME}}' => htmlspecialchars($assignment['tenant_name']),
                '{{BASE_URL}}' => $baseUrl,
                '{{YEAR}}' => date('Y')
            ];

            $success = true;
            $subject = "Assegnazione in scadenza: $itemName";

            // Send to assignee
            $assigneeReplacements = array_merge($commonReplacements, [
                '{{USER_NAME}}' => htmlspecialchars($assignment['assignee_name']),
                '{{ASSIGNER_NAME}}' => htmlspecialchars($assignment['assigner_name'])
            ]);

            $htmlBody = str_replace(
                array_keys($assigneeReplacements),
                array_values($assigneeReplacements),
                $template
            );
            $htmlBody = self::wrapEmailHtml($subject, $htmlBody, $baseUrl, (string)($assignment['tenant_name'] ?? ''));

            $context = [
                'action' => 'assignment_expiring',
                'tenant_id' => $tenantId,
                'user_id' => $assignment['user_id'],
                'assignment_id' => $assignmentId
            ];

            if (!sendEmail($assignment['assignee_email'], $subject, $htmlBody, '', ['context' => $context])) {
                error_log("[WORKFLOW_EMAIL] Failed to send email to assignee: " . $assignment['assignee_email']);
                $success = false;
            }

            // Also send to assigner as FYI
            $assignerReplacements = array_merge($commonReplacements, [
                '{{USER_NAME}}' => htmlspecialchars($assignment['assigner_name']),
                '{{ASSIGNER_NAME}}' => htmlspecialchars($assignment['assignee_name']) // Note: switched for assigner perspective
            ]);

            $htmlBody = str_replace(
                array_keys($assignerReplacements),
                array_values($assignerReplacements),
                $template
            );
            $htmlBody = self::wrapEmailHtml($subject, $htmlBody, $baseUrl, (string)($assignment['tenant_name'] ?? ''));

            $context['user_id'] = $assignment['assigned_by'];

            if (!sendEmail($assignment['assigner_email'], $subject, $htmlBody, '', ['context' => $context])) {
                error_log("[WORKFLOW_EMAIL] Failed to send email to assigner: " . $assignment['assigner_email']);
                $success = false;
            }

            return $success;

        } catch (Exception $e) {
            error_log("[WORKFLOW_EMAIL] Error in notifyAssignmentExpiring: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Resolve tenant display name with graceful fallback.
     *
     * @param int $tenantId
     * @return string
     */
    private static function getTenantName(int $tenantId): string {
        if (isset(self::$tenantNameCache[$tenantId])) {
            return self::$tenantNameCache[$tenantId];
        }

        $db = Database::getInstance();

        if (self::$tenantNameColumns === null) {
            self::$tenantNameColumns = self::detectTenantNameColumns();
        }

        foreach (self::$tenantNameColumns as $column) {
            try {
                $tenant = $db->fetchOne(
                    "SELECT `$column` as tenant_name FROM tenants WHERE id = ? LIMIT 1",
                    [$tenantId]
                );
            } catch (Exception $e) {
                error_log("[WORKFLOW_EMAIL] Failed to read tenant column `$column`: " . $e->getMessage());
                $tenant = false;
            }

            if ($tenant && !empty($tenant['tenant_name'])) {
                self::$tenantNameCache[$tenantId] = $tenant['tenant_name'];
                return self::$tenantNameCache[$tenantId];
            }
        }

        $fallback = sprintf('Tenant #%d', $tenantId);
        self::$tenantNameCache[$tenantId] = $fallback;
        return $fallback;
    }

    /**
     * Detect available columns that can be used as tenant name.
     *
     * @return array<int,string>
     */
    private static function detectTenantNameColumns(): array {
        $columns = [];
        try {
            $db = Database::getInstance();
            $result = $db->fetchAll(
                "SELECT COLUMN_NAME 
                 FROM information_schema.columns 
                 WHERE table_schema = DATABASE() 
                   AND table_name = 'tenants'"
            );

            $available = array_map(
                static fn($row) => strtolower($row['COLUMN_NAME'] ?? ''),
                $result
            );

            if (in_array('ragione_sociale', $available, true)) {
                $columns[] = 'ragione_sociale';
            }
            if (in_array('name', $available, true)) {
                $columns[] = 'name';
            }
        } catch (Exception $e) {
            error_log('[WORKFLOW_EMAIL] Unable to inspect tenant columns: ' . $e->getMessage());
        }

        if (empty($columns)) {
            $columns[] = 'name';
        }

        return $columns;
    }

    /**
     * Send notification when a workflow role (validator/approver) is assigned to a user.
     *
     * Best-effort: returns false on failure, never throws to the caller.
     *
     * @param int $targetUserId The user receiving the role
     * @param string $workflowRole 'validator'|'approver'
     * @param int $tenantId Tenant ID
     * @param int $assignedByUserId The admin/manager who assigned the role
     * @return bool
     */
    public static function notifyWorkflowRoleAssigned(int $targetUserId, string $workflowRole, int $tenantId, int $assignedByUserId): bool
    {
        try {
            if (!in_array($workflowRole, ['validator', 'approver'], true)) {
                return false;
            }

            $db = Database::getInstance();

            $target = $db->fetchOne(
                "SELECT id, name, email
                 FROM users
                 WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '')",
                [$targetUserId]
            );
            if (!$target || empty($target['email'])) {
                error_log("[WORKFLOW_EMAIL] Role assigned: target user not found or missing email: $targetUserId");
                return false;
            }

            $assigner = $db->fetchOne(
                "SELECT id, name, email
                 FROM users
                 WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '')",
                [$assignedByUserId]
            );

            $tenantName = self::getTenantName($tenantId);

            $templatePath = __DIR__ . '/email_templates/workflow/workflow_role_assigned.html';
            if (!file_exists($templatePath)) {
                error_log("[WORKFLOW_EMAIL] Template not found: $templatePath");
                return false;
            }

            $template = file_get_contents($templatePath);
            $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
            $filesUrl = $baseUrl . '/files.php';

            $roleLabel = $workflowRole === 'validator' ? 'Validatore' : 'Approvatore';
            $assignedAt = date('d/m/Y H:i');

            $replacements = [
                '{{USER_NAME}}' => htmlspecialchars((string)$target['name']),
                '{{ROLE_LABEL}}' => htmlspecialchars($roleLabel),
                '{{ASSIGNER_NAME}}' => htmlspecialchars((string)($assigner['name'] ?? 'Amministratore')),
                '{{ASSIGNED_AT}}' => $assignedAt,
                '{{FILES_URL}}' => $filesUrl,
                '{{TENANT_NAME}}' => htmlspecialchars((string)$tenantName),
                '{{BASE_URL}}' => $baseUrl,
                '{{YEAR}}' => date('Y')
            ];

            $htmlBody = str_replace(array_keys($replacements), array_values($replacements), $template);
            $htmlBody = self::wrapEmailHtml($subject, $htmlBody, $baseUrl, (string)$tenantName);

            $subject = "Nexio — Ruolo workflow assegnato: {$roleLabel}";

            $context = [
                'action' => 'workflow_role_assigned',
                'tenant_id' => $tenantId,
                'user_id' => $targetUserId,
                'assigned_by' => $assignedByUserId,
                'workflow_role' => $workflowRole
            ];

            return (bool)sendEmail($target['email'], $subject, $htmlBody, '', ['context' => $context]);

        } catch (Exception $e) {
            error_log("[WORKFLOW_EMAIL] Error in notifyWorkflowRoleAssigned: " . $e->getMessage());
            return false;
        }
    }
}