<?php
// Consulting Planning: suggest consultant allocation for plan items (Tenant 28 internal tools)
// Distance-first for on-site, capacity-first for remote/call. Best-effort AI refinement when available.
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/openai_client.php';
require_once __DIR__ . '/../../includes/locations_municipalities.php';

verifyApiCsrfToken();

function cnx_has_table(Database $db, string $table): bool {
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

function cnx_region_rank(?string $region): ?int {
    $r = strtolower(trim((string)$region));
    if ($r === '') return null;

    // Macro-rank: 0=nord, 1=centro, 2=sud, 3=isole (used as coarse distance proxy)
    $north = ['valle d\'aosta', "valle d’aosta", 'piemonte', 'liguria', 'lombardia', 'trentino-alto adige', 'trentino alto adige', 'veneto', 'friuli-venezia giulia', 'friuli venezia giulia', 'emilia-romagna', 'emilia romagna'];
    $center = ['toscana', 'umbria', 'marche', 'lazio'];
    $south = ['abruzzo', 'molise', 'campania', 'puglia', 'basilicata', 'calabria'];
    $islands = ['sicilia', 'sardegna'];

    if (in_array($r, $north, true)) return 0;
    if (in_array($r, $center, true)) return 1;
    if (in_array($r, $south, true)) return 2;
    if (in_array($r, $islands, true)) return 3;
    return null;
}

/**
 * Lookup a city in italian_municipalities/provinces to get province+region (best-effort).
 * Returns null if reference tables are missing or lookup fails.
 *
 * @return array{name:string,province:string,region:string,rank:?int}|null
 */
function cnx_lookup_city(Database $db, string $city): ?array {
    $city = cnx_locations_normalize_city_input($city);
    if ($city === '') return null;
    if (!cnx_locations_has_italian_tables($db)) return null;

    try {
        $row = $db->fetchOne(
            "SELECT m.name, m.province_code AS province, p.region
             FROM italian_municipalities m
             JOIN italian_provinces p ON p.code = m.province_code
             WHERE LOWER(m.name) = LOWER(?)
             LIMIT 1",
            [$city]
        );
        if (!$row) return null;
        $name = (string)($row['name'] ?? $city);
        $prov = strtoupper(trim((string)($row['province'] ?? '')));
        $region = (string)($row['region'] ?? '');
        if ($prov === '' || $region === '') return null;
        return [
            'name' => $name,
            'province' => $prov,
            'region' => $region,
            'rank' => cnx_region_rank($region),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function cnx_city_distance_score(?array $a, ?array $b): int {
    // Lower is closer
    if (!$a || !$b) return 999;
    $an = strtolower(trim((string)($a['name'] ?? '')));
    $bn = strtolower(trim((string)($b['name'] ?? '')));
    if ($an !== '' && $bn !== '' && $an === $bn) return 0;
    $ap = strtoupper(trim((string)($a['province'] ?? '')));
    $bp = strtoupper(trim((string)($b['province'] ?? '')));
    if ($ap !== '' && $bp !== '' && $ap === $bp) return 1;
    $ar = strtolower(trim((string)($a['region'] ?? '')));
    $br = strtolower(trim((string)($b['region'] ?? '')));
    if ($ar !== '' && $br !== '' && $ar === $br) return 2;
    $ra = $a['rank'] ?? null;
    $rb = $b['rank'] ?? null;
    if ($ra === null || $rb === null) return 999;
    return 3 + abs((int)$ra - (int)$rb); // 4..6 in most cases
}

/**
 * @return array<int,array{comune:string,provincia:string,region:?string}>
 */
function cnx_extract_client_locations_from_estimate(?array $estimate): array {
    if (!$estimate || !is_array($estimate)) return [];
    $sel = $estimate['meta']['client_locations']['selected'] ?? null;
    if (!is_array($sel) || empty($sel)) return [];
    $out = [];
    foreach ($sel as $l) {
        if (!is_array($l)) continue;
        $comune = trim((string)($l['comune'] ?? ''));
        $prov = strtoupper(trim((string)($l['provincia'] ?? '')));
        if ($comune === '' || $prov === '') continue;
        $out[] = ['comune' => $comune, 'provincia' => $prov, 'region' => null];
    }
    // Dedup by comune+prov
    $seen = [];
    $dedup = [];
    foreach ($out as $l) {
        $k = strtolower($l['comune']) . '|' . strtoupper($l['provincia']);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $dedup[] = $l;
    }
    return $dedup;
}

/**
 * Fallback: determine a primary client location if estimate lacks selection.
 *
 * @return array{comune:string,provincia:string}|null
 */
function cnx_fetch_primary_client_location(Database $db, int $clientTenantId): ?array {
    $clientTenantId = (int)$clientTenantId;
    if ($clientTenantId <= 0) return null;

    // Prefer tenant_locations when available
    $hasTenantLocations = false;
    try {
        $hasTenantLocations = (bool)$db->fetchOne(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tenant_locations'
             LIMIT 1"
        );
    } catch (Throwable $e) {
        $hasTenantLocations = false;
    }

    if ($hasTenantLocations) {
        try {
            $row = $db->fetchOne(
                "SELECT comune, provincia
                 FROM tenant_locations
                 WHERE tenant_id = ?
                   AND deleted_at IS NULL
                   AND is_active = 1
                 ORDER BY is_primary DESC, CASE location_type WHEN 'sede_legale' THEN 0 ELSE 1 END, created_at ASC
                 LIMIT 1",
                [$clientTenantId]
            );
            if ($row) {
                $comune = trim((string)($row['comune'] ?? ''));
                $prov = strtoupper(trim((string)($row['provincia'] ?? '')));
                if ($comune !== '' && $prov !== '') return ['comune' => $comune, 'provincia' => $prov];
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Legacy fallback on tenants table
    try {
        $cols = cnx_cols($db, 'tenants');
        if (!empty($cols['sede_legale_comune']) && !empty($cols['sede_legale_provincia'])) {
            $t = $db->fetchOne(
                "SELECT sede_legale_comune AS comune, sede_legale_provincia AS provincia
                 FROM tenants
                 WHERE id = ? AND deleted_at IS NULL
                 LIMIT 1",
                [$clientTenantId]
            );
            if ($t) {
                $comune = trim((string)($t['comune'] ?? ''));
                $prov = strtoupper(trim((string)($t['provincia'] ?? '')));
                if ($comune !== '' && $prov !== '') return ['comune' => $comune, 'provincia' => $prov];
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    return null;
}

/**
 * @param array<int,array{id:int,name:string,email:string,home_city:?string,available_days:float}> $consultants
 * @param array<int,array{id:int,activity_type:string,days:float,domain_activity_type_id:int,description:string,activity_date:?string}> $items
 * @param array<int,array{name:string,province:string,region:string,rank:?int}> $clientCityInfos
 * @return array{per_item_assignments:array<int,int>,reasons:array<int,array<int,string>>,warnings:array<int,string>,matrix:array<string,mixed>,totals:array<string,mixed>}
 */
function cnx_suggest_deterministic(Database $db, array $consultants, array $items, array $clientCityInfos): array {
    $warnings = [];
    $reasons = [];

    // Prepare consultant city info + capacity
    $cCity = []; // uid => cityInfo|null
    $cap = []; // uid => available_days
    foreach ($consultants as $c) {
        $uid = (int)($c['id'] ?? 0);
        if ($uid <= 0) continue;
        $cap[$uid] = (float)($c['available_days'] ?? 0.0);
        $hc = isset($c['home_city']) ? trim((string)$c['home_city']) : '';
        $cCity[$uid] = ($hc !== '') ? cnx_lookup_city($db, $hc) : null;
        if ($hc !== '' && $cCity[$uid] === null) {
            $warnings[] = "Consulente #{$uid}: comune non risolto per distanza (\"{$hc}\")";
        }
        if ($hc === '') {
            $warnings[] = "Consulente #{$uid}: città di partenza mancante";
        }
    }

    if (empty($clientCityInfos)) {
        $warnings[] = 'Sedi cliente non disponibili: allocazione distanza-first limitata (fallback capacità)';
    }

    $assignedDays = []; // uid => days
    foreach ($cap as $uid => $_) $assignedDays[$uid] = 0.0;

    $perItem = [];

    foreach ($items as $it) {
        $itemId = (int)($it['id'] ?? 0);
        if ($itemId <= 0) continue;
        $type = strtolower(trim((string)($it['activity_type'] ?? 'remote')));
        $days = (float)($it['days'] ?? 0.0);
        if (!is_finite($days) || $days < 0) $days = 0.0;

        $isOnsite = ($type === 'onsite' || $type === 'travel');

        $bestUid = 0;
        $bestScore = PHP_INT_MAX;
        $bestRem = -INF;

        foreach ($consultants as $c) {
            $uid = (int)($c['id'] ?? 0);
            if ($uid <= 0) continue;
            $avail = (float)($cap[$uid] ?? 0.0);
            $used = (float)($assignedDays[$uid] ?? 0.0);
            $rem = $avail > 0 ? ($avail - $used) : 0.0;

            // Base score:
            // - On-site: distance score dominates
            // - Remote/call: score=0 (capacity dominates)
            $dist = 0;
            if ($isOnsite) {
                $dist = 999;
                if (!empty($clientCityInfos) && isset($cCity[$uid]) && $cCity[$uid]) {
                    foreach ($clientCityInfos as $cl) {
                        $dist = min($dist, cnx_city_distance_score($cCity[$uid], $cl));
                    }
                }
            }

            $score = $isOnsite ? ($dist * 100) : 0;

            // Soft penalty if remaining is negative or close to zero (avoid overload)
            if ($avail > 0 && $rem < -0.01) $score += 1000;
            if ($avail > 0 && $rem < 0.5) $score += 50;

            // Pick best score; tie-break by remaining capacity
            if ($score < $bestScore || ($score === $bestScore && $rem > $bestRem)) {
                $bestScore = $score;
                $bestUid = $uid;
                $bestRem = $rem;
            }
        }

        if ($bestUid <= 0) {
            $warnings[] = "Nessun consulente selezionato per attività #{$itemId}";
            continue;
        }
        $perItem[$itemId] = $bestUid;
        $assignedDays[$bestUid] = ($assignedDays[$bestUid] ?? 0.0) + $days;

        // Reasons (human-readable)
        $reasons[$itemId] = [];
        if ($isOnsite) {
            $reasons[$itemId][] = 'On-site: preferenza distanza';
        } else {
            $reasons[$itemId][] = 'Remoto/Call: bilanciamento capacità';
        }
    }

    // Aggregate matrix by service (if present)
    $matrix = [];
    foreach ($items as $it) {
        $itemId = (int)($it['id'] ?? 0);
        $sid = (int)($it['domain_activity_type_id'] ?? 0);
        $uid = (int)($perItem[$itemId] ?? 0);
        $days = (float)($it['days'] ?? 0.0);
        if ($itemId <= 0 || $sid <= 0 || $uid <= 0 || $days <= 0) continue;
        if (!isset($matrix[$sid])) $matrix[$sid] = [];
        $matrix[$sid][$uid] = (float)($matrix[$sid][$uid] ?? 0.0) + $days;
    }

    $totals = [
        'total_days' => array_reduce($items, static function ($acc, $it) {
            $d = (float)($it['days'] ?? 0.0);
            return $acc + (is_finite($d) ? max(0.0, $d) : 0.0);
        }, 0.0),
        'by_type' => [],
        'by_user' => $assignedDays,
    ];
    foreach ($items as $it) {
        $type = strtolower(trim((string)($it['activity_type'] ?? 'remote')));
        $d = (float)($it['days'] ?? 0.0);
        if (!is_finite($d) || $d < 0) $d = 0.0;
        $totals['by_type'][$type] = (float)($totals['by_type'][$type] ?? 0.0) + $d;
    }

    return [
        'per_item_assignments' => $perItem,
        'reasons' => $reasons,
        'warnings' => array_values(array_unique(array_filter($warnings))),
        'matrix' => $matrix,
        'totals' => $totals,
    ];
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

    // Require consultants selection table (migration 35)
    $hasPlanConsultants = cnx_has_table($db, 'consulting_plan_consultants');
    if (!$hasPlanConsultants) {
        api_error(
            'Modulo consulenti non inizializzato: applica la migrazione database 35',
            503,
            ['migration' => 'database/migrations/35_consulting_activity_catalog_and_schedule.sql']
        );
    }

    // Load consultants selected for the plan
    $cpcCols = cnx_cols($db, 'consulting_plan_consultants');
    $hasCpcHomeCity = !empty($cpcCols['home_city']);

    $uCols = cnx_cols($db, 'users');
    $hasUserHomeCity = !empty($uCols['home_city']);
    $hasJobTitle = !empty($uCols['job_title']);
    $hasSkillsText = !empty($uCols['skills_text']);
    $hasCertificationsText = !empty($uCols['certifications_text']);

    $sel = "SELECT u.id, u.name, u.email";
    if (!empty($uCols['role'])) $sel .= ", u.role";
    if ($hasUserHomeCity) $sel .= ", u.home_city AS user_home_city";
    if ($hasJobTitle) $sel .= ", u.job_title";
    if ($hasSkillsText) $sel .= ", u.skills_text";
    if ($hasCertificationsText) $sel .= ", u.certifications_text";
    if ($hasCpcHomeCity) $sel .= ", c.home_city AS plan_home_city";
    $sel .= " FROM consulting_plan_consultants c
              JOIN users u ON u.id = c.user_id
              WHERE c.plan_id = ?
                AND u.deleted_at IS NULL";
    if (!empty($uCols['is_active'])) $sel .= " AND u.is_active = 1";
    $sel .= " ORDER BY u.name ASC";

    $rows = $db->fetchAll($sel, [$planId]) ?: [];
    if (empty($rows)) api_error('Seleziona prima i consulenti del piano', 400);

    $consultants = [];
    foreach ($rows as $r) {
        $uid = (int)($r['id'] ?? 0);
        if ($uid <= 0) continue;
        $hcPlan = $hasCpcHomeCity ? trim((string)($r['plan_home_city'] ?? '')) : '';
        $hcUser = $hasUserHomeCity ? trim((string)($r['user_home_city'] ?? '')) : '';
        $hc = $hcPlan !== '' ? $hcPlan : $hcUser;
        $consultants[] = [
            'id' => $uid,
            'name' => (string)($r['name'] ?? ''),
            'email' => (string)($r['email'] ?? ''),
            'role' => (string)($r['role'] ?? ''),
            'home_city' => ($hc !== '' ? cnx_locations_normalize_city_input($hc) : null),
            'job_title' => $hasJobTitle ? (string)($r['job_title'] ?? '') : '',
            'skills_text' => $hasSkillsText ? (string)($r['skills_text'] ?? '') : '',
            'certifications_text' => $hasCertificationsText ? (string)($r['certifications_text'] ?? '') : '',
            'available_days' => 0.0, // filled below
        ];
    }

    // Capacities (best-effort, migration 37 optional)
    $capByUser = [];
    if (cnx_has_table($db, 'consultant_capacities')) {
        $ids = array_values(array_map(static fn($c) => (int)$c['id'], $consultants));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $capRows = $db->fetchAll(
            "SELECT user_id, available_days FROM consultant_capacities WHERE user_id IN ($ph)",
            $ids
        ) ?: [];
        foreach ($capRows as $cr) {
            $uid = (int)($cr['user_id'] ?? 0);
            if ($uid <= 0) continue;
            $d = $cr['available_days'] ?? null;
            $d = ($d === null || $d === '') ? null : (float)$d;
            if ($d !== null && is_finite($d)) $capByUser[$uid] = max(0.0, $d);
        }
    }
    foreach ($consultants as &$c) {
        $uid = (int)$c['id'];
        $c['available_days'] = (float)($capByUser[$uid] ?? 0.0);
        // If no capacity set, treat as "unknown/large" to avoid forcing 0 weighting
        if ($c['available_days'] <= 0) $c['available_days'] = 9999.0;
    }
    unset($c);

    // Load plan items
    $itemCols = cnx_cols($db, 'consulting_plan_items');
    $hasDeletedAt = !empty($itemCols['deleted_at']);
    $hasDomain = !empty($itemCols['domain_activity_type_id']);
    $hasAssignee = !empty($itemCols['assignee_user_id']);

    $fields = "id, activity_type, days";
    if (!empty($itemCols['activity_date'])) $fields .= ", activity_date";
    if ($hasDomain) $fields .= ", domain_activity_type_id";
    if (!empty($itemCols['description'])) $fields .= ", description";
    if ($hasAssignee) $fields .= ", assignee_user_id";

    $where = "plan_id = ?";
    if ($hasDeletedAt) $where .= " AND deleted_at IS NULL";
    $itemsRows = $db->fetchAll("SELECT {$fields} FROM consulting_plan_items WHERE {$where} ORDER BY id ASC", [$planId]) ?: [];
    if (empty($itemsRows)) api_error('Nessuna attività nel piano: genera prima le attività', 400);

    $items = [];
    foreach ($itemsRows as $r) {
        $iid = (int)($r['id'] ?? 0);
        if ($iid <= 0) continue;
        $items[] = [
            'id' => $iid,
            'activity_type' => (string)($r['activity_type'] ?? 'remote'),
            'days' => (float)($r['days'] ?? 0.0),
            'activity_date' => isset($r['activity_date']) ? (string)$r['activity_date'] : null,
            'domain_activity_type_id' => $hasDomain ? (int)($r['domain_activity_type_id'] ?? 0) : 0,
            'description' => isset($r['description']) ? (string)$r['description'] : '',
            'assignee_user_id' => $hasAssignee ? (int)($r['assignee_user_id'] ?? 0) : 0,
        ];
    }
    if (empty($items)) api_error('Nessuna attività valida nel piano', 400);

    // Parse estimate_json (optional) for selected client locations
    $estimateObj = null;
    if (isset($plan['estimate_json']) && $plan['estimate_json'] !== null && trim((string)$plan['estimate_json']) !== '') {
        try {
            $estimateObj = json_decode((string)$plan['estimate_json'], true);
            if (!is_array($estimateObj)) $estimateObj = null;
        } catch (Throwable $e) {
            $estimateObj = null;
        }
    }

    $clientLocs = cnx_extract_client_locations_from_estimate($estimateObj);
    if (empty($clientLocs)) {
        $primary = cnx_fetch_primary_client_location($db, $clientTenantId);
        if ($primary) {
            $clientLocs = [['comune' => $primary['comune'], 'provincia' => $primary['provincia'], 'region' => null]];
        }
    }

    $clientCityInfos = [];
    foreach ($clientLocs as $l) {
        $ci = cnx_lookup_city($db, (string)($l['comune'] ?? ''));
        if ($ci) $clientCityInfos[] = $ci;
    }

    // Deterministic baseline
    $base = cnx_suggest_deterministic($db, $consultants, $items, $clientCityInfos);

    // Optional AI refinement (best-effort)
    $aiUsed = false;
    $aiNotes = [];
    $aiError = null;

    $wantAi = true;
    if (array_key_exists('use_ai', $payload)) {
        $wantAi = (bool)$payload['use_ai'];
    }

    $finalAssignments = $base['per_item_assignments'];
    $finalReasons = $base['reasons'];
    $warnings = $base['warnings'];

    if ($wantAi) {
        try {
            $allowedUids = array_values(array_unique(array_map(static fn($c) => (int)$c['id'], $consultants)));
            $allowedItems = array_values(array_unique(array_map(static fn($it) => (int)$it['id'], $items)));

            $schema = [
                'name' => 'allocation_suggestion',
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        // IMPORTANT (OpenAI strict json_schema):
                        // - Avoid "map objects" via additionalProperties because strict schema validation may reject them.
                        // - Use arrays of objects with explicit required properties.
                        'assignments' => [
                            'type' => 'array',
                            'description' => 'Elenco assegnazioni item_id -> user_id',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'item_id' => ['type' => 'integer'],
                                    'user_id' => ['type' => 'integer'],
                                ],
                                'required' => ['item_id', 'user_id'],
                            ],
                        ],
                        'reasons' => [
                            'type' => 'array',
                            'description' => 'Elenco motivazioni per item_id',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'item_id' => ['type' => 'integer'],
                                    'reasons' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string'],
                                    ],
                                ],
                                'required' => ['item_id', 'reasons'],
                            ],
                        ],
                        'notes' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    // OpenAI json_schema strict mode requires listing all properties in "required".
                    'required' => ['assignments', 'reasons', 'notes'],
                ],
            ];

            $system = [
                'role' => 'system',
                'content' => "Sei un assistente che propone un’allocazione consulenti per un piano. Regole: 1) Per attività on-site/trasferta privilegia consulenti più vicini alla sede cliente (città/provincia/regione). 2) Attività remote/call possono andare anche a consulenti lontani. 3) Rispetta la disponibilità (available_days) e bilancia il carico. 4) Usa l’allocazione deterministica come base e migliora se serve. Rispondi SOLO JSON conforme allo schema.",
            ];
            $user = [
                'role' => 'user',
                'content' => json_encode([
                    'client_tenant_id' => $clientTenantId,
                    'client_locations' => $clientLocs,
                    'consultants' => array_map(static function ($c) {
                        return [
                            'user_id' => (int)$c['id'],
                            'name' => (string)$c['name'],
                            'home_city' => $c['home_city'] ?? null,
                            'job_title' => trim((string)($c['job_title'] ?? '')) !== '' ? trim((string)$c['job_title']) : null,
                            'skills_text' => trim((string)($c['skills_text'] ?? '')) !== '' ? trim((string)$c['skills_text']) : null,
                            'certifications_text' => trim((string)($c['certifications_text'] ?? '')) !== '' ? trim((string)$c['certifications_text']) : null,
                            'available_days' => (float)$c['available_days'],
                        ];
                    }, $consultants),
                    'items' => array_map(static function ($it) {
                        return [
                            'item_id' => (int)$it['id'],
                            'activity_type' => (string)$it['activity_type'],
                            'days' => (float)$it['days'],
                            'service_id' => (int)($it['domain_activity_type_id'] ?? 0),
                            'description' => (string)($it['description'] ?? ''),
                        ];
                    }, $items),
                    'deterministic_per_item_assignments' => $base['per_item_assignments'],
                    'allowed_user_ids' => $allowedUids,
                    'allowed_item_ids' => $allowedItems,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];

            $ai = cnx_openai_chat_json([$system, $user], $schema, [
                'temperature' => 0.2,
                'max_tokens' => 900,
                'timeout_seconds' => 25,
                'max_retries' => 1,
            ]);

            if (!empty($ai['ok']) && isset($ai['data']) && is_array($ai['data'])) {
                $aiUsed = true;
                $candList = $ai['data']['assignments'] ?? null;
                // Back-compat: previous schema used a map object per_item_assignments
                $candMapLegacy = $ai['data']['per_item_assignments'] ?? null;
                $candReasons = $ai['data']['reasons'] ?? null;
                $candNotes = $ai['data']['notes'] ?? null;

                // Build candidate map item_id => user_id (accept new list format and legacy map format)
                $candMap = null;
                if (is_array($candList)) {
                    $candMap = [];
                    foreach ($candList as $row) {
                        if (!is_array($row)) continue;
                        $iid = (int)($row['item_id'] ?? 0);
                        $uid = (int)($row['user_id'] ?? 0);
                        if ($iid > 0 && $uid > 0) $candMap[$iid] = $uid;
                    }
                } elseif (is_array($candMapLegacy)) {
                    $candMap = $candMapLegacy;
                }

                if (is_array($candMap)) {
                    // Validate and merge with deterministic fallback
                    $allowedUsersSet = array_flip($allowedUids);
                    $allowedItemsSet = array_flip($allowedItems);
                    $merged = $finalAssignments;
                    foreach ($candMap as $itemIdStr => $uidRaw) {
                        $iid = (int)$itemIdStr;
                        $uid = (int)$uidRaw;
                        if ($iid <= 0 || $uid <= 0) continue;
                        if (!isset($allowedItemsSet[$iid])) continue;
                        if (!isset($allowedUsersSet[$uid])) continue;
                        $merged[$iid] = $uid;
                    }
                    // Ensure every item assigned
                    foreach ($allowedItems as $iid) {
                        if (empty($merged[$iid])) {
                            $merged[$iid] = (int)($finalAssignments[$iid] ?? ($allowedUids[0] ?? 0));
                        }
                    }
                    $finalAssignments = $merged;
                }

                if (is_array($candReasons)) {
                    // New schema: array of {item_id, reasons:[...]} ; legacy: map item_id => [...]
                    $isList = array_keys($candReasons) === range(0, count($candReasons) - 1);
                    if ($isList) {
                        foreach ($candReasons as $row) {
                            if (!is_array($row)) continue;
                            $iid = (int)($row['item_id'] ?? 0);
                            $arr = $row['reasons'] ?? null;
                            if ($iid <= 0 || !is_array($arr)) continue;
                            $tmp = [];
                            foreach ($arr as $s) {
                                $s = trim((string)$s);
                                if ($s !== '') $tmp[] = $s;
                            }
                            if (!empty($tmp)) $finalReasons[$iid] = $tmp;
                        }
                    } else {
                        foreach ($candReasons as $itemIdStr => $arr) {
                            $iid = (int)$itemIdStr;
                            if ($iid <= 0) continue;
                            if (!is_array($arr)) continue;
                            $tmp = [];
                            foreach ($arr as $s) {
                                $s = trim((string)$s);
                                if ($s !== '') $tmp[] = $s;
                            }
                            if (!empty($tmp)) $finalReasons[$iid] = $tmp;
                        }
                    }
                }
                if (is_array($candNotes)) {
                    foreach ($candNotes as $n) {
                        $n = trim((string)$n);
                        if ($n !== '') $aiNotes[] = $n;
                    }
                    $aiNotes = array_values(array_unique($aiNotes));
                }
            } else {
                $aiError = (string)($ai['error'] ?? 'AI non disponibile');
            }
        } catch (Throwable $e) {
            $aiError = $e->getMessage();
        }
    }

    // Build matrix from finalAssignments
    $matrix = [];
    foreach ($items as $it) {
        $iid = (int)($it['id'] ?? 0);
        $sid = (int)($it['domain_activity_type_id'] ?? 0);
        $uid = (int)($finalAssignments[$iid] ?? 0);
        $days = (float)($it['days'] ?? 0.0);
        if ($iid <= 0 || $sid <= 0 || $uid <= 0 || $days <= 0) continue;
        if (!isset($matrix[$sid])) $matrix[$sid] = [];
        $matrix[$sid][$uid] = (float)($matrix[$sid][$uid] ?? 0.0) + $days;
    }

    // Totals by user
    $byUser = [];
    foreach ($finalAssignments as $iid => $uid) {
        $uid = (int)$uid;
        if ($uid <= 0) continue;
        $it = null;
        foreach ($items as $x) {
            if ((int)($x['id'] ?? 0) === (int)$iid) { $it = $x; break; }
        }
        $d = $it ? (float)($it['days'] ?? 0.0) : 0.0;
        if (!is_finite($d) || $d < 0) $d = 0.0;
        $byUser[$uid] = (float)($byUser[$uid] ?? 0.0) + $d;
    }

    $totals = $base['totals'];
    $totals['by_user'] = $byUser;

    if ($aiUsed) {
        $warnings[] = 'Allocazione generata con AI (best-effort) + fallback deterministico.';
    }
    if ($aiError) {
        $warnings[] = 'AI non disponibile: ' . $aiError;
    }

    api_success([
        'plan_id' => $planId,
        'client_tenant_id' => $clientTenantId,
        'storage_available' => [
            'assignee_user_id' => $hasAssignee,
            'domain_activity_type_id' => $hasDomain,
            'consultant_capacities' => cnx_has_table($db, 'consultant_capacities'),
            'italian_locations' => cnx_locations_has_italian_tables($db),
        ],
        'client_locations' => $clientLocs,
        'consultants' => array_map(static function ($c) {
            return [
                'id' => (int)$c['id'],
                'name' => (string)$c['name'],
                'home_city' => $c['home_city'] ?? null,
                'job_title' => trim((string)($c['job_title'] ?? '')) !== '' ? trim((string)$c['job_title']) : null,
                'skills_text' => trim((string)($c['skills_text'] ?? '')) !== '' ? trim((string)$c['skills_text']) : null,
                'certifications_text' => trim((string)($c['certifications_text'] ?? '')) !== '' ? trim((string)$c['certifications_text']) : null,
                'available_days' => (float)$c['available_days'],
            ];
        }, $consultants),
        'per_item_assignments' => $finalAssignments,
        'reasons' => $finalReasons,
        'matrix' => $matrix,
        'totals' => $totals,
        'ai' => [
            'used' => $aiUsed,
            'notes' => $aiNotes,
            'error' => $aiError,
        ],
        'warnings' => array_values(array_unique(array_filter(array_map('strval', $warnings)))),
    ]);
} catch (Throwable $e) {
    error_log('[CONSULTING_ALLOCATION_SUGGEST] ' . $e->getMessage());
    api_error('Errore proposta allocazione', 500);
}

