<?php
// Consulting Planning: estimate days per service (Tenant 28 internal tools)
// Hybrid: deterministic baseline + best-effort AI for company profile/rationale (no ISO/UNI text).
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/openai_client.php';

verifyApiCsrfToken();

/**
 * @return array<string,bool>
 */
function cnx_consulting_activity_types_cols(Database $db): array {
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

/**
 * Load scheduling phase library (best-effort).
 * Used to keep wizard preview coherent with server workplans.
 *
 * @return array<string,array<string,mixed>>
 */
function cnx_sched_phase_library(): array {
    $path = __DIR__ . '/../../configs/scheduling/phase_library.php';
    if (is_file($path)) {
        $v = require $path;
        if (is_array($v)) return $v;
    }
    return [];
}

/**
 * Load service workplans (best-effort).
 *
 * @return array<string,mixed>
 */
function cnx_sched_service_workplans(): array {
    $path = __DIR__ . '/../../configs/scheduling/service_workplans.php';
    if (is_file($path)) {
        $v = require $path;
        if (is_array($v)) return $v;
    }
    return [];
}

function cnx_sched_intervention_key(?string $interventionType): string {
    $t = strtolower(trim((string)$interventionType));
    if ($t === 'maintenance') return 'MAINT';
    if ($t === 'recertification') return 'RECERT';
    if ($t === 'scope_extension') return 'SCOPE_EXT';
    if ($t === 'transition_update' || $t === 'transition') return 'TRANSITION';
    return 'NEW';
}

function cnx_sched_mode_to_activity_type(string $mode): string {
    $m = strtolower(trim($mode));
    if (in_array($m, ['call', 'communication'], true)) return $m;
    if ($m === 'onsite') return 'onsite';
    if ($m === 'travel') return 'travel';
    // "other" is treated as remote in preview (day-based)
    return 'remote';
}

/**
 * Build a phases list coherent with items generation (workplans when available).
 *
 * @param array<string,mixed> $svcRow consulting_activity_types row
 * @param array<string,mixed> $phaseLib
 * @param array<string,mixed> $workplans
 * @return array<int,array<string,mixed>>
 */
function cnx_sched_build_preview_phases_for_service(array $svcRow, string $serviceCode, string $interventionKey, array $phaseLib, array $workplans): array {
    $code = strtoupper(trim($serviceCode));
    $out = [];

    // Prefer workplans config (same used by items_generate_from_estimate.php)
    $tryKeys = [];
    if ($code !== '') {
        $tryKeys[] = $code;
        $tryKeys[] = strtolower($code);
    }
    foreach ($tryKeys as $k) {
        if (!isset($workplans[$k]) || !is_array($workplans[$k])) continue;
        foreach ($workplans[$k] as $t) {
            if (!is_array($t)) continue;
            $label = trim((string)($t['title'] ?? ''));
            if ($label === '') continue;

            $phaseKey = trim((string)($t['phase_key'] ?? 'ongoing'));
            if ($phaseKey === '') $phaseKey = 'ongoing';
            $phaseKeyLow = strtolower($phaseKey);

            $phaseOrder = (int)($t['phase_order'] ?? 0);
            if ($phaseOrder <= 0) {
                $m = $phaseLib[$phaseKey] ?? $phaseLib[$phaseKeyLow] ?? null;
                if (is_array($m) && isset($m['order'])) $phaseOrder = (int)$m['order'];
            }
            if ($phaseOrder <= 0) $phaseOrder = 99;

            $act = cnx_sched_mode_to_activity_type((string)($t['default_mode'] ?? 'remote'));

            $shares = $t['share_per_intervention_type'] ?? null;
            $share = 0.0;
            if (is_array($shares)) {
                $share = (float)($shares[$interventionKey] ?? $shares['NEW'] ?? 0.0);
                if (!is_finite($share) || $share < 0) $share = 0.0;
            }

            $out[] = [
                'phase_key' => $phaseKeyLow,
                'phase_order' => $phaseOrder,
                'label' => $label,
                'default_activity_type' => $act,
                'share_of_total' => $share,
            ];
        }
        if (!empty($out)) break;
    }
    if (!empty($out)) {
        usort($out, static fn($a, $b) => ((int)($a['phase_order'] ?? 99)) <=> ((int)($b['phase_order'] ?? 99)));
        return $out;
    }

    // Fallback: default phases stored on activity type (if available)
    $raw = $svcRow['default_phases_json'] ?? null;
    if (is_string($raw) && trim($raw) !== '') {
        $arr = json_decode($raw, true);
        if (is_array($arr)) {
            foreach ($arr as $p) {
                if (!is_array($p)) continue;
                $label = trim((string)($p['label'] ?? ''));
                if ($label === '') continue;
                $phaseKey = trim((string)($p['phase_key'] ?? 'phase'));
                if ($phaseKey === '') $phaseKey = 'phase';
                $phaseKeyLow = strtolower($phaseKey);
                $act = strtolower(trim((string)($p['default_activity_type'] ?? 'remote')));
                if (!in_array($act, ['onsite','remote','call','communication','travel'], true)) $act = 'remote';
                $share = (float)($p['share_of_total'] ?? 0.0);
                if (!is_finite($share) || $share < 0) $share = 0.0;
                $phaseOrder = (int)($p['phase_order'] ?? 0);
                if ($phaseOrder <= 0) {
                    $m = $phaseLib[$phaseKey] ?? $phaseLib[$phaseKeyLow] ?? null;
                    if (is_array($m) && isset($m['order'])) $phaseOrder = (int)$m['order'];
                }
                if ($phaseOrder <= 0) $phaseOrder = 99;
                $out[] = [
                    'phase_key' => $phaseKeyLow,
                    'phase_order' => $phaseOrder,
                    'label' => $label,
                    'default_activity_type' => $act,
                    'share_of_total' => $share,
                ];
            }
        }
    }
    if (!empty($out)) {
        usort($out, static fn($a, $b) => ((int)($a['phase_order'] ?? 99)) <=> ((int)($b['phase_order'] ?? 99)));
        return $out;
    }

    // Last resort: generic template (no ISO/UNI text)
    return [
        ['phase_key' => 'kickoff', 'phase_order' => 10, 'label' => 'Kickoff / Pianificazione', 'default_activity_type' => 'call', 'share_of_total' => 0.08],
        ['phase_key' => 'context_scope', 'phase_order' => 20, 'label' => 'Analisi contesto / Scopo', 'default_activity_type' => 'remote', 'share_of_total' => 0.20],
        ['phase_key' => 'documentation', 'phase_order' => 50, 'label' => 'Documentazione (best-effort)', 'default_activity_type' => 'remote', 'share_of_total' => 0.25],
        ['phase_key' => 'implementation', 'phase_order' => 60, 'label' => 'Implementazione / Affiancamento', 'default_activity_type' => 'onsite', 'share_of_total' => 0.20],
        ['phase_key' => 'internal_audit', 'phase_order' => 80, 'label' => 'Audit interno', 'default_activity_type' => 'onsite', 'share_of_total' => 0.15],
        ['phase_key' => 'external_audit_support', 'phase_order' => 90, 'label' => 'Supporto audit esterno / certificazione', 'default_activity_type' => 'onsite', 'share_of_total' => 0.12],
    ];
}

function cnx_consulting_is_private_ip(string $ip): bool {
    if ($ip === '' || $ip === '0.0.0.0') return true;
    if (strpos($ip, ':') !== false) {
        // very conservative for IPv6 in this codebase: block localhost + ULA
        $lower = strtolower($ip);
        if ($lower === '::1') return true;
        if (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) return true;
        return false;
    }
    $parts = explode('.', $ip);
    if (count($parts) !== 4) return true;
    $a = (int)$parts[0];
    $b = (int)$parts[1];
    if ($a === 10) return true;
    if ($a === 127) return true;
    if ($a === 192 && $b === 168) return true;
    if ($a === 172 && $b >= 16 && $b <= 31) return true;
    return false;
}

/**
 * Very small best-effort fetch for website text. SSRF-guarded and size-limited.
 *
 * @return array{ok:bool,url:string,text:string,error?:string}
 */
function cnx_consulting_fetch_url_text(string $url, int $timeoutSeconds = 5, int $maxBytes = 60000): array {
    $url = trim($url);
    if ($url === '') return ['ok' => false, 'url' => $url, 'text' => '', 'error' => 'empty_url'];
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $parts = @parse_url($url);
    if (!is_array($parts)) return ['ok' => false, 'url' => $url, 'text' => '', 'error' => 'invalid_url'];
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = (string)($parts['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return ['ok' => false, 'url' => $url, 'text' => '', 'error' => 'unsupported_url'];
    }

    // SSRF guard: block obvious private ranges (best-effort)
    $resolved = gethostbyname($host);
    if ($resolved === $host || cnx_consulting_is_private_ip((string)$resolved)) {
        return ['ok' => false, 'url' => $url, 'text' => '', 'error' => 'blocked_host'];
    }

    $ch = curl_init($url);
    if (!$ch) return ['ok' => false, 'url' => $url, 'text' => '', 'error' => 'curl_init_failed'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_USERAGENT => 'CollaboraNexio/1.0 (Planning Estimator)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
    ]);
    $html = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($html === false || $html === null) {
        return ['ok' => false, 'url' => $url, 'text' => '', 'error' => $err ?: 'fetch_failed'];
    }
    $html = (string)$html;
    if ($maxBytes > 0 && strlen($html) > $maxBytes) {
        $html = substr($html, 0, $maxBytes);
    }
    // Strip tags + normalize whitespace
    $text = strip_tags($html);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    if (strlen($text) > 2500) $text = substr($text, 0, 2500);
    return ['ok' => true, 'url' => $url, 'text' => $text];
}

/**
 * @return array{min:int,max:int,complexity:int,used_fallback:bool}
 */
function cnx_consulting_fallback_range(string $nameOrCode): array {
    $k = strtoupper(trim($nameOrCode));
    $k = str_replace([' ', '/', '\\'], '_', $k);
    // Conservative defaults (planning 2026 baseline) — scaled down (≈20% of previous)
    $min = 2; $max = 4; $cx = 3;

    if (str_contains($k, 'ISOIEC17025') || str_contains($k, '17025')) {
        $min = 5; $max = 14; $cx = 5;
    } elseif (str_contains($k, 'BRC') || str_contains($k, 'IFS')) {
        $min = 5; $max = 9; $cx = 5;
    } elseif (str_contains($k, 'CE')) {
        $min = 4; $max = 16; $cx = 5;
    } elseif (str_contains($k, 'ACCRED')) {
        $min = 1; $max = 2; $cx = 5;
    } elseif (str_contains($k, 'AUTORIZZ')) {
        $min = 1; $max = 2; $cx = 3;
    } elseif (str_contains($k, 'ISO22000') || str_contains($k, '22000')) {
        $min = 4; $max = 7; $cx = 5;
    } elseif (str_contains($k, 'HACCP')) {
        $min = 2; $max = 4; $cx = 4;
    } elseif (str_contains($k, 'ISO45001') || str_contains($k, '45001')) {
        $min = 3; $max = 6; $cx = 4;
    } elseif (str_contains($k, 'ISO9001') || str_contains($k, '9001')) {
        $min = 2; $max = 4; $cx = 3;
    } elseif (str_contains($k, 'ISO14001') || str_contains($k, '14001')) {
        $min = 2; $max = 5; $cx = 3;
    } elseif (str_contains($k, 'ISO50001') || str_contains($k, '50001')) {
        $min = 3; $max = 6; $cx = 4;
    } elseif (str_contains($k, 'EMAS')) {
        $min = 1; $max = 2; $cx = 3;
    } elseif (str_contains($k, 'GDP')) {
        $min = 2; $max = 6; $cx = 4;
    } elseif (str_contains($k, 'PRIVACY') || str_contains($k, 'DPO') || str_contains($k, 'GDPR')) {
        $min = 1; $max = 4; $cx = 4;
    } elseif (str_contains($k, 'ODV231') || str_contains($k, 'ODV') || str_contains($k, '231')) {
        $min = 3; $max = 7; $cx = 4;
    } elseif (str_contains($k, 'RSPP')) {
        $min = 1; $max = 2; $cx = 2;
    } elseif (str_contains($k, 'UNI16636') || str_contains($k, '16636')) {
        $min = 2; $max = 4; $cx = 3;
    } elseif (str_contains($k, 'RT12') || str_contains($k, 'BIO') || str_contains($k, '10891') || str_contains($k, '13895')) {
        $min = 1; $max = 3; $cx = 2;
    }

    return ['min' => $min, 'max' => $max, 'complexity' => $cx, 'used_fallback' => true];
}

function cnx_consulting_org_multiplier(?string $employeesRange, ?int $sitesCount, ?string $sector, ?bool $regulated): array {
    $mult = 1.0;
    $reasons = [];

    $er = $employeesRange ? trim($employeesRange) : '';
    if ($er !== '') {
        $low = strtolower($er);
        $m = 1.0;
        if (preg_match('/\b(1|0)\s*[-–]\s*10\b/', $low)) $m = 1.0;
        elseif (preg_match('/\b11\s*[-–]\s*50\b/', $low)) $m = 1.10;
        elseif (preg_match('/\b51\s*[-–]\s*200\b/', $low)) $m = 1.25;
        elseif (preg_match('/\b201\s*[-–]\s*500\b/', $low)) $m = 1.40;
        elseif (preg_match('/\b(500\+|501|1000)\b/', $low)) $m = 1.60;
        $mult *= $m;
        if ($m !== 1.0) $reasons[] = 'Dimensione organizzazione (dipendenti)';
    }

    $sc = $sitesCount ?? null;
    if ($sc !== null && $sc > 1) {
        $add = min(0.30, 0.05 * max(0, $sc - 1));
        $mult *= (1.0 + $add);
        $reasons[] = 'Numero sedi/siti';
    }

    if ($regulated === true) {
        $mult *= 1.15;
        $reasons[] = 'Settore regolamentato';
    }

    $sec = $sector ? strtolower(trim($sector)) : '';
    if ($sec !== '') {
        if (str_contains($sec, 'san') || str_contains($sec, 'osped') || str_contains($sec, 'pharma') || str_contains($sec, 'farm')) {
            $mult *= 1.10;
            $reasons[] = 'Settore (sanità/pharma)';
        } elseif (str_contains($sec, 'food') || str_contains($sec, 'aliment')) {
            $mult *= 1.08;
            $reasons[] = 'Settore (alimentare)';
        }
    }

    // clamp
    if ($mult < 0.90) $mult = 0.90;
    if ($mult > 2.00) $mult = 2.00;

    $reasons = array_values(array_unique($reasons));
    return ['multiplier' => $mult, 'reasons' => $reasons];
}

function cnx_consulting_complexity_factor(?int $score): float {
    $s = (int)($score ?? 0);
    switch ($s) {
        case 1: return 0.90;
        case 2: return 1.00;
        case 3: return 1.10;
        case 4: return 1.22;
        case 5: return 1.35;
        default: return 1.10;
    }
}

/**
 * Confidence score for the inferred company profile (0..1).
 * This is a UX-oriented heuristic: it reflects completeness of key inputs,
 * not a statistical guarantee.
 *
 * Core fields (for "stima affidabile"): employees_range, sites_count, intervention_type, qms_maturity.
 *
 * @param array<int,string> $missingCoreFields
 */
function cnx_consulting_company_profile_confidence(array $missingCoreFields, ?string $notes, ?string $website, string $websiteText): float {
    $coreTotal = 4;
    $present = $coreTotal - count($missingCoreFields);
    if ($present < 0) $present = 0;
    if ($present > $coreTotal) $present = $coreTotal;

    // Base heuristic:
    // - 0 core fields => 0.35 (rough)
    // - 4 core fields => 0.90 (high confidence)
    $c = 0.35 + (0.1375 * (float)$present);

    $notes = is_string($notes) ? trim($notes) : '';
    if ($notes !== '' && strlen($notes) >= 20) {
        $c += 0.03;
    }
    $website = is_string($website) ? trim($website) : '';
    if ($website !== '') {
        $c += 0.02;
    }
    if (trim($websiteText) !== '') {
        $c += 0.05;
    }

    if ($c > 0.95) $c = 0.95;
    if ($c < 0.20) $c = 0.20;
    return $c;
}

try {
    // Require migration 35 table (schema drift safe)
    $hasTypes = $db->fetchOne("SHOW TABLES LIKE 'consulting_activity_types'");
    if (!$hasTypes) {
        api_error(
            'Modulo catalogo servizi non inizializzato: applica la migrazione database 35 (activity catalog)',
            503,
            ['migration' => 'database/migrations/35_consulting_activity_catalog_and_schedule.sql']
        );
    }

    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) api_error('Dati non validi', 400);

    $clientTenantId = (int)($payload['client_tenant_id'] ?? 0);
    if ($clientTenantId <= 0 || $clientTenantId === CNX_VENDOR_TENANT_ID) api_error('Azienda cliente non valida', 400);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato all’azienda cliente', 403);

    $idsRaw = $payload['service_type_ids'] ?? [];
    if (!is_array($idsRaw) || empty($idsRaw)) api_error('service_type_ids obbligatorio', 400);
    $serviceTypeIds = array_values(array_unique(array_filter(array_map(static fn($v) => (int)$v, $idsRaw), static fn($v) => $v > 0)));
    if (empty($serviceTypeIds)) api_error('service_type_ids non valido', 400);
    if (count($serviceTypeIds) > 20) api_error('Troppi servizi selezionati', 400);

    $companyProfile = isset($payload['company_profile']) && is_array($payload['company_profile']) ? $payload['company_profile'] : [];
    $docProfileId = (int)($payload['doc_profile_id'] ?? 0);
    if ($docProfileId < 0) $docProfileId = 0;
    $sector = trim((string)($companyProfile['sector'] ?? ''));
    $employeesRange = trim((string)($companyProfile['employees_range'] ?? ''));
    $sitesCountRaw = $companyProfile['sites_count'] ?? null;
    $sitesCount = ($sitesCountRaw === null || $sitesCountRaw === '') ? null : (int)$sitesCountRaw;
    if ($sitesCount !== null && $sitesCount < 0) $sitesCount = null;
    $regulatedRaw = $companyProfile['regulated'] ?? null;
    $regulated = ($regulatedRaw === null || $regulatedRaw === '') ? null : (bool)$regulatedRaw;
    $notes = trim((string)($companyProfile['notes'] ?? ''));
    $website = trim((string)($companyProfile['website'] ?? ''));

    // Advanced inputs (best-effort parsing; nullable)
    $useAiEnrichmentRaw = $companyProfile['use_ai_enrichment'] ?? null;
    $useAiEnrichment = ($useAiEnrichmentRaw === true || $useAiEnrichmentRaw === 1 || $useAiEnrichmentRaw === '1' || $useAiEnrichmentRaw === 'true');

    $interventionType = strtolower(trim((string)($companyProfile['intervention_type'] ?? '')));
    // Back-compat: old UI value
    if ($interventionType === 'transition') $interventionType = 'transition_update';
    $allowedIntervention = ['new_implementation','maintenance','recertification','scope_extension','transition_update'];
    if (!in_array($interventionType, $allowedIntervention, true)) $interventionType = '';
    // Planning 2026: intervention type is mandatory (it drives phases/tasks, not only estimation)
    if ($interventionType === '') {
        api_error('Tipo intervento obbligatorio', 400, ['field' => 'intervention_type']);
    }

    $qmsMaturity = strtolower(trim((string)($companyProfile['qms_maturity'] ?? '')));
    // Back-compat: old UI value
    if ($qmsMaturity === 'partial') $qmsMaturity = 'partial_informal';
    $allowedMaturity = ['none','partial_informal','structured_not_certified','already_certified','integrated_system_existing'];
    if (!in_array($qmsMaturity, $allowedMaturity, true)) $qmsMaturity = '';

    $coreProcessCountRaw = $companyProfile['core_process_count'] ?? null;
    $coreProcessCount = ($coreProcessCountRaw === null || $coreProcessCountRaw === '') ? null : (int)$coreProcessCountRaw;
    if ($coreProcessCount !== null && $coreProcessCount < 0) $coreProcessCount = null;

    $departmentsCountRaw = $companyProfile['departments_or_units_count'] ?? null;
    $departmentsCount = ($departmentsCountRaw === null || $departmentsCountRaw === '') ? null : (int)$departmentsCountRaw;
    if ($departmentsCount !== null && $departmentsCount < 0) $departmentsCount = null;

    $productLinesCountRaw = $companyProfile['product_service_lines_count'] ?? null;
    $productLinesCount = ($productLinesCountRaw === null || $productLinesCountRaw === '') ? null : (int)$productLinesCountRaw;
    if ($productLinesCount !== null && $productLinesCount < 0) $productLinesCount = null;

    $criticalSuppliersCountRaw = $companyProfile['critical_suppliers_count'] ?? null;
    $criticalSuppliersCount = ($criticalSuppliersCountRaw === null || $criticalSuppliersCountRaw === '') ? null : (int)$criticalSuppliersCountRaw;
    if ($criticalSuppliersCount !== null && $criticalSuppliersCount < 0) $criticalSuppliersCount = null;

    $designApplicability = strtolower(trim((string)($companyProfile['design_applicability'] ?? 'unknown')));
    if (!in_array($designApplicability, ['yes','no','unknown'], true)) $designApplicability = 'unknown';

    $outsourcingLevel = strtolower(trim((string)($companyProfile['outsourcing_level'] ?? '')));
    if (!in_array($outsourcingLevel, ['low','medium','high'], true)) $outsourcingLevel = '';

    $itMaturity = strtolower(trim((string)($companyProfile['it_maturity'] ?? '')));
    if (!in_array($itMaturity, ['low','medium','high'], true)) $itMaturity = '';

    $preferredDeliveryDeadline = trim((string)($companyProfile['preferred_delivery_deadline'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $preferredDeliveryDeadline)) $preferredDeliveryDeadline = '';

    $blackoutPeriods = trim((string)($companyProfile['blackout_periods'] ?? ''));
    if ($blackoutPeriods === '') $blackoutPeriods = '';

    $langsRaw = $companyProfile['languages_needed'] ?? null;
    $languagesNeeded = [];
    if (is_array($langsRaw)) {
        foreach ($langsRaw as $l) {
            if (!is_string($l)) continue;
            $l = strtoupper(trim($l));
            if (!in_array($l, ['IT','EN','OTHER'], true)) continue;
            $languagesNeeded[] = $l;
        }
    } elseif (is_string($langsRaw)) {
        $l = strtoupper(trim($langsRaw));
        if (in_array($l, ['IT','EN','OTHER'], true)) $languagesNeeded[] = $l;
    }
    $languagesNeeded = array_values(array_unique($languagesNeeded));

    $onSitePrefRatioRaw = $companyProfile['on_site_preference_ratio'] ?? null;
    $onSitePreferenceRatio = ($onSitePrefRatioRaw === null || $onSitePrefRatioRaw === '') ? null : (int)$onSitePrefRatioRaw;
    if ($onSitePreferenceRatio !== null) {
        if ($onSitePreferenceRatio < 0) $onSitePreferenceRatio = 0;
        if ($onSitePreferenceRatio > 100) $onSitePreferenceRatio = 100;
    }

    $onSiteLocationsCountRaw = $companyProfile['on_site_locations_count'] ?? null;
    $onSiteLocationsCount = ($onSiteLocationsCountRaw === null || $onSiteLocationsCountRaw === '') ? null : (int)$onSiteLocationsCountRaw;
    if ($onSiteLocationsCount !== null && $onSiteLocationsCount < 0) $onSiteLocationsCount = null;

    // ISO 14001 advanced block (present only when ISO14001 selected in UI)
    $iso14001 = is_array($companyProfile['iso14001'] ?? null) ? $companyProfile['iso14001'] : [];
    $envAspectsComplexity = strtolower(trim((string)($iso14001['environmental_aspects_complexity'] ?? $companyProfile['environmental_aspects_complexity'] ?? '')));
    if (!in_array($envAspectsComplexity, ['low','medium','high'], true)) $envAspectsComplexity = '';
    $permitsPresence = strtolower(trim((string)($iso14001['permits_presence'] ?? $companyProfile['permits_presence'] ?? 'unknown')));
    if (!in_array($permitsPresence, ['yes','no','unknown'], true)) $permitsPresence = 'unknown';
    $hazardousSubstances = strtolower(trim((string)($iso14001['hazardous_substances'] ?? $companyProfile['hazardous_substances'] ?? 'unknown')));
    if (!in_array($hazardousSubstances, ['yes','no','unknown'], true)) $hazardousSubstances = 'unknown';

    // Conditional drivers (new wizard blocks) - best-effort parsing, schema drift safe
    $food = is_array($companyProfile['food'] ?? null) ? $companyProfile['food'] : [];
    $foodHaccpStudiesRaw = $food['haccp_studies_count'] ?? $companyProfile['haccp_studies_count'] ?? null;
    $foodHaccpStudiesCount = ($foodHaccpStudiesRaw === null || $foodHaccpStudiesRaw === '') ? null : (int)$foodHaccpStudiesRaw;
    if ($foodHaccpStudiesCount !== null && $foodHaccpStudiesCount < 0) $foodHaccpStudiesCount = null;
    $foodShiftsRaw = $food['shifts_count'] ?? $companyProfile['shifts_count'] ?? null;
    $foodShiftsCount = ($foodShiftsRaw === null || $foodShiftsRaw === '') ? null : (int)$foodShiftsRaw;
    if ($foodShiftsCount !== null && !in_array($foodShiftsCount, [1, 2, 3], true)) $foodShiftsCount = null;
    $foodHighRiskProducts = strtolower(trim((string)($food['high_risk_products'] ?? $companyProfile['high_risk_products'] ?? 'unknown')));
    if (!in_array($foodHighRiskProducts, ['yes','no','unknown'], true)) $foodHighRiskProducts = 'unknown';

    $iso17025 = is_array($companyProfile['iso17025'] ?? null) ? $companyProfile['iso17025'] : [];
    $iso17025MethodsRaw = $iso17025['methods_count'] ?? $companyProfile['methods_count'] ?? null;
    $iso17025MethodsCount = ($iso17025MethodsRaw === null || $iso17025MethodsRaw === '') ? null : (int)$iso17025MethodsRaw;
    if ($iso17025MethodsCount !== null && $iso17025MethodsCount < 0) $iso17025MethodsCount = null;
    $iso17025SamplingInScope = strtolower(trim((string)($iso17025['sampling_in_scope'] ?? $companyProfile['sampling_in_scope'] ?? 'unknown')));
    if (!in_array($iso17025SamplingInScope, ['yes','no','unknown'], true)) $iso17025SamplingInScope = 'unknown';
    $iso17025MultiSiteLab = strtolower(trim((string)($iso17025['multi_site_lab'] ?? $companyProfile['multi_site_lab'] ?? '')));
    if (!in_array($iso17025MultiSiteLab, ['yes','no',''], true)) $iso17025MultiSiteLab = '';

    $ce = is_array($companyProfile['ce'] ?? null) ? $companyProfile['ce'] : [];
    $ceNotifiedBodyRequired = strtolower(trim((string)($ce['notified_body_required'] ?? $companyProfile['notified_body_required'] ?? 'unknown')));
    if (!in_array($ceNotifiedBodyRequired, ['yes','no','unknown'], true)) $ceNotifiedBodyRequired = 'unknown';

    $gdpr = is_array($companyProfile['gdpr'] ?? null) ? $companyProfile['gdpr'] : [];
    $gdprSpecialCategoriesData = strtolower(trim((string)($gdpr['special_categories_data'] ?? $companyProfile['special_categories_data'] ?? 'unknown')));
    if (!in_array($gdprSpecialCategoriesData, ['yes','no','unknown'], true)) $gdprSpecialCategoriesData = 'unknown';
    $gdprExtraEuTransfers = strtolower(trim((string)($gdpr['extra_eu_transfers'] ?? $companyProfile['extra_eu_transfers'] ?? 'unknown')));
    if (!in_array($gdprExtraEuTransfers, ['yes','no','unknown'], true)) $gdprExtraEuTransfers = 'unknown';

    $odv231 = is_array($companyProfile['odv231'] ?? null) ? $companyProfile['odv231'] : [];
    $mogExisting = strtolower(trim((string)($odv231['mog_existing'] ?? $companyProfile['mog_existing'] ?? 'unknown')));
    if (!in_array($mogExisting, ['yes','no','unknown'], true)) $mogExisting = 'unknown';

    $accred = is_array($companyProfile['accred'] ?? null) ? $companyProfile['accred'] : [];
    $regionalReqComplexity = strtolower(trim((string)($accred['regional_requirements_complexity'] ?? $companyProfile['regional_requirements_complexity'] ?? '')));
    if (!in_array($regionalReqComplexity, ['low','medium','high',''], true)) $regionalReqComplexity = '';

    // Website enrichment (best-effort)
    $sources = [];
    $webText = '';
    if ($website !== '') {
        $res1 = cnx_consulting_fetch_url_text($website);
        if ($res1['ok'] && $res1['text'] !== '') {
            $webText .= "Fonte: " . $res1['url'] . "\n" . $res1['text'] . "\n";
            $sources[] = (string)$res1['url'];
        }
        // Try common about pages only if same host
        $baseUrl = $res1['ok'] ? (string)$res1['url'] : $website;
        if (!preg_match('#^https?://#i', $baseUrl)) $baseUrl = 'https://' . $baseUrl;
        $parts = @parse_url($baseUrl);
        if (is_array($parts) && !empty($parts['host'])) {
            $origin = (string)($parts['scheme'] ?? 'https') . '://' . (string)$parts['host'];
            foreach (['/chi-siamo', '/about', '/azienda', '/company'] as $p) {
                $res2 = cnx_consulting_fetch_url_text($origin . $p);
                if ($res2['ok'] && $res2['text'] !== '') {
                    $webText .= "Fonte: " . $res2['url'] . "\n" . $res2['text'] . "\n";
                    $sources[] = (string)$res2['url'];
                    break;
                }
            }
        }
    }
    $sources = array_values(array_unique(array_filter(array_map('strval', $sources))));

    // Load selected services
    $cols = cnx_consulting_activity_types_cols($db);
    $select = "SELECT id, name";
    if (!empty($cols['service_code'])) $select .= ", service_code";
    if (!empty($cols['category'])) $select .= ", category";
    if (!empty($cols['scheme_type'])) $select .= ", scheme_type";
    if (!empty($cols['legacy_combo'])) $select .= ", legacy_combo";
    if (!empty($cols['is_legacy_composite'])) $select .= ", is_legacy_composite";
    if (!empty($cols['base_days_min'])) $select .= ", base_days_min";
    if (!empty($cols['base_days_max'])) $select .= ", base_days_max";
    if (!empty($cols['complexity_score'])) $select .= ", complexity_score";
    if (!empty($cols['complexity_weight'])) $select .= ", complexity_weight";
    // Wizard preview coherence: allow using default phases if workplan config is missing.
    if (!empty($cols['default_phases_json'])) $select .= ", default_phases_json";
    $select .= " FROM consulting_activity_types
                WHERE tenant_id = ?
                  AND deleted_at IS NULL
                  AND id IN (" . implode(',', array_fill(0, count($serviceTypeIds), '?')) . ")";

    $rows = $db->fetchAll($select, array_merge([CNX_VENDOR_TENANT_ID], $serviceTypeIds)) ?: [];
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int)$r['id']] = $r;
    }
    foreach ($serviceTypeIds as $sid) {
        if (!isset($byId[$sid])) {
            api_error('Servizio non trovato nel catalogo (id=' . (int)$sid . ')', 404);
        }
    }

    // Build company_profile_inferred + missing fields (planning 2026)
    $missing = [];
    $missingCore = [];
    $assumptions = [];
    $openQuestions = [];
    $integrationNotes = [];

    $sectorOut = $sector !== '' ? $sector : null;
    $employeesRangeOut = $employeesRange !== '' ? $employeesRange : null;
    $sitesCountOut = $sitesCount;
    $regulatedOut = ($regulated === null) ? null : (bool)$regulated;
    $notesOut = $notes !== '' ? $notes : null;
    $websiteOut = $website !== '' ? $website : null;
    $interventionTypeOut = $interventionType !== '' ? $interventionType : null;
    $qmsMaturityOut = $qmsMaturity !== '' ? $qmsMaturity : null;
    $onSiteLocationsCountOut = $onSiteLocationsCount;

    // Optional: document intelligence profile (client tenant) to avoid "ex-novo" overestimation.
    // Input comes from planning wizard (tenant 28) as doc_profile_id (cached in ai_tenant_doc_profiles).
    $docProfile = null;
    $docProfileExpired = false;
    $docDocumentationFactor = 1.0;
    $docDocsExist = false;
    $docMaturitySuggested = '';
    if ($docProfileId > 0) {
        try {
            $hasProfiles = (bool)$db->fetchOne("SHOW TABLES LIKE 'ai_tenant_doc_profiles'");
            if ($hasProfiles) {
                $r = $db->fetchOne(
                    "SELECT id, expires_at, payload_json
                     FROM ai_tenant_doc_profiles
                     WHERE id = ?
                       AND tenant_id = ?
                       AND scope = 'IMS_PLANNING'
                     LIMIT 1",
                    [$docProfileId, $clientTenantId]
                );
                if ($r && trim((string)($r['payload_json'] ?? '')) !== '') {
                    $exp = (string)($r['expires_at'] ?? '');
                    if ($exp !== '' && strtotime($exp) !== false && strtotime($exp) <= time()) {
                        $docProfileExpired = true;
                    }
                    $decoded = json_decode((string)$r['payload_json'], true);
                    if (is_array($decoded)) {
                        $docProfile = $decoded;
                        $docMaturitySuggested = strtolower(trim((string)($docProfile['maturity_suggested'] ?? '')));
                        $docDocumentationFactor = (float)($docProfile['planning_adjustments']['documentation_factor'] ?? 1.0);
                        if ($docDocumentationFactor < 0.0) $docDocumentationFactor = 0.0;
                        if ($docDocumentationFactor > 1.0) $docDocumentationFactor = 1.0;
                        $det = is_array($docProfile['detected'] ?? null) ? $docProfile['detected'] : [];
                        $manual = (bool)($det['manual'] ?? false);
                        $proc = (int)($det['procedures_count_est'] ?? 0);
                        $docDocsExist = $manual || ($proc > 0) || in_array($docMaturitySuggested, ['structured_non_certified','already_certified','integrated_existing'], true);
                    }
                }
            }
        } catch (Throwable $e) {
            $docProfile = null;
        }
    }

    // If user didn't specify maturity, prefill from doc evidence (best-effort, override allowed in UI).
    if ($qmsMaturityOut === null && $docProfile && $docMaturitySuggested !== '') {
        $map = [
            'none' => 'none',
            'partial' => 'partial_informal',
            'structured_non_certified' => 'structured_not_certified',
            'already_certified' => 'already_certified',
            'integrated_existing' => 'integrated_system_existing',
        ];
        $mapped = $map[$docMaturitySuggested] ?? '';
        if ($mapped !== '') {
            $qmsMaturityOut = $mapped;
            $assumptions[] = 'Evidenze documentali: maturità SGQ/IMS suggerita = ' . $mapped . ' (best-effort, modificabile).';
            $integrationNotes[] = 'Stima adattata usando evidenze documentali (doc_profile_id=' . (int)$docProfileId . ').';
        }
    }

    $inferScheme = static function (string $code, string $category = ''): string {
        $k = strtoupper(trim($code));
        if ($k === '') return '';
        if (in_array($k, ['ISO9001','ISO14001','ISO45001','ISO50001','SA8000','ISO37001','PDR125'], true)) return 'MSS';
        if (in_array($k, ['ISO22000','HACCP'], true)) return 'FSMS';
        if (in_array($k, ['BRC','IFS','BRCBROKERS'], true)) return 'GFSI';
        if ($k === 'ISOIEC17025') return 'LAB';
        if ($k === 'PRIVACY') return 'PRIVACY';
        if ($k === 'ODV231') return '231';
        if ($k === 'RSPP') return 'SAFETY_SERVICE';
        if (in_array($k, ['NORMA10891','NORMA13895'], true) || str_contains($k, 'NORMA')) return 'GENERIC_NORM';
        $c = strtoupper(trim($category));
        if ($c === 'PRIVACY') return 'PRIVACY';
        if ($c === '231') return '231';
        if ($c === 'FOOD') return 'FSMS';
        if (in_array($c, ['ISO','SOCIAL','SAFETY','COMPLIANCE'], true)) return 'MSS';
        return 'REGULATORY';
    };

    $hasIso9001 = false;
    $hasIso14001 = false;
    $hasFood = false;
    $has17025 = false;
    $hasCe = false;
    $hasGdpr = false;
    $has231 = false;
    $hasAccred = false;

    foreach ($serviceTypeIds as $sid) {
        $r = $byId[$sid] ?? null;
        if (!$r) continue;
        $code = strtoupper(trim((string)($r['service_code'] ?? '')));
        $nameUp = strtoupper(trim((string)($r['name'] ?? '')));
        if ($code === '' && $nameUp !== '') {
            if (str_contains($nameUp, '14001')) $code = 'ISO14001';
            if (str_contains($nameUp, '9001')) $code = 'ISO9001';
            if (str_contains($nameUp, '17025')) $code = 'ISOIEC17025';
            if (str_contains($nameUp, 'HACCP')) $code = 'HACCP';
            if (str_contains($nameUp, '22000')) $code = 'ISO22000';
            if (str_contains($nameUp, 'PRIVACY') || str_contains($nameUp, 'GDPR') || str_contains($nameUp, 'DPO')) $code = 'PRIVACY';
            if (str_contains($nameUp, 'ODV') || str_contains($nameUp, '231')) $code = 'ODV231';
            if (str_contains($nameUp, 'ACCRED')) $code = 'ACCRED';
            if (str_contains($nameUp, 'CE')) $code = 'CE';
        }
        $schemeDb = strtoupper(trim((string)($r['scheme_type'] ?? '')));
        $schemeEff = $schemeDb !== '' ? $schemeDb : $inferScheme($code, strtoupper(trim((string)($r['category'] ?? ''))));

        if ($code === 'ISO9001') $hasIso9001 = true;
        if ($code === 'ISO14001') $hasIso14001 = true;
        if ($code === 'ISOIEC17025') $has17025 = true;
        if ($code === 'CE') $hasCe = true;
        if ($code === 'PRIVACY') $hasGdpr = true;
        if ($code === 'ODV231') $has231 = true;
        if ($code === 'ACCRED') $hasAccred = true;
        if (in_array($code, ['HACCP','ISO22000','BRC','IFS','BRCBROKERS','BIO'], true) || in_array($schemeEff, ['FSMS','GFSI'], true)) $hasFood = true;
    }

    // Missing fields (core for confidence)
    if ($employeesRangeOut === null) { $missing[] = 'employees_range'; $missingCore[] = 'employees_range'; }
    if ($sitesCountOut === null) { $missing[] = 'sites_count'; $missingCore[] = 'sites_count'; }
    if ($interventionTypeOut === null) { $missing[] = 'intervention_type'; $missingCore[] = 'intervention_type'; }
    if ($qmsMaturityOut === null) { $missing[] = 'qms_maturity'; $missingCore[] = 'qms_maturity'; }
    if ($sectorOut === null) $missing[] = 'sector';

    // Driver-specific completeness (for confidence)
    $hasAnySpecific = ($hasFood || $has17025 || $hasCe || $hasGdpr || $has231 || $hasAccred);
    $specificOk = true;
    $foodProvided = (($foodHaccpStudiesCount !== null && $foodHaccpStudiesCount > 0) || ($foodShiftsCount !== null && $foodShiftsCount > 0) || ($foodHighRiskProducts !== 'unknown'));
    if ($hasFood && !$foodProvided) { $specificOk = false; $missing[] = 'food_drivers'; }
    $iso17025Provided = (($iso17025MethodsCount !== null && $iso17025MethodsCount > 0) || ($iso17025SamplingInScope !== 'unknown') || ($iso17025MultiSiteLab !== ''));
    if ($has17025 && !$iso17025Provided) { $specificOk = false; $missing[] = 'iso17025_drivers'; }
    $ceProvided = ($ceNotifiedBodyRequired !== 'unknown');
    if ($hasCe && !$ceProvided) { $specificOk = false; $missing[] = 'ce_drivers'; }
    $gdprProvided = (($gdprSpecialCategoriesData !== 'unknown') || ($gdprExtraEuTransfers !== 'unknown'));
    if ($hasGdpr && !$gdprProvided) { $specificOk = false; $missing[] = 'gdpr_drivers'; }
    $odvProvided = ($mogExisting !== 'unknown');
    if ($has231 && !$odvProvided) { $specificOk = false; $missing[] = 'odv231_drivers'; }
    $accredProvided = ($regionalReqComplexity !== '');
    if ($hasAccred && !$accredProvided) { $specificOk = false; $missing[] = 'accred_drivers'; }

    // Confidence (0..1)
    $conf = 50;
    if ($employeesRangeOut !== null) $conf += 10;
    if ($sitesCountOut !== null) $conf += 10;
    if ($interventionTypeOut !== null) $conf += 10;
    if ($qmsMaturityOut !== null) $conf += 10;
    if ($notesOut !== null || $websiteOut !== null) $conf += 10;
    if ($hasAnySpecific && $specificOk) $conf += 10;
    if ($conf > 95) $conf = 95;
    $cap65 = ($interventionTypeOut === null || $qmsMaturityOut === null);
    if ($cap65 && $conf > 65) $conf = 65;
    $confidenceBase = max(0.20, min(0.95, (float)$conf / 100.0));

    // Boost confidence best-effort when doc profile exists and is not expired
    if ($docProfile && !$docProfileExpired) {
        $dc = (int)($docProfile['confidence'] ?? 0);
        if ($dc > 0) {
            $docConf = max(0.20, min(0.95, (float)$dc / 100.0));
            $confidenceBase = max($confidenceBase, $docConf);
        }
    } elseif ($docProfile && $docProfileExpired) {
        // Keep it conservative when profile is expired (UI can re-run analysis)
        $confidenceBase = min($confidenceBase, 0.60);
        $assumptions[] = 'Evidenze documentali: analisi scaduta (riesegui “Analizza documenti” per aggiornare).';
    }

    if ($cap65) {
        $assumptions[] = 'Compila Tipo intervento e Maturità SGQ/IMS per una stima più affidabile (confidenza max 65% finché mancanti).';
        $integrationNotes[] = 'Confidenza limitata: manca Tipo intervento e/o Maturità SGQ/IMS.';
    }

    // Open questions (best-effort)
    if ($employeesRangeOut === null) $openQuestions[] = 'Qual è la fascia dipendenti (1-10, 11-50, 51-200, 201-500, 500+)?';
    if ($sitesCountOut === null) $openQuestions[] = 'Quante sedi/siti sono inclusi nel perimetro?';
    if ($interventionTypeOut === null) $openQuestions[] = 'Che tipo di intervento è (nuova implementazione / mantenimento / ricertificazione / estensione scopo / transizione)?';
    if ($qmsMaturityOut === null) $openQuestions[] = 'Qual è la maturità del SGQ/IMS (assente / parziale / strutturato non certificato / già certificato / sistema integrato)?';
    if ($hasIso9001 && $designApplicability === 'unknown') $openQuestions[] = 'Per ISO 9001: la progettazione/sviluppo è applicabile (sì/no)?';
    if ($hasFood && !$foodProvided) $openQuestions[] = 'Per servizi Food: n. studi HACCP, turni e prodotti ad alto rischio?';
    if ($has17025 && !$iso17025Provided) $openQuestions[] = 'Per ISO/IEC 17025: n. metodi, campionamento in perimetro e lab multi-sede?';
    if ($hasCe && $ceNotifiedBodyRequired === 'unknown') $openQuestions[] = 'Per CE: è richiesto un Notified Body (sì/no)?';
    if ($hasGdpr && !$gdprProvided) $openQuestions[] = 'Per GDPR: categorie particolari e/o trasferimenti extra-UE (sì/no)?';
    if ($has231 && $mogExisting === 'unknown') $openQuestions[] = 'Per 231: esiste già un MOG (sì/no)?';
    if ($hasAccred && $regionalReqComplexity === '') $openQuestions[] = 'Per Accreditamenti: complessità requisiti regionali (bassa/media/alta)?';

    // Deterministic estimation (per service)
    $factorIntervention = 1.0;
    if ($interventionTypeOut !== null) {
        switch ($interventionTypeOut) {
            case 'new_implementation': $factorIntervention = 1.00; break;
            case 'maintenance': $factorIntervention = 0.35; break;
            case 'recertification': $factorIntervention = 0.65; break;
            case 'scope_extension': $factorIntervention = 0.65; break;
            case 'transition_update': $factorIntervention = 0.40; break;
        }
    }

    $factorMaturity = 1.0;
    if ($qmsMaturityOut !== null) {
        switch ($qmsMaturityOut) {
            case 'none': $factorMaturity = 1.32; break;
            case 'partial_informal': $factorMaturity = 1.15; break;
            case 'structured_not_certified': $factorMaturity = 1.00; break;
            case 'already_certified': $factorMaturity = 0.78; break;
            case 'integrated_system_existing': $factorMaturity = 0.70; break;
        }
    }

    $factorEmployees = 1.0;
    if ($employeesRangeOut !== null) {
        switch ($employeesRangeOut) {
            case '1-10': $factorEmployees = 0.85; break;
            case '11-50': $factorEmployees = 1.00; break;
            case '51-200': $factorEmployees = 1.18; break;
            case '201-500': $factorEmployees = 1.35; break;
            case '500+': $factorEmployees = 1.55; break;
        }
    }

    $factorSites = 1.0;
    if ($sitesCountOut !== null && $sitesCountOut > 1) {
        $factorSites = 1.0 + min(0.60, 0.15 * max(0, $sitesCountOut - 1));
    }

    $factorOnSiteLocations = 1.0;
    if ($onSiteLocationsCountOut !== null && $onSiteLocationsCountOut > 1) {
        $factorOnSiteLocations = 1.0 + min(0.20, 0.05 * max(0, $onSiteLocationsCountOut - 1));
    }

    $factorRegulated = ($regulatedOut === true) ? 1.12 : 1.0;

    $globalMult = $factorIntervention * $factorMaturity * $factorEmployees * $factorSites * $factorOnSiteLocations * $factorRegulated;

    $globalAdd = 0.0;
    if ($coreProcessCount !== null && $coreProcessCount > 6) {
        $steps = intdiv(max(0, $coreProcessCount - 6), 3);
        $globalAdd += min(0.15, 0.03 * (float)$steps);
    }
    if ($criticalSuppliersCount !== null && $criticalSuppliersCount > 10) {
        $steps = intdiv(max(0, $criticalSuppliersCount - 10), 5);
        $globalAdd += min(0.12, 0.03 * (float)$steps);
    }
    if ($outsourcingLevel === 'medium') $globalAdd += 0.05;
    if ($outsourcingLevel === 'high') $globalAdd += 0.10;
    if ($itMaturity === 'low') $globalAdd += 0.06;
    if ($itMaturity === 'high') $globalAdd -= 0.03;

    // Preview phases (server-derived) to keep wizard preview coherent with items generation.
    $phaseLib = cnx_sched_phase_library();
    $workplans = cnx_sched_service_workplans();
    $interventionKey = cnx_sched_intervention_key($interventionTypeOut);

    $estimates = [];
    foreach ($serviceTypeIds as $sid) {
        $r = $byId[$sid] ?? null;
        if (!$r) continue;

        $code = strtoupper(trim((string)($r['service_code'] ?? '')));
        $name = (string)($r['name'] ?? ('#' . $sid));
        // Best-effort: infer service code from name if missing (keeps preview/workplan coherent)
        if ($code === '' && $name !== '') {
            $nameUp = strtoupper(trim($name));
            if (str_contains($nameUp, '14001')) $code = 'ISO14001';
            if (str_contains($nameUp, '9001')) $code = 'ISO9001';
            if (str_contains($nameUp, '17025')) $code = 'ISOIEC17025';
            if (str_contains($nameUp, 'HACCP')) $code = 'HACCP';
            if (str_contains($nameUp, '22000')) $code = 'ISO22000';
            if (str_contains($nameUp, 'PRIVACY') || str_contains($nameUp, 'GDPR') || str_contains($nameUp, 'DPO')) $code = 'PRIVACY';
            if (str_contains($nameUp, 'ODV') || str_contains($nameUp, '231')) $code = 'ODV231';
            if (str_contains($nameUp, 'ACCRED')) $code = 'ACCRED';
            if (str_contains($nameUp, 'CE')) $code = 'CE';
        }
        $schemeDb = strtoupper(trim((string)($r['scheme_type'] ?? '')));
        $schemeEff = $schemeDb !== '' ? $schemeDb : $inferScheme($code, strtoupper(trim((string)($r['category'] ?? ''))));

        $baseMin = isset($r['base_days_min']) ? (($r['base_days_min'] === null || $r['base_days_min'] === '') ? null : (int)$r['base_days_min']) : null;
        $baseMax = isset($r['base_days_max']) ? (($r['base_days_max'] === null || $r['base_days_max'] === '') ? null : (int)$r['base_days_max']) : null;
        $usedFallback = false;
        if ($baseMin === null || $baseMax === null || $baseMax <= 0) {
            $fb = cnx_consulting_fallback_range($code !== '' ? $code : $name);
            $baseMin = $fb['min'];
            $baseMax = $fb['max'];
            $usedFallback = true;
        }

        $baseMinF = max(2.0, (float)$baseMin);
        $baseMaxF = max($baseMinF, (float)$baseMax);
        $baseMid = ($baseMinF + $baseMaxF) / 2.0;

        $serviceAdd = $globalAdd;
        $extraReasons = [];

        // ISO9001 driver
        if ($code === 'ISO9001' && $designApplicability === 'yes') { $serviceAdd += 0.10; $extraReasons[] = 'progettazione applicabile'; }

        // Food drivers (FSMS/GFSI)
        $isFoodSvc = (in_array($schemeEff, ['FSMS','GFSI'], true) || in_array($code, ['HACCP','ISO22000','BRC','IFS','BRCBROKERS','BIO'], true));
        if ($isFoodSvc) {
            if ($foodHaccpStudiesCount !== null) {
                if ($foodHaccpStudiesCount > 6) { $serviceAdd += 0.20; $extraReasons[] = 'studi HACCP > 6'; }
                elseif ($foodHaccpStudiesCount > 3) { $serviceAdd += 0.10; $extraReasons[] = 'studi HACCP > 3'; }
            }
            if ($foodShiftsCount === 2) { $serviceAdd += 0.05; $extraReasons[] = '2 turni'; }
            if ($foodShiftsCount === 3) { $serviceAdd += 0.10; $extraReasons[] = '3 turni'; }
            if ($foodHighRiskProducts === 'yes') { $serviceAdd += 0.10; $extraReasons[] = 'prodotti ad alto rischio'; }
        }

        // ISO/IEC 17025
        if ($code === 'ISOIEC17025') {
            if ($iso17025MethodsCount !== null && $iso17025MethodsCount > 0) {
                $steps = intdiv($iso17025MethodsCount, 20);
                $serviceAdd += min(0.25, 0.05 * (float)$steps);
                if ($steps > 0) $extraReasons[] = 'metodi';
            }
            if ($iso17025SamplingInScope === 'yes') { $serviceAdd += 0.10; $extraReasons[] = 'campionamento in scope'; }
            if ($iso17025MultiSiteLab === 'yes') { $serviceAdd += 0.10; $extraReasons[] = 'lab multi-sede'; }
        }

        // CE
        if ($code === 'CE' && $ceNotifiedBodyRequired === 'yes') { $serviceAdd += 0.15; $extraReasons[] = 'notified body richiesto'; }

        // GDPR
        if ($code === 'PRIVACY') {
            if ($gdprSpecialCategoriesData === 'yes') { $serviceAdd += 0.10; $extraReasons[] = 'categorie particolari'; }
            if ($gdprExtraEuTransfers === 'yes') { $serviceAdd += 0.10; $extraReasons[] = 'trasferimenti extra-UE'; }
        }

        // 231
        if ($code === 'ODV231' && $mogExisting === 'no') { $serviceAdd += 0.15; $extraReasons[] = 'MOG assente'; }

        // Accreditamenti
        if ($code === 'ACCRED') {
            if ($regionalReqComplexity === 'medium') { $serviceAdd += 0.05; $extraReasons[] = 'req regionali medi'; }
            if ($regionalReqComplexity === 'high') { $serviceAdd += 0.10; $extraReasons[] = 'req regionali complessi'; }
        }

        // Clamp additive part
        if ($serviceAdd < -0.20) $serviceAdd = -0.20;
        if ($serviceAdd > 0.80) $serviceAdd = 0.80;

        $factorTotal = $globalMult * (1.0 + $serviceAdd);
        if ($factorTotal < 0.25) $factorTotal = 0.25;
        if ($factorTotal > 3.50) $factorTotal = 3.50;

        $minDays = (int)round($baseMinF * $factorTotal);
        $maxDays = (int)round($baseMaxF * $factorTotal);
        $suggested = (int)round($baseMid * $factorTotal);

        // Clamp per service: [2..120]
        $clamped = false;
        $minDays = max(2, min(120, $minDays));
        $maxDays = max(2, min(120, $maxDays));
        if ($maxDays < $minDays) $maxDays = $minDays;
        $suggested = max($minDays, min($maxDays, $suggested));
        if ($minDays === 120 || $maxDays === 120 || $suggested === 120) $clamped = true;

        // On-site vs remote breakdown
        $hasOnSite = (($onSiteLocationsCountOut ?? 0) > 0);
        if ($hasOnSite) {
            $ratio = ($onSitePreferenceRatio !== null) ? ((float)$onSitePreferenceRatio / 100.0) : 0.60;
            $ratio = max(0.35, min(1.0, $ratio));
        } else {
            $ratio = 0.20;
        }
        // Planning 2026: recertification is typically on-site heavy (audit interno + supporto audit esterno).
        // Even if the user didn't explicitly select on-site locations, keep a sensible on-site baseline.
        if ($interventionTypeOut === 'recertification') {
            if ($hasOnSite) {
                $ratio = max(0.50, (float)$ratio);
            } else {
                $ratio = 0.60;
            }
            // Extra bump when multiple on-site locations are selected
            if (($onSiteLocationsCountOut ?? 0) > 1) {
                $ratio = min(1.0, (float)$ratio + min(0.15, 0.05 * (float)max(0, (int)$onSiteLocationsCountOut - 1)));
            }
            $ratio = max(0.35, min(1.0, (float)$ratio));
        }
        $onSiteDays = (int)round($suggested * $ratio);
        if ($onSiteDays < 0) $onSiteDays = 0;
        if ($onSiteDays > $suggested) $onSiteDays = $suggested;
        if ($interventionTypeOut === 'recertification' && $suggested > 0 && $onSiteDays < 1) {
            $onSiteDays = 1;
        }
        $remoteDays = max(0, $suggested - $onSiteDays);

        $driverParts = [];
        if ($interventionTypeOut !== null) $driverParts[] = 'Tipo intervento: ' . $interventionTypeOut;
        if ($qmsMaturityOut !== null) $driverParts[] = 'Maturità: ' . $qmsMaturityOut;
        if ($employeesRangeOut !== null) $driverParts[] = 'Dipendenti: ' . $employeesRangeOut;
        if ($sitesCountOut !== null) $driverParts[] = 'Siti: ' . (int)$sitesCountOut;
        if ($regulatedOut === true) $driverParts[] = 'Regolamentato';
        if (($onSiteLocationsCountOut ?? 0) > 1) $driverParts[] = 'Sedi on-site: ' . (int)$onSiteLocationsCountOut;

        $rationaleParts = [];
        $rationaleParts[] = "Base catalogo: " . (int)$baseMinF . "-" . (int)$baseMaxF . "g.";
        if (!empty($driverParts)) $rationaleParts[] = "Driver: " . implode(', ', $driverParts) . ".";
        if (!empty($extraReasons)) $rationaleParts[] = "Extra: " . implode(', ', array_values(array_unique($extraReasons))) . ".";
        if (!empty($missingCore)) $rationaleParts[] = "Dati mancanti: " . implode(', ', $missingCore) . ".";
        if ($usedFallback) $rationaleParts[] = "Range base non completo: fallback conservativo.";
        if ($clamped) $rationaleParts[] = "Warning: clamp 120g.";
        $previewPhases = cnx_sched_build_preview_phases_for_service($r, ($code !== '' ? $code : $name), $interventionKey, $phaseLib, $workplans);

        // Document evidence adjustments (best-effort):
        // - Rename "documentation" into review/update
        // - Reduce ONLY the documentation share via documentation_factor (0..1)
        if ($docProfile && $docDocsExist) {
            $docShare = 0.0;
            if (is_array($previewPhases)) {
                foreach ($previewPhases as $i => $ph) {
                    $pk = strtolower(trim((string)($ph['phase_key'] ?? '')));
                    if ($pk === 'documentation') {
                        $share = (float)($ph['share_of_total'] ?? 0.0);
                        if ($share > $docShare) $docShare = $share;
                        $previewPhases[$i]['label'] = 'Review/Aggiornamento documentazione esistente';
                    }
                }
            }
            if ($docShare <= 0.0) $docShare = 0.25; // fallback share

            $df = (float)$docDocumentationFactor;
            if ($df < 0.0) $df = 0.0;
            if ($df > 1.0) $df = 1.0;

            // Keep wizard preview coherent with items_generate_from_estimate.php:
            // apply documentation_factor to the documentation phase share (distribution), not only to total days.
            if (is_array($previewPhases)) {
                foreach ($previewPhases as $i => $ph) {
                    $pk = strtolower(trim((string)($ph['phase_key'] ?? '')));
                    if ($pk !== 'documentation') continue;
                    $share = (float)($ph['share_of_total'] ?? 0.0);
                    if ($share < 0.0) $share = 0.0;
                    $previewPhases[$i]['share_of_total'] = $share * $df;
                }
            }

            if ($df < 1.0) {
                $ratioReduction = (1.0 - $df) * min(0.35, max(0.10, $docShare));
                $ratioReduction = max(0.0, min(0.30, $ratioReduction));
                $newSuggested = (int)round($suggested * (1.0 - $ratioReduction));
                $newSuggested = max($minDays, min($maxDays, $newSuggested));
                if ($newSuggested !== $suggested) {
                    $suggested = $newSuggested;
                    // Recompute breakdown after adjusting suggested days
                    $onSiteDays = (int)round($suggested * $ratio);
                    if ($onSiteDays < 0) $onSiteDays = 0;
                    if ($onSiteDays > $suggested) $onSiteDays = $suggested;
                    if ($interventionTypeOut === 'recertification' && $suggested > 0 && $onSiteDays < 1) {
                        $onSiteDays = 1;
                    }
                    $remoteDays = max(0, $suggested - $onSiteDays);
                }
                $rationaleParts[] = "Evidenze documentali: documentazione esistente → riduzione quota documentazione (factor " . round($df, 2) . ").";
            } else {
                $rationaleParts[] = "Evidenze documentali: documentazione esistente → fase documentazione trattata come review.";
            }

            $gaps = is_array($docProfile['gaps'] ?? null) ? $docProfile['gaps'] : [];
            if (!empty($gaps)) {
                $top = array_slice($gaps, 0, 3);
                $labels = [];
                foreach ($top as $g) {
                    if (!is_array($g)) continue;
                    $area = trim((string)($g['area'] ?? ''));
                    $sev = trim((string)($g['severity'] ?? ''));
                    if ($area === '') continue;
                    $labels[] = $area . ($sev !== '' ? "({$sev})" : "");
                }
                $labels = array_values(array_unique(array_filter($labels)));
                if (!empty($labels)) {
                    $rationaleParts[] = "Gap rilevati (best-effort): " . implode(', ', $labels) . ".";
                }
            }
        }

        $rationale = trim(implode(' ', $rationaleParts));

        $estimates[] = [
            'service_type_id' => (int)$sid,
            'service_code' => ($code !== '' ? $code : null),
            'scheme_type' => ($schemeEff !== '' ? $schemeEff : null),
            'suggested_days' => $suggested,
            'suggested_on_site_days' => $onSiteDays,
            'suggested_remote_days' => $remoteDays,
            'on_site_ratio' => (float)$ratio,
            'min_days' => $minDays,
            'max_days' => $maxDays,
            'rationale' => $rationale,
            'confidence' => (float)$confidenceBase,
            // Used by planning.js to build a preview identical to server items generation.
            'preview_phases' => $previewPhases,
        ];
    }

    // Multi-service synergies (max 20% discount, deterministic)
    $eligibleIdx = [];
    foreach ($estimates as $i => $e) {
        $st = strtoupper(trim((string)($e['scheme_type'] ?? '')));
        if (in_array($st, ['MSS','FSMS','GFSI'], true)) $eligibleIdx[] = (int)$i;
    }
    if (count($eligibleIdx) >= 2) {
        $maxDiscount = 0.10;
        if ($qmsMaturityOut === 'integrated_system_existing') $maxDiscount = 0.20;
        elseif ($qmsMaturityOut === 'already_certified') $maxDiscount = 0.15;

        $discount = min($maxDiscount, 0.05 * (float)(count($eligibleIdx) - 1));
        if ($discount > 0) {
            $totalReduced = 0;
            foreach ($eligibleIdx as $i) {
                $st = strtoupper(trim((string)($estimates[$i]['scheme_type'] ?? '')));
                $share = 0.0;
                if ($st === 'MSS') $share = 0.30;
                elseif ($st === 'FSMS') $share = 0.20;
                elseif ($st === 'GFSI') $share = 0.15;
                if ($share <= 0) continue;

                $ratioReduction = $discount * $share;
                if ($ratioReduction <= 0) continue;

                $oldS = (int)($estimates[$i]['suggested_days'] ?? 0);
                $oldMin = (int)($estimates[$i]['min_days'] ?? 0);
                $oldMax = (int)($estimates[$i]['max_days'] ?? 0);

                $newS = (int)round($oldS * (1.0 - $ratioReduction));
                $newMin = (int)round($oldMin * (1.0 - $ratioReduction));
                $newMax = (int)round($oldMax * (1.0 - $ratioReduction));

                $newMin = max(2, min(120, $newMin));
                $newMax = max(2, min(120, $newMax));
                if ($newMax < $newMin) $newMax = $newMin;
                $newS = max($newMin, min($newMax, $newS));

                $reduced = max(0, $oldS - $newS);
                $totalReduced += $reduced;

                $estimates[$i]['min_days'] = $newMin;
                $estimates[$i]['max_days'] = $newMax;
                $estimates[$i]['suggested_days'] = $newS;

                $ratio = (float)($estimates[$i]['on_site_ratio'] ?? 0.0);
                if ($ratio < 0) $ratio = 0.0;
                if ($ratio > 1) $ratio = 1.0;
                $onSiteDays = (int)round($newS * $ratio);
                if ($onSiteDays < 0) $onSiteDays = 0;
                if ($onSiteDays > $newS) $onSiteDays = $newS;
                $estimates[$i]['suggested_on_site_days'] = $onSiteDays;
                $estimates[$i]['suggested_remote_days'] = max(0, $newS - $onSiteDays);

                $estimates[$i]['rationale'] = trim((string)($estimates[$i]['rationale'] ?? '')) .
                    ' Sinergia multinorma: -' . (int)round($ratioReduction * 100) . '% (quota comune).';
            }
            if ($totalReduced > 0) {
                $integrationNotes[] = 'Sinergia multinorma applicata: -' . (int)$totalReduced . ' giorni (max ' . (int)round($maxDiscount * 100) . '%).';
            }
        }
    }

    $missing = array_values(array_unique(array_filter(array_map('strval', $missing))));
    $openQuestions = array_values(array_unique(array_filter(array_map(static fn($s) => trim((string)$s), $openQuestions), static fn($s) => $s !== '')));
    $assumptions = array_values(array_unique(array_filter(array_map(static fn($s) => trim((string)$s), $assumptions), static fn($s) => $s !== '')));

    // Optional AI enrichment: adjustments (+/-20%) + extra questions (best-effort, non-blocking)
    $aiEnrichment = [
        'enabled' => (bool)$useAiEnrichment,
        'applied' => false,
        'notes' => [],
    ];
    if ($useAiEnrichment && (($notesOut !== null && trim((string)$notesOut) !== '') || trim($webText) !== '') && !empty($estimates)) {
        try {
            $schema = [
                'name' => 'consulting_estimate_enrichment_v1',
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'day_adjustments' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'service_type_id' => ['type' => 'integer'],
                                    'delta_ratio' => ['type' => 'number'], // clamp server-side to [-0.2, +0.2]
                                    'reason' => ['type' => 'string'],
                                ],
                                'required' => ['service_type_id', 'delta_ratio', 'reason'],
                            ],
                        ],
                        'open_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['day_adjustments', 'open_questions', 'notes'],
                ],
            ];

            $servicesForAi = [];
            foreach ($serviceTypeIds as $sid) {
                $r = $byId[$sid] ?? null;
                if (!$r) continue;
                $servicesForAi[] = [
                    'service_type_id' => (int)$sid,
                    'service_code' => (string)($r['service_code'] ?? ''),
                    'name' => (string)($r['name'] ?? ''),
                ];
            }

            $system = [
                'role' => 'system',
                'content' => "Aiuti a migliorare una stima consulenziale. Regole: NON citare né copiare testo ISO/UNI; niente frasi tipo \"la norma richiede\"; se non sei sicuro, non forzare. Puoi proporre solo piccoli aggiustamenti ai giorni suggeriti, con delta_ratio tra -0.20 e +0.20. Rispondi SOLO JSON conforme allo schema.",
            ];
            $user = [
                'role' => 'user',
                'content' => json_encode([
                    'company_profile' => [
                        'sector' => $sectorOut,
                        'employees_range' => $employeesRangeOut,
                        'sites_count' => $sitesCountOut,
                        'regulated' => $regulatedOut,
                        'intervention_type' => $interventionTypeOut,
                        'qms_maturity' => $qmsMaturityOut,
                        'notes' => $notesOut,
                        'website' => $websiteOut,
                        'website_text' => (trim($webText) !== '' ? $webText : null),
                    ],
                    'selected_services' => $servicesForAi,
                    'deterministic_estimates' => $estimates,
                    'missing_fields' => array_values($missing),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];

            $ai = cnx_openai_chat_json([$system, $user], $schema, [
                'temperature' => 0.2,
                'max_tokens' => 700,
                'timeout_seconds' => 25,
                'max_retries' => 1,
            ]);

            if (!empty($ai['ok']) && isset($ai['data']) && is_array($ai['data'])) {
                $adj = is_array($ai['data']['day_adjustments'] ?? null) ? $ai['data']['day_adjustments'] : [];
                $extraQ = is_array($ai['data']['open_questions'] ?? null) ? $ai['data']['open_questions'] : [];
                $aiNotes = is_array($ai['data']['notes'] ?? null) ? $ai['data']['notes'] : [];

                // Merge extra questions
                foreach ($extraQ as $q) {
                    $q = trim((string)$q);
                    if ($q !== '') $openQuestions[] = $q;
                }
                $openQuestions = array_values(array_unique(array_filter(array_map(static fn($s) => trim((string)$s), $openQuestions), static fn($s) => $s !== '')));

                $idxBySid = [];
                foreach ($estimates as $i => $e) {
                    $idxBySid[(int)($e['service_type_id'] ?? 0)] = (int)$i;
                }

                $appliedAny = false;
                foreach ($adj as $a) {
                    if (!is_array($a)) continue;
                    $sid = (int)($a['service_type_id'] ?? 0);
                    if ($sid <= 0 || !isset($idxBySid[$sid])) continue;
                    $delta = (float)($a['delta_ratio'] ?? 0.0);
                    if (!is_finite($delta)) $delta = 0.0;
                    if ($delta > 0.20) $delta = 0.20;
                    if ($delta < -0.20) $delta = -0.20;
                    $reason = trim((string)($a['reason'] ?? ''));
                    if ($reason === '') $reason = 'Aggiustamento best-effort';

                    $i = $idxBySid[$sid];
                    $old = (int)($estimates[$i]['suggested_days'] ?? 0);
                    $minD = (int)($estimates[$i]['min_days'] ?? 0);
                    $maxD = (int)($estimates[$i]['max_days'] ?? 0);
                    if ($old <= 0 || $maxD <= 0) continue;

                    $new = (int)round($old * (1.0 + $delta));
                    if ($new < $minD) $new = $minD;
                    if ($new > $maxD) $new = $maxD;
                    if ($new === $old) continue;

                    $ratio = (float)($estimates[$i]['on_site_ratio'] ?? 0.0);
                    if (!is_finite($ratio) || $ratio < 0) $ratio = 0.0;
                    if ($ratio > 1) $ratio = 1.0;
                    $onSite = (int)round($new * $ratio);
                    if ($onSite < 0) $onSite = 0;
                    if ($onSite > $new) $onSite = $new;
                    $remote = max(0, $new - $onSite);

                    $estimates[$i]['suggested_days'] = $new;
                    $estimates[$i]['suggested_on_site_days'] = $onSite;
                    $estimates[$i]['suggested_remote_days'] = $remote;
                    $estimates[$i]['rationale'] = trim((string)($estimates[$i]['rationale'] ?? '')) . ' AI (best-effort): ' . $reason . ' (Δ ' . (int)round($delta * 100) . '%).';
                    $appliedAny = true;
                }

                $aiEnrichment['applied'] = $appliedAny;
                foreach ($aiNotes as $n) {
                    $n = trim((string)$n);
                    if ($n !== '') $aiEnrichment['notes'][] = $n;
                }
                $aiEnrichment['notes'] = array_values(array_unique($aiEnrichment['notes']));
                if ($appliedAny) {
                    $integrationNotes[] = 'AI enrichment: applicati aggiustamenti best-effort (±20%) ai giorni suggeriti.';
                }
            }
        } catch (Throwable $e) {
            // non-blocking
        }
    }

    api_success([
        'company_profile_inferred' => [
            'sector' => $sectorOut,
            'employees_range' => $employeesRangeOut,
            'sites_count' => $sitesCountOut,
            'regulated' => $regulatedOut,
            'intervention_type' => $interventionTypeOut,
            'qms_maturity' => $qmsMaturityOut,
            'core_process_count' => $coreProcessCount,
            'departments_or_units_count' => $departmentsCount,
            'product_service_lines_count' => $productLinesCount,
            'critical_suppliers_count' => $criticalSuppliersCount,
            'design_applicability' => $designApplicability,
            'outsourcing_level' => ($outsourcingLevel !== '' ? $outsourcingLevel : null),
            'it_maturity' => ($itMaturity !== '' ? $itMaturity : null),
            'preferred_delivery_deadline' => ($preferredDeliveryDeadline !== '' ? $preferredDeliveryDeadline : null),
            'blackout_periods' => ($blackoutPeriods !== '' ? $blackoutPeriods : null),
            'languages_needed' => $languagesNeeded,
            'on_site_preference_ratio' => $onSitePreferenceRatio,
            'on_site_locations_count' => $onSiteLocationsCountOut,
            'iso14001' => $hasIso14001 ? [
                'environmental_aspects_complexity' => ($envAspectsComplexity !== '' ? $envAspectsComplexity : null),
                'permits_presence' => $permitsPresence,
                'hazardous_substances' => $hazardousSubstances,
            ] : null,
            'food' => $hasFood ? [
                'haccp_studies_count' => $foodHaccpStudiesCount,
                'shifts_count' => $foodShiftsCount,
                'high_risk_products' => $foodHighRiskProducts,
            ] : null,
            'iso17025' => $has17025 ? [
                'methods_count' => $iso17025MethodsCount,
                'sampling_in_scope' => $iso17025SamplingInScope,
                'multi_site_lab' => ($iso17025MultiSiteLab !== '' ? $iso17025MultiSiteLab : null),
            ] : null,
            'ce' => $hasCe ? [
                'notified_body_required' => $ceNotifiedBodyRequired,
            ] : null,
            'gdpr' => $hasGdpr ? [
                'special_categories_data' => $gdprSpecialCategoriesData,
                'extra_eu_transfers' => $gdprExtraEuTransfers,
            ] : null,
            'odv231' => $has231 ? [
                'mog_existing' => $mogExisting,
            ] : null,
            'accred' => $hasAccred ? [
                'regional_requirements_complexity' => ($regionalReqComplexity !== '' ? $regionalReqComplexity : null),
            ] : null,
            'notes' => $notesOut,
            'website' => $websiteOut,
            'confidence' => (float)$confidenceBase,
            'missing_fields' => array_values($missing),
            'sources' => $sources,
        ],
        'estimates' => $estimates,
        'assumptions' => $assumptions,
        'open_questions' => $openQuestions,
        'integration_notes' => array_values(array_filter(array_map('strval', $integrationNotes))),
        'ai_enrichment' => $aiEnrichment,
    ]);
} catch (Throwable $e) {
    error_log('[CONSULTING_ESTIMATE_DAYS] ' . $e->getMessage());
    api_error('Errore stima giornate', 500);
}

