<?php
/**
 * RESTful Calendar Events API for CollaboraNexio
 *
 * Endpoint: /api/events.php
 *
 * Core Endpoints:
 * - GET    /api/events.php?start={date}&end={date}  - Retrieve events in date range
 * - GET    /api/events.php?id={id}                  - Retrieve single event
 * - POST   /api/events.php                          - Create new event
 * - PUT    /api/events.php?id={id}                  - Update existing event
 * - DELETE /api/events.php?id={id}                  - Delete event
 *
 * Additional Actions:
 * - POST   /api/events.php?action=respond&id={id}            - Respond to invitation
 * - GET    /api/events.php?action=conflicts                  - Check scheduling conflicts
 * - GET    /api/events.php?action=availability               - Get user availability
 * - POST   /api/events.php?action=duplicate&id={id}          - Duplicate event
 * - GET    /api/events.php?action=export                     - Export events (iCal)
 * - POST   /api/events.php?action=import                     - Import events (iCal)
 * - GET    /api/events.php?action=suggestions                - Get meeting time suggestions
 * - POST   /api/events.php?action=reschedule&id={id}         - Reschedule with conflict detection
 * - GET    /api/events.php?action=available_users&event_id={id} - Get available users for invitation (RBAC filtered)
 * - POST   /api/events.php?action=invite                     - Invite participants to event (RBAC + email)
 * - GET    /api/events.php?action=participants&event_id={id} - Get event participants with RSVP status
 *
 * @version 1.0.0
 * @since PHP 8.0
 */

declare(strict_types=1);

// BUG-104 FIX: Use api_auth.php pattern (CLAUDE.md compliance)
// Migrated from legacy Auth class to standardized API authentication
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/calendar.php';

initializeApiEnvironment();

// Force no-cache headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

verifyApiAuthentication();

$userInfo = getApiUserInfo();
$user_role = (string)($userInfo['role'] ?? 'user');
$tenant_id = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
if ($tenant_id <= 0) {
    $tenant_id = (int)($_SESSION['company_filter_id'] ?? 0);
}
if ($tenant_id <= 0) {
    $tenant_id = (int)($userInfo['tenant_id'] ?? 0);
}

$user_id = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);

// Calendar is tenant-scoped. For super_admin/admin, require a selected tenant via CompanyFilter.
if ($tenant_id <= 0) {
    if (in_array($user_role, ['super_admin', 'admin'], true)) {
        api_error('Seleziona un’azienda per gestire il calendario', 400);
    }
    api_error('Tenant non valido', 400);
}

// Authorization: admin/manager/user must have access to the requested tenant.
// (super_admin can access all tenants)
if ($user_role !== 'super_admin') {
    $primaryTenantId = (int)($userInfo['tenant_id'] ?? 0);
    if ($primaryTenantId > 0 && $tenant_id !== $primaryTenantId) {
        // Check user_tenant_access (deleted_at column may or may not exist)
        $dbTmp = Database::getInstance();
        $utaHasDeletedAt = $dbTmp->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_tenant_access'
               AND COLUMN_NAME = 'deleted_at'
             LIMIT 1"
        );
        $utaWhere = $utaHasDeletedAt ? " AND deleted_at IS NULL" : "";
        $hasAccess = $dbTmp->fetchOne(
            "SELECT 1
             FROM user_tenant_access
             WHERE user_id = ?
               AND tenant_id = ?" . $utaWhere . "
             LIMIT 1",
            [$user_id, $tenant_id]
        );
        if (!$hasAccess) {
            api_error('Accesso negato al tenant richiesto', 403);
        }
    }
}

// Initialize Calendar class
try {
    $db = Database::getInstance()->getConnection();
    $calendar = new Calendar($db, $tenant_id, $user_id);
} catch (Exception $e) {
    error_log('[EVENTS API] Initialization error: ' . $e->getMessage());
    api_error('Server configuration error', 500);
}

/**
 * Get JSON request body
 * BUG-104 FIX: Updated to use api_error()
 */
function getRequestBody(): array {
    $json = file_get_contents('php://input');
    if (!$json) {
        return [];
    }

    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        api_error('Invalid JSON in request body', 400);
    }

    return $data ?? [];
}

/**
 * Validate required parameters
 * BUG-104 FIX: Updated to use api_error()
 */
function validateRequiredParams(array $params, array $required): void {
    foreach ($required as $param) {
        if (!isset($params[$param]) || $params[$param] === '') {
            api_error("Missing required parameter: $param", 400);
        }
    }
}

/**
 * Validate ISO 8601 date format
 */
function validateDateFormat(string $date): bool {
    // BUG-104A FIX: Accept ISO 8601 with milliseconds (e.g., 2025-10-26T23:00:00.000Z)
    $d = DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $date); // With milliseconds + timezone
    if (!$d) {
        $d = DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $date); // With milliseconds + Z
    }
    if (!$d) {
        $d = DateTime::createFromFormat('Y-m-d\TH:i:sP', $date); // Without milliseconds + timezone
    }
    if (!$d) {
        $d = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $date); // Without milliseconds + Z
    }
    // Accept HTML datetime-local (e.g., 2025-12-14T15:30) used by calendar.js
    if (!$d) {
        $d = DateTime::createFromFormat('Y-m-d\TH:i', $date);
    }
    // Accept local datetime with seconds (e.g., 2025-12-14T15:30:00)
    if (!$d) {
        $d = DateTime::createFromFormat('Y-m-d\TH:i:s', $date);
    }
    if (!$d) {
        $d = DateTime::createFromFormat('Y-m-d', $date); // Date only
    }
    return $d && $d->format('Y-m-d') === explode('T', $date)[0];
}

/**
 * Parse and validate date parameter
 * BUG-104 FIX: Updated to use api_error()
 */
function parseDate(string $dateStr): DateTime {
    try {
        return new DateTime($dateStr);
    } catch (Exception $e) {
        api_error("Invalid date format: $dateStr", 400);
    }
}

/**
 * Parse event identifier, supporting recurring instance IDs like "11_20251217"
 *
 * @param string|int|null $rawId
 * @return array{id:?int,instance_date:?string}
 */
function parseEventIdentifier($rawId): array {
    if ($rawId === null || $rawId === '') {
        return ['id' => null, 'instance_date' => null];
    }

    // Already numeric
    if (is_numeric($rawId) && strpos((string)$rawId, '_') === false) {
        return ['id' => intval($rawId), 'instance_date' => null];
    }

    $raw = (string)$rawId;
    if (strpos($raw, '_') !== false) {
        [$idPart, $datePart] = array_pad(explode('_', $raw, 2), 2, null);
        $id = intval($idPart);
        $instanceDate = null;

        // Accept YYYYMMDD and YYYY-MM-DD
        if ($datePart && preg_match('/^(\\d{4})(\\d{2})(\\d{2})$/', $datePart, $m)) {
            $instanceDate = sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        } elseif ($datePart && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $datePart)) {
            $instanceDate = $datePart;
        }

        return ['id' => $id ?: null, 'instance_date' => $instanceDate];
    }

    // Fallback: parse int
    return ['id' => intval($raw), 'instance_date' => null];
}

// Route request based on method and action
// BUG-104 FIX: Updated to use api_error()
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$parsedId = parseEventIdentifier($_GET['id'] ?? null);
$id = $parsedId['id'];
$instanceDateParam = $parsedId['instance_date'];
// Also accept instance_date from query string (?instance_date=YYYY-MM-DD)
if (isset($_GET['instance_date']) && is_string($_GET['instance_date'])) {
    $q = trim($_GET['instance_date']);
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $q)) {
        $instanceDateParam = $q;
    }
}

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($calendar, $action, $id);
            break;

        case 'POST':
            handlePostRequest($calendar, $action, $id);
            break;

        case 'PUT':
            handlePutRequest($calendar, $id);
            break;

        case 'DELETE':
            handleDeleteRequest($calendar, $id, $instanceDateParam);
            break;

        default:
            api_error('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log('[EVENTS API] Error: ' . $e->getMessage());
    api_error('Server error: ' . $e->getMessage(), 500);
}

/**
 * Handle GET requests
 */
function handleGetRequest(Calendar $calendar, ?string $action, ?int $id): void {
    global $user_id;
    global $user_role;
    global $tenant_id;

    // Handle special actions
    if ($action) {
        switch ($action) {
            case 'conflicts':
                handleCheckConflicts($calendar);
                break;

            case 'availability':
                handleGetAvailability($calendar);
                break;

            case 'export':
                handleExportEvents($calendar);
                break;

            case 'suggestions':
                handleGetSuggestions($calendar);
                break;

            case 'available_users':
                handleGetAvailableUsers($calendar);
                break;

            case 'participants':
                handleGetParticipants($calendar);
                break;

            default:
                api_error("Unknown action: $action", 400);
        }
        return;
    }

    // Get single event by ID
    if ($id) {
        $event = getEventById($calendar, $id);
        if (!$event) {
            api_error('Event not found', 404);
        }

        api_success($event, 'Event retrieved successfully');
        return;
    }

    // Get events in date range
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;

    if (!$start || !$end) {
        api_error('Missing required parameters: start and end dates', 400);
    }

    // Validate date formats
    if (!validateDateFormat($start) || !validateDateFormat($end)) {
        api_error('Invalid date format. Use ISO 8601 format', 400);
    }

    $startDate = parseDate($start);
    $endDate = parseDate($end);

    if ($startDate > $endDate) {
        api_error('Start date must be before end date', 400);
    }

    // Build filters
    $filters = [];

    // BUG-105 FIX: Support both calendar_ids[] (array) and calendar_id (single)
    // Apply calendar filters only if schema supports calendar_id
    if ($calendar->eventsHasColumn('calendar_id')) {
        if (isset($_GET['calendar_ids']) && is_array($_GET['calendar_ids'])) {
            // Multi-calendar filtering (primary use case)
            $filters['calendar_ids'] = array_map('intval', $_GET['calendar_ids']);
        } elseif (isset($_GET['calendar_id'])) {
            // Single calendar filtering (backward compatibility)
            $filters['calendar_id'] = intval($_GET['calendar_id']);
        }
    } elseif (isset($_GET['calendar_ids']) || isset($_GET['calendar_id'])) {
        // Schema lacks calendar_id: ignore filter but log for diagnostics
        error_log('[EVENTS API] calendar_id filter ignored (column not present in events table)');
    }

    if (isset($_GET['participant_id'])) {
        $filters['user_id'] = intval($_GET['participant_id']);
    }

    if (isset($_GET['category'])) {
        $filters['category'] = $_GET['category'];
    }

    if (isset($_GET['location'])) {
        $filters['location'] = $_GET['location'];
    }

    // Get events
    $events = $calendar->getEventsBetween($startDate, $endDate, $filters);

    // Planning (tenant 28) cross-tenant view helper:
    // When requesting events for tenant 28, optionally filter only those linked to a client tenant via metadata.planning.client_tenant_id.
    // This supports Calendar UI company filter: seeing S.CO planned events under the client filter without duplicating events.
    $planningClientTenantId = isset($_GET['planning_client_tenant_id']) ? (int)$_GET['planning_client_tenant_id'] : 0;
    if ($planningClientTenantId > 0) {
        // Only allow this filter when we are querying the vendor calendar tenant (28) AND caller is privileged.
        // (Prevents leaking cross-tenant associations through metadata filtering.)
        $vendorTenantId = defined('CNX_VENDOR_TENANT_ID') ? (int)CNX_VENDOR_TENANT_ID : 28;
        if ((int)$tenant_id !== $vendorTenantId) {
            api_error('Filtro planning_client_tenant_id non consentito per questo tenant', 403);
        }
        if (!in_array((string)$user_role, ['super_admin', 'admin'], true)) {
            api_error('Permessi insufficienti per filtro planning_client_tenant_id', 403);
        }

        // Require that the user can access the client tenant too (super_admin ok; admin must have access)
        if ((string)$user_role !== 'super_admin') {
            $dbTmp = Database::getInstance();
            $primaryTenantId = (int)($_SESSION['tenant_id'] ?? 0);
            if ($primaryTenantId > 0 && $primaryTenantId !== $planningClientTenantId) {
                $utaHasDeletedAt = $dbTmp->fetchOne(
                    "SELECT 1 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'user_tenant_access'
                       AND COLUMN_NAME = 'deleted_at'
                     LIMIT 1"
                );
                $utaWhere = $utaHasDeletedAt ? " AND deleted_at IS NULL" : "";
                $hasAccess = $dbTmp->fetchOne(
                    "SELECT 1
                     FROM user_tenant_access
                     WHERE user_id = ?
                       AND tenant_id = ?" . $utaWhere . "
                     LIMIT 1",
                    [$user_id, $planningClientTenantId]
                );
                if (!$hasAccess) {
                    api_error('Accesso negato al tenant richiesto (planning_client_tenant_id)', 403);
                }
            }
        }

        $events = array_values(array_filter($events, function($ev) use ($planningClientTenantId) {
            $meta = $ev['metadata'] ?? null;
            $arr = null;
            if (is_array($meta)) {
                $arr = $meta;
            } elseif (is_string($meta) && $meta !== '') {
                $decoded = json_decode($meta, true);
                if (is_array($decoded)) $arr = $decoded;
            }

            // Primary match: metadata.planning.client_tenant_id
            if (is_array($arr)) {
                $ptid = $arr['planning']['client_tenant_id'] ?? null;
                if ((int)$ptid === (int)$planningClientTenantId) return true;
            }

            // Fallback match: description marker (supports DBs without events.metadata column)
            // schedule_confirm.php writes: "Cliente: <name> (#<tenantId>)\nPiano: #<planId>\n\n[Generato da Pianificazione S.CO]"
            $desc = (string)($ev['description'] ?? '');
            if ($desc === '') return false;
            if (strpos($desc, '[Generato da Pianificazione S.CO]') === false) return false;
            if (strpos($desc, '(#' . (string)$planningClientTenantId . ')') === false) return false;
            // extra guard: must mention "Cliente:"
            if (stripos($desc, 'Cliente:') === false) return false;
            return true;
        }));
    }

    // Apply timezone conversion if requested
    if (isset($_GET['timezone'])) {
        $timezone = new DateTimeZone($_GET['timezone']);
        foreach ($events as &$event) {
            $event['start_date_local'] = convertToTimezone($event['start_datetime'], $timezone);
            $event['end_date_local'] = convertToTimezone($event['end_datetime'], $timezone);
        }
    }

    // Include recurring events expansion (default: true)
    $includeRecurring = filter_var($_GET['include_recurring'] ?? true, FILTER_VALIDATE_BOOLEAN);
    if (!$includeRecurring) {
        $events = array_filter($events, fn($e) => !($e['is_recurring_instance'] ?? false));
    }

    // Format response
    $responseData = [
        'events' => $events,
        'total' => count($events)
    ];

    // BUG-104 FIX: Add metadata to data object instead of separate parameter
    $responseData['metadata'] = [
        'start' => $start,
        'end' => $end,
        'filters_applied' => count($filters) > 0
    ];

    api_success($responseData, 'Events retrieved successfully');
}

/**
 * Handle POST requests
 */
function handlePostRequest(Calendar $calendar, ?string $action, ?int $id): void {
    // Handle special actions
    if ($action) {
        switch ($action) {
            case 'respond':
                if (!$id) {
                    api_error('Event ID required', 400);
                }
                handleRespondToInvitation($calendar, $id);
                break;

            case 'duplicate':
                if (!$id) {
                    api_error('Event ID required', 400);
                }
                handleDuplicateEvent($calendar, $id);
                break;

            case 'import':
                handleImportEvents($calendar);
                break;

            case 'reschedule':
                if (!$id) {
                    api_error('Event ID required', 400);
                }
                handleRescheduleEvent($calendar, $id);
                break;

            case 'invite':
                handleInviteParticipants($calendar);
                break;

            default:
                api_error("Unknown action: $action", 400);
        }
        return;
    }

    // Create new event
    $data = getRequestBody();

    // Normalize frontend payload BEFORE validation (calendar.js uses start_date/end_date/all_day/recurrence_rule)
    if (!isset($data['start']) && isset($data['start_date'])) {
        $data['start'] = $data['start_date'];
    }
    if (!isset($data['end']) && isset($data['end_date'])) {
        $data['end'] = $data['end_date'];
    }
    if (!isset($data['is_all_day']) && isset($data['all_day'])) {
        $data['is_all_day'] = $data['all_day'];
    }
    if (!isset($data['recurrence']) && isset($data['recurrence_rule'])) {
        $data['recurrence'] = $data['recurrence_rule'];
    }

    // Validate required fields
    validateRequiredParams($data, ['title', 'start', 'end']);

    // Validate dates
    if (!validateDateFormat($data['start']) || !validateDateFormat($data['end'])) {
        api_error('Invalid date format. Use ISO 8601 format', 400);
    }

    $startDate = parseDate($data['start']);
    $endDate = parseDate($data['end']);

    if ($startDate >= $endDate) {
        api_error('End date must be after start date', 400);
    }

    // Legacy cleanup (avoid passing unused aliases forward)
    unset($data['start_date'], $data['end_date'], $data['all_day'], $data['recurrence_rule']);

    // Prepare event data for Calendar class
    $eventData = [
        'title' => $data['title'],
        'description' => $data['description'] ?? null,
        'start_datetime' => $startDate->format('Y-m-d H:i:s'),
        'end_datetime' => $endDate->format('Y-m-d H:i:s'),
        'timezone' => $data['timezone'] ?? date_default_timezone_get(),
        'all_day' => (bool)($data['is_all_day'] ?? false),
        'location' => $data['location'] ?? null,
        'calendar_id' => $data['calendar_id'] ?? null,
        'category' => validateCategory($data['category'] ?? 'meeting'),
        'status' => 'confirmed',
        'visibility' => validateVisibility($data['visibility'] ?? 'private'),
        'check_conflicts' => true
    ];

    // Handle recurrence
    if (isset($data['recurrence']) && !empty($data['recurrence'])) {
        $eventData['recurrence_rule'] = $data['recurrence'];

        // Validate RRULE format
        try {
            $calendar->parseRecurrenceRule($data['recurrence']);
        } catch (Exception $e) {
            api_error('Invalid recurrence rule: ' . $e->getMessage(), 400);
        }
    }

    // Add optional fields
    if (isset($data['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $data['color'])) {
        $eventData['color'] = $data['color'];
    }

    // Handle participants
    $participants = [];
    if (isset($data['participants']) && is_array($data['participants'])) {
        foreach ($data['participants'] as $participant) {
            // calendar.js sends participants as an array of user IDs
            if (is_int($participant) || ctype_digit((string)$participant)) {
                $participants[] = (int)$participant;
                continue;
            }

            // Some clients send objects {user_id} or {email}
            if (is_array($participant) && isset($participant['user_id'])) {
                $participants[] = (int)$participant['user_id'];
                continue;
            }

            if (is_array($participant) && isset($participant['email'])) {
                // Handle external participants by email
                $externalUserId = getOrCreateExternalUser($participant['email']);
                if ($externalUserId) {
                    $participants[] = (int)$externalUserId;
                }
            }
        }
        $eventData['participants'] = $participants;
    }

    // Handle reminders
    if (isset($data['reminders']) && is_array($data['reminders'])) {
        $eventData['reminders'] = $data['reminders'];
    }

    // Handle attachments
    if (isset($data['attachments']) && is_array($data['attachments'])) {
        $eventData['attachments'] = array_map('intval', $data['attachments']);
    }

    // Create event
    try {
        $eventId = $calendar->createEvent($eventData);

        // Get created event
        $createdEvent = getEventById($calendar, $eventId);

        $message = 'Event created successfully';
        if (!empty($participants)) {
            $message .= ' and invitations sent';
        }

        api_success($createdEvent, $message);

    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'Conflitti rilevati') !== false) {
            // Parse conflicts from error message
            $conflictsJson = substr($e->getMessage(), strpos($e->getMessage(), '{'));
            $conflicts = json_decode($conflictsJson, true);

            // BUG-104 FIX: api_error doesn't support data parameter, log conflicts instead
            error_log('[EVENTS API] Scheduling conflicts detected: ' . json_encode($conflicts));
            api_error('Scheduling conflicts detected', 409);
        } else {
            throw $e;
        }
    }
}

/**
 * Handle PUT requests
 */
function handlePutRequest(Calendar $calendar, ?int $id): void {
    if (!$id) {
        api_error('Event ID required', 400);
    }

    // Check if event exists
    $existingEvent = getEventById($calendar, $id);
    if (!$existingEvent) {
        api_error('Event not found', 404);
    }

    $data = getRequestBody();

    if (empty($data)) {
        api_error('No data provided for update', 400);
    }

    // Normalize frontend aliases (same pattern as POST)
    if (!isset($data['start']) && isset($data['start_date'])) {
        $data['start'] = $data['start_date'];
    }
    if (!isset($data['end']) && isset($data['end_date'])) {
        $data['end'] = $data['end_date'];
    }
    if (!isset($data['is_all_day']) && isset($data['all_day'])) {
        $data['is_all_day'] = $data['all_day'];
    }
    if (!isset($data['recurrence']) && array_key_exists('recurrence_rule', $data)) {
        $data['recurrence'] = $data['recurrence_rule'];
    }

    // Prepare update data
    $updateData = [];

    // Update basic fields if provided
    $allowedFields = [
        'title', 'description', 'location', 'category',
        'color', 'visibility', 'status', 'timezone'
    ];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updateData[$field] = $data[$field];
        }
    }

    // Handle date updates
    if (isset($data['start'])) {
        if (!validateDateFormat($data['start'])) {
            api_error('Invalid start date format', 400);
        }
        $updateData['start_datetime'] = parseDate($data['start'])->format('Y-m-d H:i:s');
    }

    if (isset($data['end'])) {
        if (!validateDateFormat($data['end'])) {
            api_error('Invalid end date format', 400);
        }
        $updateData['end_datetime'] = parseDate($data['end'])->format('Y-m-d H:i:s');
    }

    if (isset($data['is_all_day'])) {
        $updateData['all_day'] = filter_var($data['is_all_day'], FILTER_VALIDATE_BOOLEAN);
    }

    // Handle recurrence update
    if (array_key_exists('recurrence', $data)) {
        if (!empty($data['recurrence'])) {
            try {
                $calendar->parseRecurrenceRule($data['recurrence']);
                $updateData['recurrence_rule'] = $data['recurrence'];
            } catch (Exception $e) {
                api_error('Invalid recurrence rule: ' . $e->getMessage(), 400);
            }
        } else {
            $updateData['recurrence_rule'] = null;
        }
    }

    // Check for conflicts if dates changed
    $checkConflicts = !isset($_GET['notify_participants']) ||
                      filter_var($_GET['notify_participants'], FILTER_VALIDATE_BOOLEAN);
    $updateData['check_conflicts'] = $checkConflicts;

    // Handle participants update
    if (isset($data['participants'])) {
        $participants = [];
        foreach ($data['participants'] as $participant) {
            // Accept IDs array (calendar.js) or objects {user_id}
            if (is_int($participant) || ctype_digit((string)$participant)) {
                $participants[] = (int)$participant;
                continue;
            }
            if (is_array($participant) && isset($participant['user_id'])) {
                $participants[] = intval($participant['user_id']);
                continue;
            }
        }
        $updateData['participants'] = $participants;
    }

    // Handle reminders update
    if (isset($data['reminders'])) {
        $updateData['reminders'] = $data['reminders'];
    }

    // Update event
    try {
        $success = $calendar->updateEvent($id, $updateData);

        if ($success) {
            // Get updated event
            $updatedEvent = getEventById($calendar, $id);

            $message = 'Event updated successfully';
            if ($checkConflicts) {
                $message .= ' and participants notified';
            }

            api_success($updatedEvent, $message);
        } else {
            api_error('Failed to update event', 500);
        }

    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'Conflitti rilevati') !== false) {
            // Parse conflicts
            $conflictsJson = substr($e->getMessage(), strpos($e->getMessage(), '{'));
            $conflicts = json_decode($conflictsJson, true);

            // BUG-104 FIX: api_error doesn't support data parameter, log conflicts instead
            error_log('[EVENTS API] Scheduling conflicts detected: ' . json_encode($conflicts));
            api_error('Scheduling conflicts detected', 409);
        } else if (strpos($e->getMessage(), 'Permessi insufficienti') !== false) {
            api_error('Insufficient permissions to modify event', 403);
        } else {
            throw $e;
        }
    }
}

/**
 * Handle DELETE requests
 */
function handleDeleteRequest(Calendar $calendar, ?int $id, ?string $instanceDate): void {
    if (!$id) {
        api_error('Event ID required', 400);
    }

    // Check if event exists
    $event = getEventById($calendar, $id);
    if (!$event) {
        api_error('Event not found', 404);
    }

    // Check for delete_series parameter for recurring events
    $deleteSeries = filter_var($_GET['delete_series'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $notifyParticipants = filter_var($_GET['notify_participants'] ?? true, FILTER_VALIDATE_BOOLEAN);

    // If an instance_date is provided and the event is recurring, handle single-occurrence removal
    if ($instanceDate && $event['is_recurring'] && !$deleteSeries) {
        try {
            $instanceDateObj = new DateTime($instanceDate);
        } catch (Exception $e) {
            api_error('Invalid instance_date format. Expected YYYY-MM-DD', 400);
        }

        try {
            $success = $calendar->deleteRecurringInstance($id, $instanceDateObj, $notifyParticipants);
            if ($success) {
                $message = 'Occorrenza evento cancellata';
                if ($notifyParticipants) {
                    $message .= ' e partecipanti avvisati';
                }
                api_success(null, $message);
            } else {
                api_error('Failed to delete recurring instance', 500);
            }
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'Permessi insufficienti') !== false) {
                api_error('Insufficient permissions to delete event', 403);
            }
            throw $e;
        }
        return;
    }

    try {
        // Handle recurring series deletion
        if ($deleteSeries && $event['is_recurring']) {
            // Delete all instances of recurring event
            $success = deleteRecurringSeries($calendar, $id, $notifyParticipants);
        } else {
            // Delete single event
            $success = $calendar->deleteEvent($id, $notifyParticipants);
        }

        if ($success) {
            $message = 'Event cancelled';
            if ($notifyParticipants) {
                $message .= ' and participants notified';
            }

            api_success(null, $message);
        } else {
            api_error('Failed to delete event', 500);
        }

    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'Permessi insufficienti') !== false) {
            api_error('Insufficient permissions to delete event', 403);
        } else {
            throw $e;
        }
    }
}

/**
 * Handle respond to invitation
 */
function handleRespondToInvitation(Calendar $calendar, int $eventId): void {
    global $db, $tenant_id, $user_id;

    $data = getRequestBody();
    validateRequiredParams($data, ['response']);

    $validResponses = ['accepted', 'declined', 'tentative'];
    if (!in_array($data['response'], $validResponses)) {
        api_error('Invalid response. Must be: accepted, declined, or tentative', 400);
    }

    try {
        // Update participant response
        $sql = "UPDATE event_participants
                SET status = :status,
                    responded_at = NOW(),
                    response_message = :message
                WHERE event_id = :event_id
                  AND user_id = :user_id
                  AND event_id IN (
                      SELECT id FROM events WHERE tenant_id = :tenant_id
                  )";

        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
            ':status' => $data['response'],
            ':message' => $data['message'] ?? null,
            ':event_id' => $eventId,
            ':user_id' => $user_id,
            ':tenant_id' => $tenant_id
        ]);

        if ($stmt->rowCount() === 0) {
            api_error('Invitation not found or already responded', 404);
        }

        // Log response
        $calendar->logActivity('event_response', $eventId, [
            'user_id' => $user_id,
            'response' => $data['response'],
            'message' => $data['message'] ?? null
        ]);

        api_success(null, 'Response recorded successfully');

    } catch (Exception $e) {
        error_log('Error responding to invitation: ' . $e->getMessage());
        api_error('Failed to record response', 500);
    }
}

/**
 * Handle check conflicts
 */
function handleCheckConflicts(Calendar $calendar): void {
    validateRequiredParams($_GET, ['start', 'end']);

    $startDate = parseDate($_GET['start']);
    $endDate = parseDate($_GET['end']);

    // Get participants list
    $participants = [];
    if (isset($_GET['participants']) && is_array($_GET['participants'])) {
        $participants = array_map('intval', $_GET['participants']);
    }

    try {
        $conflicts = $calendar->detectConflicts($startDate, $endDate, $participants);

        $responseData = [
            'has_conflicts' => !empty($conflicts),
            'conflicts' => $conflicts,
            'total' => count($conflicts)
        ];

        api_success($responseData,
            empty($conflicts) ? 'No conflicts found' : 'Conflicts detected');

    } catch (Exception $e) {
        error_log('Error checking conflicts: ' . $e->getMessage());
        api_error('Failed to check conflicts', 500);
    }
}

/**
 * Handle get availability
 */
function handleGetAvailability(Calendar $calendar): void {
    validateRequiredParams($_GET, ['user_id', 'date']);

    $userId = intval($_GET['user_id']);
    $date = parseDate($_GET['date']);

    try {
        $availability = $calendar->getUserAvailability($userId, $date);

        api_success($availability, 'Availability retrieved successfully');

    } catch (Exception $e) {
        error_log('Error getting availability: ' . $e->getMessage());
        api_error('Failed to get availability', 500);
    }
}

/**
 * Handle export events
 */
function handleExportEvents(Calendar $calendar): void {
    validateRequiredParams($_GET, ['start', 'end']);

    $format = $_GET['format'] ?? 'ics';
    if ($format !== 'ics') {
        api_error('Unsupported export format. Only iCalendar (ics) is supported', 400);
    }

    $startDate = parseDate($_GET['start']);
    $endDate = parseDate($_GET['end']);

    // Apply filters
    $filters = [];
    if (isset($_GET['calendar_id'])) {
        $filters['calendar_id'] = intval($_GET['calendar_id']);
    }
    if (isset($_GET['category'])) {
        $filters['category'] = $_GET['category'];
    }

    try {
        // Get events
        $events = $calendar->getEventsBetween($startDate, $endDate, $filters);

        // Generate iCalendar content
        $icsContent = $calendar->exportToICS($events);

        // Set headers for file download
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="calendar_export.ics"');
        header('Content-Length: ' . strlen($icsContent));

        echo $icsContent;
        exit();

    } catch (Exception $e) {
        error_log('Error exporting events: ' . $e->getMessage());
        api_error('Failed to export events', 500);
    }
}

/**
 * Handle import events
 */
function handleImportEvents(Calendar $calendar): void {
    $data = getRequestBody();

    // Check for file upload
    if (isset($_FILES['ics_file'])) {
        if ($_FILES['ics_file']['error'] !== UPLOAD_ERR_OK) {
            api_error('File upload failed', 400);
        }

        $icsData = file_get_contents($_FILES['ics_file']['tmp_name']);
    } elseif (isset($data['ics_data'])) {
        $icsData = $data['ics_data'];
    } else {
        api_error('No iCalendar data provided', 400);
    }

    try {
        $result = $calendar->importFromICS($icsData);

        $message = sprintf(
            'Import completed. %d events imported, %d errors',
            count($result['imported']),
            count($result['errors'])
        );

        // BUG-104 FIX: Use api_success/api_error based on errors count
        if (count($result['errors']) === 0) {
            api_success($result, $message);
        } else {
            api_success($result, $message); // 207 Multi-Status treated as success with partial errors
        }

    } catch (Exception $e) {
        error_log('Error importing events: ' . $e->getMessage());
        api_error('Failed to import events: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle get meeting suggestions
 */
function handleGetSuggestions(Calendar $calendar): void {
    validateRequiredParams($_GET, ['duration', 'date_range_start', 'date_range_end']);

    $duration = intval($_GET['duration']);
    if ($duration < 15 || $duration > 480) {
        api_error('Duration must be between 15 and 480 minutes', 400);
    }

    $participants = [];
    if (isset($_GET['participants']) && is_array($_GET['participants'])) {
        $participants = array_map('intval', $_GET['participants']);
    }

    if (empty($participants)) {
        api_error('At least one participant required', 400);
    }

    $dateRange = [
        'start' => $_GET['date_range_start'],
        'end' => $_GET['date_range_end']
    ];

    // Parse preferences
    $preferences = [];
    if (isset($_GET['preferred_times'])) {
        $preferences['preferred_times'] = $_GET['preferred_times'];
    }
    if (isset($_GET['avoid_lunch'])) {
        $preferences['avoid_lunch'] = filter_var($_GET['avoid_lunch'], FILTER_VALIDATE_BOOLEAN);
    }
    if (isset($_GET['skip_weekends'])) {
        $preferences['skip_weekends'] = filter_var($_GET['skip_weekends'], FILTER_VALIDATE_BOOLEAN);
    }
    if (isset($_GET['max_suggestions'])) {
        $preferences['max_suggestions'] = intval($_GET['max_suggestions']);
    }

    try {
        $suggestions = $calendar->suggestFreeSlots(
            $duration,
            $participants,
            $dateRange,
            $preferences
        );

        $responseData = [
            'suggestions' => $suggestions,
            'total' => count($suggestions),
            'parameters' => [
                'duration' => $duration,
                'participants' => $participants,
                'date_range' => $dateRange,
                'preferences' => $preferences
            ]
        ];

        api_success($responseData, 'Meeting suggestions generated successfully');

    } catch (Exception $e) {
        error_log('Error generating suggestions: ' . $e->getMessage());
        api_error('Failed to generate suggestions', 500);
    }
}

/**
 * Handle duplicate event
 */
function handleDuplicateEvent(Calendar $calendar, int $eventId): void {
    global $db, $tenant_id;

    $data = getRequestBody();

    // Get original event
    $originalEvent = getEventById($calendar, $eventId);
    if (!$originalEvent) {
        api_error('Event not found', 404);
    }

    // Prepare duplicate data
    $duplicateData = [
        'title' => $data['title'] ?? $originalEvent['title'] . ' (Copy)',
        'description' => $data['description'] ?? $originalEvent['description'],
        'location' => $data['location'] ?? $originalEvent['location'],
        'category' => $originalEvent['category'],
        'color' => $originalEvent['color'],
        'visibility' => $originalEvent['visibility'],
        'all_day' => $originalEvent['all_day']
    ];

    // Handle new dates
    if (isset($data['start']) && isset($data['end'])) {
        $duplicateData['start_datetime'] = parseDate($data['start'])->format('Y-m-d H:i:s');
        $duplicateData['end_datetime'] = parseDate($data['end'])->format('Y-m-d H:i:s');
    } else {
        // Use original dates
        $duplicateData['start_datetime'] = $originalEvent['start_datetime'];
        $duplicateData['end_datetime'] = $originalEvent['end_datetime'];
    }

    // Copy participants if requested
    if (filter_var($data['copy_participants'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $duplicateData['participants'] = array_map(
            fn($p) => $p['user_id'],
            $originalEvent['participants'] ?? []
        );
    }

    // Copy reminders if requested
    if (filter_var($data['copy_reminders'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
        $duplicateData['reminders'] = $originalEvent['reminders'] ?? [];
    }

    try {
        $newEventId = $calendar->createEvent($duplicateData);
        $newEvent = getEventById($calendar, $newEventId);

        api_success($newEvent, 'Event duplicated successfully');

    } catch (Exception $e) {
        error_log('Error duplicating event: ' . $e->getMessage());
        api_error('Failed to duplicate event', 500);
    }
}

/**
 * Handle reschedule event
 */
function handleRescheduleEvent(Calendar $calendar, int $eventId): void {
    $data = getRequestBody();
    validateRequiredParams($data, ['start', 'end']);

    $newStart = parseDate($data['start']);
    $newEnd = parseDate($data['end']);

    if ($newStart >= $newEnd) {
        api_error('End date must be after start date', 400);
    }

    // Get current event
    $event = getEventById($calendar, $eventId);
    if (!$event) {
        api_error('Event not found', 404);
    }

    // Check for conflicts with new time
    $participants = array_map(fn($p) => $p['user_id'], $event['participants'] ?? []);
    $conflicts = $calendar->detectConflicts($newStart, $newEnd, $participants);

    // Remove current event from conflicts
    $conflicts = array_filter($conflicts, fn($c) => $c['event_id'] != $eventId);

    if (!empty($conflicts) && !filter_var($data['force'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        // BUG-104 FIX: api_error doesn't support data parameter, log conflicts instead
        error_log('[EVENTS API] Reschedule conflicts: ' . json_encode($conflicts));
        api_error('Conflicts detected at new time. Set force=true to reschedule anyway', 409);
    }

    // Reschedule event
    $updateData = [
        'start_datetime' => $newStart->format('Y-m-d H:i:s'),
        'end_datetime' => $newEnd->format('Y-m-d H:i:s'),
        'check_conflicts' => false
    ];

    try {
        $success = $calendar->updateEvent($eventId, $updateData);

        if ($success) {
            $updatedEvent = getEventById($calendar, $eventId);

            $message = 'Event rescheduled successfully';
            if (!empty($conflicts)) {
                $message .= ' (conflicts overridden)';
            }

            api_success($updatedEvent, $message);
        } else {
            api_error('Failed to reschedule event', 500);
        }

    } catch (Exception $e) {
        error_log('Error rescheduling event: ' . $e->getMessage());
        api_error('Failed to reschedule event', 500);
    }
}

/**
 * Handle get available users for invitation (RBAC filtered)
 * BUG-120 FIX: Make event_id optional (null for new events, ID for existing events)
 */
function handleGetAvailableUsers(Calendar $calendar): void {
    // BUG-120 FIX: event_id is optional - null when creating new events
    $eventId = isset($_GET['event_id']) && !empty($_GET['event_id']) ? (int)$_GET['event_id'] : null;

    try {
        $users = $calendar->getAvailableUsersForInvitation($eventId);

        api_success([
            'users' => $users,
            'count' => count($users)
        ], 'Available users loaded');

    } catch (Exception $e) {
        error_log('[API/events/available_users] Error: ' . $e->getMessage());
        api_error('Error loading available users', 500);
    }
}

/**
 * Handle invite participants to event (RBAC + email)
 */
function handleInviteParticipants(Calendar $calendar): void {
    global $tenant_id, $user_id;

    $data = getRequestBody();

    if (empty($data['event_id']) || empty($data['user_ids'])) {
        api_error('event_id and user_ids required', 400);
    }

    $eventId = (int)$data['event_id'];
    $userIds = array_map('intval', $data['user_ids']);

    try {
        // Invite participants (RBAC validation happens inside inviteParticipants)
        $result = $calendar->inviteParticipants($eventId, $userIds);

        if ($result) {
            // Send email invitations (non-blocking)
            try {
                $calendar->scheduleEmailInvitations($eventId, $userIds);
            } catch (Exception $emailEx) {
                error_log('[API/events/invite] Email error: ' . $emailEx->getMessage());
                // Don't fail - invitations already created
            }

            api_success([
                'invited' => count($userIds),
                'event_id' => $eventId
            ], 'Participants invited successfully');
        } else {
            api_error('Failed to invite participants', 500);
        }

    } catch (Exception $e) {
        error_log('[API/events/invite] Error: ' . $e->getMessage());
        api_error('Error inviting participants: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle get event participants with RSVP status
 */
function handleGetParticipants(Calendar $calendar): void {
    global $db, $tenant_id;

    if (empty($_GET['event_id'])) {
        api_error('event_id required', 400);
    }

    $eventId = (int)$_GET['event_id'];

    try {
        $hasEpDeletedAt = tableHasColumn($db, 'event_participants', 'deleted_at');
        $hasEpStatus = tableHasColumn($db, 'event_participants', 'status');

        // BUG-144a FIX: Get event participants with user details
        // Removed u.active_tenant_id (column doesn't exist in users table)
        // Multi-tenant check via u.tenant_id OR user_tenant_access join
        $sql = "SELECT ep.*, u.name, u.email, u.role
                FROM event_participants ep
                LEFT JOIN users u ON ep.user_id = u.id
                LEFT JOIN user_tenant_access uta ON uta.user_id = u.id AND uta.tenant_id = ?
                WHERE ep.event_id = ?
                  " . ($hasEpDeletedAt ? "AND ep.deleted_at IS NULL" : "") . "
                  " . ($hasEpStatus ? "AND (ep.status IS NULL OR ep.status <> 'cancelled')" : "") . "
                  AND (u.tenant_id = ? OR uta.tenant_id IS NOT NULL)
                ORDER BY " . ($hasEpStatus ? "ep.status ASC, " : "") . "u.name ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute([$tenant_id, $eventId, $tenant_id]);

        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        api_success([
            'participants' => $participants,
            'count' => count($participants)
        ], 'Participants loaded');

    } catch (Exception $e) {
        error_log('[API/events/participants] Error: ' . $e->getMessage());
        api_error('Error loading participants', 500);
    }
}

// ========== Helper Functions ==========

/**
 * Check if a table has a given column (cached per-request).
 */
function tableHasColumn(PDO $db, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return (bool)$cache[$key];
    }

    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) AS cnt
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$key] = ((int)($row['cnt'] ?? 0)) > 0;
    } catch (Exception $e) {
        // If information_schema access is restricted, assume column is missing.
        $cache[$key] = false;
    }

    return (bool)$cache[$key];
}

/**
 * Get event by ID with full details
 */
function getEventById(Calendar $calendar, int $id): ?array {
    global $db, $tenant_id;

    try {
        $sql = "SELECT e.*,
                       u.name as organizer_name,
                       u.email as organizer_email,
                       cal.name as calendar_name,
                       cal.color as calendar_color
                FROM events e
                LEFT JOIN users u ON e.organizer_id = u.id
                LEFT JOIN calendars cal ON e.calendar_id = cal.id
                WHERE e.id = :id
                  AND e.tenant_id = :tenant_id
                  AND e.deleted_at IS NULL";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':tenant_id' => $tenant_id
        ]);

        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            return null;
        }

        // Format event data
        $organizerInfo = [
            'id' => $event['organizer_id'],
            'name' => $event['organizer_name'] ?? null,
            'email' => $event['organizer_email'] ?? null
        ];

        $formatted = [
            'id' => $event['id'],
            'title' => $event['title'],
            'description' => $event['description'],
            'start' => formatDateISO($event['start_datetime']),
            'end' => formatDateISO($event['end_datetime']),
            'is_all_day' => (bool)$event['all_day'],
            'location' => $event['location'],
            'organizer_id' => $event['organizer_id'],
            'organizer' => $organizerInfo,
            'calendar' => $event['calendar_id'] ? [
                'id' => $event['calendar_id'],
                'name' => $event['calendar_name'],
                'color' => $event['calendar_color'] ?? $event['color']
            ] : null,
            'participants' => getEventParticipants($id),
            'recurrence' => $event['recurrence_rule'] ? [
                'pattern' => $event['recurrence_rule'],
                'is_recurring' => true,
                'parent_id' => $event['parent_event_id']
            ] : null,
            'reminders' => getEventReminders($id),
            'category' => $event['category'] ?? null,
            'status' => $event['status'],
            // Backward compatibility for any consumer expecting creator field
            'creator' => $organizerInfo,
            'can_edit' => canEditEvent($event)
        ];

        return $formatted;

    } catch (Exception $e) {
        error_log('Error getting event: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get event participants
 */
function getEventParticipants(int $eventId): array {
    global $db, $tenant_id;

    try {
        $hasEpDeletedAt = tableHasColumn($db, 'event_participants', 'deleted_at');
        $hasEpStatus = tableHasColumn($db, 'event_participants', 'status');

        // BUG-144a FIX: Removed u.active_tenant_id (column doesn't exist)
        // BUG-148c FIX: Use unique named parameters (PDO doesn't support reusing named params)
        $sql = "SELECT ep.*, u.name, u.email
                FROM event_participants ep
                JOIN users u ON ep.user_id = u.id
                LEFT JOIN user_tenant_access uta ON uta.user_id = u.id AND uta.tenant_id = :tenant_id_join
                WHERE ep.event_id = :event_id
                  " . ($hasEpDeletedAt ? "AND ep.deleted_at IS NULL" : "") . "
                  " . ($hasEpStatus ? "AND (ep.status IS NULL OR ep.status <> 'cancelled')" : "") . "
                  AND (u.tenant_id = :tenant_id_where OR uta.tenant_id IS NOT NULL)";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':event_id' => $eventId,
            ':tenant_id_join' => $tenant_id,
            ':tenant_id_where' => $tenant_id
        ]);

        $participants = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $participants[] = [
                'user_id' => $row['user_id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'status' => $row['status'] ?? null,
                'is_organizer' => (bool)($row['is_organizer'] ?? false),
                'responded_at' => $row['responded_at'] ?? null
            ];
        }

        return $participants;

    } catch (Exception $e) {
        error_log('Error getting participants: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get event reminders
 */
function getEventReminders(int $eventId): array {
    global $db;

    try {
        $sql = "SELECT type, minutes_before
                FROM event_reminders
                WHERE event_id = :event_id
                ORDER BY minutes_before ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute([':event_id' => $eventId]);

        $reminders = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $reminders[] = [
                'type' => $row['type'],
                'minutes_before' => intval($row['minutes_before'])
            ];
        }

        return $reminders;

    } catch (Exception $e) {
        error_log('Error getting reminders: ' . $e->getMessage());
        return [];
    }
}

/**
 * Delete recurring series
 */
function deleteRecurringSeries(Calendar $calendar, int $parentId, bool $notify): bool {
    global $db, $tenant_id;

    try {
        // Find all instances
        $sql = "SELECT id FROM events
                WHERE (id = :id OR parent_event_id = :id)
                  AND tenant_id = :tenant_id";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':id' => $parentId,
            ':tenant_id' => $tenant_id
        ]);

        $eventIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Delete each instance
        foreach ($eventIds as $eventId) {
            $calendar->deleteEvent($eventId, $notify);
        }

        return true;

    } catch (Exception $e) {
        error_log('Error deleting series: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get or create external user
 */
function getOrCreateExternalUser(string $email): ?int {
    global $db, $tenant_id;

    try {
        // Check if user exists
        $sql = "SELECT id FROM users
                WHERE email = :email AND tenant_id = :tenant_id";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':email' => $email,
            ':tenant_id' => $tenant_id
        ]);

        $userId = $stmt->fetchColumn();

        if ($userId) {
            return intval($userId);
        }

        // Create external user
        $sql = "INSERT INTO users (tenant_id, email, name, role, is_external, created_at)
                VALUES (:tenant_id, :email, :name, 'external', TRUE, NOW())";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':email' => $email,
            ':name' => explode('@', $email)[0]
        ]);

        return intval($db->lastInsertId());

    } catch (Exception $e) {
        error_log('Error creating external user: ' . $e->getMessage());
        return null;
    }
}

/**
 * Check if user can edit event
 * BUG-104 FIX: Use getApiUserInfo() instead of direct session access
 */
function canEditEvent(array $event): bool {
    global $user_id;
    $userInfo = getApiUserInfo();

    return $event['organizer_id'] == $user_id ||
           $userInfo['role'] === 'admin' ||
           $userInfo['role'] === 'super_admin';
}

/**
 * Format date to ISO 8601
 */
function formatDateISO(string $date): string {
    $dt = new DateTime($date);
    return $dt->format('c');
}

/**
 * Convert date to timezone
 */
function convertToTimezone(string $date, DateTimeZone $timezone): string {
    $dt = new DateTime($date);
    $dt->setTimezone($timezone);
    return $dt->format('c');
}

/**
 * Validate category
 */
function validateCategory(string $category): string {
    $validCategories = ['meeting', 'task', 'reminder', 'review', 'holiday', 'other'];
    return in_array($category, $validCategories) ? $category : 'meeting';
}

/**
 * Validate visibility
 */
function validateVisibility(string $visibility): string {
    $validVisibility = ['private', 'public', 'team'];
    return in_array($visibility, $validVisibility) ? $visibility : 'private';
}

// End of file