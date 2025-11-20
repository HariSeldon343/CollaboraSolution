<?php
/**
 * Dashboard API - Get Recent Tickets
 *
 * Returns the 10 most recent support tickets
 * with formatted status, priority badges, and creation timestamps
 *
 * Method: GET
 * Parameters:
 *   - tenant_id (optional, for super_admin only)
 *   - limit (optional, default: 10, max: 50)
 * Response: Array of recent tickets
 *
 * @package CollaboraNexio
 * @subpackage Dashboard API
 * @version 1.0.0
 * @since 2025-11-16
 */

declare(strict_types=1);

// API Authentication (BUG-011 pattern)
require_once __DIR__ . '/../../includes/api_auth.php';

initializeApiEnvironment();

// Force no-cache headers (BUG-040)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

verifyApiAuthentication();  // IMMEDIATELY after init

$userInfo = getApiUserInfo();
$userId = $userInfo['user_id'];  // BUG-070: both 'id' and 'user_id' required
$userRole = $userInfo['role'];

// No CSRF required for GET requests (read-only operation)

// Database connection
require_once __DIR__ . '/../../includes/db.php';
$db = Database::getInstance();

// ============================================
// REQUEST VALIDATION
// ============================================

// Only GET method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Metodo non consentito', 405);
}

// Multi-tenant parameter pattern (BUG-066)
$requestedTenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : null;

if ($requestedTenantId !== null) {
    if ($userRole === 'super_admin') {
        $tenantId = $requestedTenantId;
    } else {
        // Validate via user_tenant_access
        $accessCheck = $db->fetchOne(
            "SELECT COUNT(*) as cnt FROM user_tenant_access
             WHERE user_id = ? AND tenant_id = ?
             AND (deleted_at IS NULL OR deleted_at = '')",  // BUG-090: check both NULL and empty string
            [$userId, $requestedTenantId]
        );
        if ($accessCheck && $accessCheck['cnt'] > 0) {
            $tenantId = $requestedTenantId;
        } else {
            api_error('Non hai accesso a questo tenant', 403);
        }
    }
} else {
    $tenantId = $userInfo['tenant_id'];
}

// Limit parameter (optional)
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($limit < 1 || $limit > 50) {
    $limit = 10;
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Format timestamp to relative time in Italian
 *
 * @param string $timestamp Timestamp string
 * @return string Formatted relative time
 */
function formatRelativeTime(string $timestamp): string {
    $time = strtotime($timestamp);
    $diff = time() - $time;

    if ($diff < 60) {
        return 'Adesso';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' ' . ($minutes == 1 ? 'minuto' : 'minuti') . ' fa';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' ' . ($hours == 1 ? 'ora' : 'ore') . ' fa';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' ' . ($days == 1 ? 'giorno' : 'giorni') . ' fa';
    } else {
        // More than a week, show date
        return date('d/m/Y', $time);
    }
}

/**
 * Get status label in Italian
 *
 * @param string $status Ticket status
 * @return string Translated status label
 */
function getStatusLabel(string $status): string {
    $labels = [
        'open' => 'Aperto',
        'in_progress' => 'In Lavorazione',
        'waiting_response' => 'In Attesa',
        'resolved' => 'Risolto',
        'closed' => 'Chiuso'
    ];

    return $labels[$status] ?? ucfirst($status);
}

/**
 * Get status badge class
 *
 * @param string $status Ticket status
 * @return string Badge class for frontend
 */
function getStatusBadgeClass(string $status): string {
    $badges = [
        'open' => 'badge-warning',
        'in_progress' => 'badge-info',
        'waiting_response' => 'badge-secondary',
        'resolved' => 'badge-success',
        'closed' => 'badge-dark'
    ];

    return $badges[$status] ?? 'badge-light';
}

/**
 * Get priority label in Italian
 *
 * @param string $priority Ticket priority
 * @return string Translated priority label
 */
function getPriorityLabel(string $priority): string {
    $labels = [
        'low' => 'Bassa',
        'medium' => 'Media',
        'high' => 'Alta',
        'critical' => 'Critica'
    ];

    return $labels[$priority] ?? ucfirst($priority);
}

/**
 * Get priority badge class
 *
 * @param string $priority Ticket priority
 * @return string Badge class for frontend
 */
function getPriorityBadgeClass(string $priority): string {
    $badges = [
        'low' => 'badge-secondary',
        'medium' => 'badge-info',
        'high' => 'badge-warning',
        'critical' => 'badge-danger'
    ];

    return $badges[$priority] ?? 'badge-light';
}

/**
 * Get priority icon
 *
 * @param string $priority Ticket priority
 * @return string Icon class for frontend
 */
function getPriorityIcon(string $priority): string {
    $icons = [
        'low' => 'fas fa-arrow-down',
        'medium' => 'fas fa-minus',
        'high' => 'fas fa-arrow-up',
        'critical' => 'fas fa-exclamation-triangle'
    ];

    return $icons[$priority] ?? 'fas fa-circle';
}

/**
 * Get ticket category icon
 *
 * @param string $title Ticket title
 * @return string Icon class for frontend
 */
function getTicketIcon(string $title): string {
    $titleLower = mb_strtolower($title);

    if (str_contains($titleLower, 'bug') || str_contains($titleLower, 'errore')) {
        return 'fas fa-bug';
    } elseif (str_contains($titleLower, 'richiesta') || str_contains($titleLower, 'feature')) {
        return 'fas fa-lightbulb';
    } elseif (str_contains($titleLower, 'problema') || str_contains($titleLower, 'issue')) {
        return 'fas fa-exclamation-circle';
    } elseif (str_contains($titleLower, 'supporto') || str_contains($titleLower, 'help')) {
        return 'fas fa-life-ring';
    } else {
        return 'fas fa-ticket-alt';
    }
}

// ============================================
// FETCH RECENT TICKETS
// ============================================

try {
    // Start defensive transaction management (BUG-070 Phase 3 pattern)
    if ($db->inTransaction()) {
        error_log('[DASHBOARD API] Warning: Transaction already active at start');
        $db->rollback();
    }

    // Query recent tickets with creator and assignee information
    $recentTicketsSql = "
        SELECT
            t.id,
            t.subject,
            t.description,
            t.status,
            t.urgency,
            t.created_at,
            u1.name as created_by_name,
            u1.avatar as created_by_avatar,
            u2.name as assigned_to_name,
            u2.avatar as assigned_to_avatar
        FROM tickets t
        LEFT JOIN users u1 ON t.created_by = u1.id
        LEFT JOIN users u2 ON t.assigned_to = u2.id
        WHERE t.tenant_id = ?
          AND (t.deleted_at IS NULL OR t.deleted_at = '')
        ORDER BY t.created_at DESC
        LIMIT ?
    ";

    $tickets = $db->fetchAll($recentTicketsSql, [$tenantId, $limit]);

    // Format tickets for frontend
    // BUG-097 FIX: Frontend expects 'subject' and 'urgency', not 'title' and 'priority'
    $formattedTickets = [];
    foreach ($tickets as $ticket) {
        $formattedTickets[] = [
            'id' => (int)$ticket['id'],
            'subject' => $ticket['subject'], // BUG-097: Changed from 'title' to 'subject'
            'title' => $ticket['subject'],   // Keep backward compatibility
            'description' => $ticket['description'] ?? '',
            'description_excerpt' => mb_substr($ticket['description'] ?? '', 0, 100) . (mb_strlen($ticket['description'] ?? '') > 100 ? '...' : ''),
            'status' => $ticket['status'],
            'status_label' => getStatusLabel($ticket['status']),
            'status_badge' => getStatusBadgeClass($ticket['status']),
            'urgency' => $ticket['urgency'], // BUG-097: Changed from 'priority' to 'urgency'
            'priority' => $ticket['urgency'], // Keep backward compatibility
            'urgency_label' => getPriorityLabel($ticket['urgency']),
            'urgency_badge' => getPriorityBadgeClass($ticket['urgency']),
            'priority_label' => getPriorityLabel($ticket['urgency']), // Keep backward compatibility
            'priority_badge' => getPriorityBadgeClass($ticket['urgency']), // Keep backward compatibility
            'priority_icon' => getPriorityIcon($ticket['urgency']),
            'ticket_icon' => getTicketIcon($ticket['subject']),
            'created_by' => $ticket['created_by_name'] ?? 'N/A',
            'created_by_avatar' => $ticket['created_by_avatar'] ?? null,
            'assigned_to' => $ticket['assigned_to_name'] ?? null,
            'assigned_to_avatar' => $ticket['assigned_to_avatar'] ?? null,
            'is_assigned' => !empty($ticket['assigned_to_name']),
            'created_at' => $ticket['created_at'],
            'created_at_relative' => formatRelativeTime($ticket['created_at']),
            'formatted_date' => date('d/m/Y H:i', strtotime($ticket['created_at']))
        ];
    }

    // Statistics
    $statusStats = [];
    foreach ($tickets as $ticket) {
        $status = $ticket['status'];
        if (!isset($statusStats[$status])) {
            $statusStats[$status] = 0;
        }
        $statusStats[$status]++;
    }

    // Metadata
    $metadata = [
        'total_tickets' => count($formattedTickets),
        'status_breakdown' => $statusStats,
        'limit' => $limit,
        'tenant_id' => $tenantId,
        'generated_at' => date('Y-m-d H:i:s')
    ];

    // API Response with named key (BUG-066 pattern)
    api_success([
        'tickets' => $formattedTickets,
        'metadata' => $metadata
    ], 'Ticket recenti caricati con successo');

} catch (Exception $e) {
    error_log('[DASHBOARD API] Error loading recent tickets: ' . $e->getMessage());

    // Defensive rollback if transaction is active
    if ($db->inTransaction()) {
        $db->rollback();
    }

    api_error('Errore durante il caricamento dei ticket recenti', 500);
}
