<?php
/**
 * Cron Job: Check Assignment Expirations
 *
 * This script checks for file/folder assignments that are expiring soon (7 days)
 * and sends warning emails to both the assignee and the assigner.
 *
 * Schedule: Run daily at 8:00 AM
 * Crontab: 0 8 * * * /usr/bin/php /path/to/CollaboraNexio/cron/check_assignment_expirations.php
 *
 * @author CollaboraNexio
 * @version 1.0.0
 */

// ============================================
// CONFIGURATION
// ============================================

define('EXPIRATION_WARNING_DAYS', 7);  // Send warning 7 days before expiration
define('BATCH_SIZE', 50);  // Process in batches to avoid memory issues

// ============================================
// BOOTSTRAP
// ============================================

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

// Set timezone
date_default_timezone_set('Europe/Rome');

// Include required files
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/workflow_email_notifier.php';
require_once __DIR__ . '/../includes/audit_helper.php';

/**
 * Detect column existence (schema may differ across environments).
 */
function tableHasColumn(Database $db, string $table, string $column): bool {
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

// ============================================
// MAIN EXECUTION
// ============================================

try {
    $startTime = microtime(true);
    $processedCount = 0;
    $emailsSent = 0;
    $errors = 0;

    echo "[" . date('Y-m-d H:i:s') . "] Starting assignment expiration check...\n";

    $db = Database::getInstance();

    // Schema detection
    $faAssignedToCol = tableHasColumn($db, 'file_assignments', 'assigned_to_user_id') ? 'assigned_to_user_id' : 'user_id';
    $faAssignedByCol = tableHasColumn($db, 'file_assignments', 'assigned_by_user_id') ? 'assigned_by_user_id' : 'assigned_by';
    $faReasonCol = tableHasColumn($db, 'file_assignments', 'assignment_reason') ? 'assignment_reason' : 'reason';
    $faHasFolderId = tableHasColumn($db, 'file_assignments', 'folder_id');
    $filesNameCol = tableHasColumn($db, 'files', 'file_name') ? 'file_name' : 'name';
    $foldersNameCol = tableHasColumn($db, 'folders', 'folder_name') ? 'folder_name' : 'name';

    // Calculate date range for warnings
    $warningStartDate = date('Y-m-d 00:00:00', strtotime('+' . EXPIRATION_WARNING_DAYS . ' days'));
    $warningEndDate = date('Y-m-d 23:59:59', strtotime('+' . EXPIRATION_WARNING_DAYS . ' days'));

    echo "Looking for assignments expiring between $warningStartDate and $warningEndDate\n";

    // ============================================
    // FIND EXPIRING ASSIGNMENTS
    // ============================================

    $query = "SELECT
                fa.id,
                fa.tenant_id,
                fa.file_id,
                " . ($faHasFolderId ? "fa.folder_id," : "NULL as folder_id,") . "
                fa.{$faAssignedToCol} AS assigned_to_user_id,
                fa.{$faAssignedByCol} AS assigned_by_user_id,
                fa.{$faReasonCol} AS assignment_reason,
                fa.expires_at,
                fa.expiration_warning_sent,
                f.{$filesNameCol} as file_name,
                " . ($faHasFolderId ? "fo.{$foldersNameCol} as folder_name," : "NULL as folder_name,") . "
                assignee.name as assignee_name,
                assignee.email as assignee_email,
                assigner.name as assigner_name,
                assigner.email as assigner_email,
                t.name as tenant_name
              FROM file_assignments fa
              LEFT JOIN files f ON fa.file_id = f.id
              " . ($faHasFolderId ? "LEFT JOIN folders fo ON fa.folder_id = fo.id" : "") . "
              JOIN users assignee ON fa.{$faAssignedToCol} = assignee.id
              JOIN users assigner ON fa.{$faAssignedByCol} = assigner.id
              JOIN tenants t ON fa.tenant_id = t.id
              WHERE fa.deleted_at IS NULL
                AND fa.expires_at BETWEEN ? AND ?
                AND (fa.expiration_warning_sent IS NULL OR fa.expiration_warning_sent = 0)
              LIMIT ?";

    $offset = 0;

    do {
        // Fetch batch of expiring assignments
        $assignments = $db->fetchAll(
            $query,
            [$warningStartDate, $warningEndDate, BATCH_SIZE]
        );

        if (empty($assignments)) {
            break;
        }

        echo "Processing batch of " . count($assignments) . " assignments...\n";

        // ============================================
        // PROCESS EACH ASSIGNMENT
        // ============================================

        foreach ($assignments as $assignment) {
            $processedCount++;

            try {
                echo "  Assignment #" . $assignment['id'] . " (";

                // Determine item type and name
                $itemName = '';
                $itemType = '';

                if ($assignment['file_id']) {
                    $itemName = $assignment['file_name'];
                    $itemType = 'file';
                    echo "File: $itemName";
                } elseif ($assignment['folder_id']) {
                    $itemName = $assignment['folder_name'];
                    $itemType = 'cartella';
                    echo "Folder: $itemName";
                }

                echo ") - Tenant: " . $assignment['tenant_name'] . "\n";

                // ============================================
                // SEND WARNING EMAIL
                // ============================================

                $emailSent = false;

                try {
                    $emailSent = WorkflowEmailNotifier::notifyAssignmentExpiring(
                        $assignment['id'],
                        $assignment['tenant_id']
                    );

                    if ($emailSent) {
                        echo "    ✓ Email sent to " . $assignment['assignee_email'] . " and " . $assignment['assigner_email'] . "\n";
                        $emailsSent++;
                    } else {
                        echo "    ✗ Email sending failed\n";
                        $errors++;
                    }
                } catch (Exception $e) {
                    echo "    ✗ Email error: " . $e->getMessage() . "\n";
                    error_log("[CRON_ASSIGNMENT_EXPIRATION] Email error for assignment #" . $assignment['id'] . ": " . $e->getMessage());
                    $errors++;
                    $emailSent = false;
                }

                // ============================================
                // UPDATE WARNING FLAG
                // ============================================

                if ($emailSent) {
                    try {
                        $db->beginTransaction();

                        // Update the expiration_warning_sent flag
                        $updated = $db->update(
                            'file_assignments',
                            [
                                'expiration_warning_sent' => 1,
                                'expiration_warning_sent_at' => date('Y-m-d H:i:s'),
                                'updated_at' => date('Y-m-d H:i:s')
                            ],
                            ['id' => $assignment['id']]
                        );

                        if ($updated) {
                            $db->commit();
                            echo "    ✓ Warning flag updated\n";
                        } else {
                            $db->rollback();
                            echo "    ✗ Failed to update warning flag\n";
                            $errors++;
                        }
                    } catch (Exception $e) {
                        if ($db->inTransaction()) {
                            $db->rollback();
                        }
                        echo "    ✗ Database error: " . $e->getMessage() . "\n";
                        error_log("[CRON_ASSIGNMENT_EXPIRATION] DB error for assignment #" . $assignment['id'] . ": " . $e->getMessage());
                        $errors++;
                    }
                }

                // ============================================
                // AUDIT LOG
                // ============================================

                if ($emailSent) {
                    try {
                        AuditLogger::logGeneric(
                            0,  // System user
                            $assignment['tenant_id'],
                            'expiration_warning',
                            'file_assignment',
                            $assignment['id'],
                            sprintf(
                                'Inviato avviso scadenza per %s "%s" (assegnato a %s)',
                                $itemType,
                                $itemName,
                                $assignment['assignee_name']
                            ),
                            [
                                'assignment_id' => $assignment['id'],
                                'item_type' => $itemType,
                                'item_name' => $itemName,
                                'assignee' => $assignment['assignee_name'],
                                'assigner' => $assignment['assigner_name'],
                                'expires_at' => $assignment['expires_at'],
                                'emails_sent_to' => [
                                    $assignment['assignee_email'],
                                    $assignment['assigner_email']
                                ]
                            ]
                        );
                    } catch (Exception $e) {
                        error_log("[CRON_ASSIGNMENT_EXPIRATION] Audit log failed: " . $e->getMessage());
                        // Non-blocking
                    }
                }

                // Small delay to avoid overwhelming email server
                usleep(500000);  // 0.5 seconds

            } catch (Exception $e) {
                echo "    ✗ Error processing assignment: " . $e->getMessage() . "\n";
                error_log("[CRON_ASSIGNMENT_EXPIRATION] Error processing assignment #" . $assignment['id'] . ": " . $e->getMessage());
                $errors++;
            }
        }

        $offset += BATCH_SIZE;

    } while (count($assignments) == BATCH_SIZE);

    // ============================================
    // SUMMARY
    // ============================================

    $executionTime = round(microtime(true) - $startTime, 2);

    echo "\n";
    echo "========================================\n";
    echo "ASSIGNMENT EXPIRATION CHECK COMPLETE\n";
    echo "========================================\n";
    echo "Processed: $processedCount assignments\n";
    echo "Emails sent: $emailsSent\n";
    echo "Errors: $errors\n";
    echo "Execution time: {$executionTime}s\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

    // Log summary to system log
    $logMessage = sprintf(
        "[CRON_ASSIGNMENT_EXPIRATION] Completed - Processed: %d, Emails: %d, Errors: %d, Time: %.2fs",
        $processedCount,
        $emailsSent,
        $errors,
        $executionTime
    );

    error_log($logMessage);
    syslog(LOG_INFO, $logMessage);

    // Exit with appropriate code
    exit($errors > 0 ? 1 : 0);

} catch (Exception $e) {
    $errorMessage = "[CRON_ASSIGNMENT_EXPIRATION] Fatal error: " . $e->getMessage();
    echo "✗ FATAL ERROR: " . $e->getMessage() . "\n";
    error_log($errorMessage);
    syslog(LOG_ERR, $errorMessage);
    exit(2);
}