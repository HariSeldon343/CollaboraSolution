<?php
// POST: ensure a task in tenant 28 and link it to a plan item (idempotent)
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

function cnx_task_marker(int $planId, int $itemId, int $clientTenantId): string {
    return "planning_item: plan_id={$planId}, item_id={$itemId}, client_tenant_id={$clientTenantId}";
}

try {
    $data = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($data)) api_error('Dati non validi', 400);

    $planItemId = (int)($data['plan_item_id'] ?? 0);
    if ($planItemId <= 0) api_error('plan_item_id obbligatorio', 400);

    $item = $db->fetchOne("SELECT * FROM consulting_plan_items WHERE id = ? AND deleted_at IS NULL", [$planItemId]);
    if (!$item) api_error('Attività non trovata', 404);

    $planId = (int)($item['plan_id'] ?? 0);
    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
    if (!$plan) api_error('Piano non trovato', 404);

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    if (!cnx_has_table($db, 'tasks') || !cnx_has_table($db, 'consulting_plan_task_links')) {
        api_error('Modulo task non disponibile (mancano tabelle)', 503);
    }

    $tasksCols = cnx_cols($db, 'tasks');
    $hasTaskAssignments = cnx_has_table($db, 'task_assignments');
    $taskAssignmentsCols = $hasTaskAssignments ? cnx_cols($db, 'task_assignments') : [];

    $createdBy = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    if ($createdBy <= 0) api_error('Utente non valido', 400);

    $assignee = null;
    if (array_key_exists('assignee_user_id', $item)) {
        $uid = (int)($item['assignee_user_id'] ?? 0);
        $assignee = $uid > 0 ? $uid : null;
    }

    $marker = cnx_task_marker($planId, $planItemId, $clientTenantId);

    // Try existing link (primary = first)
    $taskRow = $db->fetchOne(
        "SELECT t.*
         FROM consulting_plan_task_links l
         JOIN tasks t ON t.id = l.task_id
         WHERE l.plan_item_id = ?
           AND t.tenant_id = ?
           AND " . (!empty($tasksCols['deleted_at']) ? "t.deleted_at IS NULL" : "1=1") . "
         ORDER BY l.id ASC
         LIMIT 1",
        [$planItemId, CNX_VENDOR_TENANT_ID]
    );
    $taskId = $taskRow ? (int)($taskRow['id'] ?? 0) : 0;

    // If no link, try marker
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

    $created = false;

    if ($taskId <= 0) {
        $clientName = $db->fetchOne("SELECT COALESCE(denominazione, name) AS n FROM tenants WHERE id = ? AND deleted_at IS NULL", [$clientTenantId]);
        $clientLabel = (string)($clientName['n'] ?? ('Tenant #' . $clientTenantId));

        $type = strtoupper((string)($item['activity_type'] ?? 'ATTIVITÀ'));
        $short = trim((string)($item['description'] ?? ''));
        $short = preg_replace('/\s+/', ' ', $short) ?? $short;
        $short = trim((string)$short);
        if ($short !== '' && mb_strlen($short) > 80) $short = mb_substr($short, 0, 80) . '…';
        $title = "[Piano #{$planId}] {$type}" . ($short !== '' ? " — {$short}" : '');
        if (mb_strlen($title) > 500) $title = mb_substr($title, 0, 500);

        $lines = [];
        $lines[] = "Cliente: {$clientLabel} (#{$clientTenantId})";
        $lines[] = "Piano: #{$planId}";
        $lines[] = "Attività: #{$planItemId}";
        $lines[] = "Tipo: " . (string)($item['activity_type'] ?? '');
        if (!empty($item['activity_date'])) $lines[] = 'Data: ' . (string)$item['activity_date'];
        if ((float)($item['days'] ?? 0) > 0) $lines[] = 'Giornate: ' . (string)$item['days'];
        if ((float)($item['km'] ?? 0) > 0) $lines[] = 'Km: ' . (string)$item['km'];
        if (!empty($item['description'])) {
            $lines[] = "";
            $lines[] = "Note attività:";
            $lines[] = (string)$item['description'];
        }
        $lines[] = "";
        $lines[] = $marker;
        $description = implode("\n", $lines);

        $dueDate = null;
        $rawDate = trim((string)($item['activity_date'] ?? ''));
        if ($rawDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
            $dueDate = $rawDate . ' 23:59:59';
        }

        $insert = [
            'tenant_id' => CNX_VENDOR_TENANT_ID,
            'title' => $title,
            'description' => $description,
            'status' => 'todo',
            'priority' => 'medium',
            'created_by' => $createdBy,
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

        $db->beginTransaction();
        try {
            $taskId = (int)$db->insert('tasks', $filtered);
            if ($taskId <= 0) throw new Exception('Task insert fallito');
            $created = true;

            try {
                $db->insert('consulting_plan_task_links', [
                    'plan_item_id' => $planItemId,
                    'task_id' => $taskId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $e) {
                // ignore
            }

            $db->commit();
        } catch (Throwable $tx) {
            $db->rollBack();
            throw $tx;
        }
    } else {
        // Ensure link exists
        try {
            $db->insert('consulting_plan_task_links', [
                'plan_item_id' => $planItemId,
                'task_id' => $taskId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Best-effort: keep assignment in sync
    if ($taskId > 0 && !empty($tasksCols) && $assignee !== null && $assignee > 0) {
        try {
            $upd = [];
            if (!empty($tasksCols['assigned_to'])) $upd['assigned_to'] = (int)$assignee;
            if (!empty($tasksCols['updated_at'])) $upd['updated_at'] = date('Y-m-d H:i:s');
            if (!empty($tasksCols['description']) && $taskRow) {
                $curDesc = (string)($taskRow['description'] ?? '');
                if (strpos($curDesc, $marker) === false) {
                    $upd['description'] = rtrim($curDesc) . "\n\n" . $marker;
                }
            }
            if (!empty($upd)) $db->update('tasks', $upd, ['id' => $taskId]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Best-effort: N:N assignment table (keep one active)
    if ($taskId > 0 && $hasTaskAssignments && !empty($taskAssignmentsCols) && $assignee !== null && $assignee > 0) {
        try {
            if (!empty($taskAssignmentsCols['deleted_at'])) {
                $set = "deleted_at = NOW()";
                if (!empty($taskAssignmentsCols['updated_at'])) $set .= ", updated_at = NOW()";
                $db->query(
                    "UPDATE task_assignments
                     SET {$set}
                     WHERE tenant_id = ?
                       AND task_id = ?
                       AND deleted_at IS NULL
                       AND user_id <> ?",
                    [CNX_VENDOR_TENANT_ID, $taskId, (int)$assignee]
                );
            }
            $ainsert = [
                'tenant_id' => CNX_VENDOR_TENANT_ID,
                'task_id' => $taskId,
                'user_id' => (int)$assignee,
                'assigned_by' => ($createdBy > 0 ? $createdBy : (int)$assignee),
            ];
            if (!empty($taskAssignmentsCols['role'])) $ainsert['role'] = 'owner';
            if (!empty($taskAssignmentsCols['assigned_at'])) $ainsert['assigned_at'] = date('Y-m-d H:i:s');
            if (!empty($taskAssignmentsCols['created_at'])) $ainsert['created_at'] = date('Y-m-d H:i:s');
            if (!empty($taskAssignmentsCols['updated_at'])) $ainsert['updated_at'] = date('Y-m-d H:i:s');
            if (!empty($taskAssignmentsCols['deleted_at'])) $ainsert['deleted_at'] = null;
            $af = [];
            foreach ($ainsert as $k => $v) {
                if (!empty($taskAssignmentsCols[$k])) $af[$k] = $v;
            }
            try { $db->insert('task_assignments', $af); } catch (Throwable $e) { /* ignore dup */ }
        } catch (Throwable $e) {
            // ignore
        }
    }

    api_success(['task_id' => $taskId, 'created' => $created], $created ? 'Task creato e collegato' : 'Task già presente (sincronizzato)');
} catch (Throwable $e) {
    error_log('[CONSULTING_CREATE_TASK] ' . $e->getMessage());
    api_error('Errore creazione/sync task', 500);
}

