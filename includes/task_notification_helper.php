<?php
/**
 * Task Notification Helper
 *
 * Manages email notifications for task events in CollaboraNexio
 * - Task created
 * - Task assigned/removed
 * - Task updated
 *
 * @author CollaboraNexio
 * @version 1.0.0
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

class TaskNotification {

    private $db;
    private $baseUrl;
    private $templateDir;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost:8888/CollaboraNexio';
        $this->templateDir = __DIR__ . '/email_templates/tasks/';
    }

    /**
     * Send notification when task is created
     *
     * @param int $taskId Task ID
     * @param array $assigneeIds Array of user IDs to notify
     * @param int $createdBy User ID who created the task
     * @return bool Success status
     */
    public function sendTaskCreatedNotification($taskId, $assigneeIds, $createdBy) {
        if (empty($assigneeIds)) {
            return true; // No one to notify
        }

        try {
            // Get task details
            $task = $this->getTaskDetails($taskId);
            if (!$task) {
                error_log("TaskNotification: Task $taskId not found");
                return false;
            }

            // Get creator info
            $creator = $this->getUserInfo($createdBy);

            // Get all assignees info
            $assignees = [];
            foreach ($assigneeIds as $userId) {
                $userInfo = $this->getUserInfo($userId);
                if ($userInfo) {
                    $assignees[] = $userInfo;
                }
            }

            $successCount = 0;

            foreach ($assignees as $assignee) {
                // Check user preferences
                if (!$this->shouldNotify($assignee['id'], 'notify_task_created')) {
                    continue;
                }

                // Prepare template data
                $templateData = [
                    'USER_NAME' => $assignee['name'],
                    'TASK_TITLE' => $task['title'],
                    'TASK_DESCRIPTION' => $task['description'] ?? '',
                    'TASK_STATUS_LABEL' => $this->getStatusLabel($task['status']),
                    'TASK_PRIORITY' => $task['priority'],
                    'TASK_PRIORITY_LABEL' => $this->getPriorityLabel($task['priority']),
                    'TASK_DUE_DATE' => $task['due_date'] ? date('d/m/Y H:i', strtotime($task['due_date'])) : null,
                    'TASK_ESTIMATED_HOURS' => $task['estimated_hours'],
                    'CREATED_BY_NAME' => $creator['name'] ?? 'Sistema',
                    'TASK_URL' => $this->baseUrl . '/tasks.php?task_id=' . $taskId,
                    'TASK_LIST_URL' => $this->baseUrl . '/tasks.php',
                    'BASE_URL' => $this->baseUrl,
                    'YEAR' => date('Y')
                ];

                // Add other assignees list (excluding current recipient)
                $otherAssignees = array_filter($assignees, function($a) use ($assignee) {
                    return $a['id'] != $assignee['id'];
                });

                if (!empty($otherAssignees)) {
                    $templateData['ASSIGNEES_LIST'] = true;
                    $templateData['ASSIGNEES'] = array_map(function($a) { return $a['name']; }, $otherAssignees);
                }

                // Render email
                $html = $this->renderTemplate('task_created.html', $templateData);
                $subject = "Nuovo task: {$task['title']}";

                // Send email (non-blocking)
                $sent = sendEmail(
                    $assignee['email'],
                    $subject,
                    $html,
                    '',
                    [
                        'context' => [
                            'tenant_id' => $task['tenant_id'],
                            'user_id' => $assignee['id'],
                            'action' => 'task_created_notification'
                        ]
                    ]
                );

                // Log notification
                $this->logNotification(
                    $task['tenant_id'],
                    $taskId,
                    $assignee['id'],
                    'task_created',
                    $assignee['email'],
                    $subject,
                    $sent ? 'sent' : 'failed',
                    $createdBy
                );

                if ($sent) {
                    $successCount++;
                }
            }

            return $successCount > 0;

        } catch (Exception $e) {
            error_log("TaskNotification Error (sendTaskCreatedNotification): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification when user is assigned to task
     *
     * @param int $taskId Task ID
     * @param int $userId User ID who was assigned
     * @param int $assignedBy User ID who performed the assignment
     * @return bool Success status
     */
    public function sendTaskAssignedNotification($taskId, $userId, $assignedBy) {
        try {
            // Get task details
            $task = $this->getTaskDetails($taskId);
            if (!$task) {
                return false;
            }

            // Get user info
            $user = $this->getUserInfo($userId);
            if (!$user) {
                return false;
            }

            // Check user preferences
            if (!$this->shouldNotify($userId, 'notify_task_assigned')) {
                return true; // User doesn't want notifications
            }

            // Get assigner info
            $assigner = $this->getUserInfo($assignedBy);

            // Prepare template data
            $templateData = [
                'USER_NAME' => $user['name'],
                'TASK_TITLE' => $task['title'],
                'TASK_DESCRIPTION' => $task['description'] ?? '',
                'TASK_PRIORITY' => $task['priority'],
                'TASK_PRIORITY_LABEL' => $this->getPriorityLabel($task['priority']),
                'TASK_DUE_DATE' => $task['due_date'] ? date('d/m/Y H:i', strtotime($task['due_date'])) : null,
                'TASK_ESTIMATED_HOURS' => $task['estimated_hours'],
                'ASSIGNED_BY_NAME' => $assigner['name'] ?? 'Sistema',
                'TASK_URL' => $this->baseUrl . '/tasks.php?task_id=' . $taskId,
                'BASE_URL' => $this->baseUrl,
                'YEAR' => date('Y')
            ];

            // Render email
            $html = $this->renderTemplate('task_assigned.html', $templateData);
            $subject = "Assegnato al task: {$task['title']}";

            // Send email
            $sent = sendEmail(
                $user['email'],
                $subject,
                $html,
                '',
                [
                    'context' => [
                        'tenant_id' => $task['tenant_id'],
                        'user_id' => $userId,
                        'action' => 'task_assigned_notification'
                    ]
                ]
            );

            // Log notification
            $this->logNotification(
                $task['tenant_id'],
                $taskId,
                $userId,
                'task_assigned',
                $user['email'],
                $subject,
                $sent ? 'sent' : 'failed',
                $assignedBy
            );

            return $sent;

        } catch (Exception $e) {
            error_log("TaskNotification Error (sendTaskAssignedNotification): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification when user is removed from task
     *
     * @param int $taskId Task ID
     * @param int $userId User ID who was removed
     * @param int $removedBy User ID who performed the removal
     * @return bool Success status
     */
    public function sendTaskRemovedNotification($taskId, $userId, $removedBy) {
        try {
            // Get task details
            $task = $this->getTaskDetails($taskId);
            if (!$task) {
                return false;
            }

            // Get user info
            $user = $this->getUserInfo($userId);
            if (!$user) {
                return false;
            }

            // Check user preferences
            if (!$this->shouldNotify($userId, 'notify_task_removed')) {
                return true;
            }

            // Get remover info
            $remover = $this->getUserInfo($removedBy);

            // Prepare template data
            $templateData = [
                'USER_NAME' => $user['name'],
                'TASK_TITLE' => $task['title'],
                'TASK_DESCRIPTION' => $task['description'] ?? '',
                'REMOVED_BY_NAME' => $remover['name'] ?? 'Sistema',
                'TASK_LIST_URL' => $this->baseUrl . '/tasks.php',
                'BASE_URL' => $this->baseUrl,
                'YEAR' => date('Y')
            ];

            // Render email
            $html = $this->renderTemplate('task_removed.html', $templateData);
            $subject = "Rimosso dal task: {$task['title']}";

            // Send email
            $sent = sendEmail(
                $user['email'],
                $subject,
                $html,
                '',
                [
                    'context' => [
                        'tenant_id' => $task['tenant_id'],
                        'user_id' => $userId,
                        'action' => 'task_removed_notification'
                    ]
                ]
            );

            // Log notification
            $this->logNotification(
                $task['tenant_id'],
                $taskId,
                $userId,
                'task_removed',
                $user['email'],
                $subject,
                $sent ? 'sent' : 'failed',
                $removedBy
            );

            return $sent;

        } catch (Exception $e) {
            error_log("TaskNotification Error (sendTaskRemovedNotification): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification when task is updated
     *
     * @param int $taskId Task ID
     * @param array $changedFields Array of field changes ['field' => ['old' => ..., 'new' => ...]]
     * @param int $updatedBy User ID who updated the task
     * @return bool Success status
     */
    public function sendTaskUpdatedNotification($taskId, $changedFields, $updatedBy) {
        if (empty($changedFields)) {
            return true; // No changes to notify
        }

        try {
            // Get task details
            $task = $this->getTaskDetails($taskId);
            if (!$task) {
                return false;
            }

            // Get all assigned users
            $assignees = $this->getTaskAssignees($taskId);
            if (empty($assignees)) {
                return true; // No one to notify
            }

            // Get updater info
            $updater = $this->getUserInfo($updatedBy);

            $successCount = 0;

            foreach ($assignees as $assignee) {
                // Skip the user who made the update
                if ($assignee['id'] == $updatedBy) {
                    continue;
                }

                // Check user preferences
                if (!$this->shouldNotify($assignee['id'], 'notify_task_updated')) {
                    continue;
                }

                // Prepare template data
                $templateData = [
                    'USER_NAME' => $assignee['name'],
                    'TASK_TITLE' => $task['title'],
                    'HAS_CHANGES' => true,
                    'UPDATED_BY_NAME' => $updater['name'] ?? 'Sistema',
                    'UPDATE_TIME' => date('d/m/Y H:i'),
                    'TASK_URL' => $this->baseUrl . '/tasks.php?task_id=' . $taskId,
                    'BASE_URL' => $this->baseUrl,
                    'YEAR' => date('Y')
                ];

                // Add change details
                foreach ($changedFields as $field => $change) {
                    $fieldKey = strtoupper($field) . '_CHANGED';
                    $templateData[$fieldKey] = true;

                    if ($field === 'status') {
                        $templateData['OLD_STATUS'] = $this->getStatusLabel($change['old']);
                        $templateData['NEW_STATUS'] = $this->getStatusLabel($change['new']);
                    } elseif ($field === 'priority') {
                        $templateData['OLD_PRIORITY'] = $this->getPriorityLabel($change['old']);
                        $templateData['NEW_PRIORITY'] = $this->getPriorityLabel($change['new']);
                    } elseif ($field === 'due_date') {
                        $templateData['OLD_DUE_DATE'] = $change['old'] ? date('d/m/Y', strtotime($change['old'])) : 'Nessuna';
                        $templateData['NEW_DUE_DATE'] = $change['new'] ? date('d/m/Y', strtotime($change['new'])) : 'Nessuna';
                    } else {
                        $templateData['OLD_' . strtoupper($field)] = $change['old'] ?? 'N/A';
                        $templateData['NEW_' . strtoupper($field)] = $change['new'] ?? 'N/A';
                    }
                }

                // Render email
                $html = $this->renderTemplate('task_updated.html', $templateData);

                // Create summary for subject
                $changesSummary = implode(', ', array_keys($changedFields));
                $subject = "Task aggiornato: {$task['title']} ($changesSummary)";

                // Send email
                $sent = sendEmail(
                    $assignee['email'],
                    $subject,
                    $html,
                    '',
                    [
                        'context' => [
                            'tenant_id' => $task['tenant_id'],
                            'user_id' => $assignee['id'],
                            'action' => 'task_updated_notification'
                        ]
                    ]
                );

                // Log notification
                $this->logNotification(
                    $task['tenant_id'],
                    $taskId,
                    $assignee['id'],
                    'task_updated',
                    $assignee['email'],
                    $subject,
                    $sent ? 'sent' : 'failed',
                    $updatedBy,
                    json_encode($changedFields)
                );

                if ($sent) {
                    $successCount++;
                }
            }

            return $successCount > 0;

        } catch (Exception $e) {
            error_log("TaskNotification Error (sendTaskUpdatedNotification): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user notification preferences
     *
     * @param int $userId User ID
     * @return array|null Preferences or null
     */
    public function getUserNotificationPreferences($userId) {
        try {
            $prefs = $this->db->fetchOne(
                'SELECT * FROM user_notification_preferences
                 WHERE user_id = ? AND deleted_at IS NULL',
                [$userId]
            );

            return $prefs;

        } catch (Exception $e) {
            error_log("TaskNotification Error (getUserNotificationPreferences): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if user should be notified for a specific event type
     *
     * @param int $userId User ID
     * @param string $notificationType Preference key (e.g., 'notify_task_assigned')
     * @return bool True if should notify
     */
    private function shouldNotify($userId, $notificationType) {
        $prefs = $this->getUserNotificationPreferences($userId);

        if (!$prefs) {
            // No preferences set - use defaults (all TRUE)
            return true;
        }

        return isset($prefs[$notificationType]) && $prefs[$notificationType] == 1;
    }

    /**
     * Get task details
     *
     * @param int $taskId Task ID
     * @return array|null Task data
     */
    private function getTaskDetails($taskId) {
        return $this->db->fetchOne(
            'SELECT * FROM tasks WHERE id = ? AND deleted_at IS NULL',
            [$taskId]
        );
    }

    /**
     * Get user info
     *
     * @param int $userId User ID
     * @return array|null User data
     */
    private function getUserInfo($userId) {
        return $this->db->fetchOne(
            'SELECT id, name, email, tenant_id FROM users WHERE id = ? AND deleted_at IS NULL',
            [$userId]
        );
    }

    /**
     * Get all users assigned to a task
     *
     * @param int $taskId Task ID
     * @return array Array of users
     */
    private function getTaskAssignees($taskId) {
        return $this->db->fetchAll(
            'SELECT DISTINCT u.id, u.name, u.email
             FROM task_assignments ta
             JOIN users u ON ta.user_id = u.id
             WHERE ta.task_id = ? AND ta.deleted_at IS NULL AND u.deleted_at IS NULL',
            [$taskId]
        );
    }

    /**
     * Log notification attempt
     *
     * @param int $tenantId Tenant ID
     * @param int $taskId Task ID
     * @param int $userId User ID (recipient)
     * @param string $notificationType Type of notification
     * @param string $email Email address
     * @param string $subject Email subject
     * @param string $deliveryStatus 'sent' or 'failed'
     * @param int|null $sentBy User who triggered the notification
     * @param string|null $changeDetails JSON string of changes
     * @return bool Success
     */
    public function logNotification($tenantId, $taskId, $userId, $notificationType, $email, $subject, $deliveryStatus, $sentBy = null, $changeDetails = null) {
        try {
            $this->db->insert('task_notifications', [
                'tenant_id' => $tenantId,
                'task_id' => $taskId,
                'user_id' => $userId,
                'notification_type' => $notificationType,
                'recipient_email' => $email,
                'email_subject' => $subject,
                'email_sent_at' => $deliveryStatus === 'sent' ? date('Y-m-d H:i:s') : null,
                'delivery_status' => $deliveryStatus,
                'change_details' => $changeDetails,
                'sent_by' => $sentBy,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            return true;

        } catch (Exception $e) {
            error_log("TaskNotification Error (logNotification): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Render email template
     *
     * @param string $templateName Template filename
     * @param array $data Template variables
     * @return string Rendered HTML
     */
    private function renderTemplate($templateName, $data) {
        $templatePath = $this->templateDir . $templateName;

        if (!file_exists($templatePath)) {
            throw new Exception("Template not found: $templatePath");
        }

        $template = file_get_contents($templatePath);

        // Simple Mustache-like template rendering
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Handle arrays (for {{#ARRAY}}...{{/ARRAY}} blocks)
                $blockPattern = '/{{#' . $key . '}}(.*?){{\\/' . $key . '}}/s';
                if (preg_match($blockPattern, $template, $matches)) {
                    $template = preg_replace($blockPattern, $matches[1], $template);
                }
            } elseif ($value === null || $value === '') {
                // Remove conditional blocks if value is null/empty
                $blockPattern = '/{{#' . $key . '}}.*?{{\\/' . $key . '}}/s';
                $template = preg_replace($blockPattern, '', $template);
            } else {
                // Simple value replacement
                $template = str_replace('{{' . $key . '}}', htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $template);
            }
        }

        // Remove any remaining conditional blocks (for false conditions)
        $template = preg_replace('/{{#\w+}}.*?{{\/\w+}}/s', '', $template);

        // Remove any remaining placeholders
        $template = preg_replace('/{{[^}]+}}/', '', $template);

        return $template;
    }

    /**
     * Get human-readable status label
     *
     * @param string $status Status code
     * @return string Label
     */
    private function getStatusLabel($status) {
        $labels = [
            'todo' => 'Da Fare',
            'in_progress' => 'In Corso',
            'review' => 'In Revisione',
            'done' => 'Completato',
            'cancelled' => 'Annullato'
        ];

        return $labels[$status] ?? ucfirst($status);
    }

    /**
     * Get human-readable priority label
     *
     * @param string $priority Priority code
     * @return string Label
     */
    private function getPriorityLabel($priority) {
        $labels = [
            'low' => 'Bassa',
            'medium' => 'Media',
            'high' => 'Alta',
            'critical' => 'Critica'
        ];

        return $labels[$priority] ?? ucfirst($priority);
    }
}
