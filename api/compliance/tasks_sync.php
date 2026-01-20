<?php
/**
 * Leva 3: Create/sync Action Plan tasks (and optional milestone events) for a compliance program.
 *
 * POST /api/compliance/tasks_sync.php
 *
 * Security:
 * - Requires auth + CSRF
 * - Requires tenant 28 gate (planning-triggered)
 * - Requires caller has access to client tenant (super_admin or user_tenant_access)
 *
 * Idempotent:
 * - Tasks: one link per artifact (via compliance_task_links)
 * - Events: one link per event_key per program (via compliance_event_links)
 *
 * Schema drift safe:
 * - If compliance tables missing -> 503 storage_available=false
 * - If tasks/events modules missing -> 503 with migration hint (best effort)
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/calendar.php';
require_once __DIR__ . '/../../includes/sql_migration_runner.php';

cnx_compliance_require_csrf_for_write();
cnx_compliance_require_tenant28_planning($userInfo);

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$programId = (int)($data['program_id'] ?? 0);
$consultingPlanId = (int)($data['consulting_plan_id'] ?? 0);
$options = is_array($data['options'] ?? null) ? $data['options'] : [];
$createTasks = (bool)($options['create_tasks'] ?? true);
$tasksRequested = $createTasks;
$tasksSkippedReason = null;
$planItemsCount = null;
$createCalendarEvents = (bool)($options['create_calendar_events'] ?? false);
$updateDueDates = (bool)($options['update_due_dates'] ?? false);

// Schema drift: compliance tables
$chk = cnx_compliance_check_tables($db, [
    'compliance_programs',
    'compliance_artifacts',
    'compliance_task_links',
    'compliance_event_links',
]);
if (!$chk['ok']) {
    // Best-effort auto-apply for super_admin (tenant 28 tools) to reduce drift friction in prod.
    $role = (string)($userInfo['role'] ?? 'user');
    if ($role === 'super_admin') {
        try {
            $pdo = $db->getConnection();
            // Apply base compliance tables (41) if needed
            $sql41 = __DIR__ . '/../../database/migrations/41_compliance_programs.sql';
            cnx_apply_sql_migration_file($pdo, $sql41);
            // Apply links tables (42) if needed
            $sql42 = __DIR__ . '/../../database/migrations/42_compliance_task_links.sql';
            cnx_apply_sql_migration_file($pdo, $sql42);
        } catch (Throwable $e) {
            // fall through to error below
        }
        $chk = cnx_compliance_check_tables($db, [
            'compliance_programs',
            'compliance_artifacts',
            'compliance_task_links',
            'compliance_event_links',
        ]);
    }
    if (!$chk['ok']) {
        api_error(
            'Modulo compliance non inizializzato: applica le migrazioni 41/42',
            503,
            ['storage_available' => false, 'missing' => $chk['missing'] ?? [], 'migration' => $chk['migration'] ?? null]
        );
    }
}

// Schema drift: tasks module must exist
try {
    $hasTasks = (bool)$db->fetchOne("SHOW TABLES LIKE 'tasks'");
    if (!$hasTasks) {
        api_error('Modulo task non inizializzato: applica migrazione task management', 503, [
            'migration' => 'database/migrations/task_management_schema.sql'
        ]);
    }
} catch (Throwable $e) {
    api_error('Database non disponibile per tasks', 503);
}

// Schema drift: events module needed only if create_calendar_events=true
if ($createCalendarEvents) {
    try {
        $hasEvents = (bool)$db->fetchOne("SHOW TABLES LIKE 'events'");
        if (!$hasEvents) {
            api_error('Modulo calendario non inizializzato: applica migrazioni calendario (events)', 503, [
                'migration' => 'database/migrations/11_rename_calendar_events_to_events.sql'
            ]);
        }
    } catch (Throwable $e) {
        api_error('Database non disponibile per calendario', 503);
    }
}

/**
 * @return array<string,bool>
 */
function cnx_get_columns_map(Database $db, string $table): array {
    $cols = [];
    try {
        $rows = $db->fetchAll("SHOW COLUMNS FROM `$table`");
        foreach ($rows ?: [] as $r) {
            if (!empty($r['Field'])) $cols[(string)$r['Field']] = true;
        }
    } catch (Throwable $e) {
        $cols = [];
    }
    return $cols;
}

function cnx_filter_by_columns(array $data, array $cols): array {
    if (empty($cols)) return $data;
    $out = [];
    foreach ($data as $k => $v) {
        if (isset($cols[$k])) $out[$k] = $v;
    }
    return $out;
}

function cnx_table_exists(Database $db, string $table): bool {
    try { return (bool)$db->fetchOne("SHOW TABLES LIKE ?", [$table]); } catch (Throwable $e) { return false; }
}

/**
 * @return array<int,string> list of "CODE: a,b,c"
 */
function cnx_format_clause_refs_for_task($clauseRefs): array {
    if (is_array($clauseRefs)) {
        // Object form: {"ISO9001":["7.5"],"ISO14001":["6.1.2"]}
        $isAssoc = array_keys($clauseRefs) !== range(0, count($clauseRefs) - 1);
        if ($isAssoc) {
            $out = [];
            foreach ($clauseRefs as $k => $v) {
                $code = trim((string)$k);
                if ($code === '') continue;
                $arr = is_array($v) ? array_values(array_map('strval', $v)) : [];
                $arr = array_values(array_unique(array_filter($arr)));
                if (!$arr) continue;
                $out[] = $code . ': ' . implode(', ', $arr);
            }
            return $out;
        }
        // Array form: ["7.5","9.2"]
        $arr = array_values(array_unique(array_filter(array_map('strval', $clauseRefs))));
        if (!$arr) return [];
        return [implode(', ', $arr)];
    }
    return [];
}

try {
    $actorUserId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    if ($actorUserId <= 0) api_error('Utente non valido', 401);

    // Resolve program
    if ($programId <= 0) {
        if ($consultingPlanId <= 0) {
            api_error('program_id o consulting_plan_id richiesto', 400);
        }
        $row = $db->fetchOne(
            "SELECT id
             FROM compliance_programs
             WHERE source_consulting_plan_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$consultingPlanId]
        );
        if (!$row) {
            api_error('Programma compliance non trovato: esegui prima Provisioning', 404);
        }
        $programId = (int)$row['id'];
    }

    $program = $db->fetchOne(
        "SELECT id, tenant_id, standard_code, standard_edition, source_consulting_plan_id
         FROM compliance_programs
         WHERE id = ?
         LIMIT 1",
        [$programId]
    );
    if (!$program) api_error('Programma non trovato', 404);

    $clientTenantId = (int)($program['tenant_id'] ?? 0);
    if ($clientTenantId <= 0) api_error('Programma non valido (tenant_id mancante)', 400);

    // Access check to client tenant (critical)
    if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $clientTenantId)) {
        api_error('Accesso negato al tenant cliente', 403);
    }

    // Planning/Wizard coherence:
    // If the consulting plan already has consulting_plan_items, skip creating action-plan tasks
    // (keeps provisioning idempotent and avoids confusing “double planning”).
    if ($tasksRequested && $consultingPlanId > 0) {
        try {
            $hasPlanItems = cnx_table_exists($db, 'consulting_plan_items');
            if ($hasPlanItems) {
                $hasDeletedAt = (bool)$db->fetchOne(
                    "SELECT 1
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'consulting_plan_items'
                       AND COLUMN_NAME = 'deleted_at'
                     LIMIT 1"
                );
                $sql = "SELECT COUNT(*) AS c FROM consulting_plan_items WHERE plan_id = ?" . ($hasDeletedAt ? " AND deleted_at IS NULL" : "");
                $r = $db->fetchOne($sql, [$consultingPlanId]);
                $planItemsCount = (int)($r['c'] ?? 0);
            }
        } catch (Throwable $e) {
            $planItemsCount = null;
        }
        if ($planItemsCount !== null && $planItemsCount > 0) {
            $createTasks = false;
            $tasksSkippedReason = 'already_present';
        }
    }

    // Load plan dates for milestones + due dates (best-effort, schema-drift safe)
    $planStart = null;
    $planEnd = null;
    $srcPlanId = (int)($program['source_consulting_plan_id'] ?? 0);
    if ($srcPlanId > 0) {
        try {
            $hasPlans = (bool)$db->fetchOne("SHOW TABLES LIKE 'consulting_plans'");
            if ($hasPlans) {
                $cols = cnx_get_columns_map($db, 'consulting_plans');
                $startCol = isset($cols['period_start']) ? 'period_start' : (isset($cols['start_date']) ? 'start_date' : null);
                $endCol = isset($cols['period_end']) ? 'period_end' : (isset($cols['end_date']) ? 'end_date' : null);
                $sel = "SELECT " . ($startCol ? $startCol . " AS start_v" : "NULL AS start_v") . ", " . ($endCol ? $endCol . " AS end_v" : "NULL AS end_v") . " FROM consulting_plans WHERE id = ? LIMIT 1";
                $p = $db->fetchOne($sel, [$srcPlanId]);
                $planStart = !empty($p['start_v']) ? (string)$p['start_v'] : null;
                $planEnd = !empty($p['end_v']) ? (string)$p['end_v'] : null;
            }
        } catch (Throwable $e) {}
    }

    // Create tasks for artifacts
    $tasksCols = cnx_get_columns_map($db, 'tasks');
    $hasTaskHistory = false;
    try { $hasTaskHistory = (bool)$db->fetchOne("SHOW TABLES LIKE 'task_history'"); } catch (Throwable $e) { $hasTaskHistory = false; }
    $historyCols = $hasTaskHistory ? cnx_get_columns_map($db, 'task_history') : [];

    $created = 0;
    $existing = 0;
    $taskIds = [];
    $errors = [];

    $artifacts = $db->fetchAll(
        "SELECT id, title, artifact_type, clause_refs_json, file_id, due_date
         FROM compliance_artifacts
         WHERE program_id = ?
         ORDER BY id ASC",
        [$programId]
    ) ?: [];

    // Compute due dates distribution (document-first)
    $now = new DateTime('now');
    $baseStart = $planStart ? new DateTime($planStart) : clone $now;
    $baseEnd = $planEnd ? new DateTime($planEnd) : (new DateTime('now +90 days'));
    if ($baseEnd < $baseStart) {
        $baseStart = clone $now;
        $baseEnd = new DateTime('now +90 days');
    }
    $total = count($artifacts);
    $dueByArtifactId = [];
    if ($total > 0) {
        // Sort artifacts by type weight then title
        $typeWeight = static function (string $t): int {
            $t = strtolower(trim($t));
            if (in_array($t, ['manual','policy'], true)) return 10;
            if (in_array($t, ['procedure','plan'], true)) return 20;
            if ($t === 'instruction') return 30;
            if (in_array($t, ['register','record','form'], true)) return 40;
            return 50;
        };
        $sorted = $artifacts;
        usort($sorted, static function ($a, $b) use ($typeWeight) {
            $wa = $typeWeight((string)($a['artifact_type'] ?? ''));
            $wb = $typeWeight((string)($b['artifact_type'] ?? ''));
            if ($wa !== $wb) return $wa <=> $wb;
            return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        });

        $durationSeconds = max(0, $baseEnd->getTimestamp() - $baseStart->getTimestamp());
        $idx = 0;
        foreach ($sorted as $a) {
            $idx++;
            $artifactId = (int)($a['id'] ?? 0);
            if ($artifactId <= 0) continue;
            $ratio = $total > 0 ? ($idx / $total) : 1.0;
            $ts = (int)round($baseStart->getTimestamp() + ($durationSeconds * $ratio));
            $d = (new DateTime())->setTimestamp($ts);
            $d->setTime(18, 0, 0);
            if ($d > $baseEnd) $d = (clone $baseEnd)->setTime(18, 0, 0);
            $dueByArtifactId[$artifactId] = $d->format('Y-m-d H:i:s');
        }
    }

    if ($createTasks) foreach ($artifacts as $a) {
        $artifactId = (int)($a['id'] ?? 0);
        if ($artifactId <= 0) continue;

        $link = $db->fetchOne(
            "SELECT task_id
             FROM compliance_task_links
             WHERE artifact_id = ?
             ORDER BY id ASC
             LIMIT 1",
            [$artifactId]
        );
        if ($link && !empty($link['task_id'])) {
            $existing++;
            continue;
        }

        $title = trim((string)($a['title'] ?? 'Deliverable'));
        $clauseRefs = null;
        try { $clauseRefs = $a['clause_refs_json'] ? (json_decode((string)$a['clause_refs_json'], true) ?: null) : null; } catch (Throwable $e) { $clauseRefs = null; }
        $fileId = (int)($a['file_id'] ?? 0);
        $dueDate = $a['due_date'] ?? null;
        $computedDue = $dueByArtifactId[$artifactId] ?? null;
        if ($updateDueDates && $computedDue) {
            $dueDate = $computedDue;
        } elseif (!$dueDate && $computedDue) {
            $dueDate = $computedDue;
        }

        $descLines = [];
        $descLines[] = "Deliverable IMS (document-first): " . $title;
        $refsLines = cnx_format_clause_refs_for_task($clauseRefs);
        if (!empty($refsLines)) {
            $descLines[] = "Clause refs: " . implode(' | ', $refsLines);
        }
        if ($fileId > 0) {
            $descLines[] = "Documento: files.php?open_file_id=" . $fileId . "&open_mode=edit";
        }
        $descLines[] = "";
        $descLines[] = "Nota: documento placeholder (senza testo norma).";
        $description = implode("\n", $descLines);

        $db->beginTransaction();
        try {
            $insert = [
                'tenant_id' => $clientTenantId,
                'title' => "[IMS] " . $title,
                'description' => $description,
                'status' => 'todo',
                'priority' => 'medium',
                'due_date' => $dueDate,
                'assigned_to' => null,
                'created_by' => $actorUserId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $taskId = (int)$db->insert('tasks', cnx_filter_by_columns($insert, $tasksCols));
            if ($taskId <= 0) throw new Exception('Insert task failed');

            // Best-effort task_history
            if ($hasTaskHistory) {
                $hist = [
                    'tenant_id' => $clientTenantId,
                    'task_id' => $taskId,
                    'user_id' => $actorUserId,
                    'action' => 'created',
                    'field_name' => null,
                    'old_value' => null,
                    'new_value' => json_encode(['title' => $insert['title'], 'status' => 'todo'], JSON_UNESCAPED_UNICODE),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                try { $db->insert('task_history', cnx_filter_by_columns($hist, $historyCols)); } catch (Throwable $e) {}
            }

            $db->insert('compliance_task_links', [
                'artifact_id' => $artifactId,
                'tenant_id' => $clientTenantId,
                'task_id' => $taskId,
                'task_type' => 'draft',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Persist due_date back to artifacts (best-effort)
            if ($updateDueDates && $dueDate) {
                try {
                    $db->update('compliance_artifacts', [
                        'due_date' => $dueDate,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], ['id' => $artifactId]);
                } catch (Throwable $e) {}
            }

            $db->commit();
            $created++;
            $taskIds[] = $taskId;
        } catch (Throwable $e) {
            $db->rollback();
            $errors[] = "artifact_id={$artifactId}: " . $e->getMessage();
        }
    }

    // Optional: create milestone events (best-effort, non-blocking)
    $eventsCreated = 0;
    $eventsExisting = 0;
    $eventErrors = [];

    if ($createCalendarEvents) {
        $pdo = $db->getConnection();
        $cal = new Calendar($pdo, $clientTenantId, $actorUserId);

        $baseStart = $planStart ? new DateTime($planStart) : (new DateTime('now'));
        $baseEnd = $planEnd ? new DateTime($planEnd) : (new DateTime('now +90 days'));

        $milestones = [
            ['key' => 'IMS_MILESTONE_KICKOFF', 'title' => 'Kickoff IMS', 'when' => clone $baseStart],
            ['key' => 'IMS_MILESTONE_INTERNAL_AUDIT', 'title' => 'Audit interno (milestone)', 'when' => (clone $baseStart)->modify('+45 days')],
            ['key' => 'IMS_MILESTONE_MGMT_REVIEW', 'title' => 'Riesame di direzione (milestone)', 'when' => (clone $baseEnd)->modify('-14 days')],
            ['key' => 'IMS_MILESTONE_CERTIFICATION', 'title' => 'Audit esterno / certificazione (milestone)', 'when' => clone $baseEnd],
        ];

        foreach ($milestones as $m) {
            $eventKey = (string)$m['key'];
            $existingLink = $db->fetchOne(
                "SELECT event_id FROM compliance_event_links WHERE program_id = ? AND event_key = ? LIMIT 1",
                [$programId, $eventKey]
            );
            if ($existingLink && !empty($existingLink['event_id'])) {
                $eventsExisting++;
                continue;
            }

            try {
                $start = $m['when'];
                $start->setTime(9, 0, 0);
                $end = (clone $start)->modify('+60 minutes');

                $eventId = $cal->createEvent([
                    'title' => '[IMS] ' . (string)$m['title'],
                    'start_datetime' => $start->format('Y-m-d H:i:s'),
                    'end_datetime' => $end->format('Y-m-d H:i:s'),
                    'description' => "Milestone generata da Action Plan IMS.\nProgram ID: {$programId}\nEvent key: {$eventKey}",
                    'metadata' => [
                        'compliance_program_id' => $programId,
                        'event_key' => $eventKey,
                        'standard_code' => 'IMS',
                    ],
                    'check_conflicts' => false,
                    'visibility' => 'private',
                    'status' => 'confirmed',
                ]);

                $db->insert('compliance_event_links', [
                    'program_id' => $programId,
                    'tenant_id' => $clientTenantId,
                    'event_id' => $eventId,
                    'event_key' => $eventKey,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                $eventsCreated++;
            } catch (Throwable $e) {
                $eventErrors[] = $eventKey . ': ' . $e->getMessage();
            }
        }
    }

    $status = (empty($errors) && empty($eventErrors)) ? 'ok' : 'partial';

    api_success([
        'storage_available' => true,
        'status' => $status,
        'program_id' => $programId,
        'client_tenant_id' => $clientTenantId,
        'tasks_requested' => $tasksRequested,
        'tasks_skipped_reason' => $tasksSkippedReason,
        'plan_items_count' => $planItemsCount,
        'created' => [
            'tasks' => $created,
            'events' => $eventsCreated,
        ],
        'reused' => [
            'tasks' => $existing,
            'events' => $eventsExisting,
        ],
        'counts' => [
            'tasks_created' => $created,
            'tasks_existing' => $existing,
            'events_created' => $eventsCreated,
            'events_existing' => $eventsExisting,
        ],
        'task_ids' => $taskIds,
        'errors' => [
            'tasks' => $errors,
            'events' => $eventErrors,
        ],
        'options' => [
            'update_due_dates' => $updateDueDates,
            'create_tasks' => $createTasks,
            'create_calendar_events' => $createCalendarEvents,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[COMPLIANCE_TASKS_SYNC] ' . $e->getMessage());
    api_error('Errore creazione Action Plan', 500, defined('DEBUG_MODE') && DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
}

