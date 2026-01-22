<?php
/**
 * AI Data Collection Assistant (Checklist + RAG)
 *
 * Endpoints:
 * - action=start_session (POST)
 * - action=send (POST)
 * - action=history (GET)
 * - action=close_session (POST)
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/tenant28_access_check.php';
require_once __DIR__ . '/../../includes/openai_client.php';
require_once __DIR__ . '/../../includes/ai/providers_registry.php';
require_once __DIR__ . '/../../includes/ai/perplexity_client.php';
require_once __DIR__ . '/../../includes/ai/knowledge_indexer.php';
require_once __DIR__ . '/../../includes/file_access.php';

cnx_ai_require_csrf_for_write();

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($_GET['action'] ?? $payload['action'] ?? '');
$action = strtolower(trim($action));
if ($action === '') api_error('action richiesto', 400);

function cnx_assistant_has_table(Database $db, string $table): bool {
    try {
        return (bool)$db->fetchOne(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1",
            [$table]
        );
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_assistant_check_storage(Database $db): array {
    $missing = [];
    foreach (['ai_assistant_sessions', 'ai_assistant_messages'] as $t) {
        if (!cnx_assistant_has_table($db, $t)) $missing[] = $t;
    }
    if (!empty($missing)) {
        return ['ok' => false, 'missing' => $missing, 'migration' => 'database/migrations/66_ai_assistant_sessions.sql'];
    }
    return ['ok' => true];
}

function cnx_assistant_allowed_statuses(): array {
    return ['missing','present','to_review','done','not_applicable'];
}

function cnx_assistant_json_stringify($v, int $maxLen = 20000): ?string {
    if ($v === null) return null;
    if (is_string($v)) {
        $s = trim($v);
        if ($s === '') return null;
        if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
        return $s;
    }
    $s = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($s) || trim($s) === '') return null;
    if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
    return $s;
}

function cnx_assistant_require_t28(array $userInfo): void {
    $t28 = cnxCheckTenant28Access($userInfo);
    if (!$t28['ok']) api_error($t28['reason'], 403);
}

function cnx_assistant_is_client_allowed(Database $db, array $userInfo, int $clientTenantId): bool {
    if ($clientTenantId <= 0 || $clientTenantId === CNX_VENDOR_TENANT_ID) {
        return false;
    }
    $role = (string)($userInfo['role'] ?? 'user');
    if ($role === 'super_admin') {
        return true;
    }
    // Admin: must have explicit access via user_tenant_access or primary tenant
    $primaryTenantId = (int)($userInfo['tenant_id'] ?? 0);
    if ($primaryTenantId === $clientTenantId) return true;

    $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    if ($userId <= 0) return false;

    try {
        $utaHasDeletedAt = $db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_tenant_access'
               AND COLUMN_NAME = 'deleted_at'
             LIMIT 1"
        );
        $utaWhere = $utaHasDeletedAt ? " AND deleted_at IS NULL" : "";
        $has = $db->fetchOne(
            "SELECT 1
             FROM user_tenant_access
             WHERE user_id = ?
               AND tenant_id = ?" . $utaWhere . "
             LIMIT 1",
            [$userId, $clientTenantId]
        );
        return (bool)$has;
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_assistant_log_message(Database $db, int $sessionId, string $role, string $content, $meta = null): void {
    $role = strtolower(trim($role));
    if (!in_array($role, ['user','assistant','system'], true)) $role = 'assistant';
    $content = trim($content);
    if ($content === '') return;
    $metaJson = cnx_assistant_json_stringify($meta, 60000);
    $db->insert('ai_assistant_messages', [
        'session_id' => $sessionId,
        'role' => $role,
        'content' => $content,
        'meta_json' => $metaJson,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function cnx_assistant_load_session(Database $db, int $sessionId): ?array {
    if ($sessionId <= 0) return null;
    $row = $db->fetchOne(
        "SELECT * FROM ai_assistant_sessions WHERE id = ? LIMIT 1",
        [$sessionId]
    );
    return $row ?: null;
}

function cnx_assistant_require_session_access(Database $db, array $userInfo, array $session): void {
    cnx_assistant_require_t28($userInfo);
    $clientTenantId = (int)($session['client_tenant_id'] ?? 0);
    if ($clientTenantId > 0 && !cnx_assistant_is_client_allowed($db, $userInfo, $clientTenantId)) {
        api_error('Accesso negato al tenant cliente', 403);
    }
}

function cnx_assistant_apply_actions(Database $db, array $userInfo, array $session, array $actions, array $citations = []): array {
    $applied = [];
    $updatedItems = [];

    if (empty($actions)) {
        return ['applied' => [], 'updated_items' => []];
    }

    $hasEvidenceTable = cnx_assistant_has_table($db, 'consulting_project_checklist_evidence');
    $allowedStatuses = cnx_assistant_allowed_statuses();

    foreach ($actions as $raw) {
        if (!is_array($raw)) continue;
        $type = strtolower(trim((string)($raw['type'] ?? '')));
        if ($type === '') continue;

        if ($type === 'update_item') {
            $itemId = (int)($raw['item_id'] ?? 0);
            if ($itemId <= 0) continue;

            $row = $db->fetchOne(
                "SELECT i.*, c.plan_id, c.client_tenant_id, c.id AS checklist_id, c.tenant_id AS checklist_tenant_id
                 FROM consulting_project_checklist_items i
                 JOIN consulting_project_checklists c ON c.id = i.checklist_id
                 WHERE i.id = ?
                 LIMIT 1",
                [$itemId]
            );
            if (!$row) continue;

            if ((int)($row['checklist_tenant_id'] ?? 0) !== CNX_VENDOR_TENANT_ID) continue;
            $clientTenantId = (int)($row['client_tenant_id'] ?? 0);
            if ($clientTenantId <= 0 || !cnx_assistant_is_client_allowed($db, $userInfo, $clientTenantId)) continue;

            // Enforce session scope
            if (!empty($session['checklist_id']) && (int)$session['checklist_id'] !== (int)$row['checklist_id']) continue;
            if (!empty($session['plan_id']) && (int)$session['plan_id'] !== (int)$row['plan_id']) continue;

            $upd = [];
            $status = strtolower(trim((string)($raw['status'] ?? '')));
            if ($status !== '' && in_array($status, $allowedStatuses, true)) {
                $upd['status'] = $status;
            }
            if (array_key_exists('answer_text', $raw)) {
                $ans = trim((string)($raw['answer_text'] ?? ''));
                if ($ans === '') $ans = null;
                if ($ans !== null && mb_strlen($ans, 'UTF-8') > 20000) {
                    $ans = mb_substr($ans, 0, 20000, 'UTF-8');
                }
                $upd['answer_text'] = $ans;
            }
            if (!empty($upd)) {
                $upd['updated_at'] = date('Y-m-d H:i:s');
                $db->update('consulting_project_checklist_items', $upd, ['id' => $itemId]);
                $updated = $db->fetchOne("SELECT * FROM consulting_project_checklist_items WHERE id = ? LIMIT 1", [$itemId]);
                if ($updated) $updatedItems[] = $updated;
                $applied[] = ['type' => 'update_item', 'item_id' => $itemId];
            }
        } elseif ($type === 'add_evidence') {
            $itemId = (int)($raw['item_id'] ?? 0);
            $fileId = (int)($raw['file_id'] ?? 0);
            if ($itemId <= 0 || $fileId <= 0) continue;

            $row = $db->fetchOne(
                "SELECT i.*, c.plan_id, c.client_tenant_id, c.id AS checklist_id, c.tenant_id AS checklist_tenant_id
                 FROM consulting_project_checklist_items i
                 JOIN consulting_project_checklists c ON c.id = i.checklist_id
                 WHERE i.id = ?
                 LIMIT 1",
                [$itemId]
            );
            if (!$row) continue;
            if ((int)($row['checklist_tenant_id'] ?? 0) !== CNX_VENDOR_TENANT_ID) continue;
            $clientTenantId = (int)($row['client_tenant_id'] ?? 0);
            if ($clientTenantId <= 0 || !cnx_assistant_is_client_allowed($db, $userInfo, $clientTenantId)) continue;

            if (!empty($session['checklist_id']) && (int)$session['checklist_id'] !== (int)$row['checklist_id']) continue;
            if (!empty($session['plan_id']) && (int)$session['plan_id'] !== (int)$row['plan_id']) continue;

            $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
            $userRole = (string)($userInfo['role'] ?? 'user');
            try {
                $acc = hasFileOrFolderAccess($db, $fileId, $userId, $userRole, $clientTenantId);
                if (empty($acc['has_access'])) continue;
            } catch (Throwable $e) {
                continue;
            }

            $file = $db->fetchOne(
                "SELECT id, name, file_path
                 FROM files
                 WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL AND is_folder = 0
                 LIMIT 1",
                [$fileId, $clientTenantId]
            );
            if (!$file) continue;

            $fileName = (string)($file['name'] ?? '');
            $filePath = (string)($file['file_path'] ?? '');

            if ($hasEvidenceTable) {
                $meta = [
                    'source' => 'assistant',
                    'citations' => $citations,
                ];
                $metaJson = cnx_assistant_json_stringify($meta, 12000);
                $stmt = $db->getConnection()->prepare(
                    "INSERT INTO consulting_project_checklist_evidence
                     (tenant_id, checklist_item_id, file_tenant_id, file_id, file_name, file_path, meta_json, created_by_user_id, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), file_path = VALUES(file_path)"
                );
                $stmt->execute([
                    CNX_VENDOR_TENANT_ID,
                    $itemId,
                    $clientTenantId,
                    $fileId,
                    $fileName,
                    $filePath,
                    $metaJson,
                    $userId > 0 ? $userId : null,
                ]);
            }

            // Update evidence_json for UI compatibility
            $curEv = [];
            $rawEv = (string)($row['evidence_json'] ?? '');
            if ($rawEv !== '') {
                $dec = json_decode($rawEv, true);
                if (is_array($dec)) $curEv = $dec;
            }
            $curEv[] = ['tenant_id' => $clientTenantId, 'file_id' => $fileId, 'name' => $fileName, 'path' => $filePath];
            $ded = [];
            $seen = [];
            foreach ($curEv as $ev) {
                if (!is_array($ev)) continue;
                $fid = (int)($ev['file_id'] ?? 0);
                if ($fid <= 0 || isset($seen[$fid])) continue;
                $seen[$fid] = true;
                $ded[] = $ev;
            }
            $evJson = cnx_assistant_json_stringify($ded, 20000);
            $upd = ['evidence_json' => $evJson, 'updated_at' => date('Y-m-d H:i:s')];
            $statusCur = strtolower(trim((string)($row['status'] ?? '')));
            if ($statusCur === '' || $statusCur === 'missing') {
                $upd['status'] = 'present';
            }
            $db->update('consulting_project_checklist_items', $upd, ['id' => $itemId]);
            $updated = $db->fetchOne("SELECT * FROM consulting_project_checklist_items WHERE id = ? LIMIT 1", [$itemId]);
            if ($updated) $updatedItems[] = $updated;
            $applied[] = ['type' => 'add_evidence', 'item_id' => $itemId, 'file_id' => $fileId];
        }
    }

    return ['applied' => $applied, 'updated_items' => $updatedItems];
}

try {
    if ($action === 'start_session') {
        cnx_assistant_require_t28($userInfo);
        $storage = cnx_assistant_check_storage($db);
        if (empty($storage['ok'])) {
            api_error('Storage non disponibile (migrazione mancante)', 503, $storage);
        }

        $clientTenantId = (int)($payload['client_tenant_id'] ?? 0);
        $planId = (int)($payload['plan_id'] ?? 0);
        $checklistId = (int)($payload['checklist_id'] ?? 0);
        $templateKey = trim((string)($payload['template_key'] ?? ''));

        if ($clientTenantId > 0 && !cnx_assistant_is_client_allowed($db, $userInfo, $clientTenantId)) {
            api_error('Accesso negato al tenant cliente', 403);
        }

        if ($planId > 0) {
            $plan = $db->fetchOne(
                "SELECT id, client_tenant_id
                 FROM consulting_plans
                 WHERE id = ? AND deleted_at IS NULL
                 LIMIT 1",
                [$planId]
            );
            if (!$plan) api_error('Piano non trovato', 404);
            $planClientId = (int)($plan['client_tenant_id'] ?? 0);
            if ($planClientId <= 0) api_error('Piano senza tenant cliente', 400);
            if ($clientTenantId > 0 && $clientTenantId !== $planClientId) api_error('client_tenant_id non coerente con il piano', 400);
            if (!cnx_assistant_is_client_allowed($db, $userInfo, $planClientId)) api_error('Accesso negato al tenant cliente', 403);
            $clientTenantId = $planClientId;
        }

        if ($checklistId > 0) {
            $chk = $db->fetchOne(
                "SELECT id, client_tenant_id, plan_id
                 FROM consulting_project_checklists
                 WHERE id = ?
                 LIMIT 1",
                [$checklistId]
            );
            if (!$chk) api_error('Checklist non trovata', 404);
            $chkClientId = (int)($chk['client_tenant_id'] ?? 0);
            if ($chkClientId > 0 && $clientTenantId > 0 && $chkClientId !== $clientTenantId) {
                api_error('Checklist non coerente con il tenant cliente', 400);
            }
            if ($chkClientId > 0 && !cnx_assistant_is_client_allowed($db, $userInfo, $chkClientId)) {
                api_error('Accesso negato al tenant cliente', 403);
            }
            if ($planId > 0 && (int)($chk['plan_id'] ?? 0) !== $planId) {
                api_error('Checklist non coerente con il piano', 400);
            }
            $clientTenantId = $chkClientId ?: $clientTenantId;
            $planId = $planId ?: (int)($chk['plan_id'] ?? 0);
        }

        $sessionId = (int)$db->insert('ai_assistant_sessions', [
            'tenant_id' => (int)($userInfo['tenant_id'] ?? 0),
            'client_tenant_id' => $clientTenantId > 0 ? $clientTenantId : null,
            'plan_id' => $planId > 0 ? $planId : null,
            'checklist_id' => $checklistId > 0 ? $checklistId : null,
            'template_key' => $templateKey !== '' ? $templateKey : null,
            'created_by' => (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0) ?: null,
            'status' => 'open',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        api_success([
            'session_id' => $sessionId,
        ]);
    }

    if ($action === 'history') {
        $sessionId = (int)($_GET['session_id'] ?? 0);
        if ($sessionId <= 0) api_error('session_id richiesto', 400);
        $session = cnx_assistant_load_session($db, $sessionId);
        if (!$session) api_error('Sessione non trovata', 404);
        cnx_assistant_require_session_access($db, $userInfo, $session);

        $rows = $db->fetchAll(
            "SELECT id, role, content, meta_json, created_at
             FROM ai_assistant_messages
             WHERE session_id = ?
             ORDER BY id ASC",
            [$sessionId]
        ) ?: [];
        api_success([
            'session' => $session,
            'messages' => $rows,
        ]);
    }

    if ($action === 'close_session') {
        $sessionId = (int)($payload['session_id'] ?? 0);
        if ($sessionId <= 0) api_error('session_id richiesto', 400);
        $session = cnx_assistant_load_session($db, $sessionId);
        if (!$session) api_error('Sessione non trovata', 404);
        cnx_assistant_require_session_access($db, $userInfo, $session);

        $db->update('ai_assistant_sessions', [
            'status' => 'closed',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $sessionId]);
        api_success(['closed' => true]);
    }

    if ($action === 'send') {
        $storage = cnx_assistant_check_storage($db);
        if (empty($storage['ok'])) {
            api_error('Storage non disponibile (migrazione mancante)', 503, $storage);
        }

        $sessionId = (int)($payload['session_id'] ?? 0);
        $message = trim((string)($payload['message'] ?? ''));
        if ($sessionId <= 0) api_error('session_id richiesto', 400);
        if ($message === '') api_error('message richiesto', 400);

        $session = cnx_assistant_load_session($db, $sessionId);
        if (!$session) api_error('Sessione non trovata', 404);
        cnx_assistant_require_session_access($db, $userInfo, $session);
        if ((string)($session['status'] ?? '') === 'closed') api_error('Sessione chiusa', 409);

        cnx_assistant_log_message($db, $sessionId, 'user', $message, ['ts' => date('c')]);

        $provider = (string)($payload['provider'] ?? 'openai');
        $model = (string)($payload['model'] ?? '');
        $val = cnx_ai_validate_provider_model($provider, $model);
        if (!$val['ok']) api_error((string)($val['error'] ?? 'Provider/model non valido'), 400);
        $provider = (string)$val['provider'];
        $model = (string)$val['model'];

        $tenantTargetId = (int)($session['client_tenant_id'] ?? 0);
        if ($tenantTargetId <= 0) $tenantTargetId = (int)($session['tenant_id'] ?? 0);
        if ($tenantTargetId <= 0) api_error('tenant target non valido', 400);

        // Best-effort: reindex if stale (>10 min)
        $knowledgeWarning = '';
        $citations = [];
        $knowledgeText = '';
        try {
            $chk = cnx_ai_check_tables($db, ['ai_knowledge_sources', 'ai_knowledge_chunks'], 'database/migrations/48_ai_knowledge_index.sql');
            if (!$chk['ok']) {
                $knowledgeWarning = 'Knowledge non disponibile: applica migrazione 48 e indicizza';
            } else {
                $source = $db->fetchOne(
                    "SELECT id, folder_id FROM ai_knowledge_sources WHERE tenant_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1",
                    [$tenantTargetId]
                );
                $sourceId = $source ? (int)($source['id'] ?? 0) : 0;
                $folderId = $source ? (int)($source['folder_id'] ?? 0) : 0;
                if ($sourceId <= 0) {
                    $knowledgeWarning = 'Knowledge non configurata: crea /Knowledge o /IMS e avvia indicizzazione';
                } else {
                    $hasState = cnx_ai_check_tables($db, ['ai_knowledge_index_state'], null);
                    $shouldRun = true;
                    if (!empty($hasState['ok'])) {
                        $state = $db->fetchOne(
                            "SELECT last_indexed_at
                             FROM ai_knowledge_index_state
                             WHERE tenant_id = ? AND source_id = ?
                             LIMIT 1",
                            [$tenantTargetId, $sourceId]
                        );
                        $last = trim((string)($state['last_indexed_at'] ?? ''));
                        if ($last !== '') {
                            $ts = @strtotime($last);
                            if (is_int($ts) && $ts > 0 && (time() - $ts) < 600) {
                                $shouldRun = false;
                            }
                        }
                    }

                    $role = (string)($userInfo['role'] ?? 'user');
                    $canAutoIndex = in_array($role, ['manager', 'admin', 'super_admin'], true);
                    if ($shouldRun && $canAutoIndex && $folderId > 0) {
                        try {
                            cnx_ai_index_folder_delta($db, $tenantTargetId, $sourceId, $folderId, [
                                'max_indexed_files' => 5,
                                'max_checked_files' => 200,
                                'max_seconds' => 4,
                            ]);
                        } catch (Throwable $e) {
                            error_log('[AI_ASSISTANT][delta_index] ' . $e->getMessage());
                        }
                    }

                    // Retrieve chunks (best-effort LIKE search)
                    $q = $message;
                    if (mb_strlen($q, 'UTF-8') > 180) $q = mb_substr($q, 0, 180, 'UTF-8');
                    $like = '%' . $q . '%';
                    $rows = $db->fetchAll(
                        "SELECT file_id, file_name, logical_path, chunk_text, chunk_index
                         FROM ai_knowledge_chunks
                         WHERE tenant_id = ?
                           AND source_id = ?
                           AND chunk_text LIKE ?
                         ORDER BY updated_at DESC, id DESC
                         LIMIT 6",
                        [$tenantTargetId, $sourceId, $like]
                    ) ?: [];

                    if (!empty($rows)) {
                        $parts = [];
                        $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
                        $userRole = (string)($userInfo['role'] ?? 'user');
                        foreach ($rows as $r) {
                            $fid = (int)($r['file_id'] ?? 0);
                            $fn = (string)($r['file_name'] ?? '');
                            $lp = (string)($r['logical_path'] ?? '');
                            $txt = (string)($r['chunk_text'] ?? '');
                            if ($fid > 0) {
                                try {
                                    $acc = hasFileOrFolderAccess($db, $fid, $userId, $userRole, $tenantTargetId);
                                    if (empty($acc['has_access'])) continue;
                                } catch (Throwable $e) {
                                    continue;
                                }
                            }
                            if (mb_strlen($txt, 'UTF-8') > 700) $txt = mb_substr($txt, 0, 700, 'UTF-8') . '…';
                            $parts[] = "[SOURCE file_id={$fid} path=\"" . str_replace('"', "'", $lp) . "\" name=\"" . str_replace('"', "'", $fn) . "\"]\n{$txt}\n[/SOURCE]";
                            $citations[] = [
                                'file_id' => $fid,
                                'file_name' => $fn,
                                'logical_path' => $lp,
                                'chunk_index' => (int)($r['chunk_index'] ?? 0),
                                'snippet' => $txt,
                            ];
                        }
                        $knowledgeText = implode("\n\n", $parts);
                    } else {
                        $knowledgeWarning = 'Nessun match trovato nella knowledge (prova a indicizzare o a raffinare la domanda)';
                    }
                }
            }
        } catch (Throwable $e) {
            $knowledgeWarning = 'Errore lettura knowledge';
        }

        $system = [
            'role' => 'system',
            'content' =>
                "Sei l'assistente AI per la raccolta dati e gap analysis.\n"
                . "Regole:\n"
                . "- Rispondi in italiano.\n"
                . "- NON scrivere o modificare documenti; proponi azioni sulla checklist.\n"
                . "- Non riportare testo ISO/UNI.\n"
                . "- Se le fonti non bastano, chiedi chiarimenti con 2-4 domande mirate.\n"
                . "- Restituisci JSON con i campi richiesti.\n",
        ];

        $contextMsg = null;
        if ($knowledgeText !== '') {
            $contextMsg = [
                'role' => 'user',
                'content' => "FONTI (documentazione tenant):\n\n" . $knowledgeText . "\n\nDomanda:\n" . $message,
            ];
        }

        $schema = [
            'name' => 'assistant_checklist_response',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'assistant_message' => ['type' => 'string'],
                    'next_question' => ['type' => 'string'],
                    'actions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'type' => ['type' => 'string', 'enum' => ['update_item','add_evidence']],
                                'item_id' => ['type' => 'integer'],
                                'status' => ['type' => 'string'],
                                'answer_text' => ['type' => 'string'],
                                'file_id' => ['type' => 'integer'],
                            ],
                            'required' => ['type', 'item_id'],
                        ],
                    ],
                ],
                'required' => ['assistant_message', 'next_question', 'actions'],
            ],
        ];

        $finalMessages = [$system];
        if ($contextMsg) {
            $finalMessages[] = $contextMsg;
        } else {
            $finalMessages[] = ['role' => 'user', 'content' => $message];
        }

        $assistantMessage = '';
        $nextQuestion = '';
        $actions = [];
        $warnings = [];

        if ($provider === 'openai') {
            $resp = cnx_openai_chat_json($finalMessages, $schema, [
                'model' => $model,
                'temperature' => 0.2,
                'max_tokens' => 900,
            ]);
            if (!$resp['ok']) {
                api_error('Errore AI (OpenAI)', 503, ['details' => $resp['error'] ?? '']);
            }
            $data = $resp['data'] ?? [];
            $assistantMessage = trim((string)($data['assistant_message'] ?? ''));
            $nextQuestion = trim((string)($data['next_question'] ?? ''));
            $actions = is_array($data['actions'] ?? null) ? $data['actions'] : [];
        } else {
            $resp = cnx_pplx_chat_text($finalMessages, $model, [
                'temperature' => 0.2,
                'max_tokens' => 900,
            ]);
            if (!$resp['ok']) {
                api_error('Errore AI (Perplexity)', 503, ['details' => $resp['error'] ?? '']);
            }
            $text = trim((string)($resp['text'] ?? ''));
            // Try to parse JSON if present, otherwise fallback to text-only response
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $assistantMessage = trim((string)($decoded['assistant_message'] ?? ''));
                $nextQuestion = trim((string)($decoded['next_question'] ?? ''));
                $actions = is_array($decoded['actions'] ?? null) ? $decoded['actions'] : [];
            } else {
                $assistantMessage = $text;
                $nextQuestion = '';
                $actions = [];
                $warnings[] = 'Risposta AI non in JSON: actions non applicate';
            }
        }

        if ($assistantMessage === '') {
            $assistantMessage = 'Non ho abbastanza elementi per rispondere con certezza. Vuoi aggiungere dettagli?';
        }

        $applyResult = ['applied' => [], 'updated_items' => []];
        try {
            $applyResult = cnx_assistant_apply_actions($db, $userInfo, $session, $actions, $citations);
        } catch (Throwable $e) {
            $warnings[] = 'Actions non applicate: ' . $e->getMessage();
        }

        cnx_assistant_log_message($db, $sessionId, 'assistant', $assistantMessage, [
            'actions' => $applyResult['applied'] ?? [],
            'citations' => $citations,
            'next_question' => $nextQuestion,
            'warnings' => $warnings,
        ]);

        api_success([
            'assistant_message' => $assistantMessage,
            'next_question' => $nextQuestion,
            'applied_actions' => $applyResult['applied'] ?? [],
            'updated_items' => $applyResult['updated_items'] ?? [],
            'citations' => $citations,
            'knowledge_warning' => $knowledgeWarning,
            'warnings' => $warnings,
        ]);
    }

    api_error('Azione non valida', 400);
} catch (Throwable $e) {
    $errId = 'ai_assistant_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[AI_ASSISTANT][{$errId}] " . $e->getMessage());
    api_error('Errore assistente AI', 500, ['error_id' => $errId]);
}

