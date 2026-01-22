<?php
declare(strict_types=1);

/**
 * Compliance Artifact AI API (Document Wizard)
 *
 * POST /api/compliance/artifact_ai.php?action=suggest_field
 * POST /api/compliance/artifact_ai.php?action=generate_document_draft
 *
 * Uses includes/openai_client.php (cnx_openai_chat_json).
 *
 * Guardrails:
 * - No ISO/UNI copyrighted text
 * - No clause citations, no “normative” wording
 * - Write original, practical SGQ content
 */

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/openai_client.php';
require_once __DIR__ . '/../../includes/compliance/template_schema_defaults.php';
require_once __DIR__ . '/../../includes/file_access.php';

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));
if ($action === '') api_error('action richiesto', 400);

verifyApiCsrfToken(true);

// Storage check
$chk = cnx_compliance_check_tables(
    $db,
    ['compliance_artifacts', 'compliance_programs', 'compliance_program_profiles', 'compliance_artifact_inputs', 'compliance_artifact_templates'],
    'database/migrations/47_document_wizard.sql'
);
if (!$chk['ok']) {
    api_success([
        'storage_available' => false,
        'missing' => $chk['missing'] ?? [],
        'migration' => $chk['migration'] ?? 'database/migrations/47_document_wizard.sql',
    ]);
}

/**
 * @return array{artifact:array,tenant_id:int,program_id:int,template_key:string}
 */
function cnx_dw_load_artifact_ctx(Database $db, array $userInfo, int $artifactId): array {
    $artifact = $db->fetchOne(
        "SELECT a.id, a.program_id, a.artifact_key, a.title, a.artifact_type,
                p.tenant_id
         FROM compliance_artifacts a
         JOIN compliance_programs p ON p.id = a.program_id
         WHERE a.id = ?
         LIMIT 1",
        [$artifactId]
    );
    if (!$artifact) api_error('Deliverable non trovato', 404);
    $tenantId = (int)($artifact['tenant_id'] ?? 0);
    if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $tenantId)) {
        api_error('Accesso negato al tenant del deliverable', 403);
    }
    return [
        'artifact' => $artifact,
        'tenant_id' => $tenantId,
        'program_id' => (int)$artifact['program_id'],
        'template_key' => (string)($artifact['artifact_key'] ?? ''),
    ];
}

/**
 * @return array{schema:?array,ai_hint:?string,template_title:?string,doc_type:?string,file_kind:?string}
 */
function cnx_dw_load_template_schema(Database $db, string $templateKey): array {
    $row = $db->fetchOne(
        "SELECT title, doc_type, file_kind, input_schema_json, ai_hint
         FROM compliance_artifact_templates
         WHERE template_key = ?
         LIMIT 1",
        [$templateKey]
    );
    $schema = null;
    if ($row && !empty($row['input_schema_json'])) {
        try { $schema = json_decode((string)$row['input_schema_json'], true) ?: null; } catch (Throwable $e) { $schema = null; }
    }
    return [
        'schema' => is_array($schema) ? $schema : null,
        'ai_hint' => $row ? (string)($row['ai_hint'] ?? '') : null,
        'template_title' => $row ? (string)($row['title'] ?? '') : null,
        'doc_type' => $row ? (string)($row['doc_type'] ?? '') : null,
        'file_kind' => $row ? (string)($row['file_kind'] ?? '') : null,
    ];
}

/**
 * Load best-effort program context from other deliverables (inputs already saved).
 * This helps the AI "autoapprendere" as more data is entered over time.
 *
 * @return array<string,mixed>
 */
function cnx_dw_load_program_context(Database $db, int $tenantId, int $programId, int $excludeArtifactId): array {
    try {
        $rows = $db->fetchAll(
            "SELECT a.artifact_key, a.title, i.input_json, i.updated_at
             FROM compliance_artifact_inputs i
             JOIN compliance_artifacts a ON a.id = i.artifact_id
             WHERE i.tenant_id = ?
               AND i.program_id = ?
               AND i.artifact_id <> ?
               AND i.input_json IS NOT NULL
               AND i.input_json <> ''
             ORDER BY i.updated_at DESC
             LIMIT 20",
            [$tenantId, $programId, $excludeArtifactId]
        ) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $k = trim((string)($r['artifact_key'] ?? ''));
        if ($k === '') continue;
        $raw = (string)($r['input_json'] ?? '');
        if ($raw === '') continue;
        try { $decoded = json_decode($raw, true); } catch (Throwable $e) { $decoded = null; }
        if (!is_array($decoded)) continue;

        // Trim large values to keep prompts bounded
        $trimmed = [];
        foreach ($decoded as $kk => $vv) {
            $key = trim((string)$kk);
            if ($key === '') continue;
            if (is_array($vv)) {
                $s = json_encode($vv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $s = is_string($s) ? $s : '';
            } else {
                $s = (string)$vv;
            }
            $s = trim($s);
            if ($s === '') continue;
            if (mb_strlen($s, 'UTF-8') > 450) {
                $s = mb_substr($s, 0, 450, 'UTF-8') . '…';
            }
            $trimmed[$key] = $s;
        }
        if (empty($trimmed)) continue;
        $out[$k] = [
            'title' => (string)($r['title'] ?? ''),
            'updated_at' => (string)($r['updated_at'] ?? ''),
            'inputs' => $trimmed,
        ];
    }
    return $out;
}

/**
 * @return array<string,mixed>
 */
function cnx_dw_load_profile(Database $db, int $tenantId, int $programId): array {
    $row = $db->fetchOne(
        "SELECT profile_json FROM compliance_program_profiles WHERE tenant_id = ? AND program_id = ? LIMIT 1",
        [$tenantId, $programId]
    );
    if (!$row || empty($row['profile_json'])) return [];
    try {
        $decoded = json_decode((string)$row['profile_json'], true);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array<string,mixed>
 */
function cnx_dw_load_inputs(Database $db, int $tenantId, int $artifactId): array {
    $row = $db->fetchOne(
        "SELECT input_json FROM compliance_artifact_inputs WHERE tenant_id = ? AND artifact_id = ? LIMIT 1",
        [$tenantId, $artifactId]
    );
    if (!$row || empty($row['input_json'])) return [];
    try {
        $decoded = json_decode((string)$row['input_json'], true);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Load onboarding checklist values (best-effort).
 * @return array<int,array<string,mixed>>
 */
function cnx_dw_load_onboarding_checklist(Database $db, int $tenantId, int $programId): array {
    $has = $db->fetchOne(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('tenant_checklists','tenant_checklist_items','tenant_checklist_item_values')
         LIMIT 1"
    );
    if (!$has) return [];
    $chk = $db->fetchOne(
        "SELECT id
         FROM tenant_checklists
         WHERE tenant_id = ? AND scope_type = 'program' AND scope_id = ?
         ORDER BY id DESC
         LIMIT 1",
        [$tenantId, $programId]
    );
    if (!$chk) return [];
    $checklistId = (int)($chk['id'] ?? 0);
    if ($checklistId <= 0) return [];
    $rows = $db->fetchAll(
        "SELECT i.section_key, i.item_key, i.title, v.status, v.answer_text
         FROM tenant_checklist_items i
         LEFT JOIN tenant_checklist_item_values v
           ON v.tenant_id = i.tenant_id AND v.checklist_id = i.checklist_id AND v.item_key = i.item_key
         WHERE i.checklist_id = ? AND i.tenant_id = ?
         ORDER BY i.section_key ASC, i.id ASC",
        [$checklistId, $tenantId]
    ) ?: [];
    return $rows;
}

/**
 * Best-effort: check if AI knowledge tables exist (migration 48).
 */
function cnx_dw_ai_has_knowledge_storage(Database $db): bool {
    try {
        $row = $db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('ai_knowledge_sources','ai_knowledge_chunks')"
        );
        return $row ? ((int)($row['c'] ?? 0) >= 2) : false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Best-effort tenant knowledge retrieval (RAG) using LIKE search.
 *
 * @return array{knowledge_text:string,citations:array<int,array<string,mixed>>,knowledge_warning:string}
 */
function cnx_dw_ai_fetch_knowledge(Database $db, array $userInfo, int $tenantId, string $q, int $limit = 6): array {
    $tenantId = (int)$tenantId;
    $q = trim((string)$q);
    $limit = max(1, min(12, (int)$limit));

    if ($tenantId <= 0) {
        return ['knowledge_text' => '', 'citations' => [], 'knowledge_warning' => 'Tenant non valido'];
    }
    if ($q === '' || mb_strlen($q, 'UTF-8') < 2) {
        return ['knowledge_text' => '', 'citations' => [], 'knowledge_warning' => ''];
    }
    if (!cnx_dw_ai_has_knowledge_storage($db)) {
        return ['knowledge_text' => '', 'citations' => [], 'knowledge_warning' => 'Knowledge non disponibile: applica migrazione 48 e indicizza'];
    }

    // Active source for tenant
    $source = $db->fetchOne(
        "SELECT id
         FROM ai_knowledge_sources
         WHERE tenant_id = ? AND is_active = 1
         ORDER BY id DESC
         LIMIT 1",
        [$tenantId]
    );
    $sourceId = $source ? (int)($source['id'] ?? 0) : 0;
    if ($sourceId <= 0) {
        return ['knowledge_text' => '', 'citations' => [], 'knowledge_warning' => 'Knowledge non configurata: crea /Knowledge o /IMS e avvia indicizzazione'];
    }

    // Keep query small for LIKE
    if (mb_strlen($q, 'UTF-8') > 180) $q = mb_substr($q, 0, 180, 'UTF-8');
    $like = '%' . $q . '%';

    $rows = $db->fetchAll(
        "SELECT file_id, file_name, logical_path, chunk_text, chunk_index
         FROM ai_knowledge_chunks
         WHERE tenant_id = ?
           AND source_id = ?
           AND chunk_text LIKE ?
         ORDER BY updated_at DESC, id DESC
         LIMIT {$limit}",
        [$tenantId, $sourceId, $like]
    ) ?: [];

    if (empty($rows)) {
        return ['knowledge_text' => '', 'citations' => [], 'knowledge_warning' => 'Nessun match trovato nella knowledge (prova a indicizzare o a raffinare la domanda)'];
    }

    $citations = [];
    $parts = [];
    $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    $userRole = (string)($userInfo['role'] ?? 'user');
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
        $fn = (string)($r['file_name'] ?? '');
        $lp = (string)($r['logical_path'] ?? '');
        $txt = (string)($r['chunk_text'] ?? '');
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
    return [
        'knowledge_text' => $knowledgeText,
        'citations' => $citations,
        'knowledge_warning' => '',
    ];
}

/**
 * Best-effort onboarding knowledge (tenant_doc_chunks).
 *
 * @return array{knowledge_text:string,citations:array<int,array<string,mixed>>,knowledge_warning:string}
 */
function cnx_dw_ai_fetch_tenant_doc_chunks(Database $db, array $userInfo, int $tenantId, string $q, int $limit = 6): array {
    $tenantId = (int)$tenantId;
    $q = trim((string)$q);
    $limit = max(1, min(12, (int)$limit));
    if ($tenantId <= 0 || $q === '') {
        return ['knowledge_text' => '', 'citations' => [], 'knowledge_warning' => ''];
    }
    $has = $db->fetchOne(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'tenant_doc_chunks'
         LIMIT 1"
    );
    if (!$has) return ['knowledge_text' => '', 'citations' => [], 'knowledge_warning' => ''];

    if (mb_strlen($q, 'UTF-8') > 180) $q = mb_substr($q, 0, 180, 'UTF-8');
    $like = '%' . $q . '%';
    $rows = $db->fetchAll(
        "SELECT id, file_id, chunk_text
         FROM tenant_doc_chunks
         WHERE tenant_id = ?
           AND chunk_text LIKE ?
         ORDER BY updated_at DESC, id DESC
         LIMIT {$limit}",
        [$tenantId, $like]
    ) ?: [];
    if (empty($rows)) return ['knowledge_text' => '', 'citations' => [], 'knowledge_warning' => ''];

    $citations = [];
    $parts = [];
    $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    $userRole = (string)($userInfo['role'] ?? 'user');
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
        $parts[] = "[SOURCE file_id={$fid}]\n{$txt}\n[/SOURCE]";
        $citations[] = [
            'file_id' => $fid,
            'chunk_id' => (int)($r['id'] ?? 0),
        ];
    }
    $knowledgeText = implode("\n\n", $parts);
    return ['knowledge_text' => $knowledgeText, 'citations' => $citations, 'knowledge_warning' => ''];
}

/**
 * Extract field keys from input_schema_json.
 * @return string[]
 */
function cnx_dw_schema_field_keys(?array $schema): array {
    if (!$schema) return [];
    $sections = $schema['sections'] ?? null;
    if (!is_array($sections)) return [];
    $keys = [];
    foreach ($sections as $sec) {
        if (!is_array($sec)) continue;
        $fields = $sec['fields'] ?? null;
        if (!is_array($fields)) continue;
        foreach ($fields as $f) {
            if (!is_array($f)) continue;
            $k = trim((string)($f['key'] ?? ''));
            if ($k !== '') $keys[] = $k;
        }
    }
    $keys = array_values(array_unique($keys));
    return $keys;
}

/**
 * Find a field definition inside input_schema_json (best-effort).
 * @return array<string,mixed>|null
 */
function cnx_dw_schema_find_field(?array $schema, string $fieldKey): ?array {
    if (!$schema) return null;
    $fieldKey = trim($fieldKey);
    if ($fieldKey === '') return null;
    $sections = $schema['sections'] ?? null;
    if (!is_array($sections)) return null;
    foreach ($sections as $sec) {
        if (!is_array($sec)) continue;
        $fields = $sec['fields'] ?? null;
        if (!is_array($fields)) continue;
        foreach ($fields as $f) {
            if (!is_array($f)) continue;
            $k = trim((string)($f['key'] ?? ''));
            if ($k === $fieldKey) return $f;
        }
    }
    return null;
}

/**
 * Best-effort safety filter to avoid returning normative/copyrighted content.
 * We sanitize obvious patterns and only fallback to TODO if forbidden content remains.
 */
function cnx_dw_contains_normative_leak(string $text): bool {
    $t = mb_strtolower($text, 'UTF-8');
    // Allowed: mentioning the standard name (e.g. "ISO 9001") without clause citations or normative wording.
    // We DO NOT treat "iso 9001" as leak by itself.
    if (preg_match('/\\buni\\b\\s*\\d{3,6}\\b/u', $t)) return true;
    if (preg_match('/\\bla\\s+norma\\s+richiede\\b|\\bcome\\s+previsto\\s+dalla\\s+norma\\b|\\bin\\s+conformit[aà]\\b|\\bconforme\\b|\\bobbligatorio\\b/u', $t)) return true;
    // clause-like numbering (we disallow clause citations in AI output)
    if (preg_match('/\\b\\d{1,2}\\.\\d(\\.\\d+)?\\b/u', $t)) return true;
    return false;
}

/**
 * Sanitize AI output by removing only forbidden parts, keeping useful content.
 *
 * @param array<int,string> $warnings Output list of applied sanitizations.
 */
function cnx_dw_sanitize_ai_output(string $text, array &$warnings = []): string {
    $t = (string)$text;
    if (trim($t) === '') return $t;

    $original = $t;

    // 1) Remove clause/paragraph citations (e.g., 7.5, 10.2.1) and variants like "clausola 7.5"
    $t2 = preg_replace('/\\b(?:clausola|paragrafo|punto|requisito)\\s*\\d{1,2}\\.\\d(?:\\.\\d+)?\\b/iu', '', $t);
    if ($t2 !== null) $t = $t2;
    $t2 = preg_replace('/\\b\\d{1,2}\\.\\d(?:\\.\\d+)?\\b/iu', '', $t);
    if ($t2 !== null) $t = $t2;

    // 2) Remove explicit normative framing phrases (keep the remainder of the sentence when possible)
    $t2 = preg_replace('/\\b(?:la\\s+norma\\s+richiede|come\\s+previsto\\s+dalla\\s+norma|in\\s+conformit[aà]\\s+alla\\s+norma|ai\\s+sensi\\s+della\\s+norma)\\b/iu', '', $t);
    if ($t2 !== null) $t = $t2;
    $t2 = preg_replace('/\\b(?:conforme|conformit[aà])\\s+(?:a|alla)\\s+(?:iso|uni|iec)\\b/iu', '', $t);
    if ($t2 !== null) $t = $t2;

    // 3) Remove UNI numeric references (keep ISO 9001 name allowed)
    $t2 = preg_replace('/\\buni\\s*\\d{3,6}\\b/iu', '', $t);
    if ($t2 !== null) $t = $t2;

    // Normalize whitespace/punctuation artifacts
    $t = preg_replace("/[ \\t]+/u", " ", $t) ?? $t;
    $t = preg_replace("/\\n{3,}/u", "\n\n", $t) ?? $t;
    $t = preg_replace("/\\s+([,;:.])/u", "$1", $t) ?? $t;
    $t = trim($t);

    if ($t !== trim($original)) {
        $warnings[] = 'sanitized_normative_or_clause_refs';
    }
    return $t;
}

/**
 * Sanitize → re-check → fallback to TODO only if forbidden content remains (or content becomes empty).
 *
 * @param array<int,string> $warnings Output list of applied sanitizations.
 */
function cnx_dw_safe_or_todo(string $text, string $fieldKey = '', array &$warnings = []): string {
    $t = trim((string)$text);
    if ($t === '') return $t;

    // First pass: sanitize and keep as much as possible
    $t = cnx_dw_sanitize_ai_output($t, $warnings);

    // If after sanitization it's still leaking or basically empty, fallback to TODO/questions
    if ($t === '' || mb_strlen($t, 'UTF-8') < 40 || cnx_dw_contains_normative_leak($t)) {
        $warnings[] = 'fallback_todo';
        $k = $fieldKey !== '' ? (" (" . $fieldKey . ")") : '';
        return "TODO{$k}: Inserisci dettagli specifici dell’azienda.\nDomande:\n- Che cosa includi nello scopo (sedi/processi/prodotti/servizi)?\n- Quali esclusioni ci sono (se presenti)?\n- Chi è responsabile del SGQ?\n- Quali strumenti/documenti usate oggi?";
    }
    return $t;
}

try {
    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) api_error('Body JSON non valido', 400);

    $artifactId = (int)($payload['artifact_id'] ?? 0);
    if ($artifactId <= 0) api_error('artifact_id richiesto', 400);

    $ctx = cnx_dw_load_artifact_ctx($db, $userInfo, $artifactId);
    $templateKey = (string)($ctx['template_key'] ?? '');
    if ($templateKey === '') api_error('Deliverable senza template_key', 400);

    $tpl = cnx_dw_load_template_schema($db, $templateKey);
    $schema = $tpl['schema'];
    // Runtime fallback schema if missing
    if (!$schema) {
        $schema = cnx_compliance_fallback_schema($templateKey, (string)($tpl['template_title'] ?? ''), (string)($tpl['doc_type'] ?? ''));
        $tpl['ai_hint'] = $tpl['ai_hint'] ?: cnx_compliance_fallback_ai_hint($templateKey, (string)($tpl['template_title'] ?? ''), (string)($tpl['doc_type'] ?? ''));
    }
    $fieldKeys = cnx_dw_schema_field_keys($schema);
    if (empty($fieldKeys)) {
        api_error('Schema compilazione non disponibile per questo template', 400);
    }

    $profile = cnx_dw_load_profile($db, $ctx['tenant_id'], $ctx['program_id']);
    $inputs = cnx_dw_load_inputs($db, $ctx['tenant_id'], $artifactId);
    $programCtx = cnx_dw_load_program_context($db, $ctx['tenant_id'], $ctx['program_id'], $artifactId);
    $onboardingChecklist = cnx_dw_load_onboarding_checklist($db, $ctx['tenant_id'], $ctx['program_id']);

    $docObjective = trim((string)($inputs['document_objective'] ?? ''));
    if (in_array($action, ['suggest_field', 'generate_document_draft'], true) && $docObjective === '') {
        api_error('Obiettivo documento mancante (compilalo prima di generare)', 400, ['field' => 'document_objective']);
    }

    $docTitle = (string)($tpl['template_title'] ?: ($ctx['artifact']['title'] ?? $templateKey));
    $docType = (string)($tpl['doc_type'] ?? '');
    $fileKind = (string)($tpl['file_kind'] ?? '');
    $aiHint = (string)($tpl['ai_hint'] ?? '');

    $guardrails = "Regole obbligatorie:\n"
        . "- Non riportare o citare testo di norme ISO/UNI e non usare virgolette con frasi che sembrano normative.\n"
        . "- Non citare clausole (es. \"7.5\") e non menzionare numeri di paragrafi.\n"
        . "- Scrivi contenuti originali, pratici e applicabili a un SGQ (ISO 9001) in stile aziendale.\n"
        . "- Usa un linguaggio semplice e operativo, senza legalese.\n"
        . "- Sii specifico: usa i dati del profilo/contesto forniti e NON scrivere testo generico.\n"
        . "- Se mancano dati, inserisci TODO/domande mirate invece di inventare.\n";

    $style = "Stile richiesto:\n"
        . "- Output esteso e utile (non sintetico), con frasi complete.\n"
        . "- Struttura in paragrafi e, quando utile, elenchi puntati.\n"
        . "- Includi dettagli pratici (ruoli, attività, strumenti, evidenze interne), senza citare norme.\n";

    if ($action === 'chat') {
        $messagesIn = $payload['messages'] ?? null;
        if (!is_array($messagesIn) || empty($messagesIn)) api_error('messages richiesto', 400);

        // Build compact chat history (last 12 messages, user/assistant only)
        $messages = [];
        foreach ($messagesIn as $m) {
            if (!is_array($m)) continue;
            $role = strtolower(trim((string)($m['role'] ?? '')));
            if (!in_array($role, ['user', 'assistant'], true)) continue;
            $content = trim((string)($m['content'] ?? ''));
            if ($content === '') continue;
            if (mb_strlen($content, 'UTF-8') > 5000) $content = mb_substr($content, 0, 5000, 'UTF-8') . '…';
            $messages[] = ['role' => $role, 'content' => $content];
        }
        $messages = array_slice($messages, max(0, count($messages) - 12));
        if (empty($messages)) api_error('messages vuoto', 400);

        // Last user message
        $lastUser = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $lastUser = (string)($messages[$i]['content'] ?? '');
                break;
            }
        }
        if (trim($lastUser) === '') $lastUser = (string)($messages[count($messages) - 1]['content'] ?? '');

        $selectedFieldKey = trim((string)($payload['selected_field_key'] ?? ''));
        $fieldDef = $selectedFieldKey !== '' ? cnx_dw_schema_find_field($schema, $selectedFieldKey) : null;
        $fieldLabel = $fieldDef ? trim((string)($fieldDef['label'] ?? '')) : '';

        // Knowledge query (best-effort)
        $q = $lastUser;
        if ($fieldLabel !== '') $q .= ' ' . $fieldLabel;
        $k = cnx_dw_ai_fetch_knowledge($db, $userInfo, (int)$ctx['tenant_id'], $q, 6);
        $knowledgeText = (string)($k['knowledge_text'] ?? '');
        $citations = is_array($k['citations'] ?? null) ? $k['citations'] : [];
        $knowledgeWarning = (string)($k['knowledge_warning'] ?? '');
        $k2 = cnx_dw_ai_fetch_tenant_doc_chunks($db, $userInfo, (int)$ctx['tenant_id'], $q, 6);
        $knowledgeText2 = (string)($k2['knowledge_text'] ?? '');
        if ($knowledgeText2 !== '') {
            $knowledgeText = trim($knowledgeText . "\n\n" . $knowledgeText2);
        }

        $system = [
            'role' => 'system',
            'content' =>
                "Sei un consulente qualità che lavora in chat per migliorare testi di un documento aziendale.\n"
                . $guardrails
                . $style
                . "Regola extra:\n- Se usi le FONTI, parafrasa e non citare testualmente.\n"
                . ($aiHint ? ("\nHint template:\n" . $aiHint . "\n") : ''),
        ];

        $ctxUser = [
            'role' => 'user',
            'content' =>
                "Documento: {$docTitle}\n"
                . "Tipo: {$docType}\n"
                . "Formato: {$fileKind}\n"
                . ($selectedFieldKey !== '' ? ("Campo selezionato: {$selectedFieldKey}\n") : '')
                . ($fieldLabel !== '' ? ("Etichetta campo: {$fieldLabel}\n") : '')
                . "\n"
                . "Profilo organizzazione (JSON):\n" . json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
                . (!empty($onboardingChecklist) ? ("Checklist raccolta dati (JSON):\n" . json_encode($onboardingChecklist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n") : "")
                . (!empty($programCtx) ? ("Contesto già compilato (altri deliverable) (JSON):\n" . json_encode($programCtx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n") : "")
                . "Valori già inseriti (JSON):\n" . json_encode($inputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
                . ($knowledgeText !== '' ? ("FONTI (documentazione tenant):\n\n" . $knowledgeText . "\n\n") : "")
                . "Cronologia chat (usa questa per rispondere):\n"
                . json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
                . "Rispondi con un testo pronto da incollare. Niente markdown.",
        ];

        $schemaOut = [
            'name' => 'artifact_chat_response',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'answer_text' => ['type' => 'string'],
                ],
                'required' => ['answer_text'],
            ],
        ];

        $resp = cnx_openai_chat_json([$system, $ctxUser], $schemaOut, [
            'max_tokens' => 1800,
            'temperature' => 0.2,
            'timeout_seconds' => 90,
            'max_retries' => 1,
        ]);
        if (!$resp['ok']) {
            api_error((string)($resp['error'] ?? 'AI non disponibile'), 503, ['debug' => $resp['debug'] ?? null]);
        }

        $warns = [];
        $text = cnx_dw_safe_or_todo((string)($resp['data']['answer_text'] ?? ''), $selectedFieldKey !== '' ? $selectedFieldKey : 'chat', $warns);
        api_success([
            'answer_text' => $text,
            'citations' => $citations,
            'knowledge_warning' => $knowledgeWarning,
            'ai_warnings' => $warns,
        ], 'Chat AI');
    }

    if ($action === 'suggest_field') {
        $fieldKey = trim((string)($payload['field_key'] ?? ''));
        if ($fieldKey === '') api_error('field_key richiesto', 400);
        if (!in_array($fieldKey, $fieldKeys, true)) api_error('field_key non presente nello schema', 400);

        $currentValues = $payload['current_values'] ?? null;
        if ($currentValues !== null && !is_array($currentValues)) $currentValues = null;

        $fieldDef = cnx_dw_schema_find_field($schema, $fieldKey);
        $fieldType = strtolower(trim((string)($fieldDef['type'] ?? 'text')));
        $isShortField = ($fieldType === 'text' || $fieldKey === 'scope' || str_contains($fieldKey, 'scopo'));
        $targetChars = $isShortField ? '600–1200' : '1500–3500';

        $system = [
            'role' => 'system',
            'content' =>
                "Sei un consulente qualità che scrive contenuti ORIGINALI per documenti aziendali.\n"
                . $guardrails
                . $style
                . ($aiHint ? ("\nHint template:\n" . $aiHint . "\n") : ''),
        ];
        $user = [
            'role' => 'user',
            'content' =>
                "Documento: {$docTitle}\n"
                . "Tipo: {$docType}\n"
                . "Formato: {$fileKind}\n"
                . "Campo da suggerire: {$fieldKey}\n\n"
                . "Profilo organizzazione (JSON):\n" . json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
                . (!empty($programCtx) ? ("Contesto già compilato (altri deliverable) (JSON):\n" . json_encode($programCtx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n") : "")
                . "Valori correnti (JSON):\n" . json_encode($currentValues ?? $inputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
                . "Restituisci un testo adatto al campo richiesto, dettagliato e pronto da copiare nel documento.\n"
                . "Lunghezza indicativa: {$targetChars} caratteri (se il campo lo consente). Niente markdown.\n"
                . ($isShortField ? "Per questo campo preferisci 1 paragrafo sintetico, senza elenchi lunghi.\n" : ""),
        ];

        // Knowledge context (best-effort)
        $k = cnx_dw_ai_fetch_knowledge($db, $userInfo, (int)$ctx['tenant_id'], $fieldKey . ' ' . (string)($fieldDef['label'] ?? '') . ' ' . $docTitle, 6);
        if (!empty($k['knowledge_text'])) {
            $user['content'] .= "\n\nFONTI (documentazione tenant):\n\n" . (string)$k['knowledge_text'] . "\n\nRegola extra: se usi le FONTI, parafrasa e non citare testualmente.\n";
        }

        $schemaOut = [
            'name' => 'artifact_field_suggestion',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'suggestion_text' => ['type' => 'string'],
                ],
                'required' => ['suggestion_text'],
            ],
        ];

        $resp = cnx_openai_chat_json([$system, $user], $schemaOut, [
            'max_tokens' => $isShortField ? 900 : 1800,
            'temperature' => 0.2,
            'timeout_seconds' => 60,
            'max_retries' => 1,
        ]);
        if (!$resp['ok']) {
            api_error((string)($resp['error'] ?? 'AI non disponibile'), 503, ['debug' => $resp['debug'] ?? null]);
        }

        $warnings = [];
        $text = cnx_dw_safe_or_todo((string)($resp['data']['suggestion_text'] ?? ''), $fieldKey, $warnings);
        api_success([
            'suggestion_text' => $text,
            'ai_warnings' => $warnings,
            'citations' => is_array($k['citations'] ?? null) ? $k['citations'] : [],
            'knowledge_warning' => (string)($k['knowledge_warning'] ?? ''),
        ], 'Suggerimento AI');
    }

    if ($action === 'generate_document_draft') {
        $keysList = implode(', ', $fieldKeys);
        $system = [
            'role' => 'system',
            'content' =>
                "Sei un consulente qualità che compila contenuti ORIGINALI per un documento SGQ.\n"
                . $guardrails
                . $style
                . "Devi restituire un oggetto JSON con chiavi ESATTAMENTE tra: {$keysList}.\n"
                . "Se un campo non è applicabile, restituisci stringa vuota.\n"
                . ($aiHint ? ("\nHint template:\n" . $aiHint . "\n") : ''),
        ];
        $user = [
            'role' => 'user',
            'content' =>
                "Documento: {$docTitle}\n"
                . "Tipo: {$docType}\n"
                . "Formato: {$fileKind}\n\n"
                . "Schema (JSON):\n" . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
                . "Profilo organizzazione (JSON):\n" . json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
                . (!empty($programCtx) ? ("Contesto già compilato (altri deliverable) (JSON):\n" . json_encode($programCtx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n") : "")
                . "Valori già inseriti (JSON):\n" . json_encode($inputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
                . "Genera una bozza coerente e pronta da incollare nei campi (max 2000 caratteri per campo).",
        ];

        // Knowledge context (best-effort)
        $k = cnx_dw_ai_fetch_knowledge($db, $userInfo, (int)$ctx['tenant_id'], $docTitle . ' ' . $docType, 6);
        if (!empty($k['knowledge_text'])) {
            $user['content'] .= "\n\nFONTI (documentazione tenant):\n\n" . (string)$k['knowledge_text'] . "\n\nRegola extra: se usi le FONTI, parafrasa e non citare testualmente.\n";
        }

        // Build a strict schema with allowed keys
        $props = [];
        foreach ($fieldKeys as $k) {
            $props[$k] = ['type' => 'string'];
        }
        $schemaOut = [
            'name' => 'artifact_document_draft',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => $props,
                'required' => $fieldKeys,
            ],
        ];

        $resp = cnx_openai_chat_json([$system, $user], $schemaOut, [
            'max_tokens' => 2600,
            'temperature' => 0.2,
            'timeout_seconds' => 90,
            'max_retries' => 1,
        ]);
        if (!$resp['ok']) {
            api_error((string)($resp['error'] ?? 'AI non disponibile'), 503, ['debug' => $resp['debug'] ?? null]);
        }

        $out = [];
        $allWarnings = [];
        foreach ($fieldKeys as $k) {
            $w = [];
            $out[$k] = cnx_dw_safe_or_todo((string)($resp['data'][$k] ?? ''), $k, $w);
            if (!empty($w)) {
                foreach ($w as $ww) $allWarnings[] = $k . ':' . $ww;
            }
        }
        api_success([
            'suggested_values_map' => $out,
            'ai_warnings' => array_values(array_unique($allWarnings)),
            'citations' => is_array($k['citations'] ?? null) ? $k['citations'] : [],
            'knowledge_warning' => (string)($k['knowledge_warning'] ?? ''),
        ], 'Bozza AI');
    }

    api_error('Azione non supportata', 400);
} catch (Throwable $e) {
    error_log('[COMPLIANCE_ARTIFACT_AI] ' . $e->getMessage());
    api_error('Errore AI deliverable', 500);
}

