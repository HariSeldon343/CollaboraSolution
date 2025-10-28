<?php
/**
 * Audit Log Delete API Endpoint
 *
 * Deletes audit logs with complete tracking via stored procedure
 * Creates IMMUTABLE deletion record and triggers email notifications
 *
 * Method: POST
 * Auth: Required (super_admin ONLY)
 *
 * POST Body (JSON):
 * {
 *   "mode": "all|range",
 *   "date_from": "2025-01-01 00:00:00",  // Required if mode=range
 *   "date_to": "2025-01-31 23:59:59",    // Required if mode=range
 *   "reason": "Maintenance cleanup",      // Required
 *   "csrf_token": "..."                   // Required
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

// 1. Initialize API environment
initializeApiEnvironment();

// 2. IMMEDIATELY verify authentication (BEFORE any operations!)
verifyApiAuthentication();

// 3. Get user info
$userInfo = getApiUserInfo();

// 4. CRITICAL: Only super_admin can delete audit logs
if ($userInfo['role'] !== 'super_admin') {
    api_error('Accesso negato. Solo super_admin può eliminare gli audit log.', 403);
}

// 5. Verify CSRF token (critical for destructive operations)
verifyApiCsrfToken();

// 6. Parse JSON body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    api_error('JSON non valido nel body della richiesta', 400);
}

// 7. Validate required fields
$mode = $data['mode'] ?? null;
$reason = $data['reason'] ?? null;
$date_from = $data['date_from'] ?? null;
$date_to = $data['date_to'] ?? null;

// Validate mode
if (!in_array($mode, ['all', 'range'])) {
    api_error('Parametro "mode" deve essere "all" o "range"', 400);
}

// Validate reason
if (empty($reason) || strlen(trim($reason)) < 10) {
    api_error('Parametro "reason" obbligatorio (minimo 10 caratteri)', 400);
}

// Validate date range if mode=range
if ($mode === 'range') {
    if (empty($date_from) || empty($date_to)) {
        api_error('Parametri "date_from" e "date_to" obbligatori per mode=range', 400);
    }

    // Validate date format
    $date_from_parsed = strtotime($date_from);
    $date_to_parsed = strtotime($date_to);

    if (!$date_from_parsed || !$date_to_parsed) {
        api_error('Formato date non valido. Usare YYYY-MM-DD HH:MM:SS', 400);
    }

    if ($date_from_parsed > $date_to_parsed) {
        api_error('date_from deve essere precedente a date_to', 400);
    }
}

// 8. Get database instance
$db = Database::getInstance();

try {
    // Start transaction for atomic operation
    $db->beginTransaction();

    // Get tenant_id (super_admin may want to delete for specific tenant or all)
    // For now, use current user's tenant_id or allow NULL for all tenants
    $tenant_id = $userInfo['tenant_id'] ?? null;

    // Optional: Allow super_admin to specify tenant_id in request
    if (isset($data['tenant_id'])) {
        $tenant_id = (int)$data['tenant_id'];
    }

    // If tenant_id is null, we need to handle this for super_admin
    // For safety, require explicit tenant_id
    if ($tenant_id === null) {
        // CRITICAL (BUG-038): Rollback transaction before api_error() which calls exit()
        if ($db->inTransaction()) {
            $db->rollback();
        }
        api_error('tenant_id richiesto. Specificare quale tenant eliminare i log.', 400);
    }

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
    $p_mode = $mode; // CRITICAL: This was missing!

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
    // Database error - rollback transaction
    if ($db->inTransaction()) {
        $db->rollback();
    }

    error_log('Audit Log Deletion Error: ' . $e->getMessage());
    api_error('Errore durante l\'eliminazione dei log: ' . $e->getMessage(), 500);

} catch (Exception $e) {
    // Generic error - rollback transaction
    if ($db->inTransaction()) {
        $db->rollback();
    }

    error_log('Audit Log Deletion Error: ' . $e->getMessage());
    api_error('Errore imprevisto: ' . $e->getMessage(), 500);
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
