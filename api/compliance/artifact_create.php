<?php
/**
 * Create artifact from missing requirement (Leva 4)
 *
 * POST /api/compliance/artifact_create.php
 *
 * Input:
 * - program_id: int (required)
 * - clause: string (required, e.g. "5.1")
 * - standard_code: string (required, e.g. "ISO9001")
 * - requirement_title: string (required)
 * - artifact_type: string (optional, defaults to auto-detect based on clause)
 *
 * Output:
 * - artifact_id
 * - file_id
 * - title
 * - artifact_type
 *
 * Notes:
 * - Creates the artifact row in compliance_artifacts
 * - Creates a DOCX placeholder in the appropriate IMS folder
 * - Uses existing helpers from provisioning_files_helper.php
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/tenant_folder_helper.php';
require_once __DIR__ . '/../../includes/compliance/provisioning_files_helper.php';

cnx_compliance_require_csrf_for_write();

// Storage check (schema drift safe)
$chk = cnx_compliance_check_tables($db, ['compliance_programs', 'compliance_artifacts']);
if (!$chk['ok']) {
    api_error(
        'Storage compliance non disponibile: applica migrazione 41',
        503,
        ['storage_available' => false, 'missing' => $chk['missing'] ?? [], 'migration' => 'database/migrations/41_compliance_programs.sql']
    );
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];

$programId = (int)($data['program_id'] ?? 0);
$clause = trim((string)($data['clause'] ?? ''));
$standardCode = strtoupper(trim((string)($data['standard_code'] ?? '')));
$requirementTitle = trim((string)($data['requirement_title'] ?? ''));
$artifactTypeHint = strtolower(trim((string)($data['artifact_type'] ?? '')));

if ($programId <= 0) api_error('program_id richiesto', 400);
if ($clause === '') api_error('clause richiesto', 400);
if ($standardCode === '') api_error('standard_code richiesto', 400);
if ($requirementTitle === '') api_error('requirement_title richiesto', 400);

try {
    // Load program and verify access
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

    $actorUserId = (int)($userInfo['user_id'] ?? $userInfo['id'] ?? 0);
    if ($actorUserId <= 0) api_error('Utente non valido', 400);

    // Determine artifact type based on clause prefix (best-effort heuristic)
    $artifactType = 'document';
    if ($artifactTypeHint !== '' && in_array($artifactTypeHint, ['manual', 'procedure', 'policy', 'plan', 'form', 'record', 'register', 'document'], true)) {
        $artifactType = $artifactTypeHint;
    } else {
        // Auto-detect based on common ISO 9001 clause patterns
        $clauseMain = (int)explode('.', $clause)[0];
        $clauseTitle = strtolower($requirementTitle);
        
        if (strpos($clauseTitle, 'politica') !== false || strpos($clauseTitle, 'policy') !== false) {
            $artifactType = 'policy';
        } elseif (strpos($clauseTitle, 'procedura') !== false || strpos($clauseTitle, 'procedure') !== false) {
            $artifactType = 'procedure';
        } elseif (strpos($clauseTitle, 'piano') !== false || strpos($clauseTitle, 'plan') !== false) {
            $artifactType = 'plan';
        } elseif (strpos($clauseTitle, 'registro') !== false || strpos($clauseTitle, 'register') !== false) {
            $artifactType = 'register';
        } elseif (strpos($clauseTitle, 'modulo') !== false || strpos($clauseTitle, 'form') !== false || strpos($clauseTitle, 'scheda') !== false) {
            $artifactType = 'form';
        } elseif (strpos($clauseTitle, 'manuale') !== false || strpos($clauseTitle, 'manual') !== false) {
            $artifactType = 'manual';
        } elseif ($clauseMain >= 9 || strpos($clauseTitle, 'audit') !== false || strpos($clauseTitle, 'riesame') !== false) {
            $artifactType = 'record';
        } else {
            $artifactType = 'procedure';
        }
    }

    // Generate artifact key (unique per program)
    $normalizeKey = static function (string $s): string {
        $s = strtoupper(trim($s));
        $s = preg_replace('/[^A-Z0-9_\\-]+/', '_', $s) ?: $s;
        $s = preg_replace('/_+/', '_', $s) ?: $s;
        return trim($s, '_');
    };
    $artifactKey = $standardCode . '_' . str_replace('.', '_', $clause) . '_' . $normalizeKey(substr($requirementTitle, 0, 40));
    if (strlen($artifactKey) > 80) $artifactKey = substr($artifactKey, 0, 80);

    // Check if artifact already exists for this clause in this program
    $existing = $db->fetchOne(
        "SELECT id, file_id, title
         FROM compliance_artifacts
         WHERE program_id = ?
           AND artifact_key = ?
         LIMIT 1",
        [$programId, $artifactKey]
    );
    if ($existing && !empty($existing['id'])) {
        // Artifact already exists, return it
        api_success([
            'artifact_id' => (int)$existing['id'],
            'file_id' => (int)($existing['file_id'] ?? 0) ?: null,
            'title' => (string)$existing['title'],
            'artifact_type' => $artifactType,
            'already_existed' => true,
        ], 'Artifact già esistente');
    }

    // Determine folder path based on HLS structure
    $hlsSection = '00-IMS';
    $clauseMain = (int)explode('.', $clause)[0];
    switch ($clauseMain) {
        case 4: $hlsSection = '01-Context'; break;
        case 5: $hlsSection = '02-Leadership'; break;
        case 6: $hlsSection = '03-Planning'; break;
        case 7: $hlsSection = '04-Support'; break;
        case 8: $hlsSection = '05-Operation'; break;
        case 9: $hlsSection = '06-Performance'; break;
        case 10: $hlsSection = '07-Improvement'; break;
    }
    $folderPath = '/IMS/' . $hlsSection;

    // Load tenant name for root folder creation
    $tenantRow = $db->fetchOne("SELECT name FROM tenants WHERE id = ? LIMIT 1", [$tenantId]);
    $tenantName = (string)($tenantRow['name'] ?? 'Tenant ' . $tenantId);

    // Ensure tenant root folder exists
    $rootRes = cnx_ensure_tenant_root_folder($db, $tenantId, $tenantName);
    $rootFolderId = (int)$rootRes['folder_id'];

    // Ensure IMS folder exists under root
    $imsRes = cnx_ensure_folder($db, $tenantId, $rootFolderId, 'IMS', $actorUserId);
    $imsFolderId = (int)$imsRes['folder_id'];
    
    // Ensure subfolder exists
    $pathToFolderId = ['/IMS' => $imsFolderId];
    $foldersCreated = 0;
    $foldersExisting = 0;
    
    // Helper to ensure nested path
    $ensureImsPath = function (string $path) use ($db, $tenantId, $imsFolderId, $actorUserId, &$pathToFolderId, &$foldersCreated, &$foldersExisting): void {
        $path = rtrim(trim($path), '/');
        if ($path === '' || $path === '/IMS') return;
        if (strpos($path, '/IMS/') !== 0) return;
        if (isset($pathToFolderId[$path])) return;

        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        if (empty($parts) || $parts[0] !== 'IMS') return;
        array_shift($parts);
        $currentPath = '/IMS';
        $parentId = $imsFolderId;
        foreach ($parts as $seg) {
            $seg = trim((string)$seg);
            if ($seg === '') continue;
            $currentPath .= '/' . $seg;
            if (isset($pathToFolderId[$currentPath])) {
                $parentId = (int)$pathToFolderId[$currentPath];
                continue;
            }
            $res = cnx_ensure_folder($db, $tenantId, $parentId, $seg, $actorUserId);
            if ($res['created']) $foldersCreated++; else $foldersExisting++;
            $pathToFolderId[$currentPath] = (int)$res['folder_id'];
            $parentId = (int)$res['folder_id'];
        }
    };
    
    $ensureImsPath($folderPath);
    $folderId = $pathToFolderId[$folderPath] ?? $imsFolderId;

    // Create artifact title
    $title = $requirementTitle;
    if (strlen($title) > 150) $title = substr($title, 0, 150);

    // Create clause refs JSON (object per standard)
    $clauseRefsJson = json_encode([$standardCode => [$clause]], JSON_UNESCAPED_UNICODE);

    // Insert artifact row
    $artifactId = (int)$db->insert('compliance_artifacts', [
        'program_id' => $programId,
        'artifact_key' => $artifactKey,
        'title' => $title,
        'artifact_type' => $artifactType,
        'clause_refs_json' => $clauseRefsJson,
        'owner_label' => null,
        'owner_user_id' => null,
        'due_date' => null,
        'status' => 'todo',
        'file_id' => null,
        'folder_id' => $folderId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // Create placeholder document
    $fileKind = 'docx';
    $filename = $title;
    $docRes = cnx_ensure_placeholder_document($db, $tenantId, $folderId, $actorUserId, $fileKind, $filename);
    $fileId = (int)$docRes['file_id'];

    // Update artifact with file_id
    $db->update('compliance_artifacts', [
        'file_id' => $fileId,
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['id' => $artifactId]);

    api_success([
        'artifact_id' => $artifactId,
        'file_id' => $fileId,
        'title' => $title,
        'artifact_type' => $artifactType,
        'folder_path' => $folderPath,
        'already_existed' => false,
    ], 'Artifact e documento creati');

} catch (Throwable $e) {
    error_log('[COMPLIANCE_ARTIFACT_CREATE] ' . $e->getMessage());
    api_error('Errore creazione artifact: ' . $e->getMessage(), 500, defined('DEBUG_MODE') && DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
}
