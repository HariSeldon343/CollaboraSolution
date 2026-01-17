<?php
/**
 * Consulting Planning: ensure/sync tasks for plan items (Tenant 28 internal tools)
 *
 * Goal:
 * - Each consulting_plan_item should have (at least) one linked task in tenant 28.
 * - Task should be assigned to the same assignee_user_id (when available).
 *
 * Idempotent:
 * - Re-running does not create duplicates; it reuses consulting_plan_task_links and/or a stable marker in task description.
 *
 * Schema-drift safe:
 * - If tasks tables are missing -> no 500; returns storage_available=false.
 * - If assignee_user_id column missing -> tasks are created unassigned (best-effort).
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

verifyApiCsrfToken();

/**
 * @return array<string,bool>
 */
function cnx_cols(Database $db, string $table): array {
    try {
        $rows = $db->fetchAll("SHOW COLUMNS FROM `$table`") ?: [];
        $out = [];
        foreach ($rows as $r) {
            if (!empty($r['Field'])) $out[(string)$r['Field']] = true;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function cnx_has_table(Database $db, string $table): bool {
    try {
        return (bool)$db->fetchOne(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1",
            [$table]
        );
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_has_col(Database $db, string $table, string $col): bool {
    try {
        return (bool)$db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$table, $col]
        );
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_task_marker(int $planId, int $itemId, int $clientTenantId): string {
    return "planning_item: plan_id={$planId}, item_id={$itemId}, client_tenant_id={$clientTenantId}";
}

/**
 * Build a conservative task title for a plan item (no ISO/UNI text).
 */
function cnx_task_title(int $planId, array $item): string {
    $type = strtoupper((string)($item['activity_type'] ?? 'ATTIVITÀ'));
    $desc = trim((string)($item['description'] ?? ''));
    $short = $desc !== '' ? preg_replace('/\s+/', ' ', $desc) : '';
    $short = is_string($short) ? trim($short) : '';
    if ($short !== '' && mb_strlen($short) > 80) $short = mb_substr($short, 0, 80) . '…';
    $base = "[Piano #{$planId}] {$type}";
    if ($short !== '') $base .= " — {$short}";
    if (mb_strlen($base) > 500) $base = mb_substr($base, 0, 500);
    return $base;
}

/**
 * Build a conservative task description for a plan item, including stable marker.
 */
function cnx_task_description(string $clientName, int $clientTenantId, int $planId, int $itemId, array $item, string $marker): string {
    $lines = [];
    $lines[] = "Cliente: {$clientName} (#{$clientTenantId})";
    $lines[] = "Piano: #{$planId}";
    $lines[] = "Attività: #{$itemId}";
    $lines[] = "Tipo: " . (string)($item['activity_type'] ?? '');
    if (!empty($item['activity_date'])) $lines[] = "Data: " . (string)$item['activity_date'];
    if (isset($item['days']) && (float)$item['days'] > 0) $lines[] = "Giornate: " . (string)$item['days'];
    if (isset($item['km']) && (float)$item['km'] > 0) $lines[] = "Km: " . (string)$item['km'];
    $note = trim((string)($item['description'] ?? ''));
    if ($note !== '') {
        $lines[] = "";
        $lines[] = "Note attività:";
        $lines[] = $note;
    }
    $lines[] = "";
    $lines[] = $marker;
    $out = implode("\n", $lines);
    if (mb_strlen($out) > 65000) $out = mb_substr($out, 0, 65000);
    return $out;
}

/**
 * Best-effort: ensure a task assignment row exists (and remove others) for a task.
 *
 * @param array<string,bool> $taskAssignmentsCols
 */
function cnx_sync_task_assignments(Database $db, array $taskAssignmentsCols, int $taskId, int $assigneeUserId, int $apiUserId): void {
    if ($taskId <= 0 || $assigneeUserId <= 0) return;
    $hasDeletedAt = !empty($taskAssignmentsCols['deleted_at']);

    try {
        if ($hasDeletedAt) {
            // Keep only one active assignee for planning-generated tasks (best-effort)
            $set = "deleted_at = NOW()";
            if (!empty($taskAssignmentsCols['updated_at'])) $set .= ", updated_at = NOW()";
            $db->query(
                "UPDATE task_assignments
                 SET {$set}
                 WHERE tenant_id = ?
                   AND task_id = ?
                   AND deleted_at IS NULL
                   AND user_id <> ?",
                [CNX_VENDOR_TENANT_ID, $taskId, $assigneeUserId]
            );
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Insert active assignment (ignore duplicate)
    $insert = [
        'tenant_id' => CNX_VENDOR_TENANT_ID,
        'task_id' => $taskId,
        'user_id' => $assigneeUserId,
        'assigned_by' => ($apiUserId > 0 ? $apiUserId : $assigneeUserId),
    ];
    if (!empty($taskAssignmentsCols['role'])) $insert['role'] = 'owner';
    if (!empty($taskAssignmentsCols['assigned_at'])) $insert['assigned_at'] = date('Y-m-d H:i:s');
    if (!empty($taskAssignmentsCols['created_at'])) $insert['created_at'] = date('Y-m-d H:i:s');
    if (!empty($taskAssignmentsCols['updated_at'])) $insert['updated_at'] = date('Y-m-d H:i:s');
    if (!empty($taskAssignmentsCols['deleted_at'])) $insert['deleted_at'] = null;

    $filtered = [];
    foreach ($insert as $k => $v) {
        if (!empty($taskAssignmentsCols[$k])) $filtered[$k] = $v;
    }
    try {
        $db->insert('task_assignments', $filtered);
    } catch (Throwable $e) {
        // likely duplicate
    }
}

try {
    $apiUserId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    if ($apiUserId <= 0) api_error('Utente non valido', 401);

    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) api_error('Dati non validi', 400);

    $planId = (int)($payload['plan_id'] ?? 0);
    if ($planId <= 0) api_error('plan_id obbligatorio', 400);

    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
    if (!$plan) api_error('Piano non trovato', 404);

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    // Feature-detect required tables
    $hasTasks = cnx_has_table($db, 'tasks');
    $hasLinks = cnx_has_table($db, 'consulting_plan_task_links');
    if (!$hasTasks || !$hasLinks) {
        api_success([
            'storage_available' => false,
            'plan_id' => $planId,
            'synced' => 0,
            'created' => 0,
            'updated' => 0,
            'warnings' => ['Task storage non disponibile: manca tabella tasks o consulting_plan_task_links'],
        ], 'Storage task non disponibile');
    }

    $tasksCols = cnx_cols($db, 'tasks');
    $hasTaskAssignments = cnx_has_table($db, 'task_assignments');
    $taskAssignmentsCols = $hasTaskAssignments ? cnx_cols($db, 'task_assignments') : [];

    $itemsCols = cnx_cols($db, 'consulting_plan_items');
    $hasAssigneeCol = !empty($itemsCols['assignee_user_id']);

    $limitItemIds = [];
    if (isset($payload['item_ids']) && is_array($payload['item_ids'])) {
        foreach ($payload['item_ids'] as $v) {
            $id = (int)$v;
            if ($id > 0) $limitItemIds[] = $id;
        }
        $limitItemIds = array_values(array_unique($limitItemIds));
    }

    $sql = "SELECT * FROM consulting_plan_items WHERE plan_id = ? AND deleted_at IS NULL";
    $params = [$planId];
    if (!empty($limitItemIds)) {
        $sql .= " AND id IN (" . implode(',', array_fill(0, count($limitItemIds), '?')) . ")";
        $params = array_merge($params, $limitItemIds);
    }
    $sql .= " ORDER BY id ASC";

    $items = $db->fetchAll($sql, $params) ?: [];
    if (empty($items)) {
        api_success([
            'storage_available' => true,
            'plan_id' => $planId,
            'synced' => 0,
            'created' => 0,
            'updated' => 0,
            'warnings' => [],
        ], 'Nessuna attività da sincronizzare');
    }

    $clientNameRow = $db->fetchOne("SELECT COALESCE(denominazione, name) AS dn FROM tenants WHERE id = ? LIMIT 1", [$clientTenantId]);
    $clientName = (string)($clientNameRow['dn'] ?? ('Tenant #' . $clientTenantId));

    $created = 0;
    $updated = 0;
    $synced = 0;
    $warnings = [];

    foreach ($items as $it) {
        $itemId = (int)($it['id'] ?? 0);
        if ($itemId <= 0) continue;

        $assignee = null;
        if ($hasAssigneeCol) {
            $uid = (int)($it['assignee_user_id'] ?? 0);
            $assignee = $uid > 0 ? $uid : null;
            if ($assignee === null) {
                $warnings[] = "Attività #{$itemId} senza consulente: task non assegnato";
            }
        }

        $marker = cnx_task_marker($planId, $itemId, $clientTenantId);

        // 1) Try existing link (primary = first)
        $taskRow = $db->fetchOne(
            "SELECT t.*
             FROM consulting_plan_task_links l
             JOIN tasks t ON t.id = l.task_id
             WHERE l.plan_item_id = ?
               AND t.tenant_id = ?
               AND " . (!empty($tasksCols['deleted_at']) ? "t.deleted_at IS NULL" : "1=1") . "
             ORDER BY l.id ASC
             LIMIT 1",
            [$itemId, CNX_VENDOR_TENANT_ID]
        );

        $taskId = $taskRow ? (int)($taskRow['id'] ?? 0) : 0;

        // 2) If missing, try find by marker (idempotent even if link missing)
        if ($taskId <= 0 && !empty($tasksCols)) {
            try {
                $whereNotDeleted = !empty($tasksCols['deleted_at']) ? " AND deleted_at IS NULL" : "";
                $byMarker = $db->fetchOne(
                    "SELECT id
                     FROM tasks
                     WHERE tenant_id = ?{$whereNotDeleted}
                       AND description LIKE ?
                     ORDER BY id ASC
                     LIMIT 1",
                    [CNX_VENDOR_TENANT_ID, '%' . $marker . '%']
                );
                $taskId = (int)($byMarker['id'] ?? 0);
            } catch (Throwable $e) {
                $taskId = 0;
            }
        }

        // 3) Create task if still missing
        $didCreate = false;
        if ($taskId <= 0) {
            if (empty($tasksCols)) {
                $warnings[] = "Impossibile leggere colonne tasks (schema non disponibile)";
                continue;
            }

            $title = cnx_task_title($planId, $it);
            $desc = cnx_task_description($clientName, $clientTenantId, $planId, $itemId, $it, $marker);

            $dueDate = null;
            $rawDate = trim((string)($it['activity_date'] ?? ''));
            if ($rawDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
                $dueDate = $rawDate . ' 23:59:59';
            }

            $insert = [
                'tenant_id' => CNX_VENDOR_TENANT_ID,
                'title' => $title,
                'description' => $desc,
                'status' => 'todo',
                'priority' => 'medium',
                'created_by' => $apiUserId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if (!empty($tasksCols['due_date'])) $insert['due_date'] = $dueDate;
            if (!empty($tasksCols['assigned_to'])) $insert['assigned_to'] = $assignee;
            if (!empty($tasksCols['progress_percentage'])) $insert['progress_percentage'] = 0;

            $filtered = [];
            foreach ($insert as $k => $v) {
                if (!empty($tasksCols[$k])) $filtered[$k] = $v;
            }

            $taskId = (int)$db->insert('tasks', $filtered);
            if ($taskId <= 0) {
                $warnings[] = "Creazione task fallita per attività #{$itemId}";
                continue;
            }
            $didCreate = true;

            // Link
            try {
                $db->insert('consulting_plan_task_links', [
                    'plan_item_id' => $itemId,
                    'task_id' => $taskId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $e) {
                // ignore
            }
        } else {
            // Ensure link exists
            try {
                $db->insert('consulting_plan_task_links', [
                    'plan_item_id' => $itemId,
                    'task_id' => $taskId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $e) {
                // ignore
            }
        }

        // 4) Best-effort update assignment / due_date / marker (non-destructive)
        $didUpdate = false;
        if ($taskId > 0 && !empty($tasksCols)) {
            $updates = [];

            if (!empty($tasksCols['assigned_to']) && $assignee !== null) {
                $curAssigned = $taskRow ? (int)($taskRow['assigned_to'] ?? 0) : null;
                if (!$curAssigned || (int)$curAssigned !== (int)$assignee) {
                    $updates['assigned_to'] = (int)$assignee;
                    $didUpdate = true;
                }
            }

            if (!empty($tasksCols['due_date'])) {
                $rawDate = trim((string)($it['activity_date'] ?? ''));
                $wantedDue = ($rawDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) ? ($rawDate . ' 23:59:59') : null;
                if ($wantedDue !== null) {
                    $curDue = $taskRow ? (string)($taskRow['due_date'] ?? '') : '';
                    if (trim($curDue) === '') {
                        $updates['due_date'] = $wantedDue;
                        $didUpdate = true;
                    }
                }
            }

            if (!empty($tasksCols['description'])) {
                $curDesc = $taskRow ? (string)($taskRow['description'] ?? '') : '';
                if (strpos($curDesc, $marker) === false) {
                    $updates['description'] = rtrim($curDesc) . "\n\n" . $marker;
                    $didUpdate = true;
                }
            }

            if ($didUpdate) {
                if (!empty($tasksCols['updated_at'])) $updates['updated_at'] = date('Y-m-d H:i:s');
                try {
                    $db->update('tasks', $updates, ['id' => $taskId]);
                } catch (Throwable $e) {
                    // ignore
                }
            }
        }

        // 5) Best-effort assignment table sync
        if ($hasTaskAssignments && !empty($taskAssignmentsCols) && $assignee !== null && $assignee > 0) {
            try { cnx_sync_task_assignments($db, $taskAssignmentsCols, $taskId, (int)$assignee, $apiUserId); } catch (Throwable $e) {}
        }

        $synced++;
        if ($didCreate) $created++;
        if ($didUpdate) $updated++;
    }

    api_success([
        'storage_available' => true,
        'plan_id' => $planId,
        'items_total' => count($items),
        'synced' => $synced,
        'created' => $created,
        'updated' => $updated,
        'warnings' => array_slice(array_values(array_unique(array_filter(array_map('strval', $warnings)))), 0, 30),
    ], 'Task sincronizzati');
} catch (Throwable $e) {
    error_log('[CONSULTING_TASKS_SYNC] ' . $e->getMessage());
    api_error('Errore sincronizzazione task', 500);
}

