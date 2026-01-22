<?php
/**
 * Calendar API - Get Calendars List
 *
 * Returns list of calendars for current tenant with event counts.
 * Auto-creates default calendar if none exist.
 *
 * Method: GET
 * Parameters:
 *   - id (optional): Specific calendar ID to fetch
 * Response: Calendar object or list of calendars
 *
 * @package CollaboraNexio
 * @subpackage Calendar API
 * @version 1.0.0
 * @since 2025-11-17
 */

declare(strict_types=1);

// BUG-104 FIX: Use api_auth.php pattern (CLAUDE.md compliance)
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../includes/db.php';

initializeApiEnvironment();

// Force no-cache headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

verifyApiAuthentication();

$userInfo = getApiUserInfo();
$tenantId = (int) $userInfo['tenant_id'];
$userId = (int) $userInfo['user_id'];

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    ensureDefaultCalendar($pdo, $tenantId, $userId, $userInfo['name'] ?? 'Calendario');

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGetCalendars($pdo, $tenantId);
            break;

        default:
            api_error('Metodo non supportato', 405);
    }
} catch (Throwable $e) {
    error_log('[Calendar API] ' . $e->getMessage());
    api_error('Errore del server', 500);
}

function handleGetCalendars(PDO $pdo, int $tenantId): void
{
    global $userId; // BUG-120 FIX: Need user_id to filter by permissions
    $userInfo = getApiUserInfo();
    $currentUserId = (int) $userInfo['user_id'];
    $userRole = $userInfo['role'] ?? 'user'; // BUG-121 FIX: Get user role

    $calendarId = isset($_GET['id']) ? (int) $_GET['id'] : null;

    // BUG-121 FIX: Super admin sees ALL tenant calendars
    // BUG-120 FIX: Return calendars user has access to:
    // 1. Calendars owned by user (owner_id = user_id)
    // 2. Public calendars in tenant (visibility = 'public')
    // 3. Shared calendars with explicit permissions (via calendar_permissions)
    $sql = "
        SELECT
            c.id,
            c.name,
            c.description,
            c.color,
            c.owner_id,
            c.tenant_id,
            t.name AS tenant_name,
            u.name AS owner_name,
            c.visibility,
            c.is_default,
            c.created_at,
            c.updated_at,
            COALESCE(ev.total_events, 0) AS events_count,
            CASE
                WHEN c.owner_id = :user_id THEN 'owner'
                WHEN cp.permission_level IS NOT NULL THEN cp.permission_level
                WHEN c.visibility = 'public' THEN 'read'
                ELSE NULL
            END AS user_permission
        FROM calendars c
        LEFT JOIN tenants t ON c.tenant_id = t.id
        LEFT JOIN users u ON c.owner_id = u.id
        LEFT JOIN (
            SELECT calendar_id, COUNT(*) AS total_events
            FROM events
            WHERE (deleted_at IS NULL)
            " . ($userRole === 'super_admin' ? '' : 'AND tenant_id = :tenant_id_sub') . "
            GROUP BY calendar_id
        ) ev ON ev.calendar_id = c.id
        LEFT JOIN calendar_permissions cp ON cp.calendar_id = c.id
            AND cp.user_id = :user_id_perm
            AND cp.deleted_at IS NULL
    ";

    // BUG-122 FIX: Build WHERE clause based on role with correct personal calendar filtering
    if ($userRole === 'super_admin') {
        // Super admin sees:
        // 1. Own personal calendars (owner_id = current_user_id)
        // 2. ALL public/shared calendars from ANY tenant (cross-tenant access)
        // 3. NOT other users' personal calendars
        $sql .= " WHERE c.deleted_at IS NULL
          AND (
              c.owner_id = :user_id_owner                    -- Own personal calendars
              OR c.visibility IN ('public', 'shared')        -- BUG-123: All tenants (cross-tenant)
          )";
    } else {
        // Regular users: only their tenant + permission filters
        $sql .= " WHERE c.tenant_id = :tenant_id
          AND c.deleted_at IS NULL
          AND (
              c.owner_id = :user_id_owner                    -- User owns calendar
              OR c.visibility = 'public'                     -- Public calendar
              OR cp.calendar_id IS NOT NULL                  -- Shared via permissions
          )";
    }

    $params = [
        ':user_id' => $currentUserId,
        ':user_id_perm' => $currentUserId,
        ':user_id_owner' => $currentUserId,  // BUG-122 FIX: Always needed for owner filter
    ];

    // BUG-121 FIX: Add tenant_id parameters only for non-super_admin users
    if ($userRole !== 'super_admin') {
        $params[':tenant_id'] = $tenantId;
        $params[':tenant_id_sub'] = $tenantId;
    }

    if ($calendarId) {
        $sql .= " AND c.id = :calendar_id";
        $params[':calendar_id'] = $calendarId;
    }

    $sql .= " ORDER BY c.is_default DESC, c.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($calendarId && empty($rows)) {
        api_error('Calendario non trovato', 404);
    }

    $calendars = array_map(static function (array $calendar): array {
        return [
            'id' => (int) $calendar['id'],
            'name' => $calendar['name'],
            'description' => $calendar['description'] ?? '',
            'color' => $calendar['color'] ?? '#3B82F6',
            'owner' => [
                'id' => (int) $calendar['owner_id'],
                'name' => $calendar['owner_name'] ?? null,
            ],
            'tenant' => [ // BUG-121 FIX: Include tenant info for super_admin display
                'id' => (int) $calendar['tenant_id'],
                'name' => $calendar['tenant_name'] ?? null,
            ],
            'visibility' => $calendar['visibility'],
            'is_default' => (bool) $calendar['is_default'],
            'events_count' => (int) $calendar['events_count'],
            'user_permission' => $calendar['user_permission'] ?? 'read', // BUG-120 FIX: Include permission level
            'created_at' => $calendar['created_at'],
            'updated_at' => $calendar['updated_at'],
        ];
    }, $rows);

    // BUG-104 FIX: Use api_success() for consistent response format
    if ($calendarId) {
        api_success(['calendar' => $calendars[0]], 'Calendario caricato con successo');
    } else {
        api_success([
            'calendars' => $calendars,
            'total' => count($calendars),
        ], 'Calendari caricati con successo');
    }
}

function ensureDefaultCalendar(PDO $pdo, int $tenantId, int $userId, string $userName): void
{
    // BUG-127 FIX: Check for ACTIVE calendars only (exclude soft-deleted)
    $stmt = $pdo->prepare('SELECT id FROM calendars WHERE tenant_id = :tenant_id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':tenant_id' => $tenantId]);

    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return;
    }

    $insert = $pdo->prepare("
        INSERT INTO calendars (tenant_id, name, description, color, owner_id, is_default, visibility)
        VALUES (:tenant_id, :name, :description, :color, :owner_id, 1, 'private')
    ");

    $insert->execute([
        ':tenant_id' => $tenantId,
        ':name' => $userName . ' - Calendario',
        ':description' => 'Calendario personale generato automaticamente',
        ':color' => '#3B82F6',
        ':owner_id' => $userId,
    ]);
}

