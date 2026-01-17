<?php
// Consulting Planning: Activity Types (catalog + per-client overrides)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

// Require migration 35 tables (schema drift safe)
try {
    $hasTypes = $db->fetchOne("SHOW TABLES LIKE 'consulting_activity_types'");
    $hasOverrides = $db->fetchOne("SHOW TABLES LIKE 'consulting_activity_type_overrides'");
    if (!$hasTypes || !$hasOverrides) {
        api_error(
            'Modulo catalogo attività non inizializzato: applica la migrazione database 35 (activity catalog)',
            503,
            ['migration' => 'database/migrations/35_consulting_activity_catalog_and_schedule.sql']
        );
    }
} catch (Exception $e) {
    api_error('Database non disponibile per il catalogo attività', 503);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    verifyApiCsrfToken();
}

function cnx_consulting_activity_types_columns(Database $db): array {
    try {
        $rows = $db->fetchAll(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'consulting_activity_types'"
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

function cnx_consulting_normalize_service_code(string $raw): ?string {
    $s = trim($raw);
    if ($s === '') return null;
    $s = strtoupper($s);
    // IMPORTANT (atomic rules):
    // - Do NOT introduce underscores from separators, otherwise atomic codes like "ISO/IEC 17025" become "ISO_IEC_17025"
    // - Keep user-provided "_" (so we can mark legacy composites), but remove spaces/slashes.
    $s = str_replace([' ', '/', '\\', "\t", "\r", "\n"], '', $s);
    $s = preg_replace('/[^A-Z0-9_-]/', '', $s) ?? $s;
    $s = trim($s, '_-');
    if ($s === '') return null;
    if (strlen($s) > 64) $s = substr($s, 0, 64);
    return $s;
}

function cnx_consulting_json_string_or_null($raw, int $maxLen = 65000): ?string {
    if ($raw === null || $raw === '') return null;
    if (is_string($raw)) {
        $s = trim($raw);
        if ($s === '') return null;
        if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
        // validate JSON best-effort (must decode to array/object)
        try {
            $tmp = json_decode($s, true);
            if ($tmp === null && strtolower($s) !== 'null') return null;
        } catch (Throwable $e) {
            return null;
        }
        return $s;
    }
    // arrays/objects: encode
    try {
        $s = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($s) || $s === '') return null;
        if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
        return $s;
    } catch (Throwable $e) {
        return null;
    }
}

function cnx_consulting_is_composite_service_code(?string $serviceCode): bool {
    $sc = strtoupper(trim((string)($serviceCode ?? '')));
    if ($sc === '') return false;
    // Rule 1: underscore-based codes are treated as legacy "combo" services.
    if (strpos($sc, '_') !== false) return true;

    // Rule 2 (best-effort): detect concatenated combos without separators (e.g. "ISO9001HACCP")
    // Keep the list small to avoid false positives.
    $tokens = [
        'ISO9001',
        'ISO14001',
        'ISO45001',
        'ISO22000',
        'HACCP',
        'UNI16636',
        'PRIVACY',
        'DPO',
        'BRC',
        'IFS',
    ];
    $hits = 0;
    foreach ($tokens as $t) {
        if (strpos($sc, $t) !== false) {
            $hits++;
            if ($hits >= 2) return true;
        }
    }
    return false;
}

/**
 * Normalize a list of standard codes.
 * - Uppercase
 * - Keep only A-Z0-9
 * - De-duplicate
 *
 * @return string[]
 */
function cnx_consulting_normalize_standard_codes($raw): array {
    $arr = [];
    if ($raw === null || $raw === '') return [];
    if (is_string($raw)) {
        $s = trim($raw);
        if ($s === '') return [];
        try {
            $tmp = json_decode($s, true);
            $arr = is_array($tmp) ? $tmp : [];
        } catch (Throwable $e) {
            $arr = [];
        }
    } elseif (is_array($raw)) {
        $arr = $raw;
    } else {
        $arr = [];
    }

    $out = [];
    foreach ($arr as $v) {
        $c = strtoupper(trim((string)$v));
        if ($c === '') continue;
        $c = preg_replace('/[^A-Z0-9]/', '', $c) ?? $c;
        if ($c === '') continue;
        $out[$c] = true;
    }
    return array_values(array_keys($out));
}

/**
 * Derive weight_factor from complexity_score (1..5).
 * Requested mapping:
 * - complexity 1 => weight 1.0
 * - complexity 5 => weight 1.5
 */
function cnx_consulting_weight_factor_from_complexity(?int $complexity): float {
    $c = (int)($complexity ?? 0);
    if ($c < 1 || $c > 5) return 1.0;
    $w = 1.0 + (float)($c - 1) * (0.5 / 4.0); // linear to 1.50
    return (float)round($w, 2);
}

/**
 * Derive call cadence from complexity_score (1..5).
 * Requested mapping:
 * - complexity 1 => 90 days
 * - complexity 5 => 30 days
 */
function cnx_consulting_call_every_days_from_complexity(?int $complexity): int {
    $c = (int)($complexity ?? 0);
    if ($c < 1 || $c > 5) return 7; // keep legacy default when unknown
    return 90 - (($c - 1) * 15); // 90,75,60,45,30
}

/**
 * Map a consulting_activity_types row, including optional service-catalog fields (migration 50).
 *
 * @return array<string,mixed>
 */
function cnx_map_type_row(array $r): array {
    $aliases = [];
    if (isset($r['aliases_json']) && $r['aliases_json'] !== null && $r['aliases_json'] !== '') {
        try { $aliases = json_decode((string)$r['aliases_json'], true) ?: []; } catch (Throwable $e) { $aliases = []; }
    }
    if (!is_array($aliases)) $aliases = [];
    $aliases = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $aliases), static fn($v) => $v !== ''));

    $phases = [];
    if (isset($r['default_phases_json']) && $r['default_phases_json'] !== null && $r['default_phases_json'] !== '') {
        try { $phases = json_decode((string)$r['default_phases_json'], true) ?: []; } catch (Throwable $e) { $phases = []; }
    }
    if (!is_array($phases)) $phases = [];

    $deliverables = [];
    if (isset($r['default_deliverables_json']) && $r['default_deliverables_json'] !== null && $r['default_deliverables_json'] !== '') {
        try { $deliverables = json_decode((string)$r['default_deliverables_json'], true) ?: []; } catch (Throwable $e) { $deliverables = []; }
    }
    if (!is_array($deliverables)) $deliverables = [];

    $stdCodes = [];
    if (isset($r['standard_codes_json']) && $r['standard_codes_json'] !== null && $r['standard_codes_json'] !== '') {
        try { $stdCodes = json_decode((string)$r['standard_codes_json'], true) ?: []; } catch (Throwable $e) { $stdCodes = []; }
    }
    if (!is_array($stdCodes)) $stdCodes = [];
    $stdCodes = cnx_consulting_normalize_standard_codes($stdCodes);

    return [
        'id' => (int)$r['id'],
        'name' => (string)$r['name'],
        'weight_factor' => (float)$r['weight_factor'],
        'call_every_days_default' => (int)$r['call_every_days_default'],
        'call_duration_minutes_default' => (int)$r['call_duration_minutes_default'],
        'is_active' => (bool)((int)($r['is_active'] ?? 0)),
        'is_legacy_composite' => isset($r['is_legacy_composite']) ? (bool)((int)($r['is_legacy_composite'] ?? 0)) : false,
        // Optional pricing columns (migration 39) - default 0 when missing (schema drift safe)
        'default_day_rate' => isset($r['default_day_rate']) ? (float)$r['default_day_rate'] : 0.0,
        'default_fixed_amount' => isset($r['default_fixed_amount']) ? (float)$r['default_fixed_amount'] : 0.0,
        // Optional service-catalog columns (migration 50) - null/[] when missing (schema drift safe)
        'service_code' => isset($r['service_code']) ? (string)($r['service_code'] ?? '') : null,
        'category' => isset($r['category']) ? (string)($r['category'] ?? '') : null,
        // Optional scheme/legacy columns (migration 60) - null/false when missing (schema drift safe)
        'scheme_type' => isset($r['scheme_type']) ? (string)($r['scheme_type'] ?? '') : null,
        'legacy_combo' => isset($r['legacy_combo']) ? (bool)((int)($r['legacy_combo'] ?? 0)) : false,
        'aliases' => $aliases,
        'standard_codes' => $stdCodes,
        'base_days_min' => isset($r['base_days_min']) ? (($r['base_days_min'] === null || $r['base_days_min'] === '') ? null : (int)$r['base_days_min']) : null,
        'base_days_max' => isset($r['base_days_max']) ? (($r['base_days_max'] === null || $r['base_days_max'] === '') ? null : (int)$r['base_days_max']) : null,
        'complexity_score' => isset($r['complexity_score']) ? (($r['complexity_score'] === null || $r['complexity_score'] === '') ? null : (int)$r['complexity_score']) : null,
        'default_phases' => $phases,
        'default_deliverables' => $deliverables,
    ];
}

try {
    $action = (string)($_GET['action'] ?? ($_POST['action'] ?? 'list'));

    if ($method === 'GET' && $action === 'list') {
        $clientTenantId = isset($_GET['client_tenant_id']) ? (int)$_GET['client_tenant_id'] : 0;
        if ($clientTenantId > 0 && !cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) {
            api_error('Accesso negato all’azienda cliente', 403);
        }

        $cols = cnx_consulting_activity_types_columns($db);

        $select = "SELECT id, name, weight_factor, call_every_days_default, call_duration_minutes_default, is_active";
        if (!empty($cols['default_day_rate'])) $select .= ", default_day_rate";
        if (!empty($cols['default_fixed_amount'])) $select .= ", default_fixed_amount";
        if (!empty($cols['service_code'])) $select .= ", service_code";
        if (!empty($cols['category'])) $select .= ", category";
        if (!empty($cols['scheme_type'])) $select .= ", scheme_type";
        if (!empty($cols['legacy_combo'])) $select .= ", legacy_combo";
        if (!empty($cols['aliases_json'])) $select .= ", aliases_json";
        if (!empty($cols['standard_codes_json'])) $select .= ", standard_codes_json";
        if (!empty($cols['is_legacy_composite'])) $select .= ", is_legacy_composite";
        if (!empty($cols['base_days_min'])) $select .= ", base_days_min";
        if (!empty($cols['base_days_max'])) $select .= ", base_days_max";
        if (!empty($cols['complexity_score'])) $select .= ", complexity_score";
        if (!empty($cols['default_phases_json'])) $select .= ", default_phases_json";
        if (!empty($cols['default_deliverables_json'])) $select .= ", default_deliverables_json";

        // Optional filters (server-side, non-breaking)
        $q = trim((string)($_GET['search'] ?? $_GET['q'] ?? ''));
        $cat = trim((string)($_GET['category'] ?? ''));
        $activeOnly = isset($_GET['active_only']) && ($_GET['active_only'] === '1' || $_GET['active_only'] === 'true');

        $where = " WHERE deleted_at IS NULL AND tenant_id = ? ";
        $params = [CNX_VENDOR_TENANT_ID];
        if ($activeOnly) {
            $where .= " AND is_active = 1 ";
            // Hide legacy combos when requesting active-only (best-effort)
            if (!empty($cols['is_legacy_composite'])) $where .= " AND (is_legacy_composite IS NULL OR is_legacy_composite = 0) ";
            if (!empty($cols['legacy_combo'])) $where .= " AND (legacy_combo IS NULL OR legacy_combo = 0) ";
        }
        if ($cat !== '' && !empty($cols['category'])) {
            $where .= " AND category = ? ";
            $params[] = $cat;
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $conds = ["name LIKE ?"];
            $params[] = $like;
            if (!empty($cols['service_code'])) { $conds[] = "service_code LIKE ?"; $params[] = $like; }
            if (!empty($cols['aliases_json'])) { $conds[] = "aliases_json LIKE ?"; $params[] = $like; }
            $where .= " AND (" . implode(" OR ", $conds) . ") ";
        }

        $select .= " FROM consulting_activity_types " . $where . " ORDER BY name ASC";

        $types = $db->fetchAll($select, $params) ?: [];

        $overridesByType = [];
        if ($clientTenantId > 0) {
            $rows = $db->fetchAll(
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
            foreach ($rows as $o) {
                $overridesByType[(int)$o['activity_type_id']] = $o;
            }
        }

        $out = [];
        foreach ($types as $t) {
            $base = cnx_map_type_row($t);
            $ov = $clientTenantId > 0 ? ($overridesByType[$base['id']] ?? null) : null;
            $effective = $base;
            $hasOverride = false;

            if (is_array($ov)) {
                if ($ov['weight_factor_override'] !== null && $ov['weight_factor_override'] !== '') {
                    $effective['weight_factor'] = (float)$ov['weight_factor_override'];
                    $hasOverride = true;
                }
                if ($ov['call_every_days_override'] !== null && $ov['call_every_days_override'] !== '') {
                    $effective['call_every_days_default'] = (int)$ov['call_every_days_override'];
                    $hasOverride = true;
                }
                if ($ov['call_duration_minutes_override'] !== null && $ov['call_duration_minutes_override'] !== '') {
                    $effective['call_duration_minutes_default'] = (int)$ov['call_duration_minutes_override'];
                    $hasOverride = true;
                }
                if ($ov['is_active_override'] !== null && $ov['is_active_override'] !== '') {
                    $effective['is_active'] = (bool)((int)$ov['is_active_override']);
                    $hasOverride = true;
                }
            }

            $out[] = [
                'base' => $base,
                'effective' => $effective,
                'has_override' => $hasOverride,
            ];
        }

        api_success([
            'client_tenant_id' => $clientTenantId ?: null,
            'types' => $out,
        ]);
    }

    // For non-GET, parse JSON body
    $raw = cnx_get_raw_request_body();
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        api_error('Dati non validi', 400);
    }

    $action = (string)($payload['action'] ?? $action);

    if ($action === 'create') {
        $name = trim((string)($payload['name'] ?? ''));
        $weightRaw = $payload['weight_factor'] ?? null;
        $callEveryRaw = $payload['call_every_days_default'] ?? null;
        $callDurRaw = $payload['call_duration_minutes_default'] ?? null;
        $hasWeightKey = array_key_exists('weight_factor', $payload);
        $hasCallEveryKey = array_key_exists('call_every_days_default', $payload);
        $weight = ($weightRaw === null || $weightRaw === '') ? 1.0 : (float)$weightRaw;
        $callEvery = ($callEveryRaw === null || $callEveryRaw === '') ? 7 : (int)$callEveryRaw;
        $callDur = ($callDurRaw === null || $callDurRaw === '') ? 30 : (int)$callDurRaw;
        $defDayRateRaw = $payload['default_day_rate'] ?? null;
        $defFixedRaw = $payload['default_fixed_amount'] ?? null;
        // Pricing defaults: 360€/day when unset; allow explicit 0 if provided
        $defDayRate = ($defDayRateRaw === null || $defDayRateRaw === '') ? 360.0 : (float)$defDayRateRaw;
        $defFixed = ($defFixedRaw === null || $defFixedRaw === '') ? 0.0 : (float)$defFixedRaw;
        $active = isset($payload['is_active']) ? (int)((bool)$payload['is_active']) : 1;

        if ($name === '') api_error('Nome attività obbligatorio', 400);
        if ($callEvery <= 0) api_error('call_every_days_default non valido', 400);
        if ($callDur <= 0) api_error('call_duration_minutes_default non valido', 400);
        if ($defDayRate < 0) api_error('default_day_rate non valido', 400);
        if ($defFixed < 0) api_error('default_fixed_amount non valido', 400);

        $cols = cnx_consulting_activity_types_columns($db);

        $serviceCode = null;
        if (!empty($cols['service_code']) && array_key_exists('service_code', $payload)) {
            $serviceCode = cnx_consulting_normalize_service_code((string)($payload['service_code'] ?? ''));
        }

        $standardCodes = [];
        $standardCodesJson = null;
        if (!empty($cols['standard_codes_json']) && (array_key_exists('standard_codes', $payload) || array_key_exists('standard_codes_json', $payload))) {
            $standardCodes = cnx_consulting_normalize_standard_codes($payload['standard_codes'] ?? ($payload['standard_codes_json'] ?? null));
            $standardCodesJson = !empty($standardCodes)
                ? (json_encode($standardCodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null)
                : null;
        }

        // Atomic catalog rule:
        // - Composite codes MUST NOT be active (they can exist only as legacy for historical plans).
        $isComposite = cnx_consulting_is_composite_service_code($serviceCode);
        $isLegacyComposite = $isComposite;
        if ($serviceCode !== null && $serviceCode !== '') {
            if ($serviceCode === 'ACCREDIA') {
                api_error('Codice servizio non valido: "ACCREDIA" è legacy. Usa "ACCRED".', 400);
            }
            if ($isComposite) {
                api_error('Codice servizio composito non consentito. Crea servizi atomici separati (es. ISO9001, HACCP) e selezionali nel piano.', 400);
            }
        }
        $category = null;
        if (!empty($cols['category']) && array_key_exists('category', $payload)) {
            $category = trim((string)($payload['category'] ?? ''));
            if ($category === '') $category = null;
            if ($category !== null && strlen($category) > 64) $category = substr($category, 0, 64);
        }
        $schemeType = null;
        if (!empty($cols['scheme_type']) && array_key_exists('scheme_type', $payload)) {
            $schemeType = trim((string)($payload['scheme_type'] ?? ''));
            if ($schemeType === '') $schemeType = null;
            if ($schemeType !== null && strlen($schemeType) > 30) $schemeType = substr($schemeType, 0, 30);
        }
        $aliasesJson = null;
        if (!empty($cols['aliases_json'])) {
            $aliasesJson = cnx_consulting_json_string_or_null($payload['aliases'] ?? ($payload['aliases_json'] ?? null));
        }
        $baseMin = null;
        $baseMax = null;
        if (!empty($cols['base_days_min']) && array_key_exists('base_days_min', $payload)) {
            $v = $payload['base_days_min'];
            $baseMin = ($v === null || $v === '') ? null : (int)$v;
            if ($baseMin !== null && $baseMin < 0) $baseMin = 0;
        }
        if (!empty($cols['base_days_max']) && array_key_exists('base_days_max', $payload)) {
            $v = $payload['base_days_max'];
            $baseMax = ($v === null || $v === '') ? null : (int)$v;
            if ($baseMax !== null && $baseMax < 0) $baseMax = 0;
        }
        if ($baseMin !== null && $baseMax !== null && $baseMax < $baseMin) {
            api_error('base_days_max deve essere >= base_days_min', 400);
        }
        $complexity = null;
        if (!empty($cols['complexity_score']) && array_key_exists('complexity_score', $payload)) {
            $v = $payload['complexity_score'];
            $complexity = ($v === null || $v === '') ? null : (int)$v;
            if ($complexity !== null && ($complexity < 1 || $complexity > 5)) {
                api_error('complexity_score non valido (1-5)', 400);
            }
        }
        // If complexity is provided but weight/call cadence are not explicitly set, derive them.
        if ($complexity !== null) {
            if (!$hasWeightKey || $weightRaw === null || $weightRaw === '') {
                $weight = cnx_consulting_weight_factor_from_complexity($complexity);
            }
            if (!$hasCallEveryKey || $callEveryRaw === null || $callEveryRaw === '') {
                $callEvery = cnx_consulting_call_every_days_from_complexity($complexity);
            }
        }
        $defaultPhasesJson = null;
        if (!empty($cols['default_phases_json'])) {
            $defaultPhasesJson = cnx_consulting_json_string_or_null($payload['default_phases'] ?? ($payload['default_phases_json'] ?? null));
        }
        $defaultDeliverablesJson = null;
        if (!empty($cols['default_deliverables_json'])) {
            $defaultDeliverablesJson = cnx_consulting_json_string_or_null($payload['default_deliverables'] ?? ($payload['default_deliverables_json'] ?? null));
        }

        $insert = [
            'tenant_id' => CNX_VENDOR_TENANT_ID,
            'name' => $name,
            'weight_factor' => $weight,
            'call_every_days_default' => $callEvery,
            'call_duration_minutes_default' => $callDur,
            'is_active' => $active ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (!empty($cols['default_day_rate'])) $insert['default_day_rate'] = max(0, $defDayRate);
        if (!empty($cols['default_fixed_amount'])) $insert['default_fixed_amount'] = max(0, $defFixed);
        if (!empty($cols['service_code'])) $insert['service_code'] = $serviceCode;
        if (!empty($cols['category'])) $insert['category'] = $category;
        if (!empty($cols['scheme_type'])) $insert['scheme_type'] = $schemeType;
        if (!empty($cols['aliases_json'])) $insert['aliases_json'] = $aliasesJson;
        if (!empty($cols['standard_codes_json'])) $insert['standard_codes_json'] = $standardCodesJson;
        if (!empty($cols['is_legacy_composite'])) $insert['is_legacy_composite'] = $isLegacyComposite ? 1 : 0;
        if (!empty($cols['legacy_combo'])) $insert['legacy_combo'] = 0; // atomic rule: newly created services are never legacy combos
        if (!empty($cols['base_days_min'])) $insert['base_days_min'] = $baseMin;
        if (!empty($cols['base_days_max'])) $insert['base_days_max'] = $baseMax;
        if (!empty($cols['complexity_score'])) $insert['complexity_score'] = $complexity;
        if (!empty($cols['default_phases_json'])) $insert['default_phases_json'] = $defaultPhasesJson;
        if (!empty($cols['default_deliverables_json'])) $insert['default_deliverables_json'] = $defaultDeliverablesJson;

        // Friendly uniqueness checks (Database::insert masks SQL errors)
        if ($serviceCode && !empty($cols['service_code'])) {
            $dup = $db->fetchOne(
                "SELECT id FROM consulting_activity_types
                 WHERE tenant_id = ?
                   AND deleted_at IS NULL
                   AND service_code = ?
                 LIMIT 1",
                [CNX_VENDOR_TENANT_ID, $serviceCode]
            );
            if ($dup) api_error('Codice servizio già esistente', 409);
        }
        $dupName = $db->fetchOne(
            "SELECT id FROM consulting_activity_types
             WHERE tenant_id = ?
               AND deleted_at IS NULL
               AND name = ?
             LIMIT 1",
            [CNX_VENDOR_TENANT_ID, $name]
        );
        if ($dupName) api_error('Nome servizio già esistente', 409);

        $id = $db->insert('consulting_activity_types', $insert);
        if (!$id) api_error('Creazione fallita', 500);
        api_success(['id' => (int)$id], 'Attività creata');
    }

    if ($action === 'update') {
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) api_error('id obbligatorio', 400);

        $existing = $db->fetchOne(
            "SELECT id FROM consulting_activity_types WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$id, CNX_VENDOR_TENANT_ID]
        );
        if (!$existing) api_error('Attività non trovata', 404);

        $name = trim((string)($payload['name'] ?? ''));
        $weightRaw = $payload['weight_factor'] ?? null;
        $callEveryRaw = $payload['call_every_days_default'] ?? null;
        $callDurRaw = $payload['call_duration_minutes_default'] ?? null;
        $hasWeightKey = array_key_exists('weight_factor', $payload);
        $hasCallEveryKey = array_key_exists('call_every_days_default', $payload);
        $weight = ($weightRaw === null || $weightRaw === '') ? 1.0 : (float)$weightRaw;
        $callEvery = ($callEveryRaw === null || $callEveryRaw === '') ? 7 : (int)$callEveryRaw;
        $callDur = ($callDurRaw === null || $callDurRaw === '') ? 30 : (int)$callDurRaw;
        $defDayRateRaw = $payload['default_day_rate'] ?? null;
        $defFixedRaw = $payload['default_fixed_amount'] ?? null;
        // Pricing defaults: 360€/day when unset; allow explicit 0 if provided
        $defDayRate = ($defDayRateRaw === null || $defDayRateRaw === '') ? 360.0 : (float)$defDayRateRaw;
        $defFixed = ($defFixedRaw === null || $defFixedRaw === '') ? 0.0 : (float)$defFixedRaw;
        $active = isset($payload['is_active']) ? (int)((bool)$payload['is_active']) : 1;

        if ($name === '') api_error('Nome attività obbligatorio', 400);
        if ($callEvery <= 0) api_error('call_every_days_default non valido', 400);
        if ($callDur <= 0) api_error('call_duration_minutes_default non valido', 400);
        if ($defDayRate < 0) api_error('default_day_rate non valido', 400);
        if ($defFixed < 0) api_error('default_fixed_amount non valido', 400);

        $cols = cnx_consulting_activity_types_columns($db);

        $serviceCode = null;
        if (!empty($cols['service_code']) && array_key_exists('service_code', $payload)) {
            $serviceCode = cnx_consulting_normalize_service_code((string)($payload['service_code'] ?? ''));
        }

        $standardCodes = [];
        $standardCodesJson = null;
        if (!empty($cols['standard_codes_json']) && (array_key_exists('standard_codes', $payload) || array_key_exists('standard_codes_json', $payload))) {
            $standardCodes = cnx_consulting_normalize_standard_codes($payload['standard_codes'] ?? ($payload['standard_codes_json'] ?? null));
            $standardCodesJson = !empty($standardCodes)
                ? (json_encode($standardCodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null)
                : null;
        }
        $category = null;
        if (!empty($cols['category']) && array_key_exists('category', $payload)) {
            $category = trim((string)($payload['category'] ?? ''));
            if ($category === '') $category = null;
            if ($category !== null && strlen($category) > 64) $category = substr($category, 0, 64);
        }
        $schemeType = null;
        if (!empty($cols['scheme_type']) && array_key_exists('scheme_type', $payload)) {
            $schemeType = trim((string)($payload['scheme_type'] ?? ''));
            if ($schemeType === '') $schemeType = null;
            if ($schemeType !== null && strlen($schemeType) > 30) $schemeType = substr($schemeType, 0, 30);
        }
        $aliasesJson = null;
        if (!empty($cols['aliases_json']) && (array_key_exists('aliases', $payload) || array_key_exists('aliases_json', $payload))) {
            $aliasesJson = cnx_consulting_json_string_or_null($payload['aliases'] ?? ($payload['aliases_json'] ?? null));
        }
        $baseMin = null;
        $baseMax = null;
        if (!empty($cols['base_days_min']) && array_key_exists('base_days_min', $payload)) {
            $v = $payload['base_days_min'];
            $baseMin = ($v === null || $v === '') ? null : (int)$v;
            if ($baseMin !== null && $baseMin < 0) $baseMin = 0;
        }
        if (!empty($cols['base_days_max']) && array_key_exists('base_days_max', $payload)) {
            $v = $payload['base_days_max'];
            $baseMax = ($v === null || $v === '') ? null : (int)$v;
            if ($baseMax !== null && $baseMax < 0) $baseMax = 0;
        }
        if ($baseMin !== null && $baseMax !== null && $baseMax < $baseMin) {
            api_error('base_days_max deve essere >= base_days_min', 400);
        }
        $complexity = null;
        if (!empty($cols['complexity_score']) && array_key_exists('complexity_score', $payload)) {
            $v = $payload['complexity_score'];
            $complexity = ($v === null || $v === '') ? null : (int)$v;
            if ($complexity !== null && ($complexity < 1 || $complexity > 5)) {
                api_error('complexity_score non valido (1-5)', 400);
            }
        }
        // If complexity is provided but weight/call cadence are not explicitly set, derive them.
        if ($complexity !== null) {
            if (!$hasWeightKey || $weightRaw === null || $weightRaw === '') {
                $weight = cnx_consulting_weight_factor_from_complexity($complexity);
            }
            if (!$hasCallEveryKey || $callEveryRaw === null || $callEveryRaw === '') {
                $callEvery = cnx_consulting_call_every_days_from_complexity($complexity);
            }
        }
        $defaultPhasesJson = null;
        if (!empty($cols['default_phases_json']) && (array_key_exists('default_phases', $payload) || array_key_exists('default_phases_json', $payload))) {
            $defaultPhasesJson = cnx_consulting_json_string_or_null($payload['default_phases'] ?? ($payload['default_phases_json'] ?? null));
        }
        $defaultDeliverablesJson = null;
        if (!empty($cols['default_deliverables_json']) && (array_key_exists('default_deliverables', $payload) || array_key_exists('default_deliverables_json', $payload))) {
            $defaultDeliverablesJson = cnx_consulting_json_string_or_null($payload['default_deliverables'] ?? ($payload['default_deliverables_json'] ?? null));
        }

        $update = [
            'name' => $name,
            'weight_factor' => $weight,
            'call_every_days_default' => $callEvery,
            'call_duration_minutes_default' => $callDur,
            'is_active' => $active ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (!empty($cols['default_day_rate'])) $update['default_day_rate'] = max(0, $defDayRate);
        if (!empty($cols['default_fixed_amount'])) $update['default_fixed_amount'] = max(0, $defFixed);
        if (!empty($cols['service_code']) && array_key_exists('service_code', $payload)) $update['service_code'] = $serviceCode;
        if (!empty($cols['category']) && array_key_exists('category', $payload)) $update['category'] = $category;
        if (!empty($cols['scheme_type']) && array_key_exists('scheme_type', $payload)) $update['scheme_type'] = $schemeType;
        if (!empty($cols['aliases_json']) && (array_key_exists('aliases', $payload) || array_key_exists('aliases_json', $payload))) $update['aliases_json'] = $aliasesJson;
        if (!empty($cols['standard_codes_json']) && (array_key_exists('standard_codes', $payload) || array_key_exists('standard_codes_json', $payload))) $update['standard_codes_json'] = $standardCodesJson;
        if (!empty($cols['legacy_combo']) && array_key_exists('legacy_combo', $payload)) $update['legacy_combo'] = (int)((bool)$payload['legacy_combo']);
        if (!empty($cols['base_days_min']) && array_key_exists('base_days_min', $payload)) $update['base_days_min'] = $baseMin;
        if (!empty($cols['base_days_max']) && array_key_exists('base_days_max', $payload)) $update['base_days_max'] = $baseMax;
        if (!empty($cols['complexity_score']) && array_key_exists('complexity_score', $payload)) $update['complexity_score'] = $complexity;
        if (!empty($cols['default_phases_json']) && (array_key_exists('default_phases', $payload) || array_key_exists('default_phases_json', $payload))) $update['default_phases_json'] = $defaultPhasesJson;
        if (!empty($cols['default_deliverables_json']) && (array_key_exists('default_deliverables', $payload) || array_key_exists('default_deliverables_json', $payload))) $update['default_deliverables_json'] = $defaultDeliverablesJson;

        // Enforce atomic rule on update too
        $isComposite = cnx_consulting_is_composite_service_code($serviceCode);
        $isLegacyComposite = $isComposite;
        if (!empty($cols['is_legacy_composite']) && array_key_exists('service_code', $payload)) {
            $update['is_legacy_composite'] = $isLegacyComposite ? 1 : 0;
        }
        if (!empty($cols['legacy_combo']) && array_key_exists('service_code', $payload)) {
            // Atomic rule: updates cannot introduce composite codes (blocked above), so keep legacy_combo in sync if service_code changes
            $update['legacy_combo'] = 0;
        }
        if (array_key_exists('service_code', $payload)) {
            if ($serviceCode !== null && $serviceCode !== '') {
                if ($serviceCode === 'ACCREDIA') {
                    api_error('Codice servizio non valido: "ACCREDIA" è legacy. Usa "ACCRED".', 400);
                }
                if ($isComposite) {
                    api_error('Codice servizio composito non consentito. Seleziona servizi atomici separati nel piano.', 400);
                }
            }
        }

        // Friendly uniqueness checks (Database::update masks SQL errors)
        if ($serviceCode && !empty($cols['service_code']) && array_key_exists('service_code', $payload)) {
            $dup = $db->fetchOne(
                "SELECT id FROM consulting_activity_types
                 WHERE tenant_id = ?
                   AND deleted_at IS NULL
                   AND service_code = ?
                   AND id != ?
                 LIMIT 1",
                [CNX_VENDOR_TENANT_ID, $serviceCode, $id]
            );
            if ($dup) api_error('Codice servizio già esistente', 409);
        }
        $dupName = $db->fetchOne(
            "SELECT id FROM consulting_activity_types
             WHERE tenant_id = ?
               AND deleted_at IS NULL
               AND name = ?
               AND id != ?
             LIMIT 1",
            [CNX_VENDOR_TENANT_ID, $name, $id]
        );
        if ($dupName) api_error('Nome servizio già esistente', 409);

        $ok = $db->update('consulting_activity_types', $update, ['id' => $id]);
        if (!$ok) api_error('Aggiornamento fallito', 500);
        api_success(['id' => $id], 'Attività aggiornata');
    }

    if ($action === 'delete') {
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) api_error('id obbligatorio', 400);

        $existing = $db->fetchOne(
            "SELECT id FROM consulting_activity_types WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$id, CNX_VENDOR_TENANT_ID]
        );
        if (!$existing) api_error('Attività non trovata', 404);

        $ok = $db->update('consulting_activity_types', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
        if (!$ok) api_error('Eliminazione fallita', 500);
        api_success(['id' => $id], 'Attività eliminata');
    }

    if ($action === 'seed_services' || $action === 'seed_base') {
        $role = (string)($userInfo['role'] ?? 'user');
        if (!in_array($role, ['super_admin', 'admin'], true)) {
            api_error('Accesso negato', 403);
        }

        $cols = cnx_consulting_activity_types_columns($db);

        $defaultPhases = [
            ['phase_key' => 'kickoff', 'label' => 'Kickoff / Pianificazione', 'default_activity_type' => 'call', 'share_of_total' => 0.06],
            ['phase_key' => 'gap_analysis', 'label' => 'Analisi gap / Analisi contesto', 'default_activity_type' => 'remote', 'share_of_total' => 0.16],
            ['phase_key' => 'documentation', 'label' => 'Documentazione (manuale/procedure/moduli)', 'default_activity_type' => 'remote', 'share_of_total' => 0.30],
            ['phase_key' => 'implementation', 'label' => 'Implementazione / Affiancamento', 'default_activity_type' => 'onsite', 'share_of_total' => 0.22],
            ['phase_key' => 'internal_audit', 'label' => 'Audit interno', 'default_activity_type' => 'onsite', 'share_of_total' => 0.10],
            ['phase_key' => 'management_review', 'label' => 'Riesame di direzione', 'default_activity_type' => 'call', 'share_of_total' => 0.06],
            ['phase_key' => 'cert_support', 'label' => 'Supporto verifica / certificazione', 'default_activity_type' => 'onsite', 'share_of_total' => 0.10],
        ];
        $defaultPhasesJson = !empty($cols['default_phases_json'])
            ? (json_encode($defaultPhases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null)
            : null;

        // Seed list (atomic services for planning 2026; editable later from catalog UI)
        $services = [
            // ISO / MSS / standards (atomic)
            ['service_code' => 'ISO9001', 'name' => 'ISO 9001', 'category' => 'ISO', 'scheme_type' => 'MSS', 'aliases' => ['9001', 'SGQ', 'qualità'], 'min' => 2, 'max' => 4, 'complexity' => 3, 'standard_codes' => ['ISO9001']],
            ['service_code' => 'ISO14001', 'name' => 'ISO 14001', 'category' => 'ISO', 'scheme_type' => 'MSS', 'aliases' => ['14001', 'ambiente', 'EMS'], 'min' => 2, 'max' => 5, 'complexity' => 3, 'standard_codes' => ['ISO14001']],
            ['service_code' => 'EMAS', 'name' => 'EMAS', 'category' => 'Regulatory', 'scheme_type' => 'REGULATORY', 'aliases' => ['EMAS'], 'min' => 1, 'max' => 2, 'complexity' => 3, 'standard_codes' => ['EMAS']],
            ['service_code' => 'ISO45001', 'name' => 'ISO 45001', 'category' => 'Safety', 'scheme_type' => 'MSS', 'aliases' => ['45001', 'sicurezza', 'OHS'], 'min' => 3, 'max' => 6, 'complexity' => 4, 'standard_codes' => ['ISO45001']],
            ['service_code' => 'ISO50001', 'name' => 'ISO 50001', 'category' => 'ISO', 'scheme_type' => 'MSS', 'aliases' => ['50001', 'energia'], 'min' => 3, 'max' => 6, 'complexity' => 4, 'standard_codes' => ['ISO50001']],
            ['service_code' => 'SA8000', 'name' => 'SA8000', 'category' => 'Social', 'scheme_type' => 'MSS', 'aliases' => ['SA8000', 'responsabilità sociale'], 'min' => 3, 'max' => 6, 'complexity' => 4, 'standard_codes' => ['SA8000']],
            ['service_code' => 'ISO37001', 'name' => 'ISO 37001', 'category' => 'Compliance', 'scheme_type' => 'MSS', 'aliases' => ['37001', 'anticorruzione'], 'min' => 2, 'max' => 4, 'complexity' => 3, 'standard_codes' => ['ISO37001']],
            ['service_code' => 'PDR125', 'name' => 'UNI/PdR 125', 'category' => 'Social', 'scheme_type' => 'MSS', 'aliases' => ['PdR125', 'parità di genere'], 'min' => 2, 'max' => 4, 'complexity' => 3, 'standard_codes' => ['PDR125']],

            // LAB
            ['service_code' => 'ISOIEC17025', 'name' => 'ISO/IEC 17025', 'category' => 'Accreditamenti', 'scheme_type' => 'LAB', 'aliases' => ['17025', 'laboratorio'], 'min' => 5, 'max' => 14, 'complexity' => 5, 'standard_codes' => ['ISOIEC17025']],

            // Regulatory / generic norms
            ['service_code' => 'UNI16636', 'name' => 'UNI 16636', 'category' => 'Regulatory', 'scheme_type' => 'REGULATORY', 'aliases' => ['16636', 'pest control'], 'min' => 2, 'max' => 4, 'complexity' => 3, 'standard_codes' => ['UNI16636']],
            ['service_code' => 'NORMA10891', 'name' => 'Norma 10891', 'category' => 'Norme', 'scheme_type' => 'GENERIC_NORM', 'aliases' => ['10891'], 'min' => 1, 'max' => 3, 'complexity' => 2, 'standard_codes' => ['10891']],
            ['service_code' => 'NORMA13895', 'name' => 'Norma 13895', 'category' => 'Norme', 'scheme_type' => 'GENERIC_NORM', 'aliases' => ['13895'], 'min' => 1, 'max' => 3, 'complexity' => 2, 'standard_codes' => ['13895']],
            ['service_code' => 'RT12', 'name' => 'RT 12', 'category' => 'Regulatory', 'scheme_type' => 'REGULATORY', 'aliases' => ['RT12', 'RT 12'], 'min' => 1, 'max' => 3, 'complexity' => 2, 'standard_codes' => []],
            ['service_code' => 'CE', 'name' => 'Marcatura CE', 'category' => 'Regulatory', 'scheme_type' => 'REGULATORY', 'aliases' => ['CE', 'marcatura CE', 'marchiatura CE'], 'min' => 4, 'max' => 16, 'complexity' => 5, 'standard_codes' => []],
            ['service_code' => 'ACCRED', 'name' => 'ACCRED (accreditamento istituzionale)', 'category' => 'Accreditamenti', 'scheme_type' => 'REGULATORY', 'aliases' => ['ACCRED', 'ACCREDIA', 'accreditamento istituzionale'], 'min' => 1, 'max' => 2, 'complexity' => 5, 'standard_codes' => []],
            ['service_code' => 'AUTORIZZ', 'name' => 'Autorizzazioni', 'category' => 'Regulatory', 'scheme_type' => 'REGULATORY', 'aliases' => ['autorizzazioni'], 'min' => 1, 'max' => 2, 'complexity' => 3, 'standard_codes' => []],

            // Safety services
            ['service_code' => 'RSPP', 'name' => 'RSPP', 'category' => 'Safety', 'scheme_type' => 'SAFETY_SERVICE', 'aliases' => ['RSPP'], 'min' => 1, 'max' => 2, 'complexity' => 2, 'standard_codes' => []],

            // Food / FSMS / GFSI
            ['service_code' => 'ISO22000', 'name' => 'ISO 22000', 'category' => 'Food', 'scheme_type' => 'FSMS', 'aliases' => ['22000', 'FSMS'], 'min' => 4, 'max' => 7, 'complexity' => 5, 'standard_codes' => ['ISO22000']],
            ['service_code' => 'HACCP', 'name' => 'HACCP', 'category' => 'Food', 'scheme_type' => 'FSMS', 'aliases' => ['HACCP'], 'min' => 2, 'max' => 4, 'complexity' => 4, 'standard_codes' => []],
            ['service_code' => 'BRC', 'name' => 'BRC', 'category' => 'Food', 'scheme_type' => 'GFSI', 'aliases' => ['BRC', 'BRCGS'], 'min' => 5, 'max' => 9, 'complexity' => 5, 'standard_codes' => ['BRC']],
            ['service_code' => 'IFS', 'name' => 'IFS', 'category' => 'Food', 'scheme_type' => 'GFSI', 'aliases' => ['IFS'], 'min' => 5, 'max' => 9, 'complexity' => 5, 'standard_codes' => ['IFS']],
            ['service_code' => 'BRCBROKERS', 'name' => 'BRC Brokers', 'category' => 'Food', 'scheme_type' => 'GFSI', 'aliases' => ['BRC Brokers', 'Agents & Brokers'], 'min' => 2, 'max' => 5, 'complexity' => 4, 'standard_codes' => ['BRCBROKERS']],
            ['service_code' => 'BIO', 'name' => 'BIO', 'category' => 'Food', 'scheme_type' => 'REGULATORY', 'aliases' => ['BIO'], 'min' => 1, 'max' => 3, 'complexity' => 3, 'standard_codes' => ['BIO']],

            // Privacy / 231 / Pharma
            ['service_code' => 'GDP', 'name' => 'GDP', 'category' => 'Pharma', 'scheme_type' => 'REGULATORY', 'aliases' => ['GDP', 'Good Distribution Practice'], 'min' => 2, 'max' => 6, 'complexity' => 4, 'standard_codes' => []],
            ['service_code' => 'PRIVACY', 'name' => 'Privacy / DPO', 'category' => 'Privacy', 'scheme_type' => 'PRIVACY', 'aliases' => ['GDPR', 'Privacy', 'DPO'], 'min' => 1, 'max' => 4, 'complexity' => 4, 'standard_codes' => []],
            ['service_code' => 'ODV231', 'name' => 'ODV / 231', 'category' => '231', 'scheme_type' => '231', 'aliases' => ['231', 'ODV', 'organismo di vigilanza'], 'min' => 3, 'max' => 7, 'complexity' => 4, 'standard_codes' => []],
        ];

        $inserted = 0;
        $updated = 0;

        foreach ($services as $s) {
            $name = trim((string)($s['name'] ?? ''));
            if ($name === '') continue;
            $serviceCode = !empty($cols['service_code']) ? cnx_consulting_normalize_service_code((string)($s['service_code'] ?? '')) : null;

            // Find existing row (prefer service_code, fallback by name)
            $existing = null;
            if ($serviceCode) {
                $existing = $db->fetchOne(
                    "SELECT id FROM consulting_activity_types
                     WHERE tenant_id = ?
                       AND deleted_at IS NULL
                       AND service_code = ?
                     LIMIT 1",
                    [CNX_VENDOR_TENANT_ID, $serviceCode]
                );
            }
            if (!$existing) {
                $existing = $db->fetchOne(
                    "SELECT id FROM consulting_activity_types
                     WHERE tenant_id = ?
                       AND deleted_at IS NULL
                       AND name = ?
                     LIMIT 1",
                    [CNX_VENDOR_TENANT_ID, $name]
                );
            }

            $cx = isset($s['complexity']) ? (int)$s['complexity'] : null;
            if ($cx !== null && ($cx < 1 || $cx > 5)) $cx = null;

            $payloadRow = [
                'tenant_id' => CNX_VENDOR_TENANT_ID,
                'name' => $name,
                'weight_factor' => cnx_consulting_weight_factor_from_complexity($cx),
                'call_every_days_default' => cnx_consulting_call_every_days_from_complexity($cx),
                'call_duration_minutes_default' => 30,
                'is_active' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if (!empty($cols['service_code'])) $payloadRow['service_code'] = $serviceCode;
            if (!empty($cols['category'])) $payloadRow['category'] = (string)($s['category'] ?? '');
            if (!empty($cols['scheme_type'])) {
                $st = trim((string)($s['scheme_type'] ?? ''));
                $payloadRow['scheme_type'] = ($st === '') ? null : (strlen($st) > 30 ? substr($st, 0, 30) : $st);
            }
            if (!empty($cols['aliases_json'])) {
                $payloadRow['aliases_json'] = json_encode((array)($s['aliases'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (!empty($cols['standard_codes_json'])) {
                $std = cnx_consulting_normalize_standard_codes($s['standard_codes'] ?? []);
                $payloadRow['standard_codes_json'] = !empty($std)
                    ? (json_encode($std, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null)
                    : null;
            }
            if (!empty($cols['is_legacy_composite'])) $payloadRow['is_legacy_composite'] = 0;
            if (!empty($cols['legacy_combo'])) $payloadRow['legacy_combo'] = 0;
            if (!empty($cols['base_days_min'])) $payloadRow['base_days_min'] = isset($s['min']) ? (int)$s['min'] : null;
            if (!empty($cols['base_days_max'])) $payloadRow['base_days_max'] = isset($s['max']) ? (int)$s['max'] : null;
            if (!empty($cols['complexity_score'])) $payloadRow['complexity_score'] = isset($s['complexity']) ? (int)$s['complexity'] : null;
            if (!empty($cols['default_phases_json'])) $payloadRow['default_phases_json'] = $defaultPhasesJson;
            if (!empty($cols['default_deliverables_json'])) $payloadRow['default_deliverables_json'] = json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Optional pricing columns (migration 39)
            if (!empty($cols['default_day_rate'])) $payloadRow['default_day_rate'] = 360.0;
            if (!empty($cols['default_fixed_amount'])) $payloadRow['default_fixed_amount'] = 0.0;

            if ($existing && isset($existing['id'])) {
                $ok = $db->update('consulting_activity_types', $payloadRow, ['id' => (int)$existing['id']]);
                if ($ok) $updated++;
                continue;
            }

            $payloadRow['created_at'] = date('Y-m-d H:i:s');
            $id = $db->insert('consulting_activity_types', $payloadRow);
            if ($id) $inserted++;
        }

        // Mark legacy/composite (underscore or detected combo) services as inactive (keep for historical plans)
        if (!empty($cols['service_code'])) {
            try {
                $now = date('Y-m-d H:i:s');

                // 1) Underscore combos (fast SQL)
                if (!empty($cols['is_legacy_composite']) || !empty($cols['legacy_combo'])) {
                    $sets = [];
                    if (!empty($cols['is_legacy_composite'])) $sets[] = "is_legacy_composite = 1";
                    if (!empty($cols['legacy_combo'])) $sets[] = "legacy_combo = 1";
                    $sets[] = "is_active = 0";
                    $sets[] = "updated_at = ?";
                    $db->query(
                        "UPDATE consulting_activity_types
                         SET " . implode(", ", $sets) . "
                         WHERE tenant_id = ?
                           AND deleted_at IS NULL
                           AND service_code IS NOT NULL
                           AND service_code LIKE '%\\_%' ESCAPE '\\\\'",
                        [$now, CNX_VENDOR_TENANT_ID]
                    );
                } else {
                    $db->query(
                        "UPDATE consulting_activity_types
                         SET is_active = 0,
                             updated_at = ?
                         WHERE tenant_id = ?
                           AND deleted_at IS NULL
                           AND service_code IS NOT NULL
                           AND service_code LIKE '%\\_%' ESCAPE '\\\\'",
                        [$now, CNX_VENDOR_TENANT_ID]
                    );
                }

                // 2) Deprecated non-underscore duplicates (best-effort, explicit list)
                $deprecated = ['ACCREDIA'];
                foreach ($deprecated as $dc) {
                    $dc = strtoupper(trim((string)$dc));
                    if ($dc === '') continue;
                    if (!empty($cols['is_legacy_composite']) || !empty($cols['legacy_combo'])) {
                        $sets = [];
                        if (!empty($cols['is_legacy_composite'])) $sets[] = "is_legacy_composite = 1";
                        if (!empty($cols['legacy_combo'])) $sets[] = "legacy_combo = 1";
                        $sets[] = "is_active = 0";
                        $sets[] = "updated_at = ?";
                        $db->query(
                            "UPDATE consulting_activity_types
                             SET " . implode(", ", $sets) . "
                             WHERE tenant_id = ?
                               AND deleted_at IS NULL
                               AND service_code = ?",
                            [$now, CNX_VENDOR_TENANT_ID, $dc]
                        );
                    } else {
                        $db->query(
                            "UPDATE consulting_activity_types
                             SET is_active = 0,
                                 updated_at = ?
                             WHERE tenant_id = ?
                               AND deleted_at IS NULL
                               AND service_code = ?",
                            [$now, CNX_VENDOR_TENANT_ID, $dc]
                        );
                    }
                }

                // 3) Best-effort: detect composite codes without underscores (e.g. "ISO9001HACCP") and mark legacy/inactive.
                // We keep them for historical plans, but they should not be selectable as "atomic" services.
                try {
                    $rows = $db->fetchAll(
                        "SELECT id, service_code
                         FROM consulting_activity_types
                         WHERE tenant_id = ?
                           AND deleted_at IS NULL
                           AND is_active = 1
                           AND service_code IS NOT NULL",
                        [CNX_VENDOR_TENANT_ID]
                    ) ?: [];
                    foreach ($rows as $r) {
                        $rid = (int)($r['id'] ?? 0);
                        if ($rid <= 0) continue;
                        $sc = strtoupper(trim((string)($r['service_code'] ?? '')));
                        if ($sc === '') continue;
                        if (strpos($sc, '_') !== false) continue; // already handled above
                        if (in_array($sc, $deprecated, true)) continue; // already handled above
                        if (!cnx_consulting_is_composite_service_code($sc)) continue;

                        if (!empty($cols['is_legacy_composite']) || !empty($cols['legacy_combo'])) {
                            $upd = [
                                'is_active' => 0,
                                'updated_at' => $now,
                            ];
                            if (!empty($cols['is_legacy_composite'])) $upd['is_legacy_composite'] = 1;
                            if (!empty($cols['legacy_combo'])) $upd['legacy_combo'] = 1;
                            $db->update('consulting_activity_types', $upd, ['id' => $rid]);
                        } else {
                            $db->update('consulting_activity_types', [
                                'is_active' => 0,
                                'updated_at' => $now,
                            ], ['id' => $rid]);
                        }
                    }
                } catch (Throwable $e) {
                    // non-blocking
                }
            } catch (Throwable $e) {
                // non-blocking
            }
        }

        api_success(
            ['inserted' => $inserted, 'updated' => $updated],
            ($action === 'seed_base') ? 'Catalogo base inizializzato' : 'Catalogo servizi inizializzato'
        );
    }

    if ($action === 'set_override') {
        $clientTenantId = (int)($payload['client_tenant_id'] ?? 0);
        $typeId = (int)($payload['activity_type_id'] ?? 0);
        if ($clientTenantId <= 0) api_error('client_tenant_id obbligatorio', 400);
        if ($typeId <= 0) api_error('activity_type_id obbligatorio', 400);
        if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato all’azienda cliente', 403);

        $exists = $db->fetchOne(
            "SELECT 1 FROM consulting_activity_types WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$typeId, CNX_VENDOR_TENANT_ID]
        );
        if (!$exists) api_error('Attività non trovata', 404);

        $ov = [
            'client_tenant_id' => $clientTenantId,
            'activity_type_id' => $typeId,
            'weight_factor_override' => array_key_exists('weight_factor_override', $payload) ? $payload['weight_factor_override'] : null,
            'call_every_days_override' => array_key_exists('call_every_days_override', $payload) ? $payload['call_every_days_override'] : null,
            'call_duration_minutes_override' => array_key_exists('call_duration_minutes_override', $payload) ? $payload['call_duration_minutes_override'] : null,
            'is_active_override' => array_key_exists('is_active_override', $payload) ? $payload['is_active_override'] : null,
            'notes' => isset($payload['notes']) ? trim((string)$payload['notes']) : null,
            'deleted_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $existingOv = $db->fetchOne(
            "SELECT id FROM consulting_activity_type_overrides
             WHERE client_tenant_id = ? AND activity_type_id = ? AND deleted_at IS NULL",
            [$clientTenantId, $typeId]
        );
        if ($existingOv) {
            $ok = $db->update('consulting_activity_type_overrides', $ov, ['id' => (int)$existingOv['id']]);
            if (!$ok) api_error('Aggiornamento override fallito', 500);
            api_success(['id' => (int)$existingOv['id']], 'Override aggiornato');
        }

        $ov['created_at'] = date('Y-m-d H:i:s');
        $id = $db->insert('consulting_activity_type_overrides', $ov);
        if (!$id) api_error('Creazione override fallita', 500);
        api_success(['id' => (int)$id], 'Override creato');
    }

    if ($action === 'delete_override') {
        $clientTenantId = (int)($payload['client_tenant_id'] ?? 0);
        $typeId = (int)($payload['activity_type_id'] ?? 0);
        if ($clientTenantId <= 0) api_error('client_tenant_id obbligatorio', 400);
        if ($typeId <= 0) api_error('activity_type_id obbligatorio', 400);
        if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato all’azienda cliente', 403);

        $existingOv = $db->fetchOne(
            "SELECT id FROM consulting_activity_type_overrides
             WHERE client_tenant_id = ? AND activity_type_id = ? AND deleted_at IS NULL",
            [$clientTenantId, $typeId]
        );
        if (!$existingOv) api_error('Override non trovato', 404);

        $ok = $db->update('consulting_activity_type_overrides', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => (int)$existingOv['id']]);
        if (!$ok) api_error('Eliminazione override fallita', 500);
        api_success(['id' => (int)$existingOv['id']], 'Override eliminato');
    }

    api_error('Azione non supportata', 400);
} catch (Exception $e) {
    error_log('[CONSULTING_ACTIVITY_TYPES] ' . $e->getMessage());
    api_error('Errore gestione catalogo attività', 500);
}


