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

class WorkflowEmailNotifier {

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
                "SELECT f.*, u.name as creator_name, u.email as creator_email,
                        t.name as tenant_name
                 FROM files f
                 JOIN users u ON u.id = f.created_by
                 JOIN tenants t ON t.id = f.tenant_id
                 WHERE f.id = ? AND f.tenant_id = ? AND f.deleted_at IS NULL",
                [$fileId, $tenantId]
            );

            if (!$file) {
                error_log("[WORKFLOW_EMAIL] File not found: ID=$fileId");
                return false;
            }

            // Get all validators for tenant
            $validators = $db->fetchAll(
                "SELECT u.id, u.name, u.email
                 FROM workflow_roles wr
                 JOIN users u ON u.id = wr.user_id
                 WHERE wr.tenant_id = ?
                 AND wr.workflow_role = 'validator'
                 AND wr.deleted_at IS NULL
                 AND u.deleted_at IS NULL",
                [$tenantId]
            );

            if (empty($validators)) {
                error_log("[WORKFLOW_EMAIL] No validators configured for tenant: ID=$tenantId");
                return false;
            }

            // Load template
            $templatePath = __DIR__ . '/email_templates/workflow/document_submitted.html';
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
                '{{CREATOR_NAME}}' => htmlspecialchars($file['creator_name']),
                '{{SUBMISSION_DATE}}' => date('d/m/Y H:i'),
                '{{DOCUMENT_URL}}' => $documentUrl,
                '{{TENANT_NAME}}' => htmlspecialchars($file['tenant_name']),
                '{{BASE_URL}}' => $baseUrl,
                '{{YEAR}}' => date('Y')
            ];

            $success = true;
            $subject = "Nuovo documento da validare: " . $file['name'];

            // Send to each validator
            foreach ($validators as $validator) {
                $personalReplacements = array_merge($commonReplacements, [
                    '{{USER_NAME}}' => htmlspecialchars($validator['name'])
                ]);

                $htmlBody = str_replace(
                    array_keys($personalReplacements),
                    array_values($personalReplacements),
                    $template
                );

                $context = [
                    'action' => 'workflow_document_submitted',
                    'tenant_id' => $tenantId,
                    'user_id' => $validator['id'],
                    'file_id' => $fileId
                ];

                if (!sendEmail($validator['email'], $subject, $htmlBody, '', ['context' => $context])) {
                    error_log("[WORKFLOW_EMAIL] Failed to send email to: " . $validator['email']);
                    $success = false;
                }
            }

            // Log in audit
            if ($success) {
                AuditLogger::logGeneric(
                    $submitterId,
                    $tenantId,
                    'email_sent',
                    'notification',
                    null,
                    "Sent workflow notifications: document_submitted for file $fileId to " . count($validators) . " validators"
                );
            }

            return $success;

        } catch (Exception $e) {
            error_log("[WORKFLOW_EMAIL] Error in notifyDocumentSubmitted: " . $e->getMessage());
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
                "SELECT f.*,
                        creator.name as creator_name, creator.email as creator_email,
                        validator.name as validator_name,
                        t.name as tenant_name
                 FROM files f
                 JOIN users creator ON creator.id = f.created_by
                 JOIN users validator ON validator.id = ?
                 JOIN tenants t ON t.id = f.tenant_id
                 WHERE f.id = ? AND f.tenant_id = ? AND f.deleted_at IS NULL",
                [$validatorId, $fileId, $tenantId]
            );

            if (!$file) {
                error_log("[WORKFLOW_EMAIL] File not found: ID=$fileId");
                return false;
            }

            // Get all approvers for tenant
            $approvers = $db->fetchAll(
                "SELECT u.id, u.name, u.email
                 FROM workflow_roles wr
                 JOIN users u ON u.id = wr.user_id
                 WHERE wr.tenant_id = ?
                 AND wr.workflow_role = 'approver'
                 AND wr.deleted_at IS NULL
                 AND u.deleted_at IS NULL",
                [$tenantId]
            );

            if (empty($approvers)) {
                error_log("[WORKFLOW_EMAIL] No approvers configured for tenant: ID=$tenantId");
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
                '{{TENANT_NAME}}' => htmlspecialchars($file['tenant_name']),
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

            $success = true;
            $subject = "Documento validato e in attesa di approvazione: " . $file['name'];

            // Send to all approvers
            foreach ($approvers as $approver) {
                $personalReplacements = array_merge($commonReplacements, [
                    '{{USER_NAME}}' => htmlspecialchars($approver['name'])
                ]);

                $htmlBody = str_replace(
                    array_keys($personalReplacements),
                    array_values($personalReplacements),
                    $template
                );

                $context = [
                    'action' => 'workflow_document_validated',
                    'tenant_id' => $tenantId,
                    'user_id' => $approver['id'],
                    'file_id' => $fileId
                ];

                if (!sendEmail($approver['email'], $subject, $htmlBody, '', ['context' => $context])) {
                    error_log("[WORKFLOW_EMAIL] Failed to send email to: " . $approver['email']);
                    $success = false;
                }
            }

            // Also send FYI to creator
            $creatorSubject = "Il tuo documento è stato validato: " . $file['name'];
            $personalReplacements = array_merge($commonReplacements, [
                '{{USER_NAME}}' => htmlspecialchars($file['creator_name'])
            ]);

            $htmlBody = str_replace(
                array_keys($personalReplacements),
                array_values($personalReplacements),
                $template
            );

            $context = [
                'action' => 'workflow_document_validated_fyi',
                'tenant_id' => $tenantId,
                'user_id' => $file['created_by'],
                'file_id' => $fileId
            ];

            sendEmail($file['creator_email'], $creatorSubject, $htmlBody, '', ['context' => $context]);

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
                "SELECT f.*,
                        creator.name as creator_name, creator.email as creator_email,
                        approver.name as approver_name,
                        t.name as tenant_name
                 FROM files f
                 JOIN users creator ON creator.id = f.created_by
                 JOIN users approver ON approver.id = ?
                 JOIN tenants t ON t.id = f.tenant_id
                 WHERE f.id = ? AND f.tenant_id = ? AND f.deleted_at IS NULL",
                [$approverId, $fileId, $tenantId]
            );

            if (!$file) {
                error_log("[WORKFLOW_EMAIL] File not found: ID=$fileId");
                return false;
            }

            // Get all validators and approvers for notification
            $stakeholders = $db->fetchAll(
                "SELECT DISTINCT u.id, u.name, u.email
                 FROM workflow_roles wr
                 JOIN users u ON u.id = wr.user_id
                 WHERE wr.tenant_id = ?
                 AND wr.workflow_role IN ('validator', 'approver')
                 AND wr.deleted_at IS NULL
                 AND u.deleted_at IS NULL
                 UNION
                 SELECT id, name, email FROM users WHERE id = ?",
                [$tenantId, $file['created_by']]
            );

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
                '{{TENANT_NAME}}' => htmlspecialchars($file['tenant_name']),
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

            // Send to all stakeholders
            foreach ($stakeholders as $recipient) {
                $personalReplacements = array_merge($commonReplacements, [
                    '{{USER_NAME}}' => htmlspecialchars($recipient['name'])
                ]);

                $htmlBody = str_replace(
                    array_keys($personalReplacements),
                    array_values($personalReplacements),
                    $template
                );

                $context = [
                    'action' => 'workflow_document_approved',
                    'tenant_id' => $tenantId,
                    'user_id' => $recipient['id'],
                    'file_id' => $fileId
                ];

                if (!sendEmail($recipient['email'], $subject, $htmlBody, '', ['context' => $context])) {
                    error_log("[WORKFLOW_EMAIL] Failed to send email to: " . $recipient['email']);
                    $success = false;
                }
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
                "SELECT f.*,
                        creator.name as creator_name, creator.email as creator_email,
                        rejector.name as rejector_name, rejector.email as rejector_email,
                        t.name as tenant_name
                 FROM files f
                 JOIN users creator ON creator.id = f.created_by
                 JOIN users rejector ON rejector.id = ?
                 JOIN tenants t ON t.id = f.tenant_id
                 WHERE f.id = ? AND f.tenant_id = ? AND f.deleted_at IS NULL",
                [$rejectorId, $fileId, $tenantId]
            );

            if (!$file) {
                error_log("[WORKFLOW_EMAIL] File not found: ID=$fileId");
                return false;
            }

            // Determine template and recipients based on current state
            $recipients = [];
            $templateFile = '';
            $subject = '';

            if ($currentState === 'in_validazione') {
                // Rejected by validator - notify creator only
                $templateFile = 'document_rejected_validation.html';
                $subject = "Documento rifiutato: " . $file['name'];

                $recipients[] = [
                    'id' => $file['created_by'],
                    'name' => $file['creator_name'],
                    'email' => $file['creator_email']
                ];

            } elseif ($currentState === 'in_approvazione') {
                // Rejected by approver - notify creator and all validators
                $templateFile = 'document_rejected_approval.html';
                $subject = "Documento rifiutato in fase di approvazione: " . $file['name'];

                // Add creator
                $recipients[] = [
                    'id' => $file['created_by'],
                    'name' => $file['creator_name'],
                    'email' => $file['creator_email']
                ];

                // Add all validators as FYI
                $validators = $db->fetchAll(
                    "SELECT u.id, u.name, u.email
                     FROM workflow_roles wr
                     JOIN users u ON u.id = wr.user_id
                     WHERE wr.tenant_id = ?
                     AND wr.workflow_role = 'validator'
                     AND wr.deleted_at IS NULL
                     AND u.deleted_at IS NULL",
                    [$tenantId]
                );

                foreach ($validators as $validator) {
                    $recipients[] = $validator;
                }
            }

            // Load template
            $templatePath = __DIR__ . '/email_templates/workflow/' . $templateFile;
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
                '{{REJECTOR_NAME}}' => htmlspecialchars($file['rejector_name']),
                '{{REJECTION_DATE}}' => date('d/m/Y H:i'),
                '{{REJECTION_REASON}}' => htmlspecialchars($comment),
                '{{DOCUMENT_URL}}' => $documentUrl,
                '{{TENANT_NAME}}' => htmlspecialchars($file['tenant_name']),
                '{{BASE_URL}}' => $baseUrl,
                '{{YEAR}}' => date('Y'),
                '{{REJECTOR_ROLE}}' => $currentState === 'in_validazione' ? 'validatore' : 'approvatore'
            ];

            $success = true;

            // Send to all recipients
            foreach ($recipients as $recipient) {
                $personalReplacements = array_merge($commonReplacements, [
                    '{{USER_NAME}}' => htmlspecialchars($recipient['name'])
                ]);

                $htmlBody = str_replace(
                    array_keys($personalReplacements),
                    array_values($personalReplacements),
                    $template
                );

                $context = [
                    'action' => 'workflow_document_rejected',
                    'tenant_id' => $tenantId,
                    'user_id' => $recipient['id'],
                    'file_id' => $fileId,
                    'rejection_stage' => $currentState
                ];

                if (!sendEmail($recipient['email'], $subject, $htmlBody, '', ['context' => $context])) {
                    error_log("[WORKFLOW_EMAIL] Failed to send email to: " . $recipient['email']);
                    $success = false;
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

            // Get assignment details
            $assignment = $db->fetchOne(
                "SELECT fa.*,
                        f.name as file_name, f.type as file_type,
                        assignee.name as assignee_name, assignee.email as assignee_email,
                        assigner.name as assigner_name,
                        t.name as tenant_name
                 FROM file_assignments fa
                 LEFT JOIN files f ON f.id = fa.file_id
                 LEFT JOIN folders fo ON fo.id = fa.folder_id
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

            // Determine item name and type
            $itemName = '';
            $itemType = '';

            if ($assignment['file_id']) {
                $itemName = $assignment['file_name'];
                $itemType = 'file';
            } elseif ($assignment['folder_id']) {
                // Get folder name
                $folder = $db->fetchOne(
                    "SELECT name FROM folders WHERE id = ? AND tenant_id = ?",
                    [$assignment['folder_id'], $tenantId]
                );
                $itemName = $folder ? $folder['name'] : 'Cartella';
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

            // Placeholders
            $replacements = [
                '{{USER_NAME}}' => htmlspecialchars($assignment['assignee_name']),
                '{{ITEM_NAME}}' => htmlspecialchars($itemName),
                '{{ITEM_TYPE}}' => htmlspecialchars($itemType),
                '{{ASSIGNER_NAME}}' => htmlspecialchars($assignment['assigner_name']),
                '{{ASSIGNMENT_REASON}}' => htmlspecialchars($assignment['reason'] ?: 'Nessuna motivazione specificata'),
                '{{EXPIRATION_DATE}}' => $expirationDate,
                '{{FILES_URL}}' => $filesUrl,
                '{{TENANT_NAME}}' => htmlspecialchars($assignment['tenant_name']),
                '{{BASE_URL}}' => $baseUrl,
                '{{YEAR}}' => date('Y')
            ];

            $htmlBody = str_replace(
                array_keys($replacements),
                array_values($replacements),
                $template
            );

            $subject = "Ti è stato assegnato un $itemType: $itemName";

            $context = [
                'action' => 'file_assigned',
                'tenant_id' => $tenantId,
                'user_id' => $assignment['user_id'],
                'assignment_id' => $assignmentId
            ];

            $result = sendEmail(
                $assignment['assignee_email'],
                $subject,
                $htmlBody,
                '',
                ['context' => $context]
            );

            if ($result) {
                // Log in audit
                AuditLogger::logGeneric(
                    $assignment['assigned_by'],
                    $tenantId,
                    'email_sent',
                    'notification',
                    null,
                    "Sent assignment notification for $itemType: $itemName to " . $assignment['assignee_email']
                );
            }

            return $result;

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

            // Get assignment details
            $assignment = $db->fetchOne(
                "SELECT fa.*,
                        f.name as file_name,
                        assignee.name as assignee_name, assignee.email as assignee_email,
                        assigner.name as assigner_name, assigner.email as assigner_email,
                        t.name as tenant_name
                 FROM file_assignments fa
                 LEFT JOIN files f ON f.id = fa.file_id
                 LEFT JOIN folders fo ON fo.id = fa.folder_id
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
}