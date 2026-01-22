<?php
declare(strict_types=1);

/**
 * AI Chat endpoint (multi-provider)
 *
 * POST /api/ai/chat.php
 * Body JSON:
 * {
 *   provider: "openai"|"perplexity",
 *   model: "gpt-5.2"|"sonar"|...,
 *   messages: [{role:"user"|"assistant", content:"..."}],
 *   options: { use_knowledge: true|false }
 * }
 */

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/openai_client.php';
require_once __DIR__ . '/../../includes/ai/providers_registry.php';
require_once __DIR__ . '/../../includes/ai/perplexity_client.php';
require_once __DIR__ . '/../../includes/ai/knowledge_indexer.php';
require_once __DIR__ . '/../../includes/file_access.php';
require_once __DIR__ . '/../../includes/ai/tenant_checklists.php';

cnx_ai_require_csrf_for_write();

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$mode = strtolower(trim((string)($payload['mode'] ?? '')));
$isOnboarding = ($mode === 'onboarding' || !empty($payload['onboarding']));

if ($isOnboarding) {
    try {
        $provider = (string)($payload['provider'] ?? '');
        $model = (string)($payload['model'] ?? '');
        $val = cnx_ai_validate_provider_model($provider, $model);
        if (!$val['ok']) api_error((string)($val['error'] ?? 'Provider/model non valido'), 400);
        $provider = (string)$val['provider'];
        $model = (string)$val['model'];

        $tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : cnx_ai_resolve_target_tenant_id($userInfo);
        if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
        if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato al tenant', 403);

        $storage = cnx_ai_check_tables($db, [
            'tenant_doc_chunks',
            'tenant_ai_chat_sessions',
            'tenant_ai_chat_messages',
            'tenant_checklists',
            'tenant_checklist_items',
            'tenant_checklist_item_values',
        ], 'database/migrations/67_tenant_ai_onboarding.sql');
        if (!$storage['ok']) {
            api_error('Storage non disponibile (migrazione mancante)', 503, $storage);
        }

        $sessionId = (int)($payload['session_id'] ?? 0);
        $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
        $msgText = trim((string)($payload['message'] ?? ''));
        if ($msgText === '') api_error('message richiesto', 400);
        if ($sessionId <= 0) {
            $title = trim((string)($payload['title'] ?? ''));
            if ($title === '') $title = mb_substr($msgText, 0, 80, 'UTF-8');
            $sessionId = (int)$db->insert('tenant_ai_chat_sessions', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'title' => $title,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $db->insert('tenant_ai_chat_messages', [
            'session_id' => $sessionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId ?: null,
            'role' => 'user',
            'message_text' => $msgText,
            'meta_json' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // RAG retrieval (best-effort)
        $citations = [];
        $knowledgeText = '';
        $q = $msgText;
        if (mb_strlen($q, 'UTF-8') > 180) $q = mb_substr($q, 0, 180, 'UTF-8');
        $like = '%' . $q . '%';
        $rows = $db->fetchAll(
            "SELECT id, file_id, chunk_text
             FROM tenant_doc_chunks
             WHERE tenant_id = ?
               AND chunk_text LIKE ?
             ORDER BY updated_at DESC, id DESC
             LIMIT 8",
            [$tenantId, $like]
        ) ?: [];
        foreach ($rows as $r) {
            $fid = (int)($r['file_id'] ?? 0);
            $txt = (string)($r['chunk_text'] ?? '');
            if (mb_strlen($txt, 'UTF-8') > 700) $txt = mb_substr($txt, 0, 700, 'UTF-8') . '…';
            $citations[] = [
                'chunk_id' => (int)($r['id'] ?? 0),
                'file_id' => $fid,
                'snippet' => $txt,
            ];
        }
        if (!empty($citations)) {
            $parts = [];
            foreach ($citations as $c) {
                $parts[] = "[SOURCE file_id={$c['file_id']}]\n{$c['snippet']}\n[/SOURCE]";
            }
            $knowledgeText = implode("\n\n", $parts);
        }

        $system = [
            'role' => 'system',
            'content' =>
                "Sei l'assistente AI per la raccolta dati e gap analysis.\n"
                . "Regole:\n"
                . "- Rispondi in italiano.\n"
                . "- Non copiare testo ISO/UNI.\n"
                . "- Se manca informazione, fai domande mirate e inserisci TODO.\n"
                . "- Proponi azioni strutturate per checklist/azienda.\n",
        ];
        $contextMsg = null;
        if ($knowledgeText !== '') {
            $contextMsg = [
                'role' => 'user',
                'content' => "FONTI (documentazione tenant):\n\n" . $knowledgeText . "\n\nDomanda:\n" . $msgText,
            ];
        }
        $finalMessages = [$system];
        if ($contextMsg) {
            $finalMessages[] = $contextMsg;
        } else {
            $finalMessages[] = ['role' => 'user', 'content' => $msgText];
        }

        $schema = [
            'name' => 'tenant_onboarding_response',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'assistant_message' => ['type' => 'string'],
                    'next_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'actions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'type' => ['type' => 'string', 'enum' => ['upsert_checklist_item','link_evidence','update_company_profile']],
                                'checklist_id' => ['type' => 'integer'],
                                'template_key' => ['type' => 'string'],
                                'scope_type' => ['type' => 'string'],
                                'scope_id' => ['type' => 'integer'],
                                'item_key' => ['type' => 'string'],
                                'status' => ['type' => 'string'],
                                'answer_text' => ['type' => 'string'],
                                'file_id' => ['type' => 'integer'],
                                'profile_key' => ['type' => 'string'],
                                'profile_value' => ['type' => 'string'],
                                'program_id' => ['type' => 'integer'],
                            ],
                            'required' => ['type'],
                        ],
                    ],
                ],
                'required' => ['assistant_message', 'actions', 'next_questions'],
            ],
        ];

        $assistantMessage = '';
        $actions = [];
        $nextQuestions = [];
        $warnings = [];

        if ($provider === 'openai') {
            $resp = cnx_openai_chat_json($finalMessages, $schema, [
                'model' => $model,
                'temperature' => 0.2,
                'max_tokens' => 900,
            ]);
            if (!$resp['ok']) api_error('Errore AI (OpenAI)', 503, ['details' => $resp['error'] ?? '']);
            $data = $resp['data'] ?? [];
            $assistantMessage = trim((string)($data['assistant_message'] ?? ''));
            $actions = is_array($data['actions'] ?? null) ? $data['actions'] : [];
            $nextQuestions = is_array($data['next_questions'] ?? null) ? $data['next_questions'] : [];
        } else {
            $resp = cnx_pplx_chat_text($finalMessages, $model, [
                'temperature' => 0.2,
                'max_tokens' => 900,
            ]);
            if (!$resp['ok']) api_error('Errore AI (Perplexity)', 503, ['details' => $resp['error'] ?? '']);
            $text = trim((string)($resp['text'] ?? ''));
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $assistantMessage = trim((string)($decoded['assistant_message'] ?? ''));
                $actions = is_array($decoded['actions'] ?? null) ? $decoded['actions'] : [];
                $nextQuestions = is_array($decoded['next_questions'] ?? null) ? $decoded['next_questions'] : [];
            } else {
                $assistantMessage = $text;
                $actions = [];
                $nextQuestions = [];
                $warnings[] = 'Risposta AI non in JSON: actions non applicate';
            }
        }

        if ($assistantMessage === '') {
            $assistantMessage = 'Non ho abbastanza elementi per rispondere con certezza. Vuoi aggiungere dettagli?';
        }

        $applied = [];
        foreach ($actions as $a) {
            if (!is_array($a)) continue;
            $type = strtolower(trim((string)($a['type'] ?? '')));
            if ($type === 'upsert_checklist_item') {
                $templateKey = (string)($a['template_key'] ?? '');
                $scopeType = (string)($a['scope_type'] ?? 'company');
                $scopeId = isset($a['scope_id']) ? (int)$a['scope_id'] : null;
                $checklistId = (int)($a['checklist_id'] ?? 0);
                if ($checklistId <= 0 && $templateKey !== '') {
                    $res = cnx_tenant_checklists_get_or_create($db, $tenantId, $scopeType, $scopeId, $templateKey);
                    $checklistId = (int)($res['checklist_id'] ?? 0);
                }
                $itemKey = trim((string)($a['item_key'] ?? ''));
                if ($checklistId <= 0 || $itemKey === '') continue;
                $status = strtolower(trim((string)($a['status'] ?? 'missing')));
                $answerText = array_key_exists('answer_text', $a) ? (string)($a['answer_text'] ?? '') : null;
                $stmt = $db->getConnection()->prepare(
                    "INSERT INTO tenant_checklist_item_values
                        (tenant_id, checklist_id, item_key, status, answer_text, evidences_json, updated_at)
                     VALUES (?, ?, ?, ?, ?, NULL, NOW())
                     ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        answer_text = VALUES(answer_text),
                        updated_at = NOW()"
                );
                $stmt->execute([$tenantId, $checklistId, $itemKey, $status, $answerText]);
                $applied[] = ['type' => 'upsert_checklist_item', 'item_key' => $itemKey];
            } elseif ($type === 'link_evidence') {
                $checklistId = (int)($a['checklist_id'] ?? 0);
                $itemKey = trim((string)($a['item_key'] ?? ''));
                $fileId = (int)($a['file_id'] ?? 0);
                if ($checklistId <= 0 || $itemKey === '' || $fileId <= 0) continue;
                try {
                    $access = hasFileOrFolderAccess($db, $fileId, $userId, (string)($userInfo['role'] ?? ''), $tenantId);
                    if (empty($access['has_access'])) continue;
                } catch (Throwable $e) {
                    continue;
                }
                $row = $db->fetchOne(
                    "SELECT evidences_json
                     FROM tenant_checklist_item_values
                     WHERE tenant_id = ? AND checklist_id = ? AND item_key = ?
                     LIMIT 1",
                    [$tenantId, $checklistId, $itemKey]
                );
                $cur = [];
                if ($row && !empty($row['evidences_json'])) {
                    $dec = json_decode((string)$row['evidences_json'], true);
                    if (is_array($dec)) $cur = $dec;
                }
                $cur[] = ['file_id' => $fileId];
                $ded = [];
                $seen = [];
                foreach ($cur as $ev) {
                    $fid = (int)($ev['file_id'] ?? 0);
                    if ($fid <= 0 || isset($seen[$fid])) continue;
                    $seen[$fid] = true;
                    $ded[] = ['file_id' => $fid];
                }
                $stmt = $db->getConnection()->prepare(
                    "INSERT INTO tenant_checklist_item_values
                        (tenant_id, checklist_id, item_key, status, answer_text, evidences_json, updated_at)
                     VALUES (?, ?, ?, 'present', NULL, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        evidences_json = VALUES(evidences_json),
                        updated_at = NOW()"
                );
                $stmt->execute([$tenantId, $checklistId, $itemKey, json_encode($ded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                $applied[] = ['type' => 'link_evidence', 'item_key' => $itemKey, 'file_id' => $fileId];
            } elseif ($type === 'update_company_profile') {
                $programId = (int)($a['program_id'] ?? 0);
                $profileKey = trim((string)($a['profile_key'] ?? ''));
                $profileValue = (string)($a['profile_value'] ?? '');
                if ($programId > 0 && $profileKey !== '') {
                    $prog = $db->fetchOne(
                        "SELECT id FROM compliance_programs WHERE id = ? AND tenant_id = ? LIMIT 1",
                        [$programId, $tenantId]
                    );
                    if (!$prog) continue;
                    $hasProfiles = $db->fetchOne(
                        "SELECT 1 FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'compliance_program_profiles'
                         LIMIT 1"
                    );
                    if ($hasProfiles) {
                        $row = $db->fetchOne(
                            "SELECT profile_json
                             FROM compliance_program_profiles
                             WHERE tenant_id = ? AND program_id = ?
                             LIMIT 1",
                            [$tenantId, $programId]
                        );
                        $obj = [];
                        if ($row && !empty($row['profile_json'])) {
                            $dec = json_decode((string)$row['profile_json'], true);
                            if (is_array($dec)) $obj = $dec;
                        }
                        $obj[$profileKey] = $profileValue;
                        $json = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $stmt = $db->getConnection()->prepare(
                            "INSERT INTO compliance_program_profiles
                                (tenant_id, program_id, profile_json, updated_by, updated_at, created_at)
                             VALUES (?, ?, ?, ?, NOW(), NOW())
                             ON DUPLICATE KEY UPDATE
                                profile_json = VALUES(profile_json),
                                updated_by = VALUES(updated_by),
                                updated_at = NOW()"
                        );
                        $stmt->execute([$tenantId, $programId, $json, $userId ?: null]);
                        $applied[] = ['type' => 'update_company_profile', 'profile_key' => $profileKey];
                    }
                }
            }
        }

        $db->insert('tenant_ai_chat_messages', [
            'session_id' => $sessionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId ?: null,
            'role' => 'assistant',
            'message_text' => $assistantMessage,
            'meta_json' => json_encode(['actions' => $applied, 'citations' => $citations, 'next_questions' => $nextQuestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        api_success([
            'session_id' => $sessionId,
            'assistant_message' => $assistantMessage,
            'next_questions' => $nextQuestions,
            'suggested_actions' => $actions,
            'applied_actions' => $applied,
            'citations' => $citations,
            'warnings' => $warnings,
        ]);
    } catch (Throwable $e) {
        $errId = 'ai_onb_' . substr(bin2hex(random_bytes(6)), 0, 12);
        error_log("[AI_ONBOARDING_CHAT][{$errId}] " . $e->getMessage());
        api_error('Errore AI onboarding', 500, ['error_id' => $errId]);
    }
    exit;
}
$provider = (string)($payload['provider'] ?? '');
$model = (string)($payload['model'] ?? '');
$val = cnx_ai_validate_provider_model($provider, $model);
if (!$val['ok']) api_error((string)($val['error'] ?? 'Provider/model non valido'), 400);
$provider = (string)$val['provider'];
$model = (string)$val['model'];

$tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : cnx_ai_resolve_target_tenant_id($userInfo);
if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato al tenant', 403);

$messagesIn = $payload['messages'] ?? null;
if (!is_array($messagesIn) || empty($messagesIn)) api_error('messages richiesto', 400);

// Keep only user/assistant roles; cap size
$messages = [];
foreach ($messagesIn as $m) {
    if (!is_array($m)) continue;
    $role = strtolower(trim((string)($m['role'] ?? '')));
    if (!in_array($role, ['user', 'assistant'], true)) continue;
    $content = trim((string)($m['content'] ?? ''));
    if ($content === '') continue;
    if (mb_strlen($content, 'UTF-8') > 6000) {
        $content = mb_substr($content, 0, 6000, 'UTF-8') . '…';
    }
    $messages[] = ['role' => $role, 'content' => $content];
}
if (empty($messages)) api_error('messages vuoto', 400);

$useKnowledge = true;
if (isset($payload['options']) && is_array($payload['options']) && array_key_exists('use_knowledge', $payload['options'])) {
    $useKnowledge = (bool)$payload['options']['use_knowledge'];
}

// Latest user question (best-effort)
$lastUser = '';
for ($i = count($messages) - 1; $i >= 0; $i--) {
    if (($messages[$i]['role'] ?? '') === 'user') {
        $lastUser = (string)($messages[$i]['content'] ?? '');
        break;
    }
}
if (trim($lastUser) === '') $lastUser = (string)($messages[count($messages) - 1]['content'] ?? '');

// Retrieve knowledge chunks (best-effort)
$citations = [];
$knowledgeText = '';
$knowledgeWarning = '';
$fileIdCtx = (int)($payload['file_id'] ?? 0);
if ($useKnowledge) {
    $chk = cnx_ai_check_tables($db, ['ai_knowledge_sources', 'ai_knowledge_chunks'], 'database/migrations/48_ai_knowledge_index.sql');
    if (!$chk['ok']) {
        $knowledgeWarning = 'Knowledge non disponibile: applica migrazione 48 e indicizza';
    } else {
        $source = $db->fetchOne(
            "SELECT id, folder_id FROM ai_knowledge_sources WHERE tenant_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1",
            [$tenantId]
        );
        $sourceId = $source ? (int)($source['id'] ?? 0) : 0;
        $folderId = $source ? (int)($source['folder_id'] ?? 0) : 0;
        if ($sourceId <= 0) {
            $knowledgeWarning = 'Knowledge non configurata: crea /Knowledge o /IMS e avvia indicizzazione';
        } else {
            // Best-effort delta indexing as fallback (cron should be primary).
            // We keep it VERY small and only for privileged roles to avoid heavy work on user chats.
            try {
                $role = (string)($userInfo['role'] ?? 'user');
                $canAutoIndex = in_array($role, ['manager', 'admin', 'super_admin'], true);
                if ($canAutoIndex && $folderId > 0) {
                    $hasState = cnx_ai_check_tables($db, ['ai_knowledge_index_state'], null);
                    $shouldRun = true;
                    if (!empty($hasState['ok'])) {
                        $state = $db->fetchOne(
                            "SELECT last_indexed_at
                             FROM ai_knowledge_index_state
                             WHERE tenant_id = ? AND source_id = ?
                             LIMIT 1",
                            [$tenantId, $sourceId]
                        );
                        $last = trim((string)($state['last_indexed_at'] ?? ''));
                        if ($last !== '') {
                            $ts = @strtotime($last);
                            if (is_int($ts) && $ts > 0) {
                                // Throttle to avoid indexing on every message
                                if ((time() - $ts) < 180) {
                                    $shouldRun = false;
                                }
                            }
                        }
                    }

                    if ($shouldRun) {
                        $delta = cnx_ai_index_folder_delta($db, $tenantId, $sourceId, $folderId, [
                            'max_indexed_files' => 3,
                            'max_checked_files' => 200,
                            'max_seconds' => 4,
                        ]);
                        if (!empty($delta['ok']) && !empty($hasState['ok'])) {
                            // Update index state best-effort so ai.php shows fresh status.
                            try {
                                $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
                                $row = $db->fetchOne(
                                    "SELECT id FROM ai_knowledge_index_state WHERE tenant_id = ? AND source_id = ? LIMIT 1",
                                    [$tenantId, $sourceId]
                                );
                                $data = [
                                    'tenant_id' => $tenantId,
                                    'source_id' => $sourceId,
                                    'last_indexed_at' => date('Y-m-d H:i:s'),
                                    'last_file_count' => (int)($delta['scanned_files'] ?? 0),
                                    'last_error' => null,
                                    'updated_by' => $userId > 0 ? $userId : null,
                                    'updated_at' => date('Y-m-d H:i:s'),
                                ];
                                if ($row && !empty($row['id'])) {
                                    $db->update('ai_knowledge_index_state', $data, ['id' => (int)$row['id']]);
                                } else {
                                    $data['created_at'] = date('Y-m-d H:i:s');
                                    $db->insert('ai_knowledge_index_state', $data);
                                }
                            } catch (Throwable $e) {
                                // ignore
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // Never block chat on indexing
                error_log('[AI_CHAT][delta_index] ' . $e->getMessage());
            }

            $rows = [];
            if ($fileIdCtx > 0) {
                // Prefer file-specific context when provided (deep-link from File Manager)
                $rows = $db->fetchAll(
                    "SELECT file_id, file_name, logical_path, chunk_text, chunk_index
                     FROM ai_knowledge_chunks
                     WHERE tenant_id = ?
                       AND source_id = ?
                       AND file_id = ?
                     ORDER BY chunk_index ASC
                     LIMIT 8",
                    [$tenantId, $sourceId, $fileIdCtx]
                ) ?: [];
            }
            if (empty($rows)) {
                $q = trim($lastUser);
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
                    [$tenantId, $sourceId, $like]
                ) ?: [];
            }

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
                            $acc = hasFileOrFolderAccess($db, $fid, $userId, $userRole, $tenantId);
                            if (empty($acc['has_access'])) continue;
                        } catch (Throwable $e) {
                            // best-effort: if access check fails unexpectedly, skip to avoid leaks
                            continue;
                        }
                    }
                    if (mb_strlen($txt, 'UTF-8') > 900) $txt = mb_substr($txt, 0, 900, 'UTF-8') . '…';
                    $parts[] = "[SOURCE file_id={$fid} path=\"" . str_replace('"', "'", $lp) . "\" name=\"" . str_replace('"', "'", $fn) . "\"]\n{$txt}\n[/SOURCE]";
                    $citations[] = [
                        'file_id' => $fid,
                        'file_name' => $fn,
                        'logical_path' => $lp,
                        'chunk_index' => (int)($r['chunk_index'] ?? 0),
                    ];
                }
                $knowledgeText = implode("\n\n", $parts);
            } else {
                $knowledgeWarning = 'Nessun match trovato nella knowledge (prova a indicizzare o a raffinare la domanda)';
            }
        }
    }
}

// System guardrails (shared)
$system = [
    'role' => 'system',
    'content' =>
        "Sei l'assistente AI del tenant corrente.\n"
        . "Regole:\n"
        . "- Rispondi in italiano.\n"
        . "- Rispondi usando SOLO le informazioni fornite nella sezione FONTI (se presenti).\n"
        . "- Se le fonti non bastano, dì chiaramente cosa manca e fai 3-5 domande mirate.\n"
        . "- Non inventare policy/procedure non supportate.\n"
        . "- Non riportare testo ISO/UNI e non citare clausole/paragrafi.\n",
];

$contextMsg = null;
if ($useKnowledge && $knowledgeText !== '') {
    $contextMsg = [
        'role' => 'user',
        'content' => "FONTI (documentazione tenant):\n\n" . $knowledgeText . "\n\nDomanda:\n" . $lastUser,
    ];
}

// Build final message list (cap to last 10 turns)
$history = array_slice($messages, max(0, count($messages) - 10));
$finalMessages = [$system];
if ($contextMsg) {
    // If sources are provided, we let the model answer primarily from this message.
    $finalMessages[] = $contextMsg;
} else {
    $finalMessages = array_merge($finalMessages, $history);
}

$answerText = '';
if ($provider === 'openai') {
    // Force JSON output for stability
    $schema = [
        'name' => 'ai_chat_response',
        'schema' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'answer_text' => ['type' => 'string'],
            ],
            'required' => ['answer_text'],
        ],
    ];
    $resp = cnx_openai_chat_json($finalMessages, $schema, [
        'model' => $model,
        'max_tokens' => 1800,
        'timeout_seconds' => 60,
        'max_retries' => 1,
    ]);
    if (!$resp['ok']) {
        api_error((string)($resp['error'] ?? 'Errore AI'), 503, ['debug' => $resp['debug'] ?? null]);
    }
    $answerText = trim((string)($resp['data']['answer_text'] ?? ''));
} elseif ($provider === 'perplexity') {
    $resp = cnx_pplx_chat_text($finalMessages, $model, [
        'max_tokens' => 1400,
        'timeout_seconds' => 45,
        'max_retries' => 1,
    ]);
    if (!$resp['ok']) {
        api_error((string)($resp['error'] ?? 'Errore AI'), 503, ['debug' => $resp['debug'] ?? null]);
    }
    $answerText = trim((string)($resp['text'] ?? ''));
} else {
    api_error('Provider non supportato', 400);
}

if ($answerText === '') {
    api_error('Risposta AI vuota', 503);
}

api_success([
    'provider' => $provider,
    'model' => $model,
    'answer_text' => $answerText,
    'citations' => $citations,
    'knowledge_warning' => $knowledgeWarning,
]);

