<?php
declare(strict_types=1);

/**
 * Purge tenant "modulo documentale" (Compliance + Knowledge + folders /IMS + /Knowledge).
 *
 * POST /api/tenants/purge_document_module.php
 * Body JSON:
 *   {
 *     "tenant_id": 123,
 *     "confirm_tenant_id": 123,
 *     "confirm_phrase_1": "ELIMINA MODULO DOCUMENTALE",
 *     "confirm_phrase_2": "PURGE-123"
 *   }
 *
 * Notes:
 * - super_admin only
 * - schema-drift safe: skips missing tables/columns, returns warnings instead of 500 when possible
 * - does NOT delete tasks/events themselves (only compliance link tables)
 */

require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/file_helper.php';

initializeApiEnvironment();
verifyApiAuthentication();
verifyApiCsrfToken(true);
requireApiRole('super_admin');

function cnx_log_purge_error($tenantId, Throwable $e): void {
    $tid = is_null($tenantId) ? 'null' : (string)$tenantId;
    error_log(sprintf(
        '[purge_document_module] tenant=%s error=%s at %s:%d stack=%s',
        $tid,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));
}

$db = Database::getInstance();

function cnx_table_exists(Database $db, string $table): bool {
    try {
        $ok = $db->fetchOne(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1",
            [$table]
        );
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_soft_delete_files_under_folder(Database $db, int $tenantId, int $rootFolderId, array &$warnings): array {
    $tenantId = (int)$tenantId;
    $rootFolderId = (int)$rootFolderId;
    $now = date('Y-m-d H:i:s');

    $deletedRows = 0;
    $deletedPhysical = 0;

    if ($tenantId <= 0 || $rootFolderId <= 0) {
        return ['deleted_rows' => 0, 'deleted_physical' => 0];
    }

    // BFS folders + files
    $queue = [$rootFolderId];
    $seen = [];
    $guard = 0;
    $fileRows = [];
    $folderIds = [];

    while (!empty($queue) && $guard < 10000) {
        $guard++;
        $fid = (int)array_shift($queue);
        if ($fid <= 0) continue;
        if (isset($seen[$fid])) continue;
        $seen[$fid] = true;
        $folderIds[] = $fid;

        $rows = $db->fetchAll(
            "SELECT id, is_folder, name, file_path
             FROM files
             WHERE tenant_id = ?
               AND deleted_at IS NULL
               AND folder_id = ?",
            [$tenantId, $fid]
        ) ?: [];

        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) continue;
            $isFolder = (int)($r['is_folder'] ?? 0) ? 1 : 0;
            if ($isFolder) {
                $queue[] = $id;
            } else {
                $fileRows[] = $r;
            }
        }
    }

    // Soft-delete files first (so folder delete doesn't hide them from physical cleanup)
    foreach ($fileRows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id <= 0) continue;

        try {
            $db->update('files', ['deleted_at' => $now, 'updated_at' => $now], ['id' => $id, 'tenant_id' => $tenantId]);
            $deletedRows++;
        } catch (Throwable $e) {
            $warnings[] = "Delete file row failed (#{$id}): " . $e->getMessage();
        }

        $rel = (string)($r['file_path'] ?? '');
        if ($rel !== '') {
            $abs = rtrim(FileHelper::getTenantUploadPath($tenantId), '/\\') . '/' . ltrim($rel, '/\\');
            if (is_file($abs)) {
                if (@unlink($abs)) {
                    $deletedPhysical++;
                }
            }
        }
    }

    // Soft-delete folders (deep to shallow)
    $folderIds = array_values(array_unique(array_filter($folderIds)));
    rsort($folderIds);
    foreach ($folderIds as $fid) {
        try {
            $db->update('files', ['deleted_at' => $now, 'updated_at' => $now], ['id' => $fid, 'tenant_id' => $tenantId]);
            $deletedRows++;
        } catch (Throwable $e) {
            $warnings[] = "Delete folder row failed (#{$fid}): " . $e->getMessage();
        }
    }

    return ['deleted_rows' => $deletedRows, 'deleted_physical' => $deletedPhysical];
}

function cnx_find_root_folder_id(Database $db, int $tenantId): int {
    $row = $db->fetchOne(
        "SELECT id
         FROM files
         WHERE tenant_id = ?
           AND folder_id IS NULL
           AND is_folder = 1
           AND deleted_at IS NULL
         ORDER BY id ASC
         LIMIT 1",
        [$tenantId]
    );
    return $row ? (int)($row['id'] ?? 0) : 0;
}

function cnx_find_child_folder(Database $db, int $tenantId, int $parentId, string $name): int {
    $row = $db->fetchOne(
        "SELECT id
         FROM files
         WHERE tenant_id = ?
           AND folder_id = ?
           AND is_folder = 1
           AND deleted_at IS NULL
           AND name = ?
         LIMIT 1",
        [$tenantId, $parentId, $name]
    );
    return $row ? (int)($row['id'] ?? 0) : 0;
}

try {
    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) apiError('Body JSON non valido', 400);

    $tenantId = (int)($payload['tenant_id'] ?? 0);
    $confirmTenantId = (int)($payload['confirm_tenant_id'] ?? 0);
    $c1 = trim((string)($payload['confirm_phrase_1'] ?? ''));
    $c2 = trim((string)($payload['confirm_phrase_2'] ?? ''));

    if ($tenantId <= 0) apiError('tenant_id richiesto', 400);
    if ($confirmTenantId !== $tenantId) apiError('Conferma tenant_id non valida', 400);
    if (mb_strtoupper($c1, 'UTF-8') !== 'ELIMINA MODULO DOCUMENTALE') apiError('Conferma 1 non valida', 400);
    if ($c2 !== ('PURGE-' . $tenantId)) apiError('Conferma 2 non valida', 400);

    $warnings = [];

    // Protect system tenant (ID 1) by requiring extra confirm
    if ($tenantId === 1) {
        apiError('Operazione non consentita sul tenant di sistema (ID 1)', 403);
    }

    $tenant = $db->fetchOne("SELECT id, denominazione FROM tenants WHERE id = ? AND deleted_at IS NULL LIMIT 1", [$tenantId]);
    if (!$tenant) apiError('Tenant non trovato', 404);

    $counts = [
        'db' => [],
        'files' => [],
    ];

    // 1) DB cleanup (best-effort, schema drift safe)
    try {
        $db->beginTransaction();

        // Gather program_ids for tenant
        $programIds = [];
        if (cnx_table_exists($db, 'compliance_programs')) {
            $rows = $db->fetchAll("SELECT id FROM compliance_programs WHERE tenant_id = ?", [$tenantId]) ?: [];
            $programIds = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $rows)));
        }

        // Artifacts for those programs
        $artifactIds = [];
        if (!empty($programIds) && cnx_table_exists($db, 'compliance_artifacts')) {
            $in = implode(',', array_fill(0, count($programIds), '?'));
            $rows = $db->fetchAll("SELECT id FROM compliance_artifacts WHERE program_id IN ($in)", $programIds) ?: [];
            $artifactIds = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $rows)));
        }

        // Compliance link tables
        if (cnx_table_exists($db, 'compliance_task_links')) {
            $stmt = $db->query("DELETE FROM compliance_task_links WHERE tenant_id = ?", [$tenantId]);
            try {
                $counts['db']['compliance_task_links_deleted'] = is_object($stmt) && method_exists($stmt, 'rowCount') ? $stmt->rowCount() : null;
            } catch (Throwable $e) {
                $counts['db']['compliance_task_links_deleted'] = null;
            }
        }
        if (cnx_table_exists($db, 'compliance_event_links')) {
            $stmt = $db->query("DELETE FROM compliance_event_links WHERE tenant_id = ?", [$tenantId]);
            try {
                $counts['db']['compliance_event_links_deleted'] = is_object($stmt) && method_exists($stmt, 'rowCount') ? $stmt->rowCount() : null;
            } catch (Throwable $e) {
                $counts['db']['compliance_event_links_deleted'] = null;
            }
        }

        if (cnx_table_exists($db, 'compliance_file_versions')) {
            $db->query("DELETE FROM compliance_file_versions WHERE tenant_id = ?", [$tenantId]);
        }
        if (cnx_table_exists($db, 'compliance_artifact_inputs')) {
            $db->query("DELETE FROM compliance_artifact_inputs WHERE tenant_id = ?", [$tenantId]);
        }
        if (cnx_table_exists($db, 'compliance_program_profiles')) {
            $db->query("DELETE FROM compliance_program_profiles WHERE tenant_id = ?", [$tenantId]);
        }

        if (!empty($artifactIds) && cnx_table_exists($db, 'compliance_artifacts')) {
            $in = implode(',', array_fill(0, count($artifactIds), '?'));
            $db->query("DELETE FROM compliance_artifacts WHERE id IN ($in)", $artifactIds);
        }
        if (!empty($programIds) && cnx_table_exists($db, 'compliance_provisioning_runs')) {
            $in = implode(',', array_fill(0, count($programIds), '?'));
            $db->query("DELETE FROM compliance_provisioning_runs WHERE program_id IN ($in)", $programIds);
        }
        if (!empty($programIds) && cnx_table_exists($db, 'compliance_program_standards')) {
            $in = implode(',', array_fill(0, count($programIds), '?'));
            $db->query("DELETE FROM compliance_program_standards WHERE program_id IN ($in)", $programIds);
        }
        if (cnx_table_exists($db, 'compliance_programs')) {
            $db->query("DELETE FROM compliance_programs WHERE tenant_id = ?", [$tenantId]);
        }

        // AI knowledge tables (migration 48)
        if (cnx_table_exists($db, 'ai_knowledge_chunks')) {
            $db->query("DELETE FROM ai_knowledge_chunks WHERE tenant_id = ?", [$tenantId]);
        }
        if (cnx_table_exists($db, 'ai_knowledge_index_state')) {
            $db->query("DELETE FROM ai_knowledge_index_state WHERE tenant_id = ?", [$tenantId]);
        }
        if (cnx_table_exists($db, 'ai_knowledge_sources')) {
            $db->query("DELETE FROM ai_knowledge_sources WHERE tenant_id = ?", [$tenantId]);
        }

        $db->commit();
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $_) {}
        cnx_log_purge_error($tenantId, $e);
        apiError('Purge DB fallito: ' . $e->getMessage(), 500);
    }

    // 2) Files cleanup: /IMS and /Knowledge folders (best-effort)
    try {
        $rootId = cnx_find_root_folder_id($db, $tenantId);
        if ($rootId > 0 && cnx_table_exists($db, 'files')) {
            $imsId = cnx_find_child_folder($db, $tenantId, $rootId, 'IMS');
            $knId = cnx_find_child_folder($db, $tenantId, $rootId, 'Knowledge');

            if ($imsId > 0) {
                $res = cnx_soft_delete_files_under_folder($db, $tenantId, $imsId, $warnings);
                $counts['files']['ims_deleted_rows'] = $res['deleted_rows'] ?? 0;
                $counts['files']['ims_deleted_physical'] = $res['deleted_physical'] ?? 0;
            }
            if ($knId > 0) {
                $res = cnx_soft_delete_files_under_folder($db, $tenantId, $knId, $warnings);
                $counts['files']['knowledge_deleted_rows'] = $res['deleted_rows'] ?? 0;
                $counts['files']['knowledge_deleted_physical'] = $res['deleted_physical'] ?? 0;
            }
        }
    } catch (Throwable $e) {
        $warnings[] = 'Files purge fallito: ' . $e->getMessage();
    }

    // 3) Clear tenant logo reference (best-effort) so header won't keep trying to embed deleted logo
    try {
        $hasLogoCol = $db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tenants'
               AND COLUMN_NAME = 'logo_file_id'
             LIMIT 1"
        );
        if ($hasLogoCol) {
            $db->update('tenants', ['logo_file_id' => null], ['id' => $tenantId]);
        }
    } catch (Throwable $e) {
        // ignore
    }

    apiSuccess([
        'tenant_id' => $tenantId,
        'tenant_name' => (string)($tenant['denominazione'] ?? ''),
        'counts' => $counts,
        'warnings' => array_slice(array_values(array_unique(array_filter($warnings))), 0, 50),
    ], 'Purge modulo documentale completato');
} catch (Throwable $e) {
    cnx_log_purge_error($tenantId ?? null, $e);
    apiError('Errore interno purge: ' . $e->getMessage(), 500);
}

