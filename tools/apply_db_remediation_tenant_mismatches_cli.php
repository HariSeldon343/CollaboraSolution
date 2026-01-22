<?php
/**
 * Tool: Apply DB remediation for tenant mismatches (CLI)
 *
 * Why:
 * - In Nexio, it's normal that a user (e.g. S.CO consultants) creates tasks/tickets/events in client tenants.
 * - The important integrity invariant is: if cross-tenant records exist, `user_tenant_access` should grant access.
 *
 * What it does (SAFE / idempotent):
 * - Creates/reactivates `user_tenant_access` rows for all (user_id, tenant_id) pairs detected from:
 *   tickets.created_by, tasks.created_by, tasks.assigned_to, events.organizer_id, calendars.owner_id
 *   where users.tenant_id <> record.tenant_id and user is not super_admin.
 * - Best-effort: fixes objectively broken reference events.calendar_id cross-tenant => set to NULL.
 *
 * Notes:
 * - CLI only (non-interactive).
 * - Does NOT rewrite tenant_id of existing records.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!(PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'CLI only']);
    exit;
}

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/db.php';

    $db = Database::getInstance();
    $conn = $db->getConnection();

    $tableExists = static function (string $table) use ($conn): bool {
        $stmt = $conn->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND TABLE_TYPE = 'BASE TABLE'
            LIMIT 1
        ");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    };

    $hasColumn = static function (string $table, string $col) use ($conn): bool {
        $stmt = $conn->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table, $col]);
        return (bool)$stmt->fetchColumn();
    };

    if (!$tableExists('user_tenant_access')) {
        echo json_encode(['success' => false, 'error' => 'Missing table user_tenant_access']);
        exit;
    }
    if (!$tableExists('users')) {
        echo json_encode(['success' => false, 'error' => 'Missing table users']);
        exit;
    }

    // actorId: best-effort pick a super_admin for granted_by (if column exists)
    $actorId = 0;
    try {
        $stmt = $conn->query("SELECT id FROM users WHERE role = 'super_admin' AND deleted_at IS NULL ORDER BY id ASC LIMIT 1");
        $actorId = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $actorId = 0;
    }

    $utaCols = [
        'deleted_at' => $hasColumn('user_tenant_access', 'deleted_at'),
        'granted_by' => $hasColumn('user_tenant_access', 'granted_by'),
        'granted_at' => $hasColumn('user_tenant_access', 'granted_at'),
        'tenant_role_id' => $hasColumn('user_tenant_access', 'tenant_role_id'),
    ];

    $utaActiveWhere = $utaCols['deleted_at'] ? "uta.deleted_at IS NULL" : "1=1";

    // Collect needed (user_id, tenant_id) pairs
    $pairsSql = "
        SELECT DISTINCT need.user_id, need.tenant_id
        FROM (
            SELECT DISTINCT t.created_by AS user_id, t.tenant_id AS tenant_id
            FROM tickets t
            INNER JOIN users u ON u.id = t.created_by AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = t.tenant_id AND tn.deleted_at IS NULL
            WHERE t.created_by IS NOT NULL
              AND u.role <> 'super_admin'
              AND u.tenant_id <> t.tenant_id
            UNION
            SELECT DISTINCT x.created_by AS user_id, x.tenant_id AS tenant_id
            FROM tasks x
            INNER JOIN users u ON u.id = x.created_by AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = x.tenant_id AND tn.deleted_at IS NULL
            WHERE x.created_by IS NOT NULL
              AND u.role <> 'super_admin'
              AND u.tenant_id <> x.tenant_id
            UNION
            SELECT DISTINCT x.assigned_to AS user_id, x.tenant_id AS tenant_id
            FROM tasks x
            INNER JOIN users u ON u.id = x.assigned_to AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = x.tenant_id AND tn.deleted_at IS NULL
            WHERE x.assigned_to IS NOT NULL
              AND u.role <> 'super_admin'
              AND u.tenant_id <> x.tenant_id
            UNION
            SELECT DISTINCT e.organizer_id AS user_id, e.tenant_id AS tenant_id
            FROM events e
            INNER JOIN users u ON u.id = e.organizer_id AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = e.tenant_id AND tn.deleted_at IS NULL
            WHERE e.organizer_id IS NOT NULL
              AND u.role <> 'super_admin'
              AND u.tenant_id <> e.tenant_id
            UNION
            SELECT DISTINCT c.owner_id AS user_id, c.tenant_id AS tenant_id
            FROM calendars c
            INNER JOIN users u ON u.id = c.owner_id AND u.deleted_at IS NULL
            INNER JOIN tenants tn ON tn.id = c.tenant_id AND tn.deleted_at IS NULL
            WHERE c.owner_id IS NOT NULL
              AND u.role <> 'super_admin'
              AND u.tenant_id <> c.tenant_id
        ) need
        WHERE NOT EXISTS (
            SELECT 1
            FROM user_tenant_access uta
            WHERE uta.user_id = need.user_id
              AND uta.tenant_id = need.tenant_id
              AND {$utaActiveWhere}
            LIMIT 1
        )
    ";

    $countMissing = static function () use ($conn, $pairsSql): int {
        $stmt = $conn->query("SELECT COUNT(*) FROM ({$pairsSql}) x");
        return (int)$stmt->fetchColumn();
    };

    $fixEventsCalendarCrossTenant = static function () use ($conn): int {
        // If calendar belongs to a different tenant, set calendar_id NULL (objectively broken)
        try {
            $stmt = $conn->prepare("
                UPDATE events e
                JOIN calendars c ON c.id = e.calendar_id
                SET e.calendar_id = NULL
                WHERE e.calendar_id IS NOT NULL
                  AND c.tenant_id <> e.tenant_id
            ");
            $stmt->execute();
            return (int)$stmt->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    };

    $ensureAccess = static function (int $userId, int $tenantId) use ($conn, $utaCols, $actorId, $utaActiveWhere): array {
        // 1) Active exists?
        $stmt = $conn->prepare("
            SELECT id
            FROM user_tenant_access uta
            WHERE uta.user_id = ?
              AND uta.tenant_id = ?
              AND {$utaActiveWhere}
            LIMIT 1
        ");
        $stmt->execute([$userId, $tenantId]);
        $existingId = $stmt->fetchColumn();
        if ($existingId !== false) {
            return ['action' => 'noop', 'id' => (int)$existingId];
        }

        // 2) Reactivate any soft-deleted row (if supported)
        if (!empty($utaCols['deleted_at'])) {
            $stmt = $conn->prepare("
                SELECT id
                FROM user_tenant_access
                WHERE user_id = ?
                  AND tenant_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $tenantId]);
            $anyId = $stmt->fetchColumn();
            if ($anyId !== false) {
                $updates = ["deleted_at = NULL"];
                $params = [];
                if (!empty($utaCols['granted_by']) && $actorId > 0) {
                    $updates[] = "granted_by = ?";
                    $params[] = $actorId;
                }
                if (!empty($utaCols['granted_at'])) {
                    $updates[] = "granted_at = NOW()";
                }
                $params[] = (int)$anyId;
                $sql = "UPDATE user_tenant_access SET " . implode(', ', $updates) . " WHERE id = ?";
                $u = $conn->prepare($sql);
                $u->execute($params);
                return ['action' => 'reactivate', 'id' => (int)$anyId];
            }
        }

        // 3) Insert new row
        $cols = ['user_id', 'tenant_id'];
        $placeholders = ['?', '?'];
        $values = [$userId, $tenantId];

        if (!empty($utaCols['tenant_role_id'])) {
            $cols[] = 'tenant_role_id';
            $placeholders[] = 'NULL';
        }
        if (!empty($utaCols['granted_by']) && $actorId > 0) {
            $cols[] = 'granted_by';
            $placeholders[] = '?';
            $values[] = $actorId;
        }
        if (!empty($utaCols['granted_at'])) {
            $cols[] = 'granted_at';
            $placeholders[] = 'NOW()';
        }
        if (!empty($utaCols['deleted_at'])) {
            $cols[] = 'deleted_at';
            $placeholders[] = 'NULL';
        }

        $sql = "INSERT INTO user_tenant_access (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $ins = $conn->prepare($sql);
        $ins->execute($values);
        return ['action' => 'insert', 'id' => (int)$conn->lastInsertId()];
    };

    $missingBefore = $countMissing();
    $eventsFixed = 0;
    $actions = ['insert' => 0, 'reactivate' => 0, 'noop' => 0];
    $details = [];

    $conn->beginTransaction();
    $eventsFixed = $fixEventsCalendarCrossTenant();

    // Load the actual missing pairs list
    $stmt = $conn->query($pairsSql);
    $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($pairs as $p) {
        $userId = (int)($p['user_id'] ?? 0);
        $tenantId = (int)($p['tenant_id'] ?? 0);
        if ($userId <= 0 || $tenantId <= 0) continue;
        $res = $ensureAccess($userId, $tenantId);
        $actions[$res['action']] = ($actions[$res['action']] ?? 0) + 1;
        $details[] = [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'action' => $res['action'],
            'id' => $res['id'] ?? null,
        ];
    }

    $conn->commit();

    $missingAfter = $countMissing();

    echo json_encode([
        'success' => true,
        'data' => [
            'actor_id' => $actorId,
            'missing_pairs_before' => $missingBefore,
            'missing_pairs_after' => $missingAfter,
            'events_calendar_cross_tenant_fixed_rows' => $eventsFixed,
            'uta_actions' => $actions,
            'details' => $details,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

