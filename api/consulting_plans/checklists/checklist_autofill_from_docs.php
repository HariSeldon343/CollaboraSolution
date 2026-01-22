<?php
// POST: best-effort autofill checklist evidence from client tenant documents (IMS/Knowledge)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

verifyApiCsrfToken(true);
requireApiRole('admin');

function cnx_chk_has_table(Database $db, string $table): bool {
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

function cnx_chk_norm_hay(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/\s+/', ' ', $s) ?: $s;
    return trim($s);
}

try {
    $storage = cnx_checklists_check_storage($db);
    if (empty($storage['ok'])) {
        api_error(
            'Checklist non disponibile (schema non aggiornato)',
            503,
            ['storage_available' => false, 'missing' => $storage['missing'] ?? [], 'migration' => $storage['migration'] ?? CNX_CHECKLISTS_MIGRATION_HINT]
        );
    }

    $payload = json_decode(cnx_get_raw_request_body(), true) ?: [];
    if (!is_array($payload)) api_error('Dati non validi', 400);

    $planId = (int)($payload['plan_id'] ?? 0);
    if ($planId <= 0) api_error('plan_id obbligatorio', 400);
    $companyId = (int)($payload['company_id'] ?? 0);
    if ($companyId < 0) $companyId = 0;

    $templateKeyIn = (string)($payload['template_key'] ?? 'SGQ_ISO9001_BANDO');
    $templateKey = cnx_checklists_norm_template_key($templateKeyIn);
    if ($templateKey === '') $templateKey = 'SGQ_ISO9001_BANDO';

    // Load checklist
    $chk = $db->fetchOne(
        "SELECT *
         FROM consulting_project_checklists
         WHERE tenant_id = ?
           AND plan_id = ?
           AND template_key = ?
         ORDER BY id DESC
         LIMIT 1",
        [CNX_VENDOR_TENANT_ID, $planId, $templateKey]
    );
    if (!$chk || empty($chk['id'])) {
        api_error('Checklist non trovata: crea prima la checklist da template', 404, ['can_create' => true, 'template_key' => $templateKey]);
    }
    $checklistId = (int)$chk['id'];
    $clientTenantId = (int)($chk['client_tenant_id'] ?? 0);
    if ($clientTenantId <= 0) api_error('Checklist non valida (client_tenant_id mancante)', 400);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato al tenant cliente', 403);

    // Load items (only file-based)
    $items = $db->fetchAll(
        "SELECT id, item_key, section_key, title, description, question_type, required, status, evidence_json
         FROM consulting_project_checklist_items
         WHERE checklist_id = ?
         ORDER BY sort_order ASC, id ASC",
        [$checklistId]
    ) ?: [];

    // Load template to get per-item keywords (best-effort)
    $tpl = cnx_checklists_load_template($templateKey);
    $kwByKey = [];
    if (!empty($tpl['ok']) && is_array($tpl['template'] ?? null)) {
        $sections = is_array($tpl['template']['sections'] ?? null) ? $tpl['template']['sections'] : [];
        foreach ($sections as $s) {
            if (!is_array($s)) continue;
            $its = is_array($s['items'] ?? null) ? $s['items'] : [];
            foreach ($its as $it) {
                if (!is_array($it)) continue;
                $ik = trim((string)($it['item_key'] ?? ''));
                if ($ik === '') continue;
                $kws = [];
                if (isset($it['autofill_keywords']) && is_array($it['autofill_keywords'])) {
                    foreach ($it['autofill_keywords'] as $w) {
                        $w = cnx_chk_norm_hay((string)$w);
                        if ($w === '' || mb_strlen($w, 'UTF-8') < 2) continue;
                        $kws[] = $w;
                    }
                }
                $kws = array_values(array_unique(array_filter($kws)));
                if (!empty($kws)) $kwByKey[$ik] = $kws;
            }
        }
    }

    // Knowledge index: list candidate files (metadata only)
    $docsSupported = cnx_chk_has_table($db, 'ai_knowledge_sources') && cnx_chk_has_table($db, 'ai_knowledge_chunks');
    $sourceId = 0;
    $files = [];
    $docEvidenceByFileId = [];
    if ($docsSupported) {
        $src = $db->fetchOne(
            "SELECT id
             FROM ai_knowledge_sources
             WHERE tenant_id = ? AND is_active = 1
             ORDER BY id DESC
             LIMIT 1",
            [$clientTenantId]
        );
        $sourceId = $src ? (int)($src['id'] ?? 0) : 0;
        if ($sourceId > 0) {
            $rows = $db->fetchAll(
                "SELECT file_id,
                        MAX(file_name) AS file_name,
                        MAX(logical_path) AS logical_path,
                        MAX(updated_at) AS indexed_at
                 FROM ai_knowledge_chunks
                 WHERE tenant_id = ?
                   AND source_id = ?
                 GROUP BY file_id
                 ORDER BY MAX(updated_at) DESC, file_id DESC
                 LIMIT 450",
                [$clientTenantId, $sourceId]
            ) ?: [];
            foreach ($rows as $r) {
                $fid = (int)($r['file_id'] ?? 0);
                if ($fid <= 0) continue;
                $files[] = [
                    'file_id' => $fid,
                    'name' => (string)($r['file_name'] ?? ''),
                    'path' => (string)($r['logical_path'] ?? ''),
                ];
            }
        }
    }

    // Optional: include latest doc profile summary if present (no raw document content)
    $docProfileSummary = null;
    if (cnx_chk_has_table($db, 'ai_tenant_doc_profiles')) {
        try {
            $r = $db->fetchOne(
                "SELECT id, computed_at, expires_at, payload_json
                 FROM ai_tenant_doc_profiles
                 WHERE tenant_id = ?
                   AND scope = 'IMS_PLANNING'
                 ORDER BY computed_at DESC, id DESC
                 LIMIT 1",
                [$clientTenantId]
            );
            if ($r && trim((string)($r['payload_json'] ?? '')) !== '') {
                $p = json_decode((string)$r['payload_json'], true);
                if (is_array($p)) {
                    $docProfileSummary = [
                        'doc_profile_id' => (int)($r['id'] ?? 0),
                        'computed_at' => (string)($r['computed_at'] ?? ''),
                        'expires_at' => (string)($r['expires_at'] ?? ''),
                        'maturity_suggested' => (string)($p['maturity_suggested'] ?? ''),
                        'confidence' => (int)($p['confidence'] ?? 0),
                        'planning_adjustments' => (is_array($p['planning_adjustments'] ?? null) ? $p['planning_adjustments'] : null),
                        'gaps' => (is_array($p['gaps'] ?? null) ? array_slice($p['gaps'], 0, 6) : []),
                    ];

                    // Best-effort: import doc_evidence tags/keywords to improve matching.
                    $evList = is_array($p['doc_evidence'] ?? null) ? $p['doc_evidence'] : [];
                    foreach ($evList as $ev) {
                        if (!is_array($ev)) continue;
                        $fid = (int)($ev['file_id'] ?? 0);
                        if ($fid <= 0) continue;
                        $tags = isset($ev['tags']) && is_array($ev['tags']) ? array_values(array_unique(array_filter(array_map('strval', $ev['tags'])))) : [];
                        $mk = isset($ev['matched_keywords']) && is_array($ev['matched_keywords']) ? array_values(array_unique(array_filter(array_map('strval', $ev['matched_keywords'])))) : [];
                        $docEvidenceByFileId[$fid] = ['tags' => $tags, 'matched_keywords' => $mk];
                    }
                }
            }
        } catch (Throwable $e) {
            $docProfileSummary = null;
        }
    }

    // Optional: normalized evidence storage (migration 65)
    $hasEvidenceTable = cnx_chk_has_table($db, 'consulting_project_checklist_evidence');

    // Build search index over files (name + path)
    $fileIndex = [];
    foreach ($files as $f) {
        $fid = (int)($f['file_id'] ?? 0);
        $tags = $docEvidenceByFileId[$fid]['tags'] ?? [];
        $mk = $docEvidenceByFileId[$fid]['matched_keywords'] ?? [];
        $tagsHay = cnx_chk_norm_hay(implode(' ', array_merge((array)$tags, (array)$mk)));
        $hay = cnx_chk_norm_hay((string)($f['name'] ?? '') . ' ' . (string)($f['path'] ?? '') . ' ' . $tagsHay);
        $fileIndex[] = [
            'file_id' => (int)($f['file_id'] ?? 0),
            'name' => (string)($f['name'] ?? ''),
            'path' => (string)($f['path'] ?? ''),
            'hay' => $hay,
            'name_lc' => cnx_chk_norm_hay((string)($f['name'] ?? '')),
            'tags_hay' => $tagsHay,
            'tags' => $tags,
            'matched_keywords' => $mk,
        ];
    }

    $updated = 0;
    $matched = 0;
    $changedItems = [];
    $now = date('Y-m-d H:i:s');

    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $qid = (int)($it['id'] ?? 0);
        if ($qid <= 0) continue;
        $qType = strtolower(trim((string)($it['question_type'] ?? '')));
        if (!in_array($qType, ['file','multi_file'], true)) continue;
        $status = strtolower(trim((string)($it['status'] ?? 'missing')));
        if ($status === 'done' || $status === 'not_applicable') continue;

        $itemKey = (string)($it['item_key'] ?? '');
        $keywords = $kwByKey[$itemKey] ?? [];
        if (empty($keywords)) {
            // fallback keywords from title
            $keywords = [];
            $t = cnx_chk_norm_hay((string)($it['title'] ?? ''));
            foreach (preg_split('/\s+/', $t) as $w) {
                $w = trim((string)$w);
                if (mb_strlen($w, 'UTF-8') < 4) continue;
                $keywords[] = $w;
            }
            $keywords = array_values(array_unique(array_filter($keywords)));
        }

        if (empty($fileIndex) || empty($keywords)) continue;

        $scores = [];
        foreach ($fileIndex as $f) {
            $score = 0;
            $hit = [];
            foreach ($keywords as $kw) {
                if ($kw === '') continue;
                if (str_contains($f['name_lc'], $kw)) { $score += 2; $hit[] = $kw; }
                elseif (str_contains($f['hay'], $kw)) { $score += 1; $hit[] = $kw; }
                if (!empty($f['tags_hay']) && str_contains($f['tags_hay'], $kw)) { $score += 3; $hit[] = $kw; }
            }
            if ($score > 0) {
                $scores[] = [
                    'score' => $score,
                    'file_id' => $f['file_id'],
                    'name' => $f['name'],
                    'path' => $f['path'],
                    'tags' => $f['tags'] ?? [],
                    'matched_keywords' => array_slice(array_values(array_unique(array_filter($hit))), 0, 10),
                ];
            }
        }

        if (empty($scores)) continue;
        usort($scores, static function ($a, $b) {
            if ($a['score'] !== $b['score']) return (int)$b['score'] <=> (int)$a['score'];
            return (int)$a['file_id'] <=> (int)$b['file_id'];
        });

        $take = ($qType === 'multi_file') ? 4 : 1;
        $evNew = [];
        foreach (array_slice($scores, 0, $take) as $m) {
            $evNew[] = [
                'tenant_id' => $clientTenantId,
                'file_id' => (int)($m['file_id'] ?? 0),
                'path' => (string)($m['path'] ?? ''),
                'name' => (string)($m['name'] ?? ''),
                'tags' => (is_array($m['tags'] ?? null) ? array_values(array_unique(array_filter(array_map('strval', $m['tags'])))) : []),
                'matched_keywords' => (is_array($m['matched_keywords'] ?? null) ? array_values(array_unique(array_filter(array_map('strval', $m['matched_keywords'])))) : []),
                'match_score' => (int)($m['score'] ?? 0),
            ];
        }
        if (empty($evNew)) continue;

        $matched++;

        // Merge evidence (non-destructive): keep existing evidence, append new, dedup by file_id.
        $evOldRaw = (string)($it['evidence_json'] ?? '');
        $evOld = [];
        if (trim($evOldRaw) !== '') {
            $decOld = json_decode($evOldRaw, true);
            if (is_array($decOld)) $evOld = $decOld;
        }
        $oldIds = [];
        foreach ($evOld as $x) {
            if (!is_array($x)) continue;
            $fid = (int)($x['file_id'] ?? 0);
            if ($fid > 0) $oldIds[$fid] = true;
        }
        $addedAny = false;
        foreach ($evNew as $x) {
            if (!is_array($x)) continue;
            $fid = (int)($x['file_id'] ?? 0);
            if ($fid <= 0) continue;
            if (!isset($oldIds[$fid])) { $addedAny = true; break; }
        }

        // If no new evidence to add, do not overwrite evidence_json (preserves manual entries + avoids churn).
        if (!$addedAny && !($status === 'missing' || $status === '')) {
            continue;
        }

        $combined = array_merge($evOld, $evNew);
        $seen = [];
        $evFinal = [];
        $cap = ($qType === 'multi_file') ? 6 : 3;
        foreach ($combined as $x) {
            if (!is_array($x)) continue;
            $fid = (int)($x['file_id'] ?? 0);
            if ($fid <= 0 || isset($seen[$fid])) continue;
            $seen[$fid] = true;
            $evFinal[] = $x;
            if (count($evFinal) >= $cap) break;
        }

        $evidenceJson = cnx_checklists_json_stringify($evFinal, 20000);
        if ($evidenceJson === null) continue;

        $upd = [
            'evidence_json' => $evidenceJson,
            'updated_at' => $now,
        ];
        if ($status === 'missing' || $status === '') {
            $upd['status'] = 'present';
        }
        $db->update('consulting_project_checklist_items', $upd, ['id' => $qid]);

        // Best-effort: also upsert normalized evidence rows (if available) for reporting.
        if ($hasEvidenceTable && !empty($evFinal)) {
            $createdBy = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
            foreach ($evFinal as $x) {
                if (!is_array($x)) continue;
                $fid = (int)($x['file_id'] ?? 0);
                if ($fid <= 0) continue;
                $name = trim((string)($x['name'] ?? ''));
                $path = trim((string)($x['path'] ?? ''));
                if (mb_strlen($name, 'UTF-8') > 255) $name = mb_substr($name, 0, 255, 'UTF-8');
                if (mb_strlen($path, 'UTF-8') > 600) $path = mb_substr($path, 0, 600, 'UTF-8');
                $meta = [
                    'tags' => (isset($x['tags']) && is_array($x['tags'])) ? array_values(array_unique(array_filter(array_map('strval', $x['tags'])))) : [],
                    'matched_keywords' => (isset($x['matched_keywords']) && is_array($x['matched_keywords'])) ? array_values(array_unique(array_filter(array_map('strval', $x['matched_keywords'])))) : [],
                    'match_score' => (int)($x['match_score'] ?? 0),
                ];
                $metaJson = cnx_checklists_json_stringify($meta, 8000);
                try {
                    $db->query(
                        "INSERT INTO consulting_project_checklist_evidence (tenant_id, checklist_item_id, file_tenant_id, file_id, file_name, file_path, meta_json, created_by_user_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), file_path = VALUES(file_path), meta_json = COALESCE(VALUES(meta_json), meta_json)",
                        [CNX_VENDOR_TENANT_ID, $qid, $clientTenantId, $fid, $name, $path, $metaJson, $createdBy > 0 ? $createdBy : null]
                    );
                } catch (Throwable $e) {
                    // ignore
                }
            }
        }
        $updated++;
        $changedItems[] = ['id' => $qid, 'item_key' => $itemKey, 'status' => ($upd['status'] ?? $status), 'evidence_count' => count($evFinal)];
    }

    // Update checklist last_docs_* timestamps (best-effort)
    $chkUpd = [
        'last_docs_snapshot_at' => $now,
        'updated_at' => $now,
    ];
    if ($docProfileSummary && !empty($docProfileSummary['computed_at'])) {
        $chkUpd['last_docs_analyze_at'] = (string)$docProfileSummary['computed_at'];
    }
    $db->update('consulting_project_checklists', $chkUpd, ['id' => $checklistId]);

    api_success([
        'storage_available' => true,
        'docs_supported' => $docsSupported,
        'client_tenant_id' => $clientTenantId,
        'source_id' => $sourceId,
        'files_scanned' => count($fileIndex),
        'matched_items' => $matched,
        'updated_items' => $updated,
        'changed_items' => $changedItems,
        'doc_profile' => $docProfileSummary,
    ]);
} catch (Throwable $e) {
    $errId = 'chk_auto_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[CONSULTING_CHECKLIST_AUTOFILL][{$errId}] " . $e->getMessage());
    api_error('Errore rilevamento documenti', 500, ['error_id' => $errId]);
}

