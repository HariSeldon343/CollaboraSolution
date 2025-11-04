<?php
/**
 * Ticket Create API Endpoint
 * POST /api/tickets/create.php
 *
 * Creates a new support ticket with automatic ticket number generation
 * Sends email notification to super_admin users
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
    if (empty($data['subject'])) {
        api_error('Il soggetto è obbligatorio', 400);
    }

    if (empty($data['description'])) {
        api_error('La descrizione è obbligatoria', 400);
    }

    if (empty($data['category'])) {
        api_error('La categoria è obbligatoria', 400);
    }

    if (empty($data['urgency'])) {
        api_error('L\'urgenza è obbligatoria', 400);
    }

    // Sanitize and validate input
    $subject = trim($data['subject']);
    if (strlen($subject) > 500) {
        api_error('Il soggetto non può superare 500 caratteri', 400);
    }

    $description = trim($data['description']);
    $category = $data['category'];
    $urgency = $data['urgency'];
    $attachments = !empty($data['attachments']) ? json_encode($data['attachments']) : null;

    // Validate category
    $validCategories = ['technical', 'billing', 'feature_request', 'bug_report', 'general'];
    if (!in_array($category, $validCategories)) {
        api_error('Categoria non valida', 400);
    }

    // Validate urgency
    $validUrgencies = ['low', 'normal', 'high', 'critical'];
    if (!in_array($urgency, $validUrgencies)) {
        api_error('Urgenza non valida', 400);
    }

    // Start transaction
    $db->beginTransaction();

    try {
        // Generate unique ticket number: TICK-YYYY-NNNN
        $year = date('Y');
        $lastTicketSql = "SELECT MAX(CAST(SUBSTRING(ticket_number, 11) AS UNSIGNED)) as last_num
                          FROM tickets
                          WHERE tenant_id = ?
                          AND ticket_number LIKE ?
                          AND deleted_at IS NULL";
        $lastTicketResult = $db->fetchOne($lastTicketSql, [$userInfo['tenant_id'], "TICK-$year-%"]);
        $lastNum = $lastTicketResult['last_num'] ?? 0;
        $newNum = str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
        $ticketNumber = "TICK-$year-$newNum";

        // Insert ticket
        $ticketId = $db->insert('tickets', [
            'tenant_id' => $userInfo['tenant_id'],
            'ticket_number' => $ticketNumber,
            'subject' => $subject,
            'description' => $description,
            'category' => $category,
            'urgency' => $urgency,
            'status' => 'open',
            'created_by' => $userInfo['user_id'],
            'attachments' => $attachments,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if (!$ticketId) {
            throw new Exception('Errore durante la creazione del ticket');
        }

        // Log to ticket history
        $db->insert('ticket_history', [
            'tenant_id' => $userInfo['tenant_id'],
            'ticket_id' => $ticketId,
            'user_id' => $userInfo['user_id'],
            'action' => 'created',
            'field_name' => null,
            'old_value' => null,
            'new_value' => json_encode([
                'ticket_number' => $ticketNumber,
                'subject' => $subject,
                'category' => $category,
                'urgency' => $urgency
            ]),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Commit transaction
        $db->commit();

        // BUG-047: Audit log ticket creation (non-blocking)
        try {
            require_once __DIR__ . '/../../includes/audit_helper.php';
            AuditLogger::logCreate(
                $userInfo['user_id'],
                $userInfo['tenant_id'],
                'ticket',
                $ticketId,
                "Creato ticket #$ticketNumber: $subject",
                [
                    'ticket_number' => $ticketNumber,
                    'subject' => $subject,
                    'category' => $category,
                    'urgency' => $urgency,
                    'status' => 'open'
                ]
            );
        } catch (Exception $auditEx) {
            error_log('[TICKET_CREATE] Audit log failed: ' . $auditEx->getMessage());
        }

        // Fetch the created ticket with related data
        $ticket = $db->fetchOne(
            "SELECT
                t.*,
                u_creator.name as created_by_name,
                u_creator.email as created_by_email
            FROM tickets t
            LEFT JOIN users u_creator ON t.created_by = u_creator.id
            WHERE t.id = ?",
            [$ticketId]
        );

        // ========================================
        // EMAIL NOTIFICATIONS (NON-BLOCKING)
        // ========================================
        try {
            require_once __DIR__ . '/../../includes/ticket_notification_helper.php';
            $notifier = new TicketNotification();

            // Notify super admins about new ticket
            $notifier->sendTicketCreatedNotification($ticketId);

            // Send confirmation email to ticket creator
            $notifier->sendTicketCreatedConfirmation($ticketId);

        } catch (Exception $e) {
            // Log error but don't fail the request
            error_log("Ticket notification error (create): " . $e->getMessage());
        }

        api_success([
            'ticket' => $ticket,
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber
        ], 'Ticket creato con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Ticket create error: " . $e->getMessage());
    api_error('Errore nella creazione del ticket: ' . $e->getMessage(), 500);
}
