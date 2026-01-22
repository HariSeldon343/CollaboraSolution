<?php
/**
 * AI Copilot Chat (multi-tenant)
 * POST /api/ai_copilot_chat.php
 */
declare(strict_types=1);

require_once __DIR__ . '/ai/_common.php';
require_once __DIR__ . '/../includes/openai_client.php';
require_once __DIR__ . '/../includes/ai/embedding_utils.php';
require_once __DIR__ . '/../includes/file_access.php';

cnx_ai_require_csrf_for_write();

$payload = json_decode(cnx_get_raw_request_body(), true) ?: [];
$tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : cnx_ai_resolve_target_tenant_id($userInfo);
if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato', 403);

$storage = cnx_ai_check_tables($db, [
    'ai_doc_chunks',
    'ai_conversations',
    'ai_conversation_messages',
    'ai_tenant_settings',
], 'database/migrations/68_ai_copilot_rag.sql');
if (!$storage['ok']) {
    api_error('Storage non disponibile (migrazione mancante)', 503, $storage);
}

$conversationId = (int)($payload['conversation_id'] ?? 0);
$mode = trim((string)($payload['mode'] ?? 'onboarding'));
$message = trim((string)($payload['message'] ?? ''));
if ($message === '') api_error('message richiesto', 400);
$companyId = isset($payload['company_id']) ? (int)$payload['company_id'] : null;
$context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
$selectedStandards = is_array($context['selected_standards'] ?? null) ? $context['selected_standards'] : [];
$interventionType = trim((string)($context['intervention_type'] ?? ''));

$userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);

if ($conversationId <= 0) {
    $conversationId = (int)$db->insert('ai_conversations', [
        'tenant_id' => $tenantId,
        'company_id' => $companyId,
        'created_by' => $userId ?: null,
        'mode' => $mode !== '' ? $mode : 'onboarding',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

// Save user message
$db->insert('ai_conversation_messages', [
    'conversation_id' => $conversationId,
    'role' => 'user',
    'content' => $message,
    'meta_json' => null,
    'created_at' => date('Y-m-d H:i:s'),
]);

// RAG retrieval (hybrid)
$settings = $db->fetchOne(
    "SELECT ai_external_provider_enabled
     FROM ai_tenant_settings
     WHERE tenant_id = ?
     LIMIT 1",
    [$tenantId]
);
$externalEnabled = $settings ? ((int)($settings['ai_external_provider_enabled'] ?? 1) === 1) : true;

$query = $message;
$queryEmbedding = null;
if ($externalEnabled) {
    $emb = cnx_openai_embed_texts([$query], []);
    if ($emb['ok']) $queryEmbedding = $emb['embeddings'][0] ?? null;
}

$rows = $db->fetchAll(
    "SELECT id, file_id, chunk_text, embedding_json
     FROM ai_doc_chunks
     WHERE tenant_id = ?
       AND chunk_text LIKE ?
     ORDER BY updated_at DESC, id DESC
     LIMIT 120",
    [$tenantId, '%' . (mb_strlen($query, 'UTF-8') > 180 ? mb_substr($query, 0, 180, 'UTF-8') : $query) . '%']
) ?: [];

$userRole = (string)($userInfo['role'] ?? 'user');
$citations = [];
$scored = [];
foreach ($rows as $r) {
    $fid = (int)($r['file_id'] ?? 0);
    if ($fid > 0) {
        try {
            $acc = hasFileOrFolderAccess($db, $fid, $userId, $userRole, $tenantId);
            if (empty($acc['has_access'])) continue;
        } catch (Throwable $e) {
            continue;
        }
    }
    $txt = (string)($r['chunk_text'] ?? '');
    if (mb_strlen($txt, 'UTF-8') > 900) $txt = mb_substr($txt, 0, 900, 'UTF-8') . '…';
    $score = 0.0;
    if ($queryEmbedding && !empty($r['embedding_json'])) {
        $vec = json_decode((string)$r['embedding_json'], true);
        if (is_array($vec)) $score = cnx_cosine_similarity($queryEmbedding, $vec);
    } else {
        $score = (float)substr_count(mb_strtolower($txt, 'UTF-8'), mb_strtolower($query, 'UTF-8'));
    }
    $scored[] = ['row' => $r, 'score' => $score, 'text' => $txt];
}
usort($scored, static fn($a, $b) => ($b['score'] <=> $a['score']));
$scored = array_slice($scored, 0, 8);

$knowledgeText = '';
$parts = [];
foreach ($scored as $s) {
    $r = $s['row'];
    $fid = (int)($r['file_id'] ?? 0);
    $txt = (string)$s['text'];
    $parts[] = "[SOURCE file_id={$fid}]\n{$txt}\n[/SOURCE]";
    $citations[] = [
        'file_id' => $fid,
        'chunk_id' => (int)($r['id'] ?? 0),
        'score' => (float)$s['score'],
    ];
}
if (!empty($parts)) $knowledgeText = implode("\n\n", $parts);

$system = [
    'role' => 'system',
    'content' =>
        "Sei l'AI Copilot per raccolta dati e gap analysis.\n"
        . "Regole:\n"
        . "- Non copiare o citare testo ISO/UNI.\n"
        . "- Usa solo contesto aziendale e risposte utente.\n"
        . "- Se manca informazione, fai domande mirate e inserisci TODO.\n"
        . "- Non modificare documenti senza conferma esplicita.\n"
        . "- Proponi azioni strutturate (non applicarle automaticamente).\n",
];

$userCtx = [
    'role' => 'user',
    'content' =>
        "Messaggio utente:\n{$message}\n\n"
        . (!empty($selectedStandards) ? ("Standard: " . implode(', ', array_map('strval', $selectedStandards)) . "\n") : '')
        . ($interventionType !== '' ? ("Tipo intervento: {$interventionType}\n") : '')
        . ($knowledgeText !== '' ? ("\nFONTI (documenti tenant):\n" . $knowledgeText . "\n") : '')
        . "\nRispondi con JSON valido.",
];

$schema = [
    'name' => 'ai_copilot_response',
    'schema' => [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'assistant_message' => ['type' => 'string'],
            'actions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => [
                            'UPDATE_CHECKLIST_ITEM',
                            'ADD_EVIDENCE',
                            'UPDATE_COMPANY_PROFILE',
                            'SET_ARTIFACT_INPUT'
                        ]],
                        'item_id' => ['type' => 'integer'],
                        'status' => ['type' => 'string'],
                        'answer_text' => ['type' => 'string'],
                        'file_id' => ['type' => 'integer'],
                        'fields' => ['type' => 'object'],
                        'artifact_id' => ['type' => 'integer'],
                        'template_key' => ['type' => 'string'],
                        'key' => ['type' => 'string'],
                        'value' => ['type' => 'string'],
                    ],
                    'required' => ['type'],
                ],
            ],
            'open_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['assistant_message', 'actions', 'open_questions'],
    ],
];

$resp = cnx_openai_chat_json([$system, $userCtx], $schema, [
    'temperature' => 0.2,
    'max_tokens' => 900,
]);
if (!$resp['ok']) {
    $errId = 'copilot_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[AI_COPILOT][{$errId}] " . ($resp['error'] ?? 'unknown'));
    api_error('Errore AI copilot', 503, ['error_id' => $errId]);
}

$data = $resp['data'] ?? [];
$assistantMessage = trim((string)($data['assistant_message'] ?? ''));
$actions = is_array($data['actions'] ?? null) ? $data['actions'] : [];
$openQuestions = is_array($data['open_questions'] ?? null) ? $data['open_questions'] : [];

// Save assistant message
$db->insert('ai_conversation_messages', [
    'conversation_id' => $conversationId,
    'role' => 'assistant',
    'content' => $assistantMessage,
    'meta_json' => json_encode(['actions' => $actions, 'citations' => $citations, 'open_questions' => $openQuestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'created_at' => date('Y-m-d H:i:s'),
]);

api_success([
    'conversation_id' => $conversationId,
    'assistant_message' => $assistantMessage,
    'actions' => $actions,
    'open_questions' => $openQuestions,
    'citations' => $citations,
]);

