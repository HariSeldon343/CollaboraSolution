<?php
/**
 * Ticket Delete API Endpoint
 * POST /api/tickets/delete.php
 *
 * Soft deletes a ticket (ONLY closed tickets can be deleted)
 * RBAC: ONLY super_admin can delete tickets
 *
 * Input JSON:
 * {
 *   "ticket_id": 123
 * }
 *
 * SECURITY:
 * - Only super_admin role
 * - Only closed tickets can be deleted
 * - Soft delete (SET deleted_at = NOW())
 * - Logged to /logs/ticket_deletions.log
 * - Audit trail in ticket_history
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';

// Initialize API environment
initializeApiEnvironment();

// IMMEDIATELY verify authentication (CRITICAL - BUG-011 compliance)
verifyApiAuthentication();

// Verify CSRF token for POST request
verifyApiCsrfToken();

// Get user context
$userInfo = getApiUserInfo();
$db = Database::getInstance();

try {
    // Get POST data
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        api_error('Invalid JSON data', 400);
    }

    // Validate required fields
    if (empty($data['ticket_id'])) {
        api_error('ID ticket obbligatorio', 400);
    }

    // Sanitize input
    $ticketId = (int)$data['ticket_id'];

    // ========================================
    // RBAC: ONLY super_admin can delete tickets
    // ========================================
    if ($userInfo['role'] !== 'super_admin') {
        api_error('Solo i super_admin possono eliminare i ticket', 403);
    }

    // ========================================
    // Fetch ticket (super_admin can delete ANY ticket across all tenants)
    // ========================================
    $ticket = $db->fetchOne(
        "SELECT t.*,
                ten.name as tenant_name,
                u_creator.email as creator_email
         FROM tickets t
         LEFT JOIN tenants ten ON t.tenant_id = ten.id
         LEFT JOIN users u_creator ON t.created_by = u_creator.id
         WHERE t.id = ? AND t.deleted_at IS NULL",
        [$ticketId]
    );

    if (!$ticket) {
        api_error('Ticket non trovato o già eliminato', 404);
    }

    // ========================================
    // PRECONDITION: Ticket MUST be in 'closed' status
    // ========================================
    if ($ticket['status'] !== 'closed') {
        api_error(
            'Solo i ticket chiusi possono essere eliminati. Stato attuale: ' . $ticket['status'],
            400,
            [
                'current_status' => $ticket['status'],
                'required_status' => 'closed',
                'current_status_label' => getStatusLabel($ticket['status'])
            ]
        );
    }

    // Start transaction
    $db->beginTransaction();

    try {
        $deletedAt = date('Y-m-d H:i:s');

        // Soft delete ticket
        $updated = $db->update('tickets',
            ['deleted_at' => $deletedAt],
            ['id' => $ticketId]
        );

        if (!$updated) {
            throw new Exception('Errore durante l\'eliminazione del ticket');
        }

        // Log to ticket_history (audit trail)
        $db->insert('ticket_history', [
            'tenant_id' => $ticket['tenant_id'],
            'ticket_id' => $ticketId,
            'user_id' => $userInfo['user_id'],
            'action' => 'ticket_deleted',
            'field_name' => 'deleted_at',
            'old_value' => null,
            'new_value' => $deletedAt,
            'created_at' => $deletedAt
        ]);

        // Commit transaction
        $db->commit();

        // ========================================
        // LOG TO DEDICATED FILE: /logs/ticket_deletions.log
        // ========================================
        $logDir = __DIR__ . '/../../logs';
        $logFile = $logDir . '/ticket_deletions.log';

        // Ensure logs directory exists
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Prepare log entry
        $logEntry = sprintf(
            "[%s] TICKET DELETED - ID: %d | Numero: %s | Tenant: %d (%s) | Deleted By: %s (ID: %d, %s) | Reason: Manual deletion after closure | IP: %s\n",
            date('Y-m-d H:i:s'),
            $ticketId,
            $ticket['ticket_number'],
            $ticket['tenant_id'],
            $ticket['tenant_name'] ?? 'Unknown',
            $userInfo['name'] ?? 'Unknown',
            $userInfo['user_id'],
            $userInfo['email'] ?? 'unknown@example.com',
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        );

        // Append to log file (thread-safe with FILE_APPEND and LOCK_EX)
        $logResult = file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

        if ($logResult === false) {
            error_log("Failed to write ticket deletion log for ticket ID: $ticketId");
        }

        // Check file size and rotate if needed (optional - > 10MB)
        if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
            $rotatedFile = $logFile . '.' . date('Ymd_His');
            rename($logFile, $rotatedFile);
            error_log("Rotated ticket_deletions.log to: $rotatedFile");
        }

        api_success([
            'ticket_id' => $ticketId,
            'ticket_number' => $ticket['ticket_number'],
            'deleted_at' => $deletedAt,
            'deleted_by' => [
                'id' => $userInfo['user_id'],
                'name' => $userInfo['name'],
                'email' => $userInfo['email']
            ]
        ], 'Ticket eliminato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Ticket delete error: " . $e->getMessage());
    api_error('Errore nell\'eliminazione del ticket: ' . $e->getMessage(), 500);
}

/**
 * Get human-readable status label (local helper)
 *
 * @param string $status Status code
 * @return string Localized label
 */
function getStatusLabel($status) {
    $labels = [
        'open' => 'Aperto',
        'in_progress' => 'In Lavorazione',
        'waiting_response' => 'In Attesa di Risposta',
        'resolved' => 'Risolto',
        'closed' => 'Chiuso'
    ];

    return $labels[$status] ?? ucfirst($status);
}
