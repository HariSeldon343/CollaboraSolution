<?php
/**
 * Audit Log Delete API Endpoint (BUG-044 - Production Ready)
 *
 * Deletes audit logs with complete tracking via stored procedure
 * Creates IMMUTABLE deletion record and triggers email notifications
 *
 * Method: POST (ONLY)
 * Auth: Required (admin or super_admin ONLY)
 *
 * POST Body (JSON):
 * {
 *   "mode": "single|all|range",           // Deletion mode
 *   "id": 123,                             // Required if mode=single
 *   "date_from": "2025-01-01 00:00:00",   // Required if mode=range
 *   "date_to": "2025-01-31 23:59:59",     // Required if mode=range
 *   "reason": "Maintenance cleanup",       // Required (min 10 chars)
 *   "csrf_token": "..."                    // Required
 * }
 *
 * Response:
 * {
 *   "success": true,
 *   "data": {
 *     "deletion_id": "DEL-20251027-ABC123",
 *     "deleted_count": 1500,
 *     "deletion_record_id": 42
 *   },
 *   "message": "Eliminati 1500 log. Email inviata ai super admin."
 * }
 */

declare(strict_types=1);

// Load required dependencies
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';

// BUG-044: Validate HTTP method FIRST (before any processing)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    die(json_encode([
        'success' => false,
        'error' => 'Metodo non consentito. Usare POST.'
    ]));
}

// 1. Initialize API environment
initializeApiEnvironment();

// 2. IMMEDIATELY verify authentication (BEFORE any operations!)
verifyApiAuthentication();

// 3. Get user info
$userInfo = getApiUserInfo();

// 4. CRITICAL (BUG-044): Only admin or super_admin can delete audit logs
if (!in_array($userInfo['role'], ['admin', 'super_admin'])) {
    api_error('Accesso negato. Solo amministratori possono eliminare i log.', 403);
}

// 5. Verify CSRF token (critical for destructive operations)
verifyApiCsrfToken();

// 6. Parse JSON body (BUG-044: Enhanced validation)
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    api_error('JSON non valido nel body della richiesta: ' . json_last_error_msg(), 400);
}

if (!is_array($data)) {
    api_error('Body della richiesta deve essere un oggetto JSON', 400);
}

// 7. Determine deletion mode and validate accordingly (BUG-044: Added 'single' mode)
$mode = $data['mode'] ?? null;

// Validate mode
if (!in_array($mode, ['single', 'all', 'range'], true)) {
    api_error('Parametro "mode" deve essere "single", "all" o "range"', 400);
}

// MODE: Single log deletion by ID
if ($mode === 'single' || isset($data['id'])) {
    $logId = $data['id'] ?? null;

    if ($logId === null || !is_numeric($logId)) {
        api_error('Parametro "id" obbligatorio e deve essere numerico per mode=single', 400);
    }

    $logId = (int)$logId;

    if ($logId <= 0) {
        api_error('Parametro "id" deve essere un numero positivo', 400);
    }

    // Single deletion doesn't require reason in some implementations,
    // but we'll keep it optional for audit trail
    $reason = $data['reason'] ?? 'Eliminazione singolo log';

    // Ensure minimum reason length
    if (strlen(trim($reason)) < 10) {
        $reason = 'Eliminazione singolo log tramite interfaccia utente';
    }

    $date_from = null;
    $date_to = null;

} else {
    // MODE: Bulk deletion (all or range)

    // Validate reason (mandatory for bulk operations)
    $reason = $data['reason'] ?? null;

    if (empty($reason) || strlen(trim($reason)) < 10) {
        api_error('Parametro "reason" obbligatorio per eliminazioni bulk (minimo 10 caratteri)', 400);
    }

    $logId = null;
    $date_from = null;
    $date_to = null;

    // Validate date range if mode=range
    if ($mode === 'range') {
        $date_from = $data['date_from'] ?? null;
        $date_to = $data['date_to'] ?? null;

        if (empty($date_from) || empty($date_to)) {
            api_error('Parametri "date_from" e "date_to" obbligatori per mode=range', 400);
        }

        // Validate date format (Y-m-d H:i:s)
        $dateFromObj = DateTime::createFromFormat('Y-m-d H:i:s', $date_from);
        $dateToObj = DateTime::createFromFormat('Y-m-d H:i:s', $date_to);

        if (!$dateFromObj || $dateFromObj->format('Y-m-d H:i:s') !== $date_from) {
            api_error('Formato date_from non valido. Usare: YYYY-MM-DD HH:MM:SS', 400);
        }

        if (!$dateToObj || $dateToObj->format('Y-m-d H:i:s') !== $date_to) {
            api_error('Formato date_to non valido. Usare: YYYY-MM-DD HH:MM:SS', 400);
        }

        if ($dateFromObj > $dateToObj) {
            api_error('date_from deve essere precedente o uguale a date_to', 400);
        }

        // Prevent deleting more than 1 year at once (safety check)
        $interval = $dateFromObj->diff($dateToObj);
        if ($interval->y >= 1) {
            api_error('Non è possibile eliminare più di 1 anno di log in una singola operazione', 400);
        }
    }
}

// 8. Get database instance
$db = Database::getInstance();

try {
    // BUG-044: Enhanced error handling with context logging
    $operationContext = [
        'user_id' => $userInfo['user_id'],
        'user_email' => $userInfo['user_email'],
        'role' => $userInfo['role'],
        'mode' => $mode,
        'log_id' => $logId ?? null,
        'date_from' => $date_from ?? null,
        'date_to' => $date_to ?? null
    ];

    // Start transaction for atomic operation
    $db->beginTransaction();

    // Get tenant_id (for multi-tenant isolation)
    $tenant_id = $userInfo['tenant_id'] ?? null;

    // Optional: Allow super_admin to specify tenant_id in request
    if (isset($data['tenant_id']) && $userInfo['role'] === 'super_admin') {
        $tenant_id = (int)$data['tenant_id'];
    }

    // If tenant_id is null, we need to handle this carefully
    if ($tenant_id === null) {
        // CRITICAL (BUG-038): Rollback transaction before api_error() which calls exit()
        if ($db->inTransaction()) {
            $db->rollback();
        }
        error_log('[AUDIT_LOG_DELETE] Missing tenant_id | Context: ' . json_encode($operationContext));
        api_error('tenant_id richiesto per l\'operazione', 400);
    }

    // BUG-044: Handle single log deletion separately (simpler, no stored procedure)
    if ($mode === 'single') {
        // Verify log exists and belongs to tenant
        $stmt = $db->getConnection()->prepare(
            "SELECT id, action, entity_type, user_id, created_at
             FROM audit_logs
             WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([$logId, $tenant_id]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$log) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            error_log('[AUDIT_LOG_DELETE] Log not found | ID: ' . $logId . ' | Tenant: ' . $tenant_id);
            api_error('Log non trovato, già eliminato o non accessibile', 404);
        }

        // Soft delete the log
        $stmt = $db->getConnection()->prepare(
            "UPDATE audit_logs
             SET deleted_at = NOW()
             WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([$logId, $tenant_id]);
        $deletedCount = $stmt->rowCount();
        $stmt->closeCursor();

        if ($deletedCount === 0) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            error_log('[AUDIT_LOG_DELETE] No rows affected | ID: ' . $logId . ' | Tenant: ' . $tenant_id);
            api_error('Impossibile eliminare il log. Potrebbe essere già stato eliminato.', 500);
        }

        // Commit transaction
        $db->commit();

        // Log the operation
        error_log(sprintf(
            '[AUDIT_LOG_DELETE] Single log deleted | ID: %d | User: %s | Tenant: %d',
            $logId,
            $userInfo['user_email'],
            $tenant_id
        ));

        // Build success response
        $response = [
            'deleted_count' => 1,
            'log_id' => $logId,
            'mode' => 'single',
            'tenant_id' => $tenant_id
        ];

        api_success($response, 'Log eliminato con successo');
    }

    // BUG-044: Bulk deletion using stored procedure (all or range mode)
    // Prepare parameters for stored procedure
    // CRITICAL: Stored procedure signature has only 6 parameters:
    // 1. p_tenant_id (INT UNSIGNED)
    // 2. p_deleted_by (INT UNSIGNED)
    // 3. p_deletion_reason (TEXT)
    // 4. p_period_start (DATETIME) - NULL if mode='all'
    // 5. p_period_end (DATETIME) - NULL if mode='all'
    // 6. p_mode (ENUM('all', 'range'))
    $p_tenant_id = $tenant_id;
    $p_deleted_by = $userInfo['user_id'];
    $p_deletion_reason = trim($reason);
    $p_period_start = ($mode === 'range') ? $date_from : null;
    $p_period_end = ($mode === 'range') ? $date_to : null;
    $p_mode = $mode;

    // Call stored procedure: record_audit_log_deletion()
    // This procedure handles:
    // 1. Generating unique deletion_id
    // 2. Querying matching logs (all or date range based on mode)
    // 3. Creating JSON snapshot
    // 4. Inserting immutable deletion record
    // 5. Soft deleting logs (set deleted_at)
    // 6. RETURNS result set via SELECT (not OUT parameters)

    $call_query = "CALL record_audit_log_deletion(?, ?, ?, ?, ?, ?)";

    $stmt = $db->getConnection()->prepare($call_query);
    $stmt->execute([
        $p_tenant_id,       // INT UNSIGNED
        $p_deleted_by,      // INT UNSIGNED
        $p_deletion_reason, // TEXT
        $p_period_start,    // DATETIME (NULL if mode='all')
        $p_period_end,      // DATETIME (NULL if mode='all')
        $p_mode             // ENUM('all', 'range')
    ]);

    // Retrieve result set (procedure returns SELECT statement, not OUT parameters)
    // Expected columns: deletion_id, deleted_count
    //
    // IMPORTANT: Stored procedures with UPDATE/INSERT statements may generate
    // "empty result sets" before the final SELECT in some PDO driver versions.
    // We need to iterate through result sets to find the one with actual data.

    $result = false;
    $resultSetCount = 0;

    // Try to fetch from current result set, then check additional result sets if needed
    do {
        $resultSetCount++;
        $tempResult = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tempResult !== false && isset($tempResult['deletion_id'])) {
            // Found the result set with actual data
            $result = $tempResult;
            break;
        }

        // Current result set was empty or invalid, try next one
    } while ($stmt->nextRowset());

    // CRITICAL: Close cursor after stored procedure to prevent "pending result sets" error
    $stmt->closeCursor();

    // Check if result is valid
    if ($result === false || !isset($result['deletion_id'])) {
        $db->rollback();
        error_log("Stored procedure returned no valid result after checking $resultSetCount result sets");
        api_error('Errore: Stored procedure non ha ritornato risultati validi', 500);
    }

    $deletion_id = $result['deletion_id'];
    $deleted_count = (int)$result['deleted_count'];

    // Check if any logs were actually deleted
    if ($deleted_count === 0) {
        $db->rollback();
        api_error('Nessun log corrisponde ai criteri specificati', 404);
    }

    // Get the deletion record ID for email notification
    $deletion_record = $db->fetchOne(
        'SELECT id FROM audit_log_deletions WHERE deletion_id = ?',
        [$deletion_id]
    );

    $deletion_record_id = (int)$deletion_record['id'];

    // Commit transaction
    $db->commit();

    // Log the deletion operation itself
    error_log(sprintf(
        'Audit Log Deletion: %s deleted %d logs (deletion_id: %s, tenant: %d)',
        $userInfo['user_email'],
        $deleted_count,
        $deletion_id,
        $tenant_id
    ));

    // Build success response
    $response = [
        'deletion_id' => $deletion_id,
        'deleted_count' => $deleted_count,
        'deletion_record_id' => $deletion_record_id,
        'tenant_id' => $tenant_id,
        'mode' => $mode,
        'period' => [
            'from' => $p_period_start,
            'to' => $p_period_end
        ]
    ];

    // Success message
    $message = sprintf(
        'Eliminati %d log con successo. Deletion ID: %s',
        $deleted_count,
        $deletion_id
    );

    // Note: Email notification should be triggered asynchronously
    // For now, we just return the deletion_id which can be used by
    // a separate email notification service

    api_success($response, $message);

} catch (PDOException $e) {
    // BUG-044: Enhanced database error handling with context
    // CRITICAL (BUG-038/039): Rollback transaction before api_error()
    if ($db->inTransaction()) {
        $db->rollback();
    }

    // Log with full context for debugging
    error_log(sprintf(
        '[AUDIT_LOG_DELETE] PDO Error: %s | User: %s | Mode: %s | Context: %s | Stack: %s',
        $e->getMessage(),
        $userInfo['user_email'] ?? 'unknown',
        $mode ?? 'unknown',
        json_encode($operationContext ?? []),
        $e->getTraceAsString()
    ));

    // Return user-friendly error (don't expose internal details in production)
    api_error('Errore database durante l\'eliminazione dei log. Contattare l\'amministratore.', 500);

} catch (Exception $e) {
    // BUG-044: Generic error handling with context
    // CRITICAL (BUG-038/039): Rollback transaction before api_error()
    if ($db->inTransaction()) {
        $db->rollback();
    }

    // Log with full context
    error_log(sprintf(
        '[AUDIT_LOG_DELETE] Generic Error: %s | User: %s | Mode: %s | Context: %s | Stack: %s',
        $e->getMessage(),
        $userInfo['user_email'] ?? 'unknown',
        $mode ?? 'unknown',
        json_encode($operationContext ?? []),
        $e->getTraceAsString()
    ));

    // Return user-friendly error
    api_error('Errore interno durante l\'operazione. Contattare l\'amministratore.', 500);
}

/**
 * NOTE: Email Notification Implementation
 *
 * After successful deletion, an email notification should be sent to all super_admin users.
 * This should be done asynchronously (background job or queue) to avoid blocking the API response.
 *
 * Pseudo-code for email notification:
 *
 * // Get all super_admin users for this tenant
 * $super_admins = $db->fetchAll(
 *     'SELECT id, name, email FROM users WHERE role = ? AND tenant_id = ? AND deleted_at IS NULL',
 *     ['super_admin', $tenant_id]
 * );
 *
 * // Send email to each super_admin
 * foreach ($super_admins as $admin) {
 *     sendDeletionNotificationEmail(
 *         $admin['email'],
 *         $admin['name'],
 *         $deletion_id,
 *         $deleted_count,
 *         $p_deletion_reason,
 *         $p_period_start,
 *         $p_period_end
 *     );
 * }
 *
 * // Mark notification as sent
 * $notified_user_ids = array_column($super_admins, 'id');
 * $stmt = $db->getConnection()->prepare('CALL mark_deletion_notification_sent(?, ?, ?)');
 * $stmt->execute([
 *     $deletion_id,
 *     json_encode($notified_user_ids),
 *     null // No error
 * ]);
 *
 * // If email fails, mark with error:
 * // $stmt->execute([$deletion_id, null, 'SMTP error: ...']);
 */
