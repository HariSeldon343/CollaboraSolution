<?php
declare(strict_types=1);

/**
 * AI Knowledge: indexing endpoint
 *
 * POST /api/ai/knowledge_index.php?action=run
 * Payload JSON: { tenant_id?, folder_name? }
 *
 * Notes:
 * - Heavy-ish operation; requires role manager/admin/super_admin.
 * - Best-effort indexing; returns warnings and counts.
 */

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/file_helper.php';
require_once __DIR__ . '/../../includes/ai/knowledge_indexer.php';

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));
if ($action === '') api_error('action richiesto', 400);

cnx_ai_require_csrf_for_write();
requireApiRole('manager');

$chk = cnx_ai_check_tables($db, ['ai_knowledge_sources', 'ai_knowledge_chunks', 'ai_knowledge_index_state'], 'database/migrations/48_ai_knowledge_index.sql');
if (!$chk['ok']) {
    api_success([
        'storage_available' => false,
        'missing' => $chk['missing'] ?? [],
        'migration' => $chk['migration'] ?? 'database/migrations/48_ai_knowledge_index.sql',
    ]);
}

if ($action === 'status') {
    $tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : cnx_ai_resolve_target_tenant_id($userInfo);
    if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
    if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato al tenant', 403);

    // List available knowledge folders (top-level under tenant root)
    $available = [];
    $recommendedFolderName = '';
    try {
        $root = $db->fetchOne(
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
        $rootId = $root ? (int)($root['id'] ?? 0) : 0;
        if ($rootId > 0) {
            foreach (['Knowledge', 'IMS'] as $name) {
                $row = $db->fetchOne(
                    "SELECT id, name
                     FROM files
                     WHERE tenant_id = ?
                       AND folder_id = ?
                       AND is_folder = 1
                       AND deleted_at IS NULL
                       AND name = ?
                     LIMIT 1",
                    [$tenantId, $rootId, $name]
                );
                if ($row && !empty($row['id'])) {
                    $available[] = [
                        'folder_id' => (int)$row['id'],
                        'folder_name' => (string)($row['name'] ?? $name),
                    ];
                }
            }
        }
        foreach ($available as $f) {
            if (strcasecmp((string)($f['folder_name'] ?? ''), 'Knowledge') === 0) {
                $recommendedFolderName = 'Knowledge';
                break;
            }
        }
        if ($recommendedFolderName === '' && !empty($available[0]['folder_name'])) {
            $recommendedFolderName = (string)$available[0]['folder_name'];
        }
    } catch (Throwable $e) {
        $available = [];
        $recommendedFolderName = '';
    }

    $source = $db->fetchOne(
        "SELECT id, folder_id, folder_label
         FROM ai_knowledge_sources
         WHERE tenant_id = ? AND is_active = 1
         ORDER BY id DESC
         LIMIT 1",
        [$tenantId]
    );

    $sourceId = $source ? (int)($source['id'] ?? 0) : 0;
    $folderId = $source ? (int)($source['folder_id'] ?? 0) : 0;
    $folderLabel = $source ? (string)($source['folder_label'] ?? '') : '';

    $state = null;
    if ($sourceId > 0) {
        $state = $db->fetchOne(
            "SELECT last_indexed_at, last_file_count, last_error, updated_by, updated_at
             FROM ai_knowledge_index_state
             WHERE tenant_id = ? AND source_id = ?
             LIMIT 1",
            [$tenantId, $sourceId]
        );
    }

    $counts = [
        'files' => 0,
        'chunks' => 0,
    ];
    if ($sourceId > 0) {
        $c = $db->fetchOne(
            "SELECT COUNT(DISTINCT file_id) AS files, COUNT(*) AS chunks
             FROM ai_knowledge_chunks
             WHERE tenant_id = ? AND source_id = ?",
            [$tenantId, $sourceId]
        );
        if ($c) {
            $counts['files'] = (int)($c['files'] ?? 0);
            $counts['chunks'] = (int)($c['chunks'] ?? 0);
        }
    }

    api_success([
        'storage_available' => true,
        'tenant_id' => $tenantId,
        'available_folders' => $available,
        'recommended_folder_name' => $recommendedFolderName,
        'configured' => $sourceId > 0,
        'source_id' => $sourceId,
        'folder_id' => $folderId,
        'folder_name' => $folderLabel,
        'last_indexed_at' => $state ? (string)($state['last_indexed_at'] ?? '') : '',
        'last_file_count' => $state ? (int)($state['last_file_count'] ?? 0) : 0,
        'last_error' => $state ? (string)($state['last_error'] ?? '') : '',
        'counts' => $counts,
    ]);
}

/**
 * Resolve (or pick) the knowledge folder.
 * We default to a top-level folder named "Knowledge"; if missing, we try "IMS".
 *
 * @return array{ok:bool,folder_id?:int,folder_name?:string,error?:string}
 */
function cnx_ai_resolve_knowledge_folder(Database $db, int $tenantId, string $folderName = ''): array {
    $folderName = trim($folderName);
    $candidates = [];
    if ($folderName !== '') $candidates[] = $folderName;
    $candidates[] = 'Knowledge';
    $candidates[] = 'IMS';

    $root = $db->fetchOne(
        "SELECT id, name
         FROM files
         WHERE tenant_id = ?
           AND folder_id IS NULL
           AND is_folder = 1
           AND deleted_at IS NULL
         ORDER BY id ASC
         LIMIT 1",
        [$tenantId]
    );
    if (!$root) return ['ok' => false, 'error' => 'Cartella root tenant non trovata'];
    $rootId = (int)($root['id'] ?? 0);
    if ($rootId <= 0) return ['ok' => false, 'error' => 'Cartella root tenant non valida'];

    foreach ($candidates as $name) {
        $name = trim((string)$name);
        if ($name === '') continue;
        $row = $db->fetchOne(
            "SELECT id, name
             FROM files
             WHERE tenant_id = ?
               AND folder_id = ?
               AND is_folder = 1
               AND deleted_at IS NULL
               AND name = ?
             LIMIT 1",
            [$tenantId, $rootId, $name]
        );
        if ($row && !empty($row['id'])) {
            return ['ok' => true, 'folder_id' => (int)$row['id'], 'folder_name' => (string)($row['name'] ?? $name)];
        }
    }
    return ['ok' => false, 'error' => 'Cartella Knowledge non trovata (crea /Knowledge o /IMS nel File Manager)'];
}

if ($action === 'run') {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : cnx_ai_resolve_target_tenant_id($userInfo);
    if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
    if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato al tenant', 403);

    $folderName = trim((string)($payload['folder_name'] ?? ''));
    $folder = cnx_ai_resolve_knowledge_folder($db, $tenantId, $folderName);
    if (!$folder['ok']) api_error((string)($folder['error'] ?? 'Cartella Knowledge non disponibile'), 400);
    $folderId = (int)($folder['folder_id'] ?? 0);
    if ($folderId <= 0) api_error('folder_id non valido', 400);

    // Ensure/activate source record
    $source = $db->fetchOne(
        "SELECT id, folder_id
         FROM ai_knowledge_sources
         WHERE tenant_id = ? AND is_active = 1
         ORDER BY id DESC
         LIMIT 1",
        [$tenantId]
    );
    $sourceId = 0;
    if ($source && !empty($source['id'])) {
        $sourceId = (int)$source['id'];
        if ((int)($source['folder_id'] ?? 0) !== $folderId) {
            $db->update('ai_knowledge_sources', ['folder_id' => $folderId, 'folder_label' => (string)($folder['folder_name'] ?? ''), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $sourceId]);
        }
    } else {
        $sourceId = (int)$db->insert('ai_knowledge_sources', [
            'tenant_id' => $tenantId,
            'folder_id' => $folderId,
            'folder_label' => (string)($folder['folder_name'] ?? ''),
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    if ($sourceId <= 0) api_error('Impossibile creare source', 500);

    $files = cnx_ai_list_files_under_folder($db, $tenantId, $folderId);
    $indexedFiles = 0;
    $indexedChunks = 0;
    $warnings = [];

    foreach ($files as $f) {
        $res = cnx_ai_index_one_file($db, $tenantId, $sourceId, $f);
        if (!empty($res['warnings'])) {
            foreach ($res['warnings'] as $w) $warnings[] = ((string)($f['name'] ?? 'file')) . ': ' . (string)$w;
        }
        if (!empty($res['indexed'])) {
            $indexedFiles++;
            $indexedChunks += (int)($res['chunks'] ?? 0);
        }
        // Hard cap to prevent timeouts (best-effort)
        if ($indexedFiles >= 80) {
            $warnings[] = 'Indicizzazione limitata: raggiunto cap di 80 file per richiesta. Ripeti per completare.';
            break;
        }
    }

    // Update index state best-effort
    try {
        $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
        $state = $db->fetchOne(
            "SELECT id FROM ai_knowledge_index_state WHERE tenant_id = ? AND source_id = ? LIMIT 1",
            [$tenantId, $sourceId]
        );
        $data = [
            'tenant_id' => $tenantId,
            'source_id' => $sourceId,
            'last_indexed_at' => date('Y-m-d H:i:s'),
            'last_file_count' => count($files),
            'last_error' => null,
            'updated_by' => $userId > 0 ? $userId : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($state && !empty($state['id'])) {
            $db->update('ai_knowledge_index_state', $data, ['id' => (int)$state['id']]);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $db->insert('ai_knowledge_index_state', $data);
        }
    } catch (Throwable $e) {
        $warnings[] = 'State update fallito: ' . $e->getMessage();
    }

    api_success([
        'storage_available' => true,
        'tenant_id' => $tenantId,
        'source_id' => $sourceId,
        'folder_id' => $folderId,
        'folder_name' => (string)($folder['folder_name'] ?? ''),
        'scanned_files' => count($files),
        'indexed_files' => $indexedFiles,
        'indexed_chunks' => $indexedChunks,
        'warnings' => array_slice(array_values(array_unique(array_filter($warnings))), 0, 30),
    ], 'Indicizzazione completata');
}

if ($action === 'run_delta') {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $tenantId = isset($payload['tenant_id']) ? (int)$payload['tenant_id'] : cnx_ai_resolve_target_tenant_id($userInfo);
    if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
    if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato al tenant', 403);

    // Folder: prefer current active source; allow override by folder_name
    $folderName = trim((string)($payload['folder_name'] ?? ''));
    $source = $db->fetchOne(
        "SELECT id, folder_id
         FROM ai_knowledge_sources
         WHERE tenant_id = ? AND is_active = 1
         ORDER BY id DESC
         LIMIT 1",
        [$tenantId]
    );
    $sourceId = $source ? (int)($source['id'] ?? 0) : 0;
    $folderId = $source ? (int)($source['folder_id'] ?? 0) : 0;
    $folderLabel = '';

    if ($folderId <= 0 || $folderName !== '') {
        $folder = cnx_ai_resolve_knowledge_folder($db, $tenantId, $folderName);
        if (!$folder['ok']) api_error((string)($folder['error'] ?? 'Cartella Knowledge non disponibile'), 400);
        $folderId = (int)($folder['folder_id'] ?? 0);
        $folderLabel = (string)($folder['folder_name'] ?? '');
        if ($folderId <= 0) api_error('folder_id non valido', 400);

        // Ensure/activate source record
        if ($sourceId > 0) {
            $db->update('ai_knowledge_sources', [
                'folder_id' => $folderId,
                'folder_label' => $folderLabel,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $sourceId]);
        } else {
            $sourceId = (int)$db->insert('ai_knowledge_sources', [
                'tenant_id' => $tenantId,
                'folder_id' => $folderId,
                'folder_label' => $folderLabel,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    } else {
        // best-effort fetch label from files table
        $row = $db->fetchOne("SELECT name FROM files WHERE id = ? AND tenant_id = ? AND is_folder = 1 AND deleted_at IS NULL LIMIT 1", [$folderId, $tenantId]);
        $folderLabel = $row ? (string)($row['name'] ?? '') : '';
    }

    if ($sourceId <= 0) api_error('Impossibile creare source', 500);
    if ($folderId <= 0) api_error('Cartella knowledge non valida', 400);

    $opts = [
        'max_indexed_files' => (int)($payload['max_indexed_files'] ?? 12),
        'max_checked_files' => (int)($payload['max_checked_files'] ?? 500),
        'max_seconds' => (int)($payload['max_seconds'] ?? 8),
    ];

    $warnings = [];
    $res = cnx_ai_index_folder_delta($db, $tenantId, $sourceId, $folderId, $opts);
    if (empty($res['ok'])) {
        api_error('Indicizzazione delta fallita', 500);
    }
    $warnings = array_merge($warnings, $res['warnings'] ?? []);

    // Update index state best-effort
    try {
        $userId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
        $state = $db->fetchOne(
            "SELECT id FROM ai_knowledge_index_state WHERE tenant_id = ? AND source_id = ? LIMIT 1",
            [$tenantId, $sourceId]
        );
        $data = [
            'tenant_id' => $tenantId,
            'source_id' => $sourceId,
            'last_indexed_at' => date('Y-m-d H:i:s'),
            'last_file_count' => (int)($res['scanned_files'] ?? 0),
            'last_error' => null,
            'updated_by' => $userId > 0 ? $userId : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($state && !empty($state['id'])) {
            $db->update('ai_knowledge_index_state', $data, ['id' => (int)$state['id']]);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $db->insert('ai_knowledge_index_state', $data);
        }
    } catch (Throwable $e) {
        $warnings[] = 'State update fallito: ' . $e->getMessage();
    }

    api_success([
        'storage_available' => true,
        'tenant_id' => $tenantId,
        'source_id' => $sourceId,
        'folder_id' => $folderId,
        'folder_name' => $folderLabel,
        'scanned_files' => (int)($res['scanned_files'] ?? 0),
        'checked_files' => (int)($res['checked_files'] ?? 0),
        'indexed_files' => (int)($res['indexed_files'] ?? 0),
        'indexed_chunks' => (int)($res['indexed_chunks'] ?? 0),
        'partial' => !empty($res['partial']),
        'warnings' => array_slice(array_values(array_unique(array_filter($warnings))), 0, 30),
    ], 'Indicizzazione delta completata');
}

api_error('Azione non valida', 400);

