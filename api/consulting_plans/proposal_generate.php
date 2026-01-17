<?php
// POST: Generate AI proposal for a consulting plan (Tenant 28 internal tools)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/openai_client.php';
require_once __DIR__ . '/../../includes/compliance/iso9001_requirements_fallback.php';

verifyApiCsrfToken();

function cnx_is_valid_date(string $d): bool {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
}

function cnx_today(): string {
    return date('Y-m-d');
}

function cnx_add_days(string $dateYmd, int $days): string {
    $ts = strtotime($dateYmd);
    if (!$ts) return cnx_today();
    return date('Y-m-d', $ts + ($days * 86400));
}

/**
 * @return array<string,string> code => edition
 */
function cnx_standards_latest_map(): array {
    return [
        'ISO 9001' => 'ISO 9001:2015/Amd 1:2024',
        'ISO 14001' => 'ISO 14001:2015/Amd 1:2024',
        'ISO 45001' => 'ISO 45001:2018/Amd 1:2024',
        'ISO/IEC 27001' => 'ISO/IEC 27001:2022/Amd 1:2024',
    ];
}

/**
 * @param mixed $raw
 * @return string[] normalized codes present in cnx_standards_latest_map()
 */
function cnx_normalize_standards($raw): array {
    $allowed = array_keys(cnx_standards_latest_map());
    $arr = is_array($raw) ? $raw : [];
    $out = [];
    foreach ($arr as $v) {
        $s = trim((string)$v);
        if ($s === '') continue;
        // Normalize common variants
        if ($s === 'ISO 27001') $s = 'ISO/IEC 27001';
        if (in_array($s, $allowed, true)) $out[] = $s;
    }
    $out = array_values(array_unique($out));
    if (empty($out)) $out = ['ISO 9001'];
    return $out;
}

/**
 * Minimal schema validation (server-side safety).
 * @return array{ok:bool,error?:string}
 */
function cnx_validate_proposal(array &$p, array $allowedAssignees): array {
    if (!isset($p['proposal_title']) || trim((string)$p['proposal_title']) === '') {
        return ['ok' => false, 'error' => 'proposal_title mancante'];
    }
    if (!isset($p['timeline']) || !is_array($p['timeline'])) {
        return ['ok' => false, 'error' => 'timeline mancante'];
    }
    $tl = $p['timeline'];
    if (!cnx_is_valid_date((string)($tl['start_date'] ?? '')) || !cnx_is_valid_date((string)($tl['end_date'] ?? ''))) {
        return ['ok' => false, 'error' => 'timeline.start_date/end_date non validi'];
    }
    if (!isset($p['confidence']) || !is_array($p['confidence'])) {
        return ['ok' => false, 'error' => 'confidence mancante'];
    }
    $score = (float)($p['confidence']['score'] ?? -1);
    if (!is_finite($score) || $score < 0 || $score > 1) {
        $p['confidence']['score'] = max(0.0, min(1.0, $score));
    }
    if (!isset($p['risks']) || !is_array($p['risks'])) $p['risks'] = [];
    if (!isset($p['items']) || !is_array($p['items']) || count($p['items']) < 1) {
        return ['ok' => false, 'error' => 'items mancanti'];
    }

    $outItems = [];
    foreach ($p['items'] as $it) {
        if (!is_array($it)) continue;
        $type = (string)($it['activity_type'] ?? '');
        $validTypes = ['onsite','remote','call','communication','travel'];
        if (!in_array($type, $validTypes, true)) $type = 'remote';

        $sd = (string)($it['start_date'] ?? '');
        $ed = (string)($it['end_date'] ?? '');
        if (!cnx_is_valid_date($sd)) $sd = cnx_today();
        if (!cnx_is_valid_date($ed)) $ed = $sd;

        $assignee = (int)($it['assignee_user_id'] ?? 0);
        if (!in_array($assignee, $allowedAssignees, true)) {
            $assignee = $allowedAssignees[0] ?? 0;
        }

        $days = (float)($it['days'] ?? 0);
        if (!is_finite($days) || $days < 0) $days = 0.0;

        $dayRate = (float)($it['day_rate'] ?? 0);
        if (!is_finite($dayRate) || $dayRate < 0) $dayRate = 0.0;

        $km = (float)($it['km'] ?? 0);
        if (!is_finite($km) || $km < 0) $km = 0.0;

        $extras = (float)($it['extras_amount'] ?? 0);
        if (!is_finite($extras) || $extras < 0) $extras = 0.0;

        $title = trim((string)($it['title'] ?? ''));
        $desc = trim((string)($it['description'] ?? ''));

        $outItems[] = [
            'activity_type' => $type,
            'title' => $title !== '' ? $title : 'Attività',
            'start_date' => $sd,
            'end_date' => $ed,
            'assignee_user_id' => $assignee,
            'days' => $days,
            'day_rate' => $dayRate,
            'km' => $km,
            'extras_amount' => $extras,
            'description' => $desc,
            'domain_activity_type_name' => isset($it['domain_activity_type_name']) ? trim((string)$it['domain_activity_type_name']) : '',
        ];
    }
    if (empty($outItems)) return ['ok' => false, 'error' => 'items vuoti'];
    $p['items'] = $outItems;
    return ['ok' => true];
}

/**
 * Minimal validation/sanitization for compliance_blueprint output.
 * @return array{ok:bool,error?:string}
 */
function cnx_validate_compliance_blueprint(array &$b): array {
    if (!isset($b['standards']) || !is_array($b['standards']) || count($b['standards']) < 1) {
        return ['ok' => false, 'error' => 'standards mancanti'];
    }
    if (!isset($b['scope_proposal']) || trim((string)$b['scope_proposal']) === '') {
        return ['ok' => false, 'error' => 'scope_proposal mancante'];
    }
    if (!isset($b['assumptions']) || !is_array($b['assumptions'])) $b['assumptions'] = [];
    if (!isset($b['open_questions']) || !is_array($b['open_questions'])) $b['open_questions'] = [];
    if (!isset($b['process_map']) || !is_array($b['process_map'])) $b['process_map'] = [];
    if (!isset($b['repository_structure']) || !is_array($b['repository_structure'])) $b['repository_structure'] = [];
    if (!isset($b['documents']) || !is_array($b['documents'])) $b['documents'] = [];
    if (!isset($b['records_register']) || !is_array($b['records_register'])) $b['records_register'] = [];
    if (!isset($b['audit_program']) || !is_array($b['audit_program'])) {
        $b['audit_program'] = [
            'internal_audit_frequency_months' => 12,
            'management_review_frequency_months' => 12,
            'notes' => '',
        ];
    } else {
        if (!isset($b['audit_program']['internal_audit_frequency_months'])) $b['audit_program']['internal_audit_frequency_months'] = 12;
        if (!isset($b['audit_program']['management_review_frequency_months'])) $b['audit_program']['management_review_frequency_months'] = 12;
        if (!isset($b['audit_program']['notes'])) $b['audit_program']['notes'] = '';
    }
    return ['ok' => true];
}

/**
 * Load ISO 9001 requirements catalog from DB if available; otherwise fallback hardcoded.
 *
 * @return array{storage_available:bool,source:string,standard_code:string,edition:string,requirements:array<int,array<string,mixed>>}
 */
function cnx_load_iso9001_requirements_catalog(Database $db): array {
    $standardCode = 'ISO 9001';
    $edition = '2015+Amd1:2024';

    $storageAvailable = false;
    try {
        $storageAvailable = (bool)$db->fetchOne("SHOW TABLES LIKE 'compliance_requirements_catalog'");
    } catch (Throwable $e) {
        $storageAvailable = false;
    }

    $reqs = [];
    $source = 'fallback';
    if ($storageAvailable) {
        $rows = $db->fetchAll(
            "SELECT clause, section, title, intent_summary,
                    evidence_examples_json, artifacts_json, special_notes_json,
                    sort_order
             FROM compliance_requirements_catalog
             WHERE standard_code = ?
               AND edition = ?
               AND is_active = 1
             ORDER BY sort_order ASC, clause ASC",
            [$standardCode, $edition]
        ) ?: [];

        foreach ($rows as $r) {
            $evidence = [];
            $artifacts = [];
            $notes = [];
            try { $evidence = $r['evidence_examples_json'] ? (json_decode((string)$r['evidence_examples_json'], true) ?: []) : []; } catch (Throwable $e) { $evidence = []; }
            try { $artifacts = $r['artifacts_json'] ? (json_decode((string)$r['artifacts_json'], true) ?: []) : []; } catch (Throwable $e) { $artifacts = []; }
            try { $notes = $r['special_notes_json'] ? (json_decode((string)$r['special_notes_json'], true) ?: []) : []; } catch (Throwable $e) { $notes = []; }

            $reqs[] = [
                'clause' => (string)($r['clause'] ?? ''),
                'section' => (string)($r['section'] ?? ''),
                'title' => (string)($r['title'] ?? ''),
                'intent_summary' => (string)($r['intent_summary'] ?? ''),
                'evidence_examples' => is_array($evidence) ? array_values($evidence) : [],
                'artifacts' => is_array($artifacts) ? array_values($artifacts) : [],
                'special_notes' => is_array($notes) ? array_values($notes) : [],
                'sort_order' => (int)($r['sort_order'] ?? 0),
            ];
        }

        if (!empty($reqs)) {
            $source = 'db';
            return [
                'storage_available' => true,
                'source' => $source,
                'standard_code' => $standardCode,
                'edition' => $edition,
                'requirements' => $reqs,
            ];
        }
    }

    // Fallback (schema drift safe)
    $fb = cnx_get_iso9001_requirements_fallback();
    $reqs = (array)($fb['requirements'] ?? []);
    return [
        'storage_available' => false,
        'source' => 'fallback',
        'standard_code' => $standardCode,
        'edition' => $edition,
        'requirements' => $reqs,
    ];
}

/**
 * @return string[]
 */
function cnx_extract_clause_refs_from_blueprint(array $blueprint): array {
    $out = [];
    $docs = isset($blueprint['documents']) && is_array($blueprint['documents']) ? $blueprint['documents'] : [];
    foreach ($docs as $d) {
        if (!is_array($d)) continue;
        $refs = isset($d['clause_refs']) && is_array($d['clause_refs']) ? $d['clause_refs'] : [];
        foreach ($refs as $r) {
            $s = trim((string)$r);
            if ($s !== '') $out[] = $s;
        }
    }
    $regs = isset($blueprint['records_register']) && is_array($blueprint['records_register']) ? $blueprint['records_register'] : [];
    foreach ($regs as $r) {
        if (!is_array($r)) continue;
        $refs = isset($r['clause_refs']) && is_array($r['clause_refs']) ? $r['clause_refs'] : [];
        foreach ($refs as $x) {
            $s = trim((string)$x);
            if ($s !== '') $out[] = $s;
        }
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

function cnx_capacity_mobility_score(string $activityType): int {
    // Lower is more "movable" between consultants
    switch ($activityType) {
        case 'communication': return 1;
        case 'call': return 2;
        case 'remote': return 3;
        case 'travel': return 4;
        case 'onsite': return 5;
        default: return 3;
    }
}

/**
 * @param array<int,float> $availableDaysByConsultant user_id => available days
 * @return array{available_days_by_consultant:array<int,float>,used_days_by_consultant:array<int,float>,overages:array<int,array{assignee_user_id:int,used_days:float,available_days:float,over_by_days:float}>,rebalance_applied:bool,notes:string[]}
 */
function cnx_build_capacity_report(array $availableDaysByConsultant, array $items, bool $rebalanceApplied, array $notes = []): array {
    $used = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $uid = (int)($it['assignee_user_id'] ?? 0);
        if ($uid <= 0) continue;
        $d = (float)($it['days'] ?? 0);
        if (!is_finite($d) || $d < 0) $d = 0.0;
        $used[$uid] = ($used[$uid] ?? 0.0) + $d;
    }

    $overages = [];
    $allUids = array_values(array_unique(array_merge(array_keys($availableDaysByConsultant), array_keys($used))));
    foreach ($allUids as $uid) {
        $u = (float)($used[$uid] ?? 0.0);
        $a = (float)($availableDaysByConsultant[$uid] ?? 0.0);
        $over = $u - $a;
        if ($over > 1e-6) {
            $overages[] = [
                'assignee_user_id' => (int)$uid,
                'used_days' => $u,
                'available_days' => $a,
                'over_by_days' => $over,
            ];
        }
    }

    usort($overages, static function ($x, $y) {
        return ($y['over_by_days'] <=> $x['over_by_days']);
    });

    $totalAvail = 0.0;
    foreach ($availableDaysByConsultant as $v) $totalAvail += (float)$v;
    $totalUsed = 0.0;
    foreach ($used as $v) $totalUsed += (float)$v;
    if ($totalAvail > 0 && $totalUsed - $totalAvail > 1e-6) {
        $notes[] = sprintf('Capienza totale insufficiente: richiesti %.2fg, disponibili %.2fg.', $totalUsed, $totalAvail);
    }

    return [
        'available_days_by_consultant' => $availableDaysByConsultant,
        'used_days_by_consultant' => $used,
        'overages' => array_values($overages),
        'rebalance_applied' => $rebalanceApplied,
        'notes' => array_values(array_filter(array_map(static fn($s) => trim((string)$s), $notes))),
    ];
}

/**
 * Best-effort greedy rebalance:
 * - Move "more movable" items first (communication/call/remote before travel/onsite)
 * - Move whole items only (no split), to keep editing simple for users.
 *
 * @param array<int,float> $availableDaysByConsultant user_id => available days
 * @param int[] $allowedAssignees
 * @return array{rebalance_applied:bool,notes:string[]}
 */
function cnx_rebalance_items_by_capacity(array &$items, array $availableDaysByConsultant, array $allowedAssignees): array {
    $notes = [];
    $rebalanceApplied = false;

    // Build current usage
    $used = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $uid = (int)($it['assignee_user_id'] ?? 0);
        if ($uid <= 0) continue;
        $d = (float)($it['days'] ?? 0);
        if (!is_finite($d) || $d < 0) $d = 0.0;
        $used[$uid] = ($used[$uid] ?? 0.0) + $d;
    }

    // Remaining capacity map (only among allowed assignees)
    $remaining = [];
    foreach ($allowedAssignees as $uid) {
        $a = (float)($availableDaysByConsultant[$uid] ?? 0.0);
        $u = (float)($used[$uid] ?? 0.0);
        $remaining[$uid] = $a - $u;
    }

    // Build list of over-capacity consultants
    $overList = [];
    foreach ($allowedAssignees as $uid) {
        $a = (float)($availableDaysByConsultant[$uid] ?? 0.0);
        $u = (float)($used[$uid] ?? 0.0);
        if ($u - $a > 1e-6) {
            $overList[$uid] = $u - $a;
        }
    }

    if (empty($overList)) return ['rebalance_applied' => false, 'notes' => []];

    // Helper: find target consultant with enough remaining capacity
    $findTarget = static function (float $needDays) use (&$remaining, $allowedAssignees): int {
        $bestUid = 0;
        $bestRem = -INF;
        foreach ($allowedAssignees as $uid) {
            $rem = (float)($remaining[$uid] ?? 0.0);
            if ($rem + 1e-6 < $needDays) continue;
            if ($rem > $bestRem) {
                $bestRem = $rem;
                $bestUid = (int)$uid;
            }
        }
        return $bestUid;
    };

    // Iterate until no progress
    $maxIterations = max(50, count($items) * 2);
    for ($iter = 0; $iter < $maxIterations; $iter++) {
        // pick current worst overage
        arsort($overList);
        $fromUid = (int)array_key_first($overList);
        if ($fromUid <= 0) break;
        $overBy = (float)$overList[$fromUid];
        if ($overBy <= 1e-6) {
            unset($overList[$fromUid]);
            if (empty($overList)) break;
            continue;
        }

        // candidate items assigned to fromUid
        $candidates = [];
        foreach ($items as $idx => $it) {
            if (!is_array($it)) continue;
            if ((int)($it['assignee_user_id'] ?? 0) !== $fromUid) continue;
            $days = (float)($it['days'] ?? 0);
            if (!is_finite($days) || $days <= 0) continue;
            $type = (string)($it['activity_type'] ?? 'remote');
            $candidates[] = [
                'idx' => (int)$idx,
                'days' => $days,
                'mobility' => cnx_capacity_mobility_score($type),
            ];
        }

        if (empty($candidates)) {
            // Nothing to move
            break;
        }

        usort($candidates, static function ($a, $b) {
            // mobility asc (more movable first), then days desc (move larger chunks earlier)
            if ($a['mobility'] !== $b['mobility']) return $a['mobility'] <=> $b['mobility'];
            return $b['days'] <=> $a['days'];
        });

        $moved = false;
        foreach ($candidates as $cand) {
            $idx = (int)$cand['idx'];
            $days = (float)$cand['days'];
            $toUid = $findTarget($days);
            if ($toUid <= 0 || $toUid === $fromUid) continue;

            // Move entire item
            $items[$idx]['assignee_user_id'] = $toUid;
            $used[$fromUid] = ((float)($used[$fromUid] ?? 0.0)) - $days;
            $used[$toUid] = ((float)($used[$toUid] ?? 0.0)) + $days;
            $remaining[$fromUid] = ((float)($remaining[$fromUid] ?? 0.0)) + $days;
            $remaining[$toUid] = ((float)($remaining[$toUid] ?? 0.0)) - $days;

            $overList[$fromUid] = max(0.0, ((float)($used[$fromUid] ?? 0.0)) - (float)($availableDaysByConsultant[$fromUid] ?? 0.0));
            if ($overList[$fromUid] <= 1e-6) unset($overList[$fromUid]);

            $rebalanceApplied = true;
            $moved = true;
            break;
        }

        if (!$moved) {
            // No move possible for this consultant
            break;
        }
    }

    if ($rebalanceApplied) {
        $notes[] = 'Ribilanciamento automatico applicato (best-effort) per rientrare nelle disponibilità.';
    }

    return ['rebalance_applied' => $rebalanceApplied, 'notes' => $notes];
}

/**
 * Merge best-effort: merge contiguous/overlapping items with same assignee + activity_type.
 * Keeps sum(days) identical.
 */
function cnx_merge_contiguous_items(array $items): array {
    $toTs = static function (string $d): int {
        $ts = strtotime($d);
        return $ts ? (int)$ts : 0;
    };

    usort($items, static function ($a, $b) use ($toTs) {
        $au = (int)($a['assignee_user_id'] ?? 0);
        $bu = (int)($b['assignee_user_id'] ?? 0);
        if ($au !== $bu) return $au <=> $bu;
        $at = (string)($a['activity_type'] ?? '');
        $bt = (string)($b['activity_type'] ?? '');
        if ($at !== $bt) return strcmp($at, $bt);
        return $toTs((string)($a['start_date'] ?? '')) <=> $toTs((string)($b['start_date'] ?? ''));
    });

    $out = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $itAssignee = (int)($it['assignee_user_id'] ?? 0);
        $itType = (string)($it['activity_type'] ?? '');
        $itSd = (string)($it['start_date'] ?? '');
        $itEd = (string)($it['end_date'] ?? '');
        $itDays = (float)($it['days'] ?? 0);
        if (!is_finite($itDays) || $itDays < 0) $itDays = 0.0;

        $prev = end($out);
        $prevIdx = count($out) - 1;
        if ($prevIdx >= 0 && is_array($prev)) {
            $pAssignee = (int)($prev['assignee_user_id'] ?? 0);
            $pType = (string)($prev['activity_type'] ?? '');
            $pEd = (string)($prev['end_date'] ?? '');

            $pEdTs = $toTs($pEd);
            $itSdTs = $toTs($itSd);
            $adjacentOrOverlap = ($pEdTs && $itSdTs) ? ($itSdTs <= ($pEdTs + 86400)) : false;

            if ($pAssignee === $itAssignee && $pType === $itType && $adjacentOrOverlap) {
                // merge into prev
                $out[$prevIdx]['end_date'] = ($toTs($itEd) > $toTs((string)($out[$prevIdx]['end_date'] ?? ''))) ? $itEd : (string)($out[$prevIdx]['end_date'] ?? $itEd);
                $out[$prevIdx]['start_date'] = ($toTs($itSd) < $toTs((string)($out[$prevIdx]['start_date'] ?? ''))) ? $itSd : (string)($out[$prevIdx]['start_date'] ?? $itSd);
                $out[$prevIdx]['days'] = (float)($out[$prevIdx]['days'] ?? 0.0) + $itDays;

                $pDesc = trim((string)($out[$prevIdx]['description'] ?? ''));
                $itDesc = trim((string)($it['description'] ?? ''));
                if ($itDesc !== '') {
                    $out[$prevIdx]['description'] = $pDesc !== '' ? ($pDesc . "\n" . $itDesc) : $itDesc;
                }
                continue;
            }
        }

        $out[] = $it;
    }

    return $out;
}

try {
    $data = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($data)) api_error('Dati non validi', 400);

    $action = trim((string)($data['action'] ?? 'generate_action_plan'));
    if ($action === '') $action = 'generate_action_plan';
    if (!in_array($action, ['generate_action_plan', 'generate_blueprint'], true)) {
        api_error('Azione non valida', 400);
    }

    // Require migration 35 tables (catalog) ONLY for Action Plan.
    // Blueprint must be able to run without catalog (fallback objective cadence), to avoid false 503.
    $catalogAvailable = false;
    try {
        $hasTypes = $db->fetchOne("SHOW TABLES LIKE 'consulting_activity_types'");
        $hasOverrides = $db->fetchOne("SHOW TABLES LIKE 'consulting_activity_type_overrides'");
        $catalogAvailable = (bool)$hasTypes && (bool)$hasOverrides;
        if ($action === 'generate_action_plan' && !$catalogAvailable) {
            api_error(
                'Modulo proposta AI non inizializzato: applica la migrazione database 35 (catalogo attività)',
                503,
                ['migration' => 'database/migrations/35_consulting_activity_catalog_and_schedule.sql']
            );
        }
    } catch (Exception $e) {
        if ($action === 'generate_action_plan') {
            api_error('Database non disponibile per la proposta AI', 503);
        }
        $catalogAvailable = false;
    }

    $clientTenantId = (int)($data['client_tenant_id'] ?? 0);
    $objectiveTypeId = (int)($data['objective_activity_type_id'] ?? 0);
    $objectiveOverrides = isset($data['objective_overrides']) && is_array($data['objective_overrides']) ? $data['objective_overrides'] : [];
    $deadline = trim((string)($data['deadline'] ?? ''));
    $effortHours = (float)($data['effort_total_hours'] ?? 0);
    $consultantIds = isset($data['consultant_user_ids']) && is_array($data['consultant_user_ids'])
        ? array_values(array_unique(array_filter(array_map(static fn($v) => (int)$v, $data['consultant_user_ids']), static fn($v) => $v > 0)))
        : [];
    $consultantEffortDaysRaw = isset($data['consultant_effort_days']) && is_array($data['consultant_effort_days']) ? $data['consultant_effort_days'] : [];
    $consultantEffortDays = [];
    foreach ($consultantEffortDaysRaw as $k => $v) {
        $uid = (int)$k;
        if ($uid <= 0) continue;
        $days = (float)$v;
        if ($days < 0) $days = 0.0;
        $consultantEffortDays[$uid] = $days;
    }

    if ($clientTenantId <= 0 || $clientTenantId === CNX_VENDOR_TENANT_ID) api_error('Azienda cliente non valida', 400);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato all’azienda cliente', 403);

    if ($action === 'generate_action_plan') {
        if ($objectiveTypeId <= 0) api_error('Obiettivo (catalogo) obbligatorio', 400);
    } else {
        // Blueprint can infer objective from standards if not provided (for touchpoint cadence defaults)
        if ($objectiveTypeId < 0) $objectiveTypeId = 0;
    }

    if ($action === 'generate_action_plan') {
        if (!cnx_is_valid_date($deadline)) api_error('Scadenza non valida (YYYY-MM-DD)', 400);
        if ($effortHours <= 0) api_error('Effort (ore) non valido', 400);
        if (empty($consultantIds)) api_error('Seleziona almeno 1 consulente', 400);
        if (!empty($consultantEffortDays)) {
            // ensure effort map does not reference non-selected consultants
            foreach (array_keys($consultantEffortDays) as $uid) {
                if (!in_array($uid, $consultantIds, true)) {
                    api_error('Disponibilità non valida: include consulenti non selezionati', 400);
                }
            }
        }
    } else {
        // Blueprint can work with missing deadline; use a 90-day distribution if absent.
        if (!cnx_is_valid_date($deadline)) {
            $deadline = cnx_add_days(cnx_today(), 90);
        }
    }

    // Resolve client name
    $tenant = $db->fetchOne(
        "SELECT id, COALESCE(denominazione, name) AS denominazione
         FROM tenants
         WHERE id = ? AND deleted_at IS NULL
         LIMIT 1",
        [$clientTenantId]
    );
    $clientName = (string)($tenant['denominazione'] ?? ('Tenant #' . $clientTenantId));

    // Resolve consultant names/emails (best-effort)
    $consultants = [];
    $allowedAssignees = [];
    if (!empty($consultantIds)) {
        $placeholders = implode(',', array_fill(0, count($consultantIds), '?'));
        $consultantRows = $db->fetchAll(
            "SELECT id, name, email
             FROM users
             WHERE id IN ($placeholders)
             LIMIT " . count($consultantIds),
            $consultantIds
        ) ?: [];
        foreach ($consultantRows as $r) {
            $consultants[] = [
                'id' => (int)$r['id'],
                'name' => (string)($r['name'] ?? ''),
                'email' => (string)($r['email'] ?? ''),
            ];
        }
        $allowedAssignees = array_map(static fn($c) => (int)$c['id'], $consultants);
        if (empty($allowedAssignees)) $allowedAssignees = $consultantIds;
    }

    // Load effective activity catalog for this client (use weights + call cadence)
    // Catalog is optional for Blueprint.
    $typesResp = [];
    $overridesByType = [];
    if ($catalogAvailable) {
        $typesResp = $db->fetchAll(
            "SELECT id, name, weight_factor, call_every_days_default, call_duration_minutes_default, is_active
             FROM consulting_activity_types
             WHERE deleted_at IS NULL
               AND tenant_id = ?
             ORDER BY name ASC
             LIMIT 200",
            [CNX_VENDOR_TENANT_ID]
        ) ?: [];

        $ovRows = $db->fetchAll(
            "SELECT activity_type_id,
                    weight_factor_override,
                    call_every_days_override,
                    call_duration_minutes_override,
                    is_active_override
             FROM consulting_activity_type_overrides
             WHERE deleted_at IS NULL
               AND client_tenant_id = ?",
            [$clientTenantId]
        ) ?: [];
        foreach ($ovRows as $o) {
            $overridesByType[(int)$o['activity_type_id']] = $o;
        }
    } else {
        // Without catalog, objective inference by standard name is not possible; force fallback objective.
        if ($action === 'generate_blueprint') {
            $objectiveTypeId = 0;
        }
    }

    $activityCatalog = [];
    $activityNames = [];
    foreach ($typesResp as $t) {
        $id = (int)($t['id'] ?? 0);
        if ($id <= 0) continue;
        $name = (string)($t['name'] ?? '');
        if ($name === '') continue;

        $effWeight = (float)($t['weight_factor'] ?? 1.0);
        $effEvery = (int)($t['call_every_days_default'] ?? 7);
        $effDur = (int)($t['call_duration_minutes_default'] ?? 30);
        $effActive = (bool)((int)($t['is_active'] ?? 0));

        $ov = $overridesByType[$id] ?? null;
        if (is_array($ov)) {
            if ($ov['weight_factor_override'] !== null && $ov['weight_factor_override'] !== '') $effWeight = (float)$ov['weight_factor_override'];
            if ($ov['call_every_days_override'] !== null && $ov['call_every_days_override'] !== '') $effEvery = (int)$ov['call_every_days_override'];
            if ($ov['call_duration_minutes_override'] !== null && $ov['call_duration_minutes_override'] !== '') $effDur = (int)$ov['call_duration_minutes_override'];
            if ($ov['is_active_override'] !== null && $ov['is_active_override'] !== '') $effActive = (bool)((int)$ov['is_active_override']);
        }

        // basic sanitation
        if ($effWeight <= 0) $effWeight = 1.0;
        if ($effEvery <= 0) $effEvery = 7;
        if ($effDur <= 0) $effDur = 30;

        $activityCatalog[] = [
            'id' => $id,
            'name' => $name,
            'weight_factor' => $effWeight,
            'call_every_days' => $effEvery,
            'call_duration_minutes' => $effDur,
            'is_active' => $effActive,
        ];
        if ($effActive) $activityNames[] = $name;
    }
    $activityNames = array_values(array_unique(array_filter($activityNames)));

    // Resolve objective from catalog + apply per-plan overrides (without touching global catalog)
    $objectiveFromCatalog = null;
    if ($objectiveTypeId > 0) {
        foreach ($activityCatalog as $a) {
            if ((int)($a['id'] ?? 0) === $objectiveTypeId) {
                $objectiveFromCatalog = $a;
                break;
            }
        }
    }
    if (!$objectiveFromCatalog && $action === 'generate_blueprint') {
        // Infer objective from standards by matching catalog names (best-effort)
        $needleByStandard = [
            'ISO 9001' => '9001',
            'ISO 14001' => '14001',
            'ISO 45001' => '45001',
            'ISO/IEC 27001' => '27001',
        ];
        $st = cnx_normalize_standards($data['standards'] ?? []);
        foreach ($st as $code) {
            $needle = $needleByStandard[$code] ?? '';
            if ($needle === '') continue;
            foreach ($activityCatalog as $a) {
                $nm = strtolower((string)($a['name'] ?? ''));
                if ($nm !== '' && strpos($nm, $needle) !== false && !empty($a['is_active'])) {
                    $objectiveFromCatalog = $a;
                    $objectiveTypeId = (int)($a['id'] ?? 0);
                    break 2;
                }
            }
        }
    }
    if (!$objectiveFromCatalog) {
        if ($action === 'generate_action_plan') {
            api_error('Obiettivo non valido: attività non trovata o non attiva nel catalogo', 400);
        }
        // Blueprint fallback objective (touchpoint cadence defaults)
        $objectiveFromCatalog = [
            'id' => 0,
            'name' => 'Compliance Blueprint',
            'weight_factor' => 1.0,
            'call_every_days' => 7,
            'call_duration_minutes' => 30,
            'is_active' => true,
        ];
    }

    $objWeight = (float)($objectiveFromCatalog['weight_factor'] ?? 1.0);
    $objEvery = (int)($objectiveFromCatalog['call_every_days'] ?? 7);
    $objDur = (int)($objectiveFromCatalog['call_duration_minutes'] ?? 30);

    $ovWeightRaw = $objectiveOverrides['weight_factor'] ?? null;
    $ovEveryRaw = $objectiveOverrides['call_every_days'] ?? null;
    $ovDurRaw = $objectiveOverrides['call_duration_minutes'] ?? null;

    $ovWeight = ($ovWeightRaw === null || $ovWeightRaw === '') ? null : (float)$ovWeightRaw;
    $ovEvery = ($ovEveryRaw === null || $ovEveryRaw === '') ? null : (int)$ovEveryRaw;
    $ovDur = ($ovDurRaw === null || $ovDurRaw === '') ? null : (int)$ovDurRaw;

    if ($ovWeight !== null && $ovWeight <= 0) api_error('Peso override non valido', 400);
    if ($ovEvery !== null && $ovEvery <= 0) api_error('Call ogni (giorni) override non valido', 400);
    if ($ovDur !== null && $ovDur <= 0) api_error('Durata call (min) override non valida', 400);

    if ($ovWeight !== null) $objWeight = $ovWeight;
    if ($ovEvery !== null) $objEvery = $ovEvery;
    if ($ovDur !== null) $objDur = $ovDur;

    $objective = [
        'activity_type_id' => $objectiveTypeId,
        'name' => (string)$objectiveFromCatalog['name'],
        'weight_factor' => $objWeight,
        'call_every_days' => $objEvery,
        'call_duration_minutes' => $objDur,
        'overrides' => [
            'weight_factor' => $ovWeight,
            'call_every_days' => $ovEvery,
            'call_duration_minutes' => $ovDur,
        ],
    ];

    $today = cnx_today();

    if ($action === 'generate_action_plan') {
        $schema = [
            'name' => 'planning_proposal',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'proposal_title' => ['type' => 'string'],
                    'timeline' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'buffer_days' => ['type' => 'integer'],
                        ],
                        'required' => ['start_date','end_date','buffer_days'],
                    ],
                    'confidence' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'score' => ['type' => 'number'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['score','reason'],
                    ],
                    'risks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'risk' => ['type' => 'string'],
                                'mitigation' => ['type' => 'string'],
                            ],
                            'required' => ['risk','mitigation'],
                        ],
                    ],
                    'items' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'activity_type' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'start_date' => ['type' => 'string'],
                                'end_date' => ['type' => 'string'],
                                'assignee_user_id' => ['type' => 'integer'],
                                'days' => ['type' => 'number'],
                                'day_rate' => ['type' => 'number'],
                                'km' => ['type' => 'number'],
                                'extras_amount' => ['type' => 'number'],
                                'description' => ['type' => 'string'],
                                'domain_activity_type_name' => ['type' => 'string'],
                            ],
                            'required' => ['activity_type','title','start_date','end_date','assignee_user_id','days','day_rate','km','extras_amount','description','domain_activity_type_name'],
                        ],
                    ],
                ],
                'required' => ['proposal_title','timeline','confidence','risks','items'],
            ],
        ];

        $system = [
            'role' => 'system',
            'content' => "Sei un assistente che genera una proposta di piano consulenziale. Devi rispondere SOLO con JSON valido secondo lo schema.",
        ];

        $user = [
            'role' => 'user',
            'content' => json_encode([
                'client' => ['id' => $clientTenantId, 'name' => $clientName],
                'objective' => $objective,
                'today' => $today,
                'deadline' => $deadline,
                'effort_total_hours' => $effortHours,
                'consultants' => $consultants,
                'consultant_effort_days' => $consultantEffortDays,
                'activity_catalog' => $activityCatalog,
                'activity_catalog_names' => $activityNames, // compatibility/hint
                'rules' => [
                    'timeline_policy' => 'uniform_spread',
                    'buffer_days_default' => 2,
                    'allowed_activity_types' => ['remote','onsite','call','communication','travel'],
                    'use_days_unit' => 'days',
                    'assignee_user_id_must_be_one_of' => $allowedAssignees,
                    'keep_days_sum_close_to_effort_hours_divided_by_8' => true,
                    'do_not_exceed_effort_hours' => true,
                    'do_not_exceed_consultant_availability_days' => true,
                    // Client should never feel abandoned: schedule recurring touchpoints.
                    // If you pick a domain_activity_type_name from the catalog, use its call_every_days/call_duration_minutes as minimum cadence for call/communication items.
                    'touchpoint_policy' => [
                        'require_recurring_calls_or_updates' => true,
                        'allowed_touchpoint_types' => ['call','communication'],
                    ],
                ],
                'output_expectations' => [
                    'break_down_objective_into_steps' => true,
                    'include_risks_and_mitigations' => true,
                    'confidence_score_0_to_1' => true,
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];

        $resp = cnx_openai_chat_json([$system, $user], $schema, [
            'temperature' => 0.2,
            'max_tokens' => 1400,
        ]);

        if (!$resp['ok']) {
            $extra = [
                'provider' => 'openai',
                'model' => defined('OPENAI_MODEL') ? (string)OPENAI_MODEL : null,
            ];
            if (isset($resp['debug']) && is_array($resp['debug'])) {
                // Safe debug: never contains prompt or secrets (see includes/openai_client.php)
                $extra['debug'] = $resp['debug'];
            }
            api_error($resp['error'] ?? 'Errore OpenAI', 503, $extra);
        }

        $proposal = (array)($resp['data'] ?? []);
        $v = cnx_validate_proposal($proposal, $allowedAssignees);
        if (!$v['ok']) {
            api_error('Proposta AI non valida: ' . ($v['error'] ?? 'unknown'), 502);
        }

        // --- Capacity enforcement (server-side, best-effort) ---
        $capacityReport = null;
        if (!empty($consultantEffortDays)) {
            // Ensure we only include allowed assignees, and normalize missing to 0
            $availableDaysByConsultant = [];
            foreach ($allowedAssignees as $uid) {
                $availableDaysByConsultant[(int)$uid] = (float)($consultantEffortDays[(int)$uid] ?? 0.0);
            }

            $reb = cnx_rebalance_items_by_capacity($proposal['items'], $availableDaysByConsultant, $allowedAssignees);
            // Merge after rebalance to reduce fragmentation (doesn't change total days)
            $proposal['items'] = cnx_merge_contiguous_items($proposal['items']);
            $capacityReport = cnx_build_capacity_report($availableDaysByConsultant, $proposal['items'], (bool)($reb['rebalance_applied'] ?? false), (array)($reb['notes'] ?? []));
        }

        api_success([
            'client_tenant_id' => $clientTenantId,
            'proposal' => $proposal,
            'capacity_report' => $capacityReport,
            'meta' => [
                'generated_at' => date('c'),
                'model' => defined('OPENAI_MODEL') ? (string)OPENAI_MODEL : null,
            ],
        ]);
    }

    // --- Blueprint generation (greenfield) ---
    $standards = cnx_normalize_standards($data['standards'] ?? []);
    $latestMap = cnx_standards_latest_map();
    $standardsWithEdition = [];
    foreach ($standards as $code) {
        $standardsWithEdition[] = ['code' => $code, 'edition' => (string)($latestMap[$code] ?? $code)];
    }

    $clientProfile = is_array($data['client_profile'] ?? null) ? $data['client_profile'] : [];
    $businessDescription = trim((string)($clientProfile['business_description'] ?? ''));
    $desiredScopeHint = trim((string)($clientProfile['desired_scope_hint'] ?? ''));
    $employeeCount = isset($clientProfile['employee_count']) && $clientProfile['employee_count'] !== '' ? (int)$clientProfile['employee_count'] : null;
    $sitesCount = isset($clientProfile['sites_count']) && $clientProfile['sites_count'] !== '' ? (int)$clientProfile['sites_count'] : null;
    if ($employeeCount !== null && $employeeCount < 0) $employeeCount = null;
    if ($sitesCount !== null && $sitesCount < 0) $sitesCount = null;

    $bpSchema = [
        'name' => 'compliance_blueprint',
        'schema' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'compliance_blueprint' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'standards' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'code' => ['type' => 'string'],
                                    'edition' => ['type' => 'string'],
                                ],
                                'required' => ['code','edition'],
                            ],
                        ],
                        'scope_proposal' => ['type' => 'string'],
                        'assumptions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'open_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'process_map' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'process_name' => ['type' => 'string'],
                                    'owner_role' => ['type' => 'string'],
                                    'inputs' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'outputs' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'kpi_examples' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                                'required' => ['process_name','owner_role','inputs','outputs','kpi_examples'],
                            ],
                        ],
                        'repository_structure' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'path' => ['type' => 'string'],
                                ],
                                'required' => ['path'],
                            ],
                        ],
                        'documents' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'doc_code' => ['type' => 'string'],
                                    'title' => ['type' => 'string'],
                                    'doc_type' => ['type' => 'string'],
                                    'owner_role' => ['type' => 'string'],
                                    'purpose' => ['type' => 'string'],
                                    'clause_refs' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'template_outline' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'evidence_examples' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'repository_path' => ['type' => 'string'],
                                ],
                                'required' => ['doc_code','title','doc_type','owner_role','purpose','clause_refs','template_outline','evidence_examples','repository_path'],
                            ],
                        ],
                        'records_register' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'record_name' => ['type' => 'string'],
                                    'owner_role' => ['type' => 'string'],
                                    'clause_refs' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'evidence_examples' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                                'required' => ['record_name','owner_role','clause_refs','evidence_examples'],
                            ],
                        ],
                        'audit_program' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'internal_audit_frequency_months' => ['type' => 'integer'],
                                'management_review_frequency_months' => ['type' => 'integer'],
                                'notes' => ['type' => 'string'],
                            ],
                            'required' => ['internal_audit_frequency_months','management_review_frequency_months','notes'],
                        ],
                    ],
                    'required' => [
                        'standards',
                        'scope_proposal',
                        'assumptions',
                        'open_questions',
                        'process_map',
                        'repository_structure',
                        'documents',
                        'records_register',
                        'audit_program',
                    ],
                ],
                'meta' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'confidence' => ['type' => 'number'],
                        'risks' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['confidence','risks'],
                ],
            ],
            'required' => ['compliance_blueprint','meta'],
        ],
    ];

    $bpSystem = [
        'role' => 'system',
        'content' => "Sei un assistente che genera un Compliance Blueprint greenfield (0 documenti esistenti). Devi rispondere SOLO con JSON valido secondo lo schema. Vietato citare testo normativo: produci solo riassunti operativi e deliverable.",
    ];

    $bpUser = [
        'role' => 'user',
        'content' => json_encode([
            'starting_point' => 'greenfield',
            'client' => ['id' => $clientTenantId, 'name' => $clientName],
            'standards_latest' => $standardsWithEdition,
            // Requirements Catalog (pilot: ISO 9001)
            'requirements_catalog' => (in_array('ISO 9001', $standards, true) ? cnx_load_iso9001_requirements_catalog($db) : null),
            'client_profile' => [
                'business_description' => $businessDescription,
                'employee_count' => $employeeCount,
                'sites_count' => $sitesCount,
                'desired_scope_hint' => $desiredScopeHint,
            ],
            'objective' => $objective,
            'today' => $today,
            'deadline' => $deadline,
            'ims_convention' => [
                'root' => '/IMS',
                'note' => 'Struttura integrata: evitare duplicati tra norme; mettere comune in /IMS/01_Common e specifico in /IMS/02_QMS,03_EMS,04_OHS,05_ISMS',
            ],
            'rules' => [
                'do_not_quote_standard_text' => true,
                'must_output_json_only' => true,
                'include_assumptions_and_open_questions_if_missing_inputs' => true,
                'touchpoints_align_to_call_every_days' => (int)($objective['call_every_days'] ?? 7),
                'touchpoints_call_duration_minutes' => (int)($objective['call_duration_minutes'] ?? 30),
                'timeline_if_missing_deadline_days' => 90,
                'avoid_duplicate_deliverables_across_standards' => true,
                // LLM guardrails for ISO 9001 pilot
                'requirements_catalog_policy' => [
                    'if_requirements_catalog_present_use_only_that_for_requirements' => true,
                    'do_not_invent_external_obligations' => true,
                    'clause_refs_must_match_catalog_clauses' => true,
                ],
            ],
            'output_expectations' => [
                'minimum_but_complete' => true,
                'deliverables_must_include_owner_role_and_evidence_examples' => true,
                'repository_paths_must_be_consistent' => true,
            ],
        ], JSON_UNESCAPED_UNICODE),
    ];

    $bpResp = cnx_openai_chat_json([$bpSystem, $bpUser], $bpSchema, [
        'temperature' => 0.2,
        'max_tokens' => 2200,
        // Blueprints can be large/slow; be more patient and retry once on infra issues
        'timeout_seconds' => 60,
        'max_retries' => 1,
    ]);

    if (!$bpResp['ok']) {
        $extra = ['provider' => 'openai'];
        if (isset($bpResp['debug']) && is_array($bpResp['debug'])) {
            // Safe debug: never contains prompt or secrets (see includes/openai_client.php)
            $extra['debug'] = $bpResp['debug'];
        }
        api_error($bpResp['error'] ?? 'Errore OpenAI', 503, $extra);
    }

    $bpOut = (array)($bpResp['data'] ?? []);
    $blueprint = (array)($bpOut['compliance_blueprint'] ?? []);
    $vbp = cnx_validate_compliance_blueprint($blueprint);
    if (!$vbp['ok']) {
        api_error('Compliance Blueprint non valido: ' . ($vbp['error'] ?? 'unknown'), 502);
    }

    // Optional: requirements coverage (pilot ISO 9001 only) - best-effort
    $requirementsCoverage = null;
    if (in_array('ISO 9001', $standards, true)) {
        try {
            $cat = cnx_load_iso9001_requirements_catalog($db);
            $total = is_array($cat['requirements'] ?? null) ? count($cat['requirements']) : 0;
            $allClauses = [];
            foreach (($cat['requirements'] ?? []) as $r) {
                if (!is_array($r)) continue;
                $c = trim((string)($r['clause'] ?? ''));
                if ($c !== '') $allClauses[] = $c;
            }
            $allClauses = array_values(array_unique($allClauses));
            sort($allClauses);

            $covered = cnx_extract_clause_refs_from_blueprint($blueprint);
            $coveredSet = array_flip($covered);
            $coveredClauses = [];
            $uncoveredClauses = [];
            foreach ($allClauses as $c) {
                if (isset($coveredSet[$c])) $coveredClauses[] = $c;
                else $uncoveredClauses[] = $c;
            }

            $requirementsCoverage = [
                'total_requirements' => $total,
                'covered_clauses' => $coveredClauses,
                'uncovered_clauses' => $uncoveredClauses,
            ];
        } catch (Throwable $e) {
            $requirementsCoverage = null;
        }
    }

    // Best-effort persistence (schema drift safe)
    $bpStorageAvailable = false;
    $bpSaved = false;
    $planId = (int)($data['plan_id'] ?? 0);
    try {
        $bpStorageAvailable = (bool)$db->fetchOne("SHOW TABLES LIKE 'consulting_plan_blueprints'");
    } catch (Throwable $e) {
        $bpStorageAvailable = false;
    }

    if ($bpStorageAvailable && $planId > 0) {
        $plan = $db->fetchOne("SELECT id, client_tenant_id FROM consulting_plans WHERE id = ? AND deleted_at IS NULL", [$planId]);
        if ($plan && (int)($plan['client_tenant_id'] ?? 0) === $clientTenantId) {
            // plan belongs to a client tenant already validated by cnx_consulting_is_client_allowed()
            try {
                $db->insert('consulting_plan_blueprints', [
                    'plan_id' => $planId,
                    'standards_json' => json_encode($standardsWithEdition, JSON_UNESCAPED_UNICODE),
                    'blueprint_json' => json_encode([
                        'compliance_blueprint' => $blueprint,
                        'meta' => $bpOut['meta'] ?? null,
                        'generated_at' => date('c'),
                    ], JSON_UNESCAPED_UNICODE),
                    'created_by' => (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0),
                ]);
                $bpSaved = true;
            } catch (Throwable $e) {
                // best-effort, do not fail request
                $bpSaved = false;
                error_log('[CONSULTING_BLUEPRINT_SAVE] ' . $e->getMessage());
            }
        }
    }

    api_success([
        'client_tenant_id' => $clientTenantId,
        'plan_id' => $planId > 0 ? $planId : null,
        'storage_available' => $bpStorageAvailable,
        'stored' => $bpSaved,
        'compliance_blueprint' => array_merge($blueprint, [
            'requirements_coverage' => $requirementsCoverage,
        ]),
        'meta' => [
            'generated_at' => date('c'),
            'model' => defined('OPENAI_MODEL') ? (string)OPENAI_MODEL : null,
            'confidence' => (float)($bpOut['meta']['confidence'] ?? 0.5),
            'risks' => is_array($bpOut['meta']['risks'] ?? null) ? $bpOut['meta']['risks'] : [],
            'standards' => $standardsWithEdition,
            'catalog_source' => (in_array('ISO 9001', $standards, true) ? (cnx_load_iso9001_requirements_catalog($db)['source'] ?? 'fallback') : null),
        ],
    ]);
} catch (Exception $e) {
    error_log('[CONSULTING_PROPOSAL_GENERATE] ' . $e->getMessage());
    api_error('Errore generazione proposta', 500);
}

