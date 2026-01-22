<?php
/**
 * Dashboard API - Get Upcoming Events
 *
 * Returns the 10 most upcoming calendar events
 * with formatted dates and days until event
 *
 * Method: GET
 * Parameters:
 *   - tenant_id (optional, for super_admin only)
 *   - limit (optional, default: 10, max: 50)
 * Response: Array of upcoming events
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
        $tenantId = $requestedTenantId; // explicit selection
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
    // Default tenant selection:
    // - Always tenant-scoped (avoid cross-tenant "mystery events" on dashboard)
    // - For admin/super_admin, respect CompanyFilter session selection when available
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $sessionTenantId = 0;
    if (isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null && $_SESSION['company_filter_id'] !== '') {
        $sessionTenantId = (int)$_SESSION['company_filter_id'];
    }

    $tenantId = $sessionTenantId > 0 ? $sessionTenantId : (int)($userInfo['tenant_id'] ?? 0);
    if ($tenantId <= 0) {
        api_error('Tenant non valido', 400);
    }

    // Authorization: admin/manager/user must have access to the requested tenant.
    // (super_admin can access all tenants)
    if ($userRole !== 'super_admin') {
        $primaryTenantId = (int)($userInfo['tenant_id'] ?? 0);
        if ($primaryTenantId > 0 && $tenantId !== $primaryTenantId) {
            $accessCheck = $db->fetchOne(
                "SELECT COUNT(*) as cnt
                 FROM user_tenant_access
                 WHERE user_id = ? AND tenant_id = ?
                   AND (deleted_at IS NULL OR deleted_at = '')",
                [$userId, $tenantId]
            );
            if (!$accessCheck || (int)($accessCheck['cnt'] ?? 0) <= 0) {
                api_error('Non hai accesso a questo tenant', 403);
            }
        }
    }
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
 * Calculate days until event
 *
 * @param string $eventDate Event date string
 * @return int Days until event (negative if past)
 */
function calculateDaysUntil(string $eventDate): int {
    $eventTimestamp = strtotime($eventDate);
    $todayTimestamp = strtotime(date('Y-m-d'));

    $diff = $eventTimestamp - $todayTimestamp;
    return (int)floor($diff / 86400);
}

/**
 * Format days until in Italian
 *
 * @param int $daysUntil Days until event
 * @return string Formatted text
 */
function formatDaysUntilLabel(int $daysUntil): string {
    if ($daysUntil < 0) {
        $absDays = abs($daysUntil);
        return $absDays . ' ' . ($absDays == 1 ? 'giorno fa' : 'giorni fa');
    } elseif ($daysUntil === 0) {
        return 'Oggi';
    } elseif ($daysUntil === 1) {
        return 'Domani';
    } else {
        return 'Tra ' . $daysUntil . ' giorni';
    }
}

/**
 * Format event time in Italian
 *
 * @param string $startDate Event start date
 * @param string|null $endDate Event end date
 * @param bool $allDay Is all-day event
 * @return string Formatted time string
 */
function formatEventTime(string $startDate, ?string $endDate, bool $allDay): string {
    if ($allDay) {
        return 'Tutto il giorno';
    }

    $startTime = date('H:i', strtotime($startDate));

    if ($endDate && $endDate !== $startDate) {
        $endTime = date('H:i', strtotime($endDate));
        return $startTime . ' - ' . $endTime;
    }

    return $startTime;
}

/**
 * Get event icon class based on type or title
 *
 * @param string $title Event title
 * @return string Icon class for frontend
 */
function getEventIcon(string $title): string {
    $titleLower = mb_strtolower($title);

    if (str_contains($titleLower, 'riunione') || str_contains($titleLower, 'meeting')) {
        return 'fas fa-users';
    } elseif (str_contains($titleLower, 'deadline') || str_contains($titleLower, 'scadenza')) {
        return 'fas fa-calendar-check';
    } elseif (str_contains($titleLower, 'workshop') || str_contains($titleLower, 'formazione')) {
        return 'fas fa-chalkboard-teacher';
    } elseif (str_contains($titleLower, 'presentazione')) {
        return 'fas fa-presentation';
    } elseif (str_contains($titleLower, 'chiamata') || str_contains($titleLower, 'call')) {
        return 'fas fa-phone';
    } else {
        return 'fas fa-calendar-alt';
    }
}

/**
 * Get event badge class based on urgency
 *
 * @param int $daysUntil Days until event
 * @return string Badge class for frontend
 */
function getEventBadgeClass(int $daysUntil): string {
    if ($daysUntil < 0) {
        return 'badge-secondary';  // Past event
    } elseif ($daysUntil === 0) {
        return 'badge-danger';  // Today
    } elseif ($daysUntil <= 2) {
        return 'badge-warning';  // Within 2 days
    } else {
        return 'badge-info';  // Future event
    }
}

// ============================================
// FETCH UPCOMING EVENTS
// ============================================

try {
    // Start defensive transaction management (BUG-070 Phase 3 pattern)
    if ($db->inTransaction()) {
        error_log('[DASHBOARD API] Warning: Transaction already active at start');
        $db->rollback();
    }

    // Query upcoming events with organizer information (events table renamed from calendar_events - BUG-105B)
    $upcomingEventsSql = "
        SELECT
            e.id,
            e.title,
            e.description,
            e.start_datetime,
            e.end_datetime,
            e.all_day,
            e.created_at,
            e.tenant_id,
            t.name AS tenant_name,
            u.name as organizer_name,
            u.avatar as organizer_avatar
        FROM events e
        LEFT JOIN users u ON e.organizer_id = u.id
        LEFT JOIN tenants t ON e.tenant_id = t.id
        WHERE e.deleted_at IS NULL
          AND e.start_datetime >= NOW()
        /**TENANT_FILTER**/
        ORDER BY e.start_datetime ASC
        LIMIT ?
    ";

    $params = [];
    $tenantFilter = '';
    if ($tenantId !== null) {
        $tenantFilter = "AND e.tenant_id = ?";
        $params[] = $tenantId;
    }
    $upcomingEventsSql = str_replace('/**TENANT_FILTER**/', $tenantFilter, $upcomingEventsSql);
    $params[] = $limit;

    $events = $db->fetchAll($upcomingEventsSql, $params);

    // Format events for frontend
    $formattedEvents = [];
    foreach ($events as $event) {
        $daysUntil = calculateDaysUntil($event['start_datetime']);

        $formattedEvents[] = [
            'id' => (int)$event['id'],
            'title' => $event['title'],
            'description' => $event['description'] ?? '',
            'start_date' => $event['start_datetime'],
            'end_date' => $event['end_datetime'] ?? null,
            'all_day' => (bool)$event['all_day'],
            'event_time' => formatEventTime($event['start_datetime'], $event['end_datetime'], (bool)$event['all_day']),
            'days_until' => $daysUntil,
            'days_until_label' => formatDaysUntilLabel($daysUntil),
            'event_badge_class' => getEventBadgeClass($daysUntil),
            'event_icon' => getEventIcon($event['title']),
            'created_by' => $event['organizer_name'] ?? 'N/A',
            'created_by_avatar' => $event['organizer_avatar'] ?? null,
            'formatted_date' => date('d/m/Y', strtotime($event['start_datetime'])),
            'formatted_datetime' => date('d/m/Y H:i', strtotime($event['start_datetime'])),
            'tenant_name' => $event['tenant_name'] ?? null
        ];
    }

    // Metadata
    $metadata = [
        'total_events' => count($formattedEvents),
        'limit' => $limit,
        'tenant_id' => $tenantId,
        'generated_at' => date('Y-m-d H:i:s')
    ];

    // API Response with named key (BUG-066 pattern)
    api_success([
        'events' => $formattedEvents,
        'metadata' => $metadata
    ], 'Eventi prossimi caricati con successo');

} catch (Exception $e) {
    error_log('[DASHBOARD API] Error loading upcoming events: ' . $e->getMessage());

    // Defensive rollback if transaction is active
    if ($db->inTransaction()) {
        $db->rollback();
    }

    api_error('Errore durante il caricamento degli eventi prossimi', 500);
}
