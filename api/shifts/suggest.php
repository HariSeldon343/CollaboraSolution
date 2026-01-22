<?php
/**
 * API Endpoint: Shift Balancing Suggestion (Suggerisci bilanciamento turni)
 *
 * POST JSON:
 * - tenant_id (int, optional)
 * - start_date (Y-m-d, required)
 * - end_date (Y-m-d, required)
 * - shift_type_id (int, required)
 * - user_ids (int[], optional) - default: tutti gli utenti attivi del tenant
 * - required_per_day (int, optional) - default 1
 * - days_of_week (int[], optional) - 0..6 (0=dom)
 *
 * Response:
 * - suggestions: [{shift_date, user_id, user_name, shift_type_id}]
 * - stats: {days, required_per_day, existing_counts, planned_counts, skipped_slots}
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api_auth.php';

initializeApiEnvironment();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../includes/shift_permissions_helper.php';

    verifyApiAuthentication();
    verifyApiCsrfToken();

    $db = Database::getInstance();

    $userInfo = getApiUserInfo();
    $currentUserId = (int)($userInfo['user_id'] ?? 0);
    $currentUserRole = (string)($userInfo['role'] ?? 'user');
    $currentTenantId = (int)($userInfo['tenant_id'] ?? 0);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $isSuperAdmin = (
        ($currentUserRole === 'super_admin') ||
        (($_SESSION['role'] ?? '') === 'super_admin') ||
        (($_SESSION['user_role'] ?? '') === 'super_admin')
    );

    $rawBody = cnx_get_raw_request_body();
    $data = json_decode($rawBody, true) ?: [];

    // Determine target tenant_id
    $targetTenantId = (int)($data['tenant_id'] ?? 0);
    if ($targetTenantId <= 0) {
        if ($isSuperAdmin && isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null) {
            $targetTenantId = (int)$_SESSION['company_filter_id'];
        } else {
            $targetTenantId = $currentTenantId;
        }
    }
    if ($targetTenantId <= 0) {
        api_error('tenant_id richiesto', 400);
    }

    // Authorization check (cross-tenant membership)
    if (!$isSuperAdmin && $targetTenantId !== $currentTenantId) {
        $hasAccess = $db->fetchOne(
            "SELECT 1 FROM user_tenant_access
             WHERE user_id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$currentUserId, $targetTenantId]
        );
        if (!$hasAccess) {
            api_error('Accesso negato al tenant richiesto', 403);
        }
    }

    // Permission enforcement (per-tenant manage permission required)
    $shiftPerms = cnx_get_shift_permissions_for_tenant($db, $targetTenantId);
    $canManageShifts = $isSuperAdmin || in_array($currentUserRole, $shiftPerms['can_manage_shifts_roles'], true);
    if (!$canManageShifts) {
        api_error('Permessi insufficienti per gestire i turni', 403);
    }

    $startDate = trim((string)($data['start_date'] ?? ''));
    $endDate = trim((string)($data['end_date'] ?? ''));
    $shiftTypeId = (int)($data['shift_type_id'] ?? 0);
    $requiredPerDay = (int)($data['required_per_day'] ?? 1);
    $daysOfWeek = $data['days_of_week'] ?? null;

    if ($startDate === '' || $endDate === '') {
        api_error('start_date e end_date obbligatori', 400);
    }
    if ($shiftTypeId <= 0) {
        api_error('shift_type_id obbligatorio', 400);
    }
    if ($requiredPerDay <= 0 || $requiredPerDay > 10) {
        api_error('required_per_day non valido (1..10)', 400);
    }

    $startDt = DateTime::createFromFormat('Y-m-d', $startDate);
    $endDt = DateTime::createFromFormat('Y-m-d', $endDate);
    if (!$startDt || !$endDt || $startDt > $endDt) {
        api_error('Range date non valido', 400);
    }
    $interval = $startDt->diff($endDt);
    if ($interval->days > 93) {
        api_error('Range date massimo 3 mesi', 400);
    }

    $allowedDow = null;
    if (is_array($daysOfWeek)) {
        $allowedDow = [];
        foreach ($daysOfWeek as $d) {
            $di = (int)$d;
            if ($di >= 0 && $di <= 6) {
                $allowedDow[] = $di;
            }
        }
        $allowedDow = array_values(array_unique($allowedDow));
        if (count($allowedDow) === 0) {
            $allowedDow = null;
        }
    }

    // Verify shift type exists in tenant
    $shiftType = $db->fetchOne(
        "SELECT id, name, code FROM shift_types
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL AND is_active = 1",
        [$shiftTypeId, $targetTenantId]
    );
    if (!$shiftType) {
        api_error('Tipo turno non trovato o non attivo', 404);
    }

    // Candidate users
    $userIds = $data['user_ids'] ?? [];
    $candidateIds = [];
    if (is_array($userIds) && count($userIds) > 0) {
        foreach ($userIds as $uid) {
            $u = (int)$uid;
            if ($u > 0) $candidateIds[] = $u;
        }
        $candidateIds = array_values(array_unique($candidateIds));
    }

    // Detect user "active" column (status or is_active) and deleted_at presence
    $hasUsersDeletedAt = (bool)$db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'deleted_at'"
    );
    $hasUsersStatus = (bool)$db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'status'"
    );
    $hasUsersIsActive = (bool)$db->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'is_active'"
    );

    $userWhere = ["tenant_id = ?"];
    $userParams = [$targetTenantId];
    if ($hasUsersDeletedAt) {
        $userWhere[] = "deleted_at IS NULL";
    }
    if ($hasUsersStatus) {
        $userWhere[] = "status = 'active'";
    } elseif ($hasUsersIsActive) {
        $userWhere[] = "is_active = 1";
    }

    if (count($candidateIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
        $userWhere[] = "id IN ($placeholders)";
        $userParams = array_merge($userParams, $candidateIds);
    }

    $users = $db->fetchAll(
        "SELECT id, name
         FROM users
         WHERE " . implode(' AND ', $userWhere) . "
         ORDER BY name ASC, id ASC",
        $userParams
    );
    if (!$users || count($users) === 0) {
        api_error('Nessun utente disponibile per il bilanciamento', 400);
    }

    $candidates = [];
    foreach ($users as $u) {
        $candidates[(int)$u['id']] = (string)($u['name'] ?? ('User #' . $u['id']));
    }
    $candidateIds = array_keys($candidates);

    // Existing shifts in range (for balancing + collision detection)
    $existingRows = $db->fetchAll(
        "SELECT user_id, shift_date
         FROM work_shifts
         WHERE tenant_id = ?
           AND shift_type_id = ?
           AND deleted_at IS NULL
           AND shift_date >= ?
           AND shift_date <= ?",
        [$targetTenantId, $shiftTypeId, $startDate, $endDate]
    );

    $existingSet = [];
    $existingCounts = [];
    foreach ($existingRows as $r) {
        $uid = (int)$r['user_id'];
        $d = (string)$r['shift_date'];
        $existingSet[$uid . '|' . $d] = true;
        $existingCounts[$uid] = ($existingCounts[$uid] ?? 0) + 1;
    }

    // Generate dates list
    $dates = [];
    $cur = clone $startDt;
    while ($cur <= $endDt) {
        $dow = (int)$cur->format('w'); // 0=Sun..6=Sat
        if ($allowedDow === null || in_array($dow, $allowedDow, true)) {
            $dates[] = $cur->format('Y-m-d');
        }
        $cur->modify('+1 day');
    }

    // Hard limit suggestions (bulk_create max 100 per request; UI will chunk but keep preview bounded)
    $totalSlots = count($dates) * $requiredPerDay;
    if ($totalSlots > 300) {
        api_error('Troppe assegnazioni richieste: riduci range o required_per_day (max 300 slot)', 400);
    }

    $plannedCounts = [];
    $suggestions = [];
    $skippedSlots = 0;

    // Helper to pick best candidate (min total count)
    $pickCandidate = function(string $date) use (&$plannedCounts, $existingCounts, $candidateIds, $existingSet): ?int {
        $bestUid = null;
        $bestScore = null;
        foreach ($candidateIds as $uid) {
            $key = $uid . '|' . $date;
            if (isset($existingSet[$key])) {
                continue; // collision
            }
            $score = ($existingCounts[$uid] ?? 0) + ($plannedCounts[$uid] ?? 0);
            if ($bestUid === null || $score < $bestScore || ($score === $bestScore && $uid < $bestUid)) {
                $bestUid = $uid;
                $bestScore = $score;
            }
        }
        return $bestUid;
    };

    foreach ($dates as $date) {
        for ($i = 0; $i < $requiredPerDay; $i++) {
            $uid = $pickCandidate($date);
            if ($uid === null) {
                $skippedSlots++;
                continue;
            }
            $plannedCounts[$uid] = ($plannedCounts[$uid] ?? 0) + 1;
            $existingSet[$uid . '|' . $date] = true; // avoid duplicates within the same suggestion
            $suggestions[] = [
                'shift_date' => $date,
                'user_id' => $uid,
                'user_name' => $candidates[$uid] ?? ('User #' . $uid),
                'shift_type_id' => $shiftTypeId
            ];
        }
    }

    api_success([
        'tenant_id' => $targetTenantId,
        'shift_type' => [
            'id' => (int)$shiftType['id'],
            'name' => (string)$shiftType['name'],
            'code' => (string)($shiftType['code'] ?? '')
        ],
        'suggestions' => $suggestions,
        'stats' => [
            'days' => count($dates),
            'required_per_day' => $requiredPerDay,
            'total_slots' => $totalSlots,
            'suggested_count' => count($suggestions),
            'skipped_slots' => $skippedSlots,
            'existing_counts' => $existingCounts,
            'planned_counts' => $plannedCounts
        ]
    ], 'Suggerimento bilanciamento generato');
} catch (PDOException $e) {
    error_log('[Shifts Suggest API] PDO Error: ' . $e->getMessage());
    api_error('Errore database', 500);
} catch (Throwable $e) {
    error_log('[Shifts Suggest API] Error: ' . $e->getMessage());
    api_error('Errore interno del server', 500);
}

