<?php
/**
 * Leva 4: Compliance programs API
 *
 * GET /api/compliance/programs.php?action=list&tenant_id=...
 * GET /api/compliance/programs.php?action=detail&program_id=...
 *
 * Notes:
 * - Accessible to client-tenant users and consultants with user_tenant_access.
 * - Schema drift safe: if compliance tables missing -> storage_available=false (no 500).
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

$action = (string)($_GET['action'] ?? 'list');

function cnx_table_exists(Database $db, string $table): bool {
    try {
        $has = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1",
            [$table]
        );
        return (bool)$has;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Enforce "single program per tenant" by merging duplicates into one canonical program.
 * Best-effort + schema-drift safe (moves data if tables exist, otherwise just hides duplicates).
 *
 * @return array{changed:bool,canonical_id:int,merged_ids:int[],warnings:string[]}
 */
function cnx_compliance_dedupe_programs_singleton(Database $db, int $tenantId): array {
    $tenantId = (int)$tenantId;
    $warnings = [];
    if ($tenantId <= 0) return ['changed' => false, 'canonical_id' => 0, 'merged_ids' => [], 'warnings' => []];

    $rows = $db->fetchAll(
        "SELECT id, status, updated_at, created_at
         FROM compliance_programs
         WHERE tenant_id = ?
         ORDER BY updated_at DESC, id DESC",
        [$tenantId]
    ) ?: [];
    if (count($rows) <= 1) {
        $only = $rows ? (int)($rows[0]['id'] ?? 0) : 0;
        return ['changed' => false, 'canonical_id' => $only, 'merged_ids' => [], 'warnings' => []];
    }

    // Pick canonical: prefer the program with most artifacts; fallback to status+updated_at.
    $ids = array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $rows), static fn($v) => $v > 0));
    if (!$ids) return ['changed' => false, 'canonical_id' => 0, 'merged_ids' => [], 'warnings' => []];

    $counts = [];
    if (cnx_table_exists($db, 'compliance_artifacts')) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $crows = $db->fetchAll(
            "SELECT program_id, COUNT(*) AS cnt
             FROM compliance_artifacts
             WHERE program_id IN ($in)
             GROUP BY program_id",
            $ids
        ) ?: [];
        foreach ($crows as $cr) {
            $pid = (int)($cr['program_id'] ?? 0);
            if ($pid > 0) $counts[$pid] = (int)($cr['cnt'] ?? 0);
        }
    }

    $statusPri = ['active' => 3, 'draft' => 2, 'archived' => 1];
    usort($rows, static function ($a, $b) use ($counts, $statusPri) {
        $ai = (int)($a['id'] ?? 0);
        $bi = (int)($b['id'] ?? 0);
        $ac = (int)($counts[$ai] ?? 0);
        $bc = (int)($counts[$bi] ?? 0);
        if ($ac !== $bc) return $bc <=> $ac;
        $as = (string)($a['status'] ?? '');
        $bs = (string)($b['status'] ?? '');
        $ap = (int)($statusPri[$as] ?? 0);
        $bp = (int)($statusPri[$bs] ?? 0);
        if ($ap !== $bp) return $bp <=> $ap;
        $au = (string)($a['updated_at'] ?? '');
        $bu = (string)($b['updated_at'] ?? '');
        if ($au !== $bu) return $bu <=> $au;
        return $bi <=> $ai;
    });

    $canonicalId = (int)($rows[0]['id'] ?? 0);
    if ($canonicalId <= 0) return ['changed' => false, 'canonical_id' => 0, 'merged_ids' => [], 'warnings' => []];
    $others = array_values(array_filter($ids, static fn($v) => $v > 0 && $v !== $canonicalId));
    if (!$others) return ['changed' => false, 'canonical_id' => $canonicalId, 'merged_ids' => [], 'warnings' => []];

    $hasArtifacts = cnx_table_exists($db, 'compliance_artifacts');
    $hasInputs = cnx_table_exists($db, 'compliance_artifact_inputs');
    $hasProfiles = cnx_table_exists($db, 'compliance_program_profiles');
    $hasRuns = cnx_table_exists($db, 'compliance_provisioning_runs');
    $hasProgStd = cnx_table_exists($db, 'compliance_program_standards');
    $hasTaskLinks = cnx_table_exists($db, 'compliance_task_links');
    $hasEventLinks = cnx_table_exists($db, 'compliance_event_links');

    $db->beginTransaction();
    try {
        foreach ($others as $pid) {
            // Program standards: merge unique standards into canonical
            if ($hasProgStd) {
                try {
                    $db->query(
                        "INSERT IGNORE INTO compliance_program_standards (program_id, standard_code, edition_label, created_at)
                         SELECT ?, standard_code, edition_label, created_at
                         FROM compliance_program_standards
                         WHERE program_id = ?",
                        [$canonicalId, $pid]
                    );
                    $db->query("DELETE FROM compliance_program_standards WHERE program_id = ?", [$pid]);
                } catch (Throwable $e) {
                    $warnings[] = 'Merge standards fallito per program_id=' . $pid;
                }
            }

            // Program profiles: keep canonical if present; otherwise move best-effort
            if ($hasProfiles) {
                try {
                    $cProf = $db->fetchOne("SELECT id, profile_json, updated_at FROM compliance_program_profiles WHERE tenant_id = ? AND program_id = ? LIMIT 1", [$tenantId, $canonicalId]);
                    $oProf = $db->fetchOne("SELECT id, profile_json, updated_at FROM compliance_program_profiles WHERE tenant_id = ? AND program_id = ? LIMIT 1", [$tenantId, $pid]);
                    if ($oProf) {
                        $cJson = $cProf ? trim((string)($cProf['profile_json'] ?? '')) : '';
                        $oJson = trim((string)($oProf['profile_json'] ?? ''));
                        if (!$cProf) {
                            // Move profile row to canonical program_id
                            $db->update('compliance_program_profiles', [
                                'program_id' => $canonicalId,
                                'updated_at' => date('Y-m-d H:i:s'),
                            ], ['id' => (int)$oProf['id']]);
                        } else {
                            // If canonical empty, take other; then delete other
                            if (($cJson === '' || $cJson === 'null') && ($oJson !== '' && $oJson !== 'null')) {
                                $db->update('compliance_program_profiles', [
                                    'profile_json' => $oProf['profile_json'],
                                    'updated_at' => date('Y-m-d H:i:s'),
                                ], ['id' => (int)$cProf['id']]);
                            }
                            $db->query("DELETE FROM compliance_program_profiles WHERE id = ?", [(int)$oProf['id']]);
                        }
                    }
                } catch (Throwable $e) {
                    $warnings[] = 'Merge profilo fallito per program_id=' . $pid;
                }
            }

            // Provisioning runs: move to canonical
            if ($hasRuns) {
                try {
                    $db->query("UPDATE compliance_provisioning_runs SET program_id = ? WHERE program_id = ?", [$canonicalId, $pid]);
                } catch (Throwable $e) {
                    $warnings[] = 'Merge runs fallito per program_id=' . $pid;
                }
            }

            // Event links: merge idempotently (unique by program_id+event_key)
            if ($hasEventLinks) {
                try {
                    $db->query(
                        "INSERT IGNORE INTO compliance_event_links (program_id, tenant_id, event_id, event_key, created_at)
                         SELECT ?, tenant_id, event_id, event_key, created_at
                         FROM compliance_event_links
                         WHERE program_id = ?",
                        [$canonicalId, $pid]
                    );
                    $db->query("DELETE FROM compliance_event_links WHERE program_id = ?", [$pid]);
                } catch (Throwable $e) {
                    $warnings[] = 'Merge eventi fallito per program_id=' . $pid;
                }
            }

            // Artifacts: move non-conflicting; resolve conflicts by keeping canonical and deleting duplicates (after migrating links/inputs)
            if ($hasArtifacts) {
                $arts = $db->fetchAll(
                    "SELECT id, artifact_key, file_id, folder_id, updated_at
                     FROM compliance_artifacts
                     WHERE program_id = ?
                     ORDER BY id ASC",
                    [$pid]
                ) ?: [];

                foreach ($arts as $a) {
                    $aid = (int)($a['id'] ?? 0);
                    $akey = trim((string)($a['artifact_key'] ?? ''));
                    if ($aid <= 0 || $akey === '') continue;

                    $existing = $db->fetchOne(
                        "SELECT id, file_id, folder_id, updated_at
                         FROM compliance_artifacts
                         WHERE program_id = ? AND artifact_key = ?
                         LIMIT 1",
                        [$canonicalId, $akey]
                    );

                    if (!$existing) {
                        // Simple move
                        $db->update('compliance_artifacts', [
                            'program_id' => $canonicalId,
                            'updated_at' => date('Y-m-d H:i:s'),
                        ], ['id' => $aid]);
                        if ($hasInputs) {
                            // keep inputs consistent (best-effort; not required for reads)
                            try { $db->query("UPDATE compliance_artifact_inputs SET program_id = ? WHERE artifact_id = ?", [$canonicalId, $aid]); } catch (Throwable $e) {}
                        }
                        continue;
                    }

                    $keepId = (int)($existing['id'] ?? 0);
                    if ($keepId <= 0) continue;

                    // If canonical artifact misses file/folder, copy from duplicate
                    $keepFile = (int)($existing['file_id'] ?? 0);
                    $dupFile = (int)($a['file_id'] ?? 0);
                    $keepFolder = (int)($existing['folder_id'] ?? 0);
                    $dupFolder = (int)($a['folder_id'] ?? 0);
                    if (($keepFile <= 0 && $dupFile > 0) || ($keepFolder <= 0 && $dupFolder > 0)) {
                        $upd = ['updated_at' => date('Y-m-d H:i:s')];
                        if ($keepFile <= 0 && $dupFile > 0) $upd['file_id'] = $dupFile;
                        if ($keepFolder <= 0 && $dupFolder > 0) $upd['folder_id'] = $dupFolder;
                        try { $db->update('compliance_artifacts', $upd, ['id' => $keepId]); } catch (Throwable $e) {}
                    }

                    // Move task links to canonical artifact (best-effort)
                    if ($hasTaskLinks) {
                        try {
                            $db->query(
                                "INSERT IGNORE INTO compliance_task_links (artifact_id, tenant_id, task_id, task_type, created_at)
                                 SELECT ?, tenant_id, task_id, task_type, created_at
                                 FROM compliance_task_links
                                 WHERE artifact_id = ?",
                                [$keepId, $aid]
                            );
                            $db->query("DELETE FROM compliance_task_links WHERE artifact_id = ?", [$aid]);
                        } catch (Throwable $e) {}
                    }

                    // Move/merge inputs (best-effort)
                    if ($hasInputs) {
                        try {
                            $oIn = $db->fetchOne("SELECT id, input_json, updated_at FROM compliance_artifact_inputs WHERE tenant_id = ? AND artifact_id = ? LIMIT 1", [$tenantId, $aid]);
                            $cIn = $db->fetchOne("SELECT id, input_json, updated_at FROM compliance_artifact_inputs WHERE tenant_id = ? AND artifact_id = ? LIMIT 1", [$tenantId, $keepId]);
                            if ($oIn) {
                                $oJson = trim((string)($oIn['input_json'] ?? ''));
                                $cJson = $cIn ? trim((string)($cIn['input_json'] ?? '')) : '';
                                if (!$cIn) {
                                    $db->update('compliance_artifact_inputs', [
                                        'artifact_id' => $keepId,
                                        'program_id' => $canonicalId,
                                        'updated_at' => date('Y-m-d H:i:s'),
                                    ], ['id' => (int)$oIn['id']]);
                                } else {
                                    // Prefer non-empty; if both non-empty keep canonical to avoid surprises
                                    if (($cJson === '' || $cJson === 'null') && ($oJson !== '' && $oJson !== 'null')) {
                                        $db->update('compliance_artifact_inputs', [
                                            'input_json' => $oIn['input_json'],
                                            'updated_at' => date('Y-m-d H:i:s'),
                                        ], ['id' => (int)$cIn['id']]);
                                    }
                                    $db->query("DELETE FROM compliance_artifact_inputs WHERE id = ?", [(int)$oIn['id']]);
                                }
                            }
                        } catch (Throwable $e) {}
                    }

                    // Finally remove duplicate artifact row
                    try { $db->query("DELETE FROM compliance_artifacts WHERE id = ?", [$aid]); } catch (Throwable $e) {}
                }
            }

            // Archive merged program so UI shows only one program per tenant
            try {
                $db->update('compliance_programs', [
                    'status' => 'archived',
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $pid]);
            } catch (Throwable $e) {
                $warnings[] = 'Archiviazione programma fallita (id=' . $pid . ')';
            }
        }

        $db->commit();
    } catch (Throwable $tx) {
        $db->rollback();
        throw $tx;
    }

    return ['changed' => true, 'canonical_id' => $canonicalId, 'merged_ids' => $others, 'warnings' => $warnings];
}

// Storage check (schema drift safe)
$chk = cnx_compliance_check_tables($db, ['compliance_programs']);
if (!$chk['ok']) {
    api_success([
        'storage_available' => false,
        'programs' => [],
        'missing' => $chk['missing'] ?? [],
        'migration' => $chk['migration'] ?? 'database/migrations/41_compliance_programs.sql',
    ]);
}

try {
    if ($action === 'list') {
        $tenantId = cnx_compliance_resolve_target_tenant_id($userInfo);
        if ($tenantId <= 0) api_error('Tenant non valido', 400);
        if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $tenantId)) {
            api_error('Accesso negato al tenant richiesto', 403);
        }

        // Enforce singleton program per tenant (merge duplicates best-effort)
        $dedup = null;
        try {
            $dedup = cnx_compliance_dedupe_programs_singleton($db, $tenantId);
        } catch (Throwable $e) {
            // non-blocking: still list programs
            $dedup = ['changed' => false, 'canonical_id' => 0, 'merged_ids' => [], 'warnings' => ['dedupe_failed']];
        }

        $rows = $db->fetchAll(
            "SELECT id, tenant_id, standard_code, standard_edition, status, updated_at, created_at
             FROM compliance_programs
             WHERE tenant_id = ?
               AND (status IS NULL OR status <> 'archived')
             ORDER BY updated_at DESC, id DESC",
            [$tenantId]
        ) ?: [];

        $hasProgStd = false;
        try { $hasProgStd = (bool)$db->fetchOne("SHOW TABLES LIKE 'compliance_program_standards'"); } catch (Throwable $e) { $hasProgStd = false; }
        $standardsByProgram = [];
        if ($hasProgStd && !empty($rows)) {
            $ids = array_map(static fn($r) => (int)$r['id'], $rows);
            $ids = array_values(array_filter($ids, static fn($v) => $v > 0));
            if (!empty($ids)) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $srows = $db->fetchAll(
                    "SELECT program_id, standard_code, edition_label
                     FROM compliance_program_standards
                     WHERE program_id IN ($in)
                     ORDER BY program_id ASC, standard_code ASC",
                    $ids
                ) ?: [];
                foreach ($srows as $sr) {
                    $pid = (int)($sr['program_id'] ?? 0);
                    if ($pid <= 0) continue;
                    if (!isset($standardsByProgram[$pid])) $standardsByProgram[$pid] = [];
                    $standardsByProgram[$pid][] = [
                        'code' => (string)($sr['standard_code'] ?? ''),
                        'edition_label' => (string)($sr['edition_label'] ?? ''),
                    ];
                }
            }
        }

        api_success([
            'storage_available' => true,
            'tenant_id' => $tenantId,
            'dedup' => $dedup,
            'programs' => array_map(static fn($r) => [
                'id' => (int)$r['id'],
                'tenant_id' => (int)$r['tenant_id'],
                'standard_code' => (string)$r['standard_code'],
                'standard_edition' => (string)$r['standard_edition'],
                'program_standards' => $standardsByProgram[(int)$r['id']] ?? [],
                'status' => (string)$r['status'],
                'created_at' => (string)($r['created_at'] ?? ''),
                'updated_at' => (string)($r['updated_at'] ?? ''),
            ], $rows),
        ]);
    }

    if ($action === 'detail') {
        $programId = (int)($_GET['program_id'] ?? 0);
        if ($programId <= 0) api_error('program_id richiesto', 400);

        $row = $db->fetchOne(
            "SELECT *
             FROM compliance_programs
             WHERE id = ?
             LIMIT 1",
            [$programId]
        );
        if (!$row) api_error('Programma non trovato', 404);

        $tenantId = (int)($row['tenant_id'] ?? 0);
        if (!cnx_compliance_user_has_access_to_tenant($db, $userInfo, $tenantId)) {
            api_error('Accesso negato al tenant del programma', 403);
        }

        $hasProgStd = false;
        try { $hasProgStd = (bool)$db->fetchOne("SHOW TABLES LIKE 'compliance_program_standards'"); } catch (Throwable $e) { $hasProgStd = false; }
        $progStandards = [];
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
                    $progStandards[] = [
                        'code' => (string)($sr['standard_code'] ?? ''),
                        'edition_label' => (string)($sr['edition_label'] ?? ''),
                    ];
                }
            } catch (Throwable $e) {}
        }

        api_success([
            'storage_available' => true,
            'program' => [
                'id' => (int)$row['id'],
                'tenant_id' => (int)$row['tenant_id'],
                'standard_code' => (string)$row['standard_code'],
                'standard_edition' => (string)$row['standard_edition'],
                'program_standards' => $progStandards,
                'scope_text' => (string)($row['scope_text'] ?? ''),
                'sector_text' => (string)($row['sector_text'] ?? ''),
                'size_text' => (string)($row['size_text'] ?? ''),
                'source_consulting_plan_id' => (int)($row['source_consulting_plan_id'] ?? 0),
                'created_by_user_id' => (int)($row['created_by_user_id'] ?? 0),
                'status' => (string)($row['status'] ?? 'draft'),
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ],
        ]);
    }

    api_error('Azione non valida', 400);
} catch (Throwable $e) {
    error_log('[COMPLIANCE_PROGRAMS] ' . $e->getMessage());
    api_error('Errore compliance programs', 500, defined('DEBUG_MODE') && DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
}

