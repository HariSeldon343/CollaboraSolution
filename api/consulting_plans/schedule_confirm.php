<?php
// Consulting Planning: confirm schedule proposal -> create real calendar events
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/calendar.php';

verifyApiCsrfToken();

try {
    $hasDrafts = $db->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consulting_plan_schedule_drafts' LIMIT 1");
    if (!$hasDrafts) {
        api_error(
            'Modulo calendario proposto non inizializzato: applica la migrazione database 35',
            503,
            ['migration' => 'database/migrations/35_consulting_activity_catalog_and_schedule.sql']
        );
    }
} catch (Throwable $e) {
    api_error('Database non disponibile per il calendario proposto', 503);
}

try {
    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) api_error('Dati non validi', 400);

    $planId = (int)($payload['plan_id'] ?? 0);
    if ($planId <= 0) api_error('plan_id obbligatorio', 400);

    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
    if (!$plan) api_error('Piano non trovato', 404);

    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    $rows = $db->fetchAll(
        "SELECT *
         FROM consulting_plan_schedule_drafts
         WHERE plan_id = ?
           AND deleted_at IS NULL
           AND status = 'draft'
         ORDER BY start_datetime ASC",
        [$planId]
    ) ?: [];

    if (empty($rows)) {
        api_error('Nessuno slot in bozza da confermare', 400);
    }

    // Enforce assignee before confirm (so every confirmed slot generates a task assigned to a user)
    $missingAssignee = [];
    foreach ($rows as $r) {
        $uid = $r['assigned_user_id'] !== null ? (int)$r['assigned_user_id'] : 0;
        if ($uid <= 0) {
            $missingAssignee[] = (int)($r['id'] ?? 0);
        }
    }
    $missingAssignee = array_values(array_filter($missingAssignee, static fn($v) => $v > 0));
    if (!empty($missingAssignee)) {
        api_error(
            'Assegna un consulente a tutti gli slot prima di confermare (colonna "Consulente")',
            400,
            ['missing_draft_ids' => $missingAssignee]
        );
    }

    $pdo = Database::getInstance()->getConnection();
    $apiUserId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    $calendar = new Calendar($pdo, CNX_VENDOR_TENANT_ID, $apiUserId);

    // Feature-detect tasks schema (schema drift safe)
    $tasksCols = [];
    $taskAssignmentsCols = [];
    $hasTaskAssignments = false;
    try {
        $tcols = $db->fetchAll("SHOW COLUMNS FROM tasks") ?: [];
        foreach ($tcols as $c) {
            if (!empty($c['Field'])) $tasksCols[(string)$c['Field']] = true;
        }
    } catch (Throwable $e) { $tasksCols = []; }
    try {
        $hasTaskAssignments = (bool)$db->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_assignments' LIMIT 1");
        if ($hasTaskAssignments) {
            $acols = $db->fetchAll("SHOW COLUMNS FROM task_assignments") ?: [];
            foreach ($acols as $c) {
                if (!empty($c['Field'])) $taskAssignmentsCols[(string)$c['Field']] = true;
            }
        }
    } catch (Throwable $e) { $hasTaskAssignments = false; $taskAssignmentsCols = []; }

    $clientNameRow = $db->fetchOne("SELECT COALESCE(denominazione, name) AS dn FROM tenants WHERE id = ? LIMIT 1", [$clientTenantId]);
    $clientName = (string)($clientNameRow['dn'] ?? ('Tenant #' . $clientTenantId));

    $createdEventIds = [];
    $createdTaskIds = [];
    $warnings = [];

    // NOTE: Do NOT wrap the whole loop in a DB transaction here.
    // Calendar->createEvent() manages its own transaction internally; nesting would break on PDO.
    foreach ($rows as $r) {
        $draftId = (int)$r['id'];
        $title = (string)$r['title'];
        $start = (string)$r['start_datetime'];
        $end = (string)$r['end_datetime'];
        $assignee = $r['assigned_user_id'] !== null ? (int)$r['assigned_user_id'] : null;

        try {
            $participants = [];
            if ($assignee && $assignee > 0) $participants[] = $assignee;
            if ($apiUserId > 0 && !in_array($apiUserId, $participants, true)) $participants[] = $apiUserId;

            $eventId = $calendar->createEvent([
                'title' => $title,
                'description' => "Cliente: {$clientName} (#{$clientTenantId})\nPiano: #{$planId}\n\n[Generato da Pianificazione S.CO]",
                'start_datetime' => $start,
                'end_datetime' => $end,
                'organizer_id' => $apiUserId,
                'participants' => $participants,
                'status' => 'confirmed',
                'check_conflicts' => true,
                'metadata' => [
                    'planning' => [
                        'plan_id' => $planId,
                        'client_tenant_id' => $clientTenantId,
                        'draft_id' => $draftId,
                        'kind' => (string)$r['kind'],
                    ],
                ],
            ]);

            $db->update('consulting_plan_schedule_drafts', [
                'status' => 'confirmed',
                'confirmed_event_id' => (int)$eventId,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $draftId]);

            $createdEventIds[] = (int)$eventId;

            // Create a task in tenant 28 for the confirmed slot (idempotent by marker in description)
            try {
                if (!empty($tasksCols) && $assignee && $assignee > 0) {
                    $marker = "planning: plan_id={$planId}, draft_id={$draftId}, event_id={$eventId}, client_tenant_id={$clientTenantId}";
                    $whereNotDeleted = isset($tasksCols['deleted_at']) ? " AND deleted_at IS NULL" : "";
                    $existingTask = $db->fetchOne(
                        "SELECT id FROM tasks WHERE tenant_id = ?{$whereNotDeleted} AND description LIKE ? LIMIT 1",
                        [CNX_VENDOR_TENANT_ID, '%' . $marker . '%']
                    );
                    if (!$existingTask) {
                        $taskTitle = "[Piano #{$planId}] " . $title;
                        if (mb_strlen($taskTitle) > 500) $taskTitle = mb_substr($taskTitle, 0, 500);
                        $taskDesc = "Cliente: {$clientName} (#{$clientTenantId})\nPiano: #{$planId}\nEvento calendario: #{$eventId}\nDraft: #{$draftId}\n\n{$marker}";

                        $insert = [
                            'tenant_id' => CNX_VENDOR_TENANT_ID,
                            'title' => $taskTitle,
                            'description' => $taskDesc,
                            'status' => 'todo',
                            'priority' => 'medium',
                            'created_by' => $apiUserId > 0 ? $apiUserId : ($assignee ?? 0),
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ];
                        if (isset($tasksCols['assigned_to'])) $insert['assigned_to'] = $assignee;
                        if (isset($tasksCols['progress_percentage'])) $insert['progress_percentage'] = 0;
                        if (isset($tasksCols['due_date'])) $insert['due_date'] = $start; // align due with scheduled start

                        // Filter by existing columns (schema drift safe)
                        $filtered = [];
                        foreach ($insert as $k => $v) {
                            if (isset($tasksCols[$k])) $filtered[$k] = $v;
                        }

                        $taskId = (int)$db->insert('tasks', $filtered);
                        if ($taskId > 0) {
                            $createdTaskIds[] = $taskId;

                            // Optional N:N assignment row
                            if ($hasTaskAssignments && !empty($taskAssignmentsCols)) {
                                $ainsert = [
                                    'tenant_id' => CNX_VENDOR_TENANT_ID,
                                    'task_id' => $taskId,
                                    'user_id' => $assignee,
                                    'assigned_by' => $apiUserId > 0 ? $apiUserId : $assignee,
                                ];
                                if (isset($taskAssignmentsCols['assigned_at'])) $ainsert['assigned_at'] = date('Y-m-d H:i:s');
                                if (isset($taskAssignmentsCols['created_at'])) $ainsert['created_at'] = date('Y-m-d H:i:s');
                                if (isset($taskAssignmentsCols['updated_at'])) $ainsert['updated_at'] = date('Y-m-d H:i:s');
                                if (isset($taskAssignmentsCols['deleted_at'])) $ainsert['deleted_at'] = null;
                                if (isset($taskAssignmentsCols['role'])) $ainsert['role'] = 'owner';

                                $af = [];
                                foreach ($ainsert as $k => $v) {
                                    if (isset($taskAssignmentsCols[$k])) $af[$k] = $v;
                                }
                                try { $db->insert('task_assignments', $af); } catch (Throwable $e) { /* ignore dup */ }
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // Best-effort: do not block calendar confirmation
            }
        } catch (Throwable $e) {
            error_log('[CONSULTING_SCHEDULE_CONFIRM] draft_id=' . $draftId . ' failed: ' . $e->getMessage());
            $warnings[] = "Draft #{$draftId}: " . $e->getMessage();
            // keep draft as 'draft' so user can retry/adjust
            continue;
        }
    }

    api_success([
        'plan_id' => $planId,
        'created_events_count' => count($createdEventIds),
        'created_event_ids' => $createdEventIds,
        'created_tasks_count' => count($createdTaskIds),
        'created_task_ids' => $createdTaskIds,
        'warnings' => array_slice($warnings, 0, 20),
    ], 'Proposta confermata: eventi creati');
} catch (Throwable $e) {
    error_log('[CONSULTING_SCHEDULE_CONFIRM] ' . $e->getMessage());
    api_error('Errore conferma proposta', 500);
}


