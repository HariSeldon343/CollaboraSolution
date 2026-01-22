<?php
/**
 * Leva 4: Requirements coverage API (ISO 9001 pilot)
 *
 * GET /api/compliance/coverage.php?program_id=...
 *
 * Output:
 * - totals (total_requirements, covered, uncovered)
 * - covered[]: {clause, covered_by:[artifact_id,...]}
 * - missing[]: {clause}
 *
 * Notes:
 * - Uses requirements catalog DB if present; fallback if missing.
 * - No ISO standard text; only operational summaries from catalog.
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/compliance/iso9001_requirements_fallback.php';

// Storage check (schema drift safe)
$chk = cnx_compliance_check_tables($db, ['compliance_programs', 'compliance_artifacts']);
if (!$chk['ok']) {
    api_success([
        'storage_available' => false,
        'coverage' => null,
        'missing' => $chk['missing'] ?? [],
        'migration' => $chk['migration'] ?? 'database/migrations/41_compliance_programs.sql',
    ]);
}

$programId = (int)($_GET['program_id'] ?? 0);
if ($programId <= 0) {
    api_error('program_id richiesto', 400);
}

try {
    $program = $db->fetchOne(
        "SELECT id, tenant_id, standard_code, standard_edition
         FROM compliance_programs
         WHERE id = ?
         LIMIT 1",
        [$programId]
    );
    if (!$program) api_error('Programma non trovato', 404);
    $tenantId = (int)($program['tenant_id'] ?? 0);
    if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $tenantId)) {
        api_error('Accesso negato al tenant del programma', 403);
    }

    $hasProgStd = false;
    try { $hasProgStd = (bool)$db->fetchOne("SHOW TABLES LIKE 'compliance_program_standards'"); } catch (Throwable $e) { $hasProgStd = false; }
    $allStandards = [];
    if ($hasProgStd) {
        try {
            $srows = $db->fetchAll(
                "SELECT standard_code, edition_label
                 FROM compliance_program_standards
                 WHERE program_id = ?
                 ORDER BY standard_code ASC",
                [$programId]
            ) ?: [];
            foreach ($srows as $sr) {
                $c = (string)($sr['standard_code'] ?? '');
                if ($c === '') continue;
                $allStandards[] = ['standard_code' => $c, 'standard_edition' => (string)($sr['edition_label'] ?? '')];
            }
        } catch (Throwable $e) {}
    }
    if (empty($allStandards)) {
        $allStandards[] = [
            'standard_code' => (string)($program['standard_code'] ?? 'ISO9001'),
            'standard_edition' => (string)($program['standard_edition'] ?? ''),
        ];
    }

    $filterStandard = strtoupper(trim((string)($_GET['standard_code'] ?? '')));
    if ($filterStandard !== '') {
        $allStandards = array_values(array_filter($allStandards, static fn($s) => (string)$s['standard_code'] === $filterStandard));
        if (empty($allStandards)) api_error('Standard non presente nel programma', 400);
    }

    // Load artifacts once and build per-standard clause->artifact mapping
    $artifactRows = $db->fetchAll(
        "SELECT id, clause_refs_json, title, status, file_id, folder_id
         FROM compliance_artifacts
         WHERE program_id = ?",
        [$programId]
    ) ?: [];
    $byStandard = [];
    foreach ($allStandards as $s) {
        $code = (string)($s['standard_code'] ?? '');
        if ($code === '') continue;

        // Load requirements catalog (db if available else fallback) — currently only ISO9001 supported.
        $requirements = [];
        $catalogSource = 'none';
        $storageAvailableCatalog = false;
        $reqStandard = '';
        $reqEdition = '';

        if ($code === 'ISO9001') {
            $reqStandard = 'ISO 9001';
            $reqEdition = '2015+Amd1:2024';
            try { $storageAvailableCatalog = (bool)$db->fetchOne("SHOW TABLES LIKE 'compliance_requirements_catalog'"); } catch (Throwable $e) { $storageAvailableCatalog = false; }
            if ($storageAvailableCatalog) {
                $rows = $db->fetchAll(
                    "SELECT clause, section, title, intent_summary, sort_order
                     FROM compliance_requirements_catalog
                     WHERE standard_code = ?
                       AND edition = ?
                       AND is_active = 1
                     ORDER BY sort_order ASC, clause ASC",
                    [$reqStandard, $reqEdition]
                ) ?: [];
                foreach ($rows as $r) {
                    $requirements[] = [
                        'clause' => (string)($r['clause'] ?? ''),
                        'section' => (string)($r['section'] ?? ''),
                        'title' => (string)($r['title'] ?? ''),
                        'intent_summary' => (string)($r['intent_summary'] ?? ''),
                        'sort_order' => (int)($r['sort_order'] ?? 0),
                    ];
                }
                if (!empty($requirements)) $catalogSource = 'db';
            }
            if (empty($requirements)) {
                $fb = cnx_get_iso9001_requirements_fallback();
                foreach (($fb['requirements'] ?? []) as $r) {
                    if (!is_array($r)) continue;
                    $requirements[] = [
                        'clause' => (string)($r['clause'] ?? ''),
                        'section' => (string)($r['section'] ?? ''),
                        'title' => (string)($r['title'] ?? ''),
                        'intent_summary' => (string)($r['intent_summary'] ?? ''),
                        'sort_order' => (int)($r['sort_order'] ?? 0),
                    ];
                }
                $catalogSource = 'fallback';
                $storageAvailableCatalog = false;
            }
        } else {
            // Not yet supported: return "catalog_storage_available=false" but still compute coverage on artifacts refs if possible.
            $catalogSource = 'none';
            $storageAvailableCatalog = false;
        }

        // Build clause->artifact mapping for this standard
        // Strategy for "se applicabile"/N-A:
        // - If at least one artifact covers the clause with status != 'obsolete' => covered
        // - Else if at least one artifact covers the clause with status == 'obsolete' => not applicable (excluded from denominator and missing list)
        // - Else => missing
        $clauseToCoveredArtifacts = [];
        $clauseToNaArtifacts = [];
        foreach ($artifactRows as $a) {
            $aid = (int)($a['id'] ?? 0);
            if ($aid <= 0) continue;
            $isNa = strtolower(trim((string)($a['status'] ?? ''))) === 'obsolete';
            $refs = null;
            try { $refs = $a['clause_refs_json'] ? (json_decode((string)$a['clause_refs_json'], true) ?: null) : null; } catch (Throwable $e) { $refs = null; }
            $list = [];
            if (is_array($refs)) {
                $isAssoc = array_keys($refs) !== range(0, count($refs) - 1);
                if ($isAssoc) {
                    $list = (isset($refs[$code]) && is_array($refs[$code])) ? $refs[$code] : [];
                } else {
                    // legacy: treat as ISO9001 only
                    $list = ($code === 'ISO9001') ? $refs : [];
                }
            }
            foreach ($list as $c) {
                $cl = trim((string)$c);
                if ($cl === '') continue;
                if ($isNa) {
                    if (!isset($clauseToNaArtifacts[$cl])) $clauseToNaArtifacts[$cl] = [];
                    $clauseToNaArtifacts[$cl][] = $aid;
                } else {
                    if (!isset($clauseToCoveredArtifacts[$cl])) $clauseToCoveredArtifacts[$cl] = [];
                    $clauseToCoveredArtifacts[$cl][] = $aid;
                }
            }
        }
        foreach ($clauseToCoveredArtifacts as $k => $arr) {
            $clauseToCoveredArtifacts[$k] = array_values(array_unique($arr));
            sort($clauseToCoveredArtifacts[$k]);
        }
        foreach ($clauseToNaArtifacts as $k => $arr) {
            $clauseToNaArtifacts[$k] = array_values(array_unique($arr));
            sort($clauseToNaArtifacts[$k]);
        }

        $covered = [];
        $missing = [];
        $notApplicable = [];
        if (!empty($requirements)) {
            foreach ($requirements as $r) {
                $cl = trim((string)($r['clause'] ?? ''));
                if ($cl === '') continue;
                $arts = $clauseToCoveredArtifacts[$cl] ?? [];
                $naArts = $clauseToNaArtifacts[$cl] ?? [];
                if (!empty($arts)) $covered[] = ['clause' => $cl, 'covered_by' => $arts, 'requirement' => $r];
                elseif (!empty($naArts)) $notApplicable[] = ['clause' => $cl, 'not_applicable_by' => $naArts, 'requirement' => $r];
                else $missing[] = ['clause' => $cl, 'requirement' => $r];
            }
        }

        $total = count($requirements);
        $coveredCount = count($covered);
        $uncoveredCount = count($missing);
        $naCount = count($notApplicable);
        $effectiveTotal = max(0, $total - $naCount);
        $byStandard[] = [
            'standard_code' => $code,
            'standard_edition' => (string)($s['standard_edition'] ?? ''),
            'meta' => [
                'requirements_standard' => $reqStandard,
                'requirements_edition' => $reqEdition,
                'catalog_source' => $catalogSource,
                'catalog_storage_available' => $storageAvailableCatalog,
            ],
            'totals' => [
                'total_requirements' => $total,
                'covered' => $coveredCount,
                'uncovered' => $uncoveredCount,
                'not_applicable' => $naCount,
                'effective_total' => $effectiveTotal,
                'coverage_percent' => $effectiveTotal > 0 ? round(($coveredCount / $effectiveTotal) * 100, 2) : ($total > 0 ? 100 : 0),
            ],
            'covered' => $covered,
            'missing' => $missing,
            'not_applicable' => $notApplicable,
        ];
    }

    api_success([
        'storage_available' => true,
        'program_id' => $programId,
        'tenant_id' => $tenantId,
        'by_standard' => $byStandard,
    ]);
} catch (Throwable $e) {
    error_log('[COMPLIANCE_COVERAGE] ' . $e->getMessage());
    api_error('Errore coverage compliance', 500, defined('DEBUG_MODE') && DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
}

