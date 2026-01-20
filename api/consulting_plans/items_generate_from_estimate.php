<?php
// Consulting Planning: generate plan items from estimate (per-service phases) (Tenant 28 internal tools)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

verifyApiCsrfToken();

/**
 * @return bool
 */
function cnx_col_exists(Database $db, string $table, string $col): bool {
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

/**
 * @return array<string,bool>
 */
function cnx_table_cols(Database $db, string $table): array {
    try {
        $rows = $db->fetchAll(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?",
            [$table]
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $k = (string)($r['COLUMN_NAME'] ?? '');
            if ($k !== '') $out[$k] = true;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Allocate half-day units (0.5d) across day-based phases.
 *
 * Rules:
 * - totalHalfUnits = max(1, round(totalDays * 2))
 * - call/communication phases do NOT consume half-day units (handled separately)
 *
 * @return array<int,int> halfday units per phase index (sum == totalHalfUnits across day-based phases)
 */
function cnx_allocate_halfdays(float $totalDays, array $phases): array {
    $totalDays = is_finite($totalDays) ? max(0.0, $totalDays) : 0.0;
    $totalHalf = max(1, (int)round($totalDays * 2.0));

    $n = count($phases);
    if ($n <= 0) return [];

    // Eligible indices: day-based only
    $eligible = [];
    $weights = array_fill(0, $n, 0.0);
    foreach ($phases as $i => $p) {
        if (!is_array($p)) continue;
        $act = strtolower(trim((string)($p['default_activity_type'] ?? 'remote')));
        if ($act === 'call' || $act === 'communication') {
            $weights[(int)$i] = 0.0;
            continue;
        }
        $w = 0.0;
        if (array_key_exists('share_of_total', $p)) {
            $w = (float)$p['share_of_total'];
        }
        $weights[(int)$i] = ($w > 0) ? $w : 0.0;
        $eligible[] = (int)$i;
    }
    if (empty($eligible)) {
        return array_fill(0, $n, 0);
    }

    $sumW = 0.0;
    foreach ($eligible as $i) $sumW += (float)($weights[$i] ?? 0.0);
    if ($sumW <= 0.0) {
        foreach ($eligible as $i) $weights[$i] = 1.0;
        $sumW = (float)count($eligible);
    }

    $alloc = array_fill(0, $n, 0);
    $remainders = [];
    $used = 0;
    foreach ($eligible as $i) {
        $w = (float)($weights[$i] ?? 0.0);
        $raw = ($sumW > 0) ? (($totalHalf * $w) / $sumW) : 0.0;
        $u = (int)floor($raw);
        $alloc[$i] = $u;
        $remainders[$i] = $raw - $u;
        $used += $u;
    }

    $left = $totalHalf - $used;
    if ($left > 0) {
        usort($eligible, static function ($a, $b) use ($remainders) {
            return ($remainders[$b] ?? 0.0) <=> ($remainders[$a] ?? 0.0);
        });
        $k = 0;
        $cnt = count($eligible);
        while ($left > 0 && $cnt > 0) {
            $i = $eligible[$k % $cnt];
            $alloc[$i] += 1;
            $left--;
            $k++;
        }
    }

    return $alloc;
}

/**
 * @return array<int,array{phase_key:string,label:string,default_activity_type:string,share_of_total:float}>
 */
function cnx_default_phases_template(): array {
    return [
        ['phase_key' => 'kickoff', 'label' => 'Kickoff / Pianificazione', 'default_activity_type' => 'call', 'share_of_total' => 0.06],
        ['phase_key' => 'gap_analysis', 'label' => 'Analisi gap / Analisi contesto', 'default_activity_type' => 'remote', 'share_of_total' => 0.16],
        ['phase_key' => 'documentation', 'label' => 'Documentazione (manuale/procedure/moduli)', 'default_activity_type' => 'remote', 'share_of_total' => 0.30],
        ['phase_key' => 'implementation', 'label' => 'Implementazione / Affiancamento', 'default_activity_type' => 'onsite', 'share_of_total' => 0.22],
        ['phase_key' => 'internal_audit', 'label' => 'Audit interno', 'default_activity_type' => 'onsite', 'share_of_total' => 0.10],
        ['phase_key' => 'management_review', 'label' => 'Riesame di direzione', 'default_activity_type' => 'call', 'share_of_total' => 0.06],
        ['phase_key' => 'cert_support', 'label' => 'Supporto verifica / certificazione', 'default_activity_type' => 'onsite', 'share_of_total' => 0.10],
    ];
}

/**
 * Load phase library from configs/ (schema drift safe).
 * @return array<string,array{order:int,label:string}>
 */
function cnx_sched_phase_library(): array {
    static $cache = null;
    if (is_array($cache)) return $cache;
    $cache = [];
    $p = __DIR__ . '/../../configs/scheduling/phase_library.php';
    if (is_file($p)) {
        $v = require $p;
        if (is_array($v)) $cache = $v;
    }
    if (empty($cache)) {
        // Minimal fallback
        $cache = [
            'kickoff' => ['order' => 10, 'label' => 'Kickoff / Pianificazione'],
            'gap_analysis' => ['order' => 20, 'label' => 'Analisi gap / Analisi contesto'],
            'documentation' => ['order' => 50, 'label' => 'Documentazione'],
            'implementation' => ['order' => 60, 'label' => 'Implementazione'],
            'internal_audit' => ['order' => 80, 'label' => 'Audit interno'],
            'management_review' => ['order' => 85, 'label' => 'Riesame di direzione'],
            'external_audit_support' => ['order' => 90, 'label' => 'Supporto audit esterno / certificazione'],
            'cert_support' => ['order' => 90, 'label' => 'Supporto verifica / certificazione'],
        ];
    }
    return $cache;
}

/**
 * Load service workplans from configs/ (best-effort).
 * @return array<string,array<int,array<string,mixed>>> service_code => tasks
 */
function cnx_sched_service_workplans(): array {
    static $cache = null;
    if (is_array($cache)) return $cache;
    $cache = [];
    $p = __DIR__ . '/../../configs/scheduling/service_workplans.php';
    if (is_file($p)) {
        $v = require $p;
        if (is_array($v)) $cache = $v;
    }
    return $cache;
}

function cnx_sched_intervention_key(array $estimate): string {
    $raw =
        (string)($estimate['company_profile_inferred']['intervention_type'] ?? '') !== ''
            ? (string)$estimate['company_profile_inferred']['intervention_type']
            : (string)($estimate['input_company_profile']['intervention_type'] ?? '');
    $v = strtolower(trim((string)$raw));
    if ($v === '') return '';
    $map = [
        'new_implementation' => 'NEW',
        'new' => 'NEW',
        'maintenance' => 'MAINT',
        'maint' => 'MAINT',
        'recertification' => 'RECERT',
        'recert' => 'RECERT',
        'scope_extension' => 'SCOPE_EXT',
        'scope_ext' => 'SCOPE_EXT',
        'transition' => 'TRANSITION',
        'transition_update' => 'TRANSITION',
    ];
    return $map[$v] ?? '';
}

function cnx_normalize_item_activity_type(string $t): string {
    $t = strtolower(trim($t));
    $valid = ['onsite','remote','call','communication','travel'];
    return in_array($t, $valid, true) ? $t : 'remote';
}

/**
 * Choose which phases should be on-site to best match a target (quarter-days).
 * Only phases of type 'onsite'/'remote' are eligible for conversion; call/communication/travel remain unchanged.
 *
 * @param array<int,array{default_activity_type:string,label:string,phase_key:string,share_of_total:float}> $phases
 * @param array<int,int> $qs quarters per phase index
 * @return array<int,string> index => 'onsite'|'remote'
 */
function cnx_choose_phase_types_for_onsite_target(array $phases, array $qs, int $targetOnSiteQ): array {
    $conv = [];
    $convTotalQ = 0;
    foreach ($phases as $i => $p) {
        $t = isset($p['default_activity_type']) ? (string)$p['default_activity_type'] : 'remote';
        if ($t !== 'onsite' && $t !== 'remote') continue;
        $q = (int)($qs[$i] ?? 0);
        if ($q < 0) $q = 0;
        $conv[] = ['i' => (int)$i, 'q' => $q, 'def' => $t];
        $convTotalQ += $q;
    }
    if (empty($conv)) return [];

    if ($targetOnSiteQ < 0) $targetOnSiteQ = 0;
    if ($targetOnSiteQ > $convTotalQ) $targetOnSiteQ = $convTotalQ;

    $n = count($conv);
    $chosen = []; // phase idx => 'onsite'|'remote'

    // Brute force for small N, greedy fallback otherwise
    if ($n <= 16) {
        $bestMask = 0;
        $bestCost = null;
        $maxMask = 1 << $n;
        for ($mask = 0; $mask < $maxMask; $mask++) {
            $sum = 0;
            $flips = 0;
            for ($j = 0; $j < $n; $j++) {
                $isOn = (($mask >> $j) & 1) === 1;
                if ($isOn) $sum += (int)$conv[$j]['q'];
                $desired = $isOn ? 'onsite' : 'remote';
                if ($desired !== (string)$conv[$j]['def']) $flips++;
            }
            $diff = abs($sum - $targetOnSiteQ);
            $cost = $diff * 1000 + $flips;
            if ($bestCost === null || $cost < $bestCost) {
                $bestCost = $cost;
                $bestMask = $mask;
                if ($diff === 0 && $flips === 0) break;
            }
        }
        for ($j = 0; $j < $n; $j++) {
            $isOn = (($bestMask >> $j) & 1) === 1;
            $chosen[(int)$conv[$j]['i']] = $isOn ? 'onsite' : 'remote';
        }
        return $chosen;
    }

    // Greedy fallback: start from defaults, adjust using smallest-quarter phases
    $ons = [];
    $rem = [];
    $curOn = 0;
    foreach ($conv as $c) {
        if ((string)$c['def'] === 'onsite') { $ons[] = $c; $curOn += (int)$c['q']; }
        else { $rem[] = $c; }
    }
    foreach ($ons as $c) $chosen[(int)$c['i']] = 'onsite';
    foreach ($rem as $c) $chosen[(int)$c['i']] = 'remote';

    if ($curOn > $targetOnSiteQ) {
        usort($ons, static fn($a, $b) => ((int)$a['q']) <=> ((int)$b['q']));
        foreach ($ons as $c) {
            if ($curOn <= $targetOnSiteQ) break;
            $chosen[(int)$c['i']] = 'remote';
            $curOn -= (int)$c['q'];
        }
    } elseif ($curOn < $targetOnSiteQ) {
        usort($rem, static fn($a, $b) => ((int)$a['q']) <=> ((int)$b['q']));
        foreach ($rem as $c) {
            if ($curOn >= $targetOnSiteQ) break;
            $chosen[(int)$c['i']] = 'onsite';
            $curOn += (int)$c['q'];
        }
    }
    return $chosen;
}

try {
    $data = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($data)) api_error('Dati non validi', 400);

    $planId = (int)($data['plan_id'] ?? 0);
    if ($planId <= 0) api_error('plan_id obbligatorio', 400);

    // Schema drift safety: detect columns before composing queries/inserts to avoid SQL 500s
    $planCols = cnx_table_cols($db, 'consulting_plans');
    $itemCols = cnx_table_cols($db, 'consulting_plan_items');
    if (empty($planCols) || empty($itemCols)) {
        api_error(
            'Impossibile verificare lo schema DB per la generazione attività (information_schema non disponibile).',
            503
        );
    }

    foreach (['plan_id', 'activity_type', 'days'] as $reqCol) {
        if (empty($itemCols[$reqCol])) {
            api_error(
                'Schema consulting_plan_items incompleto: manca la colonna ' . $reqCol . '. Applica la migrazione DB 34.',
                503,
                ['migration' => 'database/migrations/34_consulting_plans_tenant28.sql']
            );
        }
    }

    $planWhere = "id = ?";
    if (!empty($planCols['deleted_at'])) $planWhere .= " AND deleted_at IS NULL";
    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE $planWhere", [$planId]);
    if (!$plan) api_error('Piano non trovato', 404);
    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato', 403);

    $replaceExisting = !empty($data['replace_existing']);
    $itemsWhere = "plan_id = ?";
    if (!empty($itemCols['deleted_at'])) $itemsWhere .= " AND deleted_at IS NULL";
    $existingCountRow = $db->fetchOne("SELECT COUNT(*) AS cnt FROM consulting_plan_items WHERE $itemsWhere", [$planId]);
    $existingCount = (int)($existingCountRow['cnt'] ?? 0);
    if ($existingCount > 0 && !$replaceExisting) {
        api_error(
            'Il piano contiene già attività. Conferma sostituzione.',
            409,
            ['can_replace' => true, 'existing_count' => $existingCount]
        );
    }

    // Parse estimate_json
    $estimateRaw = $data['estimate_json'] ?? null;
    if ($estimateRaw === null) api_error('estimate_json obbligatorio', 400);
    $estimate = null;
    if (is_string($estimateRaw)) {
        $estimate = json_decode($estimateRaw, true);
    } elseif (is_array($estimateRaw)) {
        $estimate = $estimateRaw;
    }
    if (!is_array($estimate)) api_error('estimate_json non valido', 400);

    $estimates = $estimate['estimates'] ?? $estimate['services'] ?? null;
    if (!is_array($estimates) || empty($estimates)) api_error('estimate_json.estimates mancante', 400);

    $serviceDays = []; // service_type_id => days
    $serviceOnSiteDays = []; // service_type_id => on-site days (optional override)
    $serviceOnSiteRatio = []; // service_type_id => ratio (0..1) fallback
    foreach ($estimates as $e) {
        if (!is_array($e)) continue;
        $sid = (int)($e['service_type_id'] ?? $e['service_id'] ?? $e['activity_type_id'] ?? 0);
        $days = (float)($e['suggested_days'] ?? $e['days'] ?? 0);
        if ($sid <= 0) continue;
        if (!is_finite($days) || $days <= 0) continue;
        $serviceDays[$sid] = $days;

        // Optional on-site override (manual edits in wizard)
        if (array_key_exists('suggested_on_site_days', $e)) {
            $os = (float)($e['suggested_on_site_days'] ?? 0);
            if (is_finite($os)) {
                if ($os < 0) $os = 0.0;
                if ($os > $days) $os = $days;
                $serviceOnSiteDays[$sid] = $os;
            }
        }
        if (array_key_exists('on_site_ratio', $e)) {
            $r = (float)($e['on_site_ratio'] ?? 0.0);
            if (is_finite($r)) {
                if ($r < 0) $r = 0.0;
                if ($r > 1) $r = 1.0;
                $serviceOnSiteRatio[$sid] = $r;
            }
        }
    }
    if (empty($serviceDays)) api_error('Nessuna stima valida', 400);
    if (count($serviceDays) > 20) api_error('Troppi servizi', 400);

    // Require catalog table (migration 35)
    $hasTypes = $db->fetchOne("SHOW TABLES LIKE 'consulting_activity_types'");
    if (!$hasTypes) {
        api_error(
            'Modulo catalogo servizi non inizializzato: applica la migrazione database 35',
            503,
            ['migration' => 'database/migrations/35_consulting_activity_catalog_and_schedule.sql']
        );
    }

    $typeCols = cnx_table_cols($db, 'consulting_activity_types');

    $hasDomainCol = !empty($itemCols['domain_activity_type_id']);
    $hasFixedAmountCol = !empty($itemCols['fixed_amount']);
    $hasAssigneeCol = !empty($itemCols['assignee_user_id']);
    $hasPhaseKeyCol = !empty($itemCols['phase_key']);
    $hasPhaseOrderCol = !empty($itemCols['phase_order']);
    $hasClientBlockingCol = !empty($itemCols['client_blocking']);
    $hasMergeKeyCol = !empty($itemCols['merge_key']);

    $phaseLib = cnx_sched_phase_library();
    $workplans = cnx_sched_service_workplans();
    $interventionKey = cnx_sched_intervention_key($estimate);
    if ($interventionKey === '') {
        api_error('Tipo intervento obbligatorio', 400, ['field' => 'intervention_type']);
    }

    // Optional: document intelligence profile (cached) to adapt "documentation" into review/update + TODO gaps.
    $docProfileId = 0;
    try { $docProfileId = (int)($estimate['meta']['doc_profile_id'] ?? 0); } catch (Throwable $e) { $docProfileId = 0; }
    if ($docProfileId < 0) $docProfileId = 0;
    $docProfile = null;
    $docDocsExist = false;
    $docDocumentationFactor = 1.0;
    $docTodoGaps = '';
    if ($docProfileId > 0) {
        try {
            $hasProfiles = !empty($db->fetchOne("SHOW TABLES LIKE 'ai_tenant_doc_profiles'"));
            if ($hasProfiles) {
                $r = $db->fetchOne(
                    "SELECT expires_at, payload_json
                     FROM ai_tenant_doc_profiles
                     WHERE id = ?
                       AND tenant_id = ?
                       AND scope = 'IMS_PLANNING'
                     LIMIT 1",
                    [$docProfileId, $clientTenantId]
                );
                if ($r && trim((string)($r['payload_json'] ?? '')) !== '') {
                    $decoded = json_decode((string)$r['payload_json'], true);
                    if (is_array($decoded)) {
                        $docProfile = $decoded;
                        $det = is_array($docProfile['detected'] ?? null) ? $docProfile['detected'] : [];
                        $manual = (bool)($det['manual'] ?? false);
                        $proc = (int)($det['procedures_count_est'] ?? 0);
                        $mat = strtolower(trim((string)($docProfile['maturity_suggested'] ?? '')));
                        $docDocsExist = $manual || ($proc > 0) || in_array($mat, ['structured_non_certified','already_certified','integrated_existing'], true);
                        $docDocumentationFactor = (float)($docProfile['planning_adjustments']['documentation_factor'] ?? 1.0);
                        if ($docDocumentationFactor < 0.0) $docDocumentationFactor = 0.0;
                        if ($docDocumentationFactor > 1.0) $docDocumentationFactor = 1.0;

                        $gaps = is_array($docProfile['gaps'] ?? null) ? $docProfile['gaps'] : [];
                        $top = array_slice($gaps, 0, 3);
                        $lines = [];
                        foreach ($top as $g) {
                            if (!is_array($g)) continue;
                            $area = trim((string)($g['area'] ?? ''));
                            $sev = trim((string)($g['severity'] ?? ''));
                            $detail = trim((string)($g['detail'] ?? ''));
                            if ($area === '' && $detail === '') continue;
                            $head = $area !== '' ? $area : 'gap';
                            if ($sev !== '') $head .= "({$sev})";
                            $lines[] = $head . ': ' . $detail;
                        }
                        $docTodoGaps = trim(implode(' | ', array_values(array_filter($lines))));
                        if (mb_strlen($docTodoGaps, 'UTF-8') > 500) {
                            $docTodoGaps = trim(mb_substr($docTodoGaps, 0, 500, 'UTF-8'));
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $docProfile = null;
        }
    }

    $select = "SELECT id, name";
    if (!empty($typeCols['service_code'])) $select .= ", service_code";
    if (!empty($typeCols['default_day_rate'])) $select .= ", default_day_rate";
    if (!empty($typeCols['default_fixed_amount'])) $select .= ", default_fixed_amount";
    if (!empty($typeCols['default_phases_json'])) $select .= ", default_phases_json";
    $select .= " FROM consulting_activity_types
                WHERE tenant_id = ?";
    if (!empty($typeCols['deleted_at'])) $select .= " AND deleted_at IS NULL";
    $select .= " AND id IN (" . implode(',', array_fill(0, count($serviceDays), '?')) . ")";

    $ids = array_keys($serviceDays);
    $rows = $db->fetchAll($select, array_merge([CNX_VENDOR_TENANT_ID], $ids)) ?: [];
    $byId = [];
    foreach ($rows as $r) $byId[(int)$r['id']] = $r;
    foreach ($ids as $sid) {
        if (!isset($byId[$sid])) api_error('Servizio non trovato nel catalogo (id=' . (int)$sid . ')', 404);
    }

    $createdIds = [];
    $db->beginTransaction();
    try {
        if ($existingCount > 0 && $replaceExisting) {
            if (!empty($itemCols['deleted_at'])) {
                // Soft-delete existing items (preferred)
                $upd = ['deleted_at' => date('Y-m-d H:i:s')];
                if (!empty($itemCols['updated_at'])) $upd['updated_at'] = date('Y-m-d H:i:s');
                $db->update('consulting_plan_items', $upd, ['plan_id' => $planId]);
            } else {
                // Very old schema: hard-delete fallback (best-effort)
                $db->query("DELETE FROM consulting_plan_items WHERE plan_id = ?", [$planId]);
            }
        }

        foreach ($ids as $sid) {
            $service = $byId[$sid];
            $totalDays = (float)$serviceDays[$sid];
            if ($totalDays <= 0) continue;
            // Planning 2026: enforce half-day granularity (0.5d) for day-based work.
            $totalDays = (float)(round($totalDays * 2.0) / 2.0);
            if ($totalDays < 0.5) $totalDays = 0.5;

            $serviceName = (string)($service['name'] ?? ('#' . $sid));
            $serviceCode = isset($service['service_code']) ? (string)($service['service_code'] ?? '') : '';
            $serviceCodeNorm = strtoupper(trim($serviceCode));
            $dayRate = isset($service['default_day_rate']) ? (float)$service['default_day_rate'] : 0.0;
            // Pricing defaults: if catalog has 0 (unset), fallback to platform default 360€/day
            if ($dayRate <= 0.0) $dayRate = 360.0;
            $serviceFixed = isset($service['default_fixed_amount']) ? (float)$service['default_fixed_amount'] : 0.0;

            // Prefer config-driven workplan (service_workplans.php); fallback to catalog default_phases_json / template.
            $normPhases = [];
            if ($serviceCodeNorm !== '' && isset($workplans[$serviceCodeNorm]) && is_array($workplans[$serviceCodeNorm])) {
                foreach ($workplans[$serviceCodeNorm] as $t) {
                    if (!is_array($t)) continue;
                    $label = trim((string)($t['title'] ?? ''));
                    if ($label === '') continue;
                    $phaseKey = trim((string)($t['phase_key'] ?? 'ongoing'));
                    if ($phaseKey === '') $phaseKey = 'ongoing';
                    $phaseOrder = (int)($t['phase_order'] ?? 0);
                    if ($phaseOrder <= 0 && isset($phaseLib[$phaseKey]['order'])) $phaseOrder = (int)$phaseLib[$phaseKey]['order'];
                    if ($phaseOrder <= 0) $phaseOrder = 99;
                    $act = cnx_normalize_item_activity_type((string)($t['default_mode'] ?? $t['default_activity_type'] ?? 'remote'));
                    $blocking = array_key_exists('client_blocking', $t) ? (bool)$t['client_blocking'] : true;
                    $mergeKey = trim((string)($t['merge_key'] ?? ''));
                    $shares = $t['share_per_intervention_type'] ?? null;
                    $share = 0.0;
                    if (is_array($shares)) {
                        if (array_key_exists($interventionKey, $shares)) $share = (float)$shares[$interventionKey];
                        elseif (array_key_exists('NEW', $shares)) $share = (float)$shares['NEW'];
                    }
                    if ($share < 0) $share = 0.0;
                    $normPhases[] = [
                        'phase_key' => $phaseKey,
                        'phase_order' => $phaseOrder,
                        'label' => $label,
                        'default_activity_type' => $act,
                        'share_of_total' => $share,
                        'client_blocking' => $blocking,
                        'merge_key' => $mergeKey,
                    ];
                }
            }
            if (empty($normPhases)) {
                $phases = null;
                if (!empty($typeCols['default_phases_json']) && isset($service['default_phases_json']) && $service['default_phases_json'] !== null && $service['default_phases_json'] !== '') {
                    try { $phases = json_decode((string)$service['default_phases_json'], true); } catch (Throwable $e) { $phases = null; }
                }
                if (!is_array($phases) || empty($phases)) {
                    $phases = cnx_default_phases_template();
                }

                // Normalize phases and keep only those with a label
                foreach ($phases as $p) {
                    if (!is_array($p)) continue;
                    $label = trim((string)($p['label'] ?? ''));
                    if ($label === '') continue;
                    $phaseKey = trim((string)($p['phase_key'] ?? 'phase'));
                    if ($phaseKey === '') $phaseKey = 'phase';
                    $phaseOrder = isset($phaseLib[$phaseKey]['order']) ? (int)$phaseLib[$phaseKey]['order'] : 99;
                    $act = cnx_normalize_item_activity_type((string)($p['default_activity_type'] ?? 'remote'));
                    $share = (float)($p['share_of_total'] ?? 0.0);
                    if ($share < 0) $share = 0.0;
                    $normPhases[] = [
                        'phase_key' => $phaseKey,
                        'phase_order' => $phaseOrder,
                        'label' => $label,
                        'default_activity_type' => $act,
                        'share_of_total' => $share,
                        'client_blocking' => true,
                        'merge_key' => '',
                    ];
                }
            }
            if (empty($normPhases)) {
                // final fallback
                foreach (cnx_default_phases_template() as $p) {
                    $phaseKey = (string)$p['phase_key'];
                    $normPhases[] = [
                        'phase_key' => $phaseKey,
                        'phase_order' => isset($phaseLib[$phaseKey]['order']) ? (int)$phaseLib[$phaseKey]['order'] : 99,
                        'label' => (string)$p['label'],
                        'default_activity_type' => (string)$p['default_activity_type'],
                        'share_of_total' => (float)$p['share_of_total'],
                        'client_blocking' => true,
                        'merge_key' => '',
                    ];
                }
            }

            // Document evidence: turn "documentation" into review/update (and shift weight via documentation_factor).
            if ($docProfile && $docDocsExist) {
                foreach ($normPhases as $i => $p) {
                    if (!is_array($p)) continue;
                    $pk = strtolower(trim((string)($p['phase_key'] ?? '')));
                    if ($pk !== 'documentation') continue;
                    $normPhases[$i]['label'] = 'Review/Aggiornamento documentazione esistente';
                    $share = (float)($p['share_of_total'] ?? 0.0);
                    if ($share < 0.0) $share = 0.0;
                    $normPhases[$i]['share_of_total'] = $share * (float)$docDocumentationFactor;
                }
            }

            // Day-based allocation uses half-day units (0.5d). Call/communication phases are handled separately.
            $halfUnits = cnx_allocate_halfdays($totalDays, $normPhases);
            $fixedApplied = false;

            // Planning 2026: RECERT must include (best-effort) internal audit + external audit support as day-based items.
            if ($interventionKey === 'RECERT') {
                $idxSupport = null;
                $idxInternal = null;
                foreach ($normPhases as $i => $p) {
                    if (!is_array($p)) continue;
                    $pk = strtolower(trim((string)($p['phase_key'] ?? '')));
                    $act = strtolower(trim((string)($p['default_activity_type'] ?? 'remote')));
                    if ($act === 'call' || $act === 'communication') continue;
                    if ($idxSupport === null && in_array($pk, ['external_audit_support', 'cert_support'], true)) $idxSupport = (int)$i;
                    if ($idxInternal === null && $pk === 'internal_audit') $idxInternal = (int)$i;
                }

                $totalHalf = max(1, (int)round($totalDays * 2.0));
                $mandatory = [];
                if ($idxSupport !== null) $mandatory[] = (int)$idxSupport; // always
                if ($idxInternal !== null && $totalHalf >= 2) $mandatory[] = (int)$idxInternal; // if feasible
                $mandatorySet = [];
                foreach ($mandatory as $mi) $mandatorySet[(int)$mi] = true;

                foreach ($mandatory as $mi) {
                    $cur = (int)($halfUnits[$mi] ?? 0);
                    if ($cur >= 1) continue;

                    // Find donor: non-mandatory day-based phase with units > 0 and lowest share.
                    $donor = null;
                    $donorShare = null;
                    $donorUnits = null;
                    foreach ($normPhases as $i => $p) {
                        $i = (int)$i;
                        if (!empty($mandatorySet[$i])) continue;
                        $act = strtolower(trim((string)($p['default_activity_type'] ?? 'remote')));
                        if ($act === 'call' || $act === 'communication') continue;
                        $u = (int)($halfUnits[$i] ?? 0);
                        if ($u <= 0) continue;
                        $share = (float)($p['share_of_total'] ?? 0.0);
                        if ($donor === null || $share < (float)$donorShare || ($share === (float)$donorShare && $u > (int)$donorUnits)) {
                            $donor = $i;
                            $donorShare = $share;
                            $donorUnits = $u;
                        }
                    }
                    if ($donor === null) break;
                    $halfUnits[$donor] = max(0, (int)($halfUnits[$donor] ?? 0) - 1);
                    $halfUnits[$mi] = (int)($halfUnits[$mi] ?? 0) + 1;
                }
            }

            // Apply on-site/remote distribution override by re-typing phases (onsite/remote) to best match target.
            $onSiteTargetDays = null;
            if (array_key_exists($sid, $serviceOnSiteDays)) {
                $onSiteTargetDays = (float)$serviceOnSiteDays[$sid];
            } elseif (array_key_exists($sid, $serviceOnSiteRatio)) {
                $onSiteTargetDays = (float)$serviceOnSiteRatio[$sid] * (float)$totalDays;
            }
            if ($onSiteTargetDays === null || !is_finite((float)$onSiteTargetDays)) {
                $onSiteTargetDays = 0.0;
            }
            if ($onSiteTargetDays < 0) $onSiteTargetDays = 0.0;
            if ($onSiteTargetDays > $totalDays) $onSiteTargetDays = (float)$totalDays;
            $targetOnSiteUnits = (int)round($onSiteTargetDays * 2);

            // Planning 2026: for RECERT keep key phases on-site even if user set a low on-site target.
            if ($interventionKey === 'RECERT') {
                $minOnSiteUnits = 0;
                foreach ($normPhases as $i => $p) {
                    if (!is_array($p)) continue;
                    $pk = strtolower(trim((string)($p['phase_key'] ?? '')));
                    $act = strtolower(trim((string)($p['default_activity_type'] ?? 'remote')));
                    if ($act !== 'onsite' && $act !== 'remote') continue;
                    if (!in_array($pk, ['internal_audit', 'external_audit_support', 'cert_support'], true)) continue;
                    $minOnSiteUnits += (int)($halfUnits[(int)$i] ?? 0);
                }
                if ($minOnSiteUnits > $targetOnSiteUnits) $targetOnSiteUnits = $minOnSiteUnits;
            }
            $phaseTypeOverride = cnx_choose_phase_types_for_onsite_target($normPhases, $halfUnits, $targetOnSiteUnits);
            if ($interventionKey === 'RECERT') {
                // Force on-site for key recert phases (best-effort, keeps preview + schedule coherent)
                foreach ($normPhases as $i => $p) {
                    if (!is_array($p)) continue;
                    $pk = strtolower(trim((string)($p['phase_key'] ?? '')));
                    if (!in_array($pk, ['internal_audit', 'external_audit_support', 'cert_support'], true)) continue;
                    $act = strtolower(trim((string)($p['default_activity_type'] ?? 'remote')));
                    if ($act !== 'onsite' && $act !== 'remote') continue;
                    $phaseTypeOverride[(int)$i] = 'onsite';
                }
            }

            // If non-call phases exceed available half-day units, merge "0-unit" phases into adjacent ones
            // (so we never create 0.25/0.0 operational rows and we don't lose phase intent).
            $mergedLabelsByIdx = []; // idx => string[]
            $finalActByIdx = [];
            foreach ($normPhases as $i => $p) {
                $act = (string)($p['default_activity_type'] ?? 'remote');
                if (isset($phaseTypeOverride[$i])) $act = (string)$phaseTypeOverride[$i];
                $finalActByIdx[(int)$i] = cnx_normalize_item_activity_type($act);
            }
            foreach ($normPhases as $i => $p) {
                $i = (int)$i;
                $u = (int)($halfUnits[$i] ?? 0);
                $act = (string)($finalActByIdx[$i] ?? 'remote');
                if ($act === 'call' || $act === 'communication') continue;
                if ($u > 0) continue;
                $label = trim((string)($p['label'] ?? ''));
                if ($label === '') continue;

                // Find best merge target (prefer adjacent with same final activity_type).
                $target = null;
                for ($j = $i - 1; $j >= 0; $j--) {
                    $ju = (int)($halfUnits[$j] ?? 0);
                    if ($ju <= 0) continue;
                    $ja = (string)($finalActByIdx[$j] ?? 'remote');
                    if ($ja === 'call' || $ja === 'communication') continue;
                    if ($ja === $act) { $target = $j; break; }
                }
                if ($target === null) {
                    for ($j = $i + 1; $j < count($normPhases); $j++) {
                        $ju = (int)($halfUnits[$j] ?? 0);
                        if ($ju <= 0) continue;
                        $ja = (string)($finalActByIdx[$j] ?? 'remote');
                        if ($ja === 'call' || $ja === 'communication') continue;
                        if ($ja === $act) { $target = $j; break; }
                    }
                }
                if ($target === null) {
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $ju = (int)($halfUnits[$j] ?? 0);
                        if ($ju <= 0) continue;
                        $ja = (string)($finalActByIdx[$j] ?? 'remote');
                        if ($ja === 'call' || $ja === 'communication') continue;
                        $target = $j;
                        break;
                    }
                }
                if ($target === null) {
                    for ($j = $i + 1; $j < count($normPhases); $j++) {
                        $ju = (int)($halfUnits[$j] ?? 0);
                        if ($ju <= 0) continue;
                        $ja = (string)($finalActByIdx[$j] ?? 'remote');
                        if ($ja === 'call' || $ja === 'communication') continue;
                        $target = $j;
                        break;
                    }
                }
                if ($target === null) continue;
                if (!isset($mergedLabelsByIdx[$target])) $mergedLabelsByIdx[$target] = [];
                $mergedLabelsByIdx[$target][] = $label;
            }

            foreach ($normPhases as $idx => $p) {
                $u = (int)($halfUnits[$idx] ?? 0);
                $d = $u / 2.0;

                $act = (string)($p['default_activity_type'] ?? 'remote');
                if (isset($phaseTypeOverride[$idx])) {
                    $act = (string)$phaseTypeOverride[$idx];
                }
                $act = cnx_normalize_item_activity_type($act);

                // Build description while keeping "Fase: <primary>" at the end (for phase heuristics fallback).
                $prefix = '[Servizio: ' . ($serviceCode !== '' ? $serviceCode : $serviceName) . ']';
                if (isset($mergedLabelsByIdx[$idx]) && is_array($mergedLabelsByIdx[$idx]) && !empty($mergedLabelsByIdx[$idx])) {
                    $subs = array_values(array_unique(array_filter(array_map('strval', $mergedLabelsByIdx[$idx]))));
                    if (!empty($subs)) {
                        $prefix .= ' Sotto-attività: ' . implode(' | ', $subs) . '.';
                    }
                }
                $phaseKey = trim((string)($p['phase_key'] ?? ''));
                if ($phaseKey === '') $phaseKey = 'ongoing';
                if ($docProfile && $docDocsExist && strtolower($phaseKey) === 'documentation' && $docTodoGaps !== '') {
                    $prefix .= ' TODO: ' . $docTodoGaps . '.';
                }
                $desc = $prefix . ' Fase: ' . (string)($p['label'] ?? '');
                $phaseOrder = (int)($p['phase_order'] ?? 0);
                if ($phaseOrder <= 0 && isset($phaseLib[$phaseKey]['order'])) $phaseOrder = (int)$phaseLib[$phaseKey]['order'];
                if ($phaseOrder <= 0) $phaseOrder = 99;
                $blocking = array_key_exists('client_blocking', $p) ? (bool)$p['client_blocking'] : true;
                $mergeKey = trim((string)($p['merge_key'] ?? ''));

                // Calls: create call/communication items as hours (days=0), derived from totalDays and share_of_total.
                if ($act === 'call' || $act === 'communication') {
                    $share = (float)($p['share_of_total'] ?? 0.0);
                    if ($share < 0) $share = 0.0;
                    $minutesTotal = (int)round($totalDays * $share * 240.0); // call-day=4h

                    $minMinutes = isset($p['min_minutes']) ? (int)$p['min_minutes'] : 0;
                    if ($minMinutes <= 0) {
                        // Mandatory defaults (best-effort)
                        $pk = strtolower(trim((string)($p['phase_key'] ?? '')));
                        if ($pk === 'kickoff' || $pk === 'management_review') $minMinutes = 30;
                    }
                    if ($minutesTotal < $minMinutes) $minutesTotal = $minMinutes;
                    if ($minutesTotal <= 0) continue;

                    $minPer = isset($p['min_minutes_per_call']) ? (int)$p['min_minutes_per_call'] : 30;
                    $maxPer = isset($p['max_minutes_per_call']) ? (int)$p['max_minutes_per_call'] : 60;
                    if ($minPer <= 0) $minPer = 30;
                    if ($maxPer <= 0) $maxPer = 60;
                    if ($maxPer < $minPer) $maxPer = $minPer;
                    if ($minPer < 15) $minPer = 15;
                    if ($maxPer > 240) $maxPer = 240;

                    $parts = [];
                    $leftMin = $minutesTotal;
                    while ($leftMin >= $maxPer) { $parts[] = $maxPer; $leftMin -= $maxPer; }
                    if ($leftMin > 0) {
                        if (empty($parts)) {
                            $parts[] = max($minPer, min($maxPer, $leftMin));
                        } else {
                            $last = (int)end($parts);
                            $idxLast = count($parts) - 1;
                            if (($last + $leftMin) <= $maxPer) {
                                $parts[$idxLast] = $last + $leftMin;
                            } elseif ($leftMin >= $minPer) {
                                $parts[] = max($minPer, min($maxPer, $leftMin));
                            }
                        }
                    }
                    // Enforce 30–60 min typical range if no explicit per-phase overrides
                    if (!isset($p['min_minutes_per_call']) && !isset($p['max_minutes_per_call'])) {
                        $parts = array_map(static function ($m) {
                            $m = (int)$m;
                            if ($m <= 30) return 30;
                            if ($m >= 60) return 60;
                            return ($m < 45) ? 30 : 60;
                        }, $parts);
                    }

                    foreach ($parts as $pi => $mins) {
                        $payload = [
                            'plan_id' => $planId,
                            'activity_type' => $act,
                            'activity_date' => null,
                            'days' => 0.0,
                            'hours' => $mins / 60.0,
                            'km' => 0.0,
                            'day_rate' => max(0.0, $dayRate),
                            'km_rate' => 0.50,
                            'extras_amount' => 0.0,
                            'description' => $desc . (count($parts) > 1 ? (' (' . ($pi + 1) . '/' . count($parts) . ')') : ''),
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ];
                        if ($hasFixedAmountCol) {
                            if (!$fixedApplied && $serviceFixed > 0) {
                                $payload['fixed_amount'] = max(0.0, $serviceFixed);
                                $fixedApplied = true;
                            } else {
                                $payload['fixed_amount'] = 0.0;
                            }
                        }
                        if ($hasDomainCol) $payload['domain_activity_type_id'] = (int)$sid;
                        if ($hasAssigneeCol) $payload['assignee_user_id'] = null;
                        if ($hasPhaseKeyCol) $payload['phase_key'] = $phaseKey;
                        if ($hasPhaseOrderCol) $payload['phase_order'] = $phaseOrder;
                        if ($hasClientBlockingCol) $payload['client_blocking'] = $blocking ? 1 : 0;
                        if ($hasMergeKeyCol) $payload['merge_key'] = ($mergeKey !== '' ? $mergeKey : null);

                        $payload = array_intersect_key($payload, $itemCols);
                        $newId = $db->insert('consulting_plan_items', $payload);
                        if ($newId) $createdIds[] = (int)$newId;
                    }
                    continue;
                }

                // Day-based phases: drop those with 0 allocation (no 0.25 allowed, only 0.5 multiples)
                if ($d <= 0) continue;

                $payload = [
                    'plan_id' => $planId,
                    'activity_type' => $act,
                    'activity_date' => null,
                    'days' => $d,
                    'hours' => 0.0,
                    'km' => 0.0,
                    'day_rate' => max(0.0, $dayRate),
                    'km_rate' => 0.50,
                    'extras_amount' => 0.0,
                    'description' => $desc,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];

                if ($hasFixedAmountCol) {
                    // Apply service fixed amount only once (first phase with >0 days)
                    if (!$fixedApplied && $serviceFixed > 0) {
                        $payload['fixed_amount'] = max(0.0, $serviceFixed);
                        $fixedApplied = true;
                    } else {
                        $payload['fixed_amount'] = 0.0;
                    }
                }
                if ($hasDomainCol) {
                    $payload['domain_activity_type_id'] = (int)$sid;
                }
                if ($hasAssigneeCol) {
                    $payload['assignee_user_id'] = null;
                }
                if ($hasPhaseKeyCol) {
                    $payload['phase_key'] = $phaseKey;
                }
                if ($hasPhaseOrderCol) {
                    $payload['phase_order'] = $phaseOrder;
                }
                if ($hasClientBlockingCol) {
                    $payload['client_blocking'] = $blocking ? 1 : 0;
                }
                if ($hasMergeKeyCol) {
                    $payload['merge_key'] = ($mergeKey !== '' ? $mergeKey : null);
                }

                // Avoid SQL 500 if some legacy columns are missing (schema drift)
                $payload = array_intersect_key($payload, $itemCols);

                $newId = $db->insert('consulting_plan_items', $payload);
                if ($newId) $createdIds[] = (int)$newId;
            }
        }

        $db->commit();
    } catch (Throwable $tx) {
        $db->rollback();
        throw $tx;
    }

    api_success([
        'plan_id' => $planId,
        'created_count' => count($createdIds),
        'created_ids' => $createdIds,
        'replace_existing' => (bool)$replaceExisting,
        'domain_activity_type_id_available' => $hasDomainCol,
        'assignee_user_id_available' => $hasAssigneeCol,
        'phase_columns_available' => [
            'phase_key' => $hasPhaseKeyCol,
            'phase_order' => $hasPhaseOrderCol,
            'client_blocking' => $hasClientBlockingCol,
            'merge_key' => $hasMergeKeyCol,
        ],
        'intervention_type_key' => $interventionKey,
    ], 'Attività generate');
} catch (Throwable $e) {
    $errId = 'cpi_' . substr(str_replace('.', '', uniqid('', true)), -10);
    try { $errId = 'cpi_' . bin2hex(random_bytes(5)); } catch (Throwable $ignored) {}
    error_log('[CONSULTING_ITEMS_GENERATE][' . $errId . '] ' . $e->getMessage());
    $role = (string)($userInfo['role'] ?? 'user');
    $extra = ['error_id' => $errId];
    if ($role === 'super_admin') {
        $extra['debug_message'] = $e->getMessage();
    }
    api_error('Errore generazione attività', 500, $extra);
}

