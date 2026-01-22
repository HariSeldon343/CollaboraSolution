<?php
declare(strict_types=1);

/**
 * Tenant Logo API (super_admin only)
 *
 * GET  /api/tenants/logo.php?action=get&tenant_id=...
 * POST /api/tenants/logo.php?action=upload (multipart/form-data: tenant_id, logo)
 * POST /api/tenants/logo.php?action=clear  (JSON: {tenant_id})
 *
 * Stores logo as a normal file under tenant folder (/IMS/Assets) and references it via tenants.logo_file_id.
 */

require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/file_helper.php';
require_once __DIR__ . '/../../includes/tenant_folder_helper.php';

initializeApiEnvironment();
verifyApiAuthentication();
verifyApiCsrfToken(true);

$db = Database::getInstance();
$userInfo = getApiUserInfo();
requireApiRole('super_admin');

function cnx_tenants_has_col(Database $db, string $col): bool {
    try {
        $ok = $db->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tenants'
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$col]
        );
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_files_has_table(Database $db): bool {
    try {
        $ok = $db->fetchOne(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'files'
             LIMIT 1"
        );
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_get_or_create_folder(Database $db, int $tenantId, int $parentId, string $name, string $fullPath): int {
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
    if ($row && !empty($row['id'])) return (int)$row['id'];

    $createdBy = (int)($_SESSION['user_id'] ?? 0);
    if ($createdBy <= 0) {
        api_error('user_id non disponibile in sessione', 500);
    }
    $now = date('Y-m-d H:i:s');
    $id = $db->insert('files', [
        'name' => $name,
        'folder_id' => $parentId,
        'tenant_id' => $tenantId,
        'uploaded_by' => $createdBy,
        'is_folder' => 1,
        'file_path' => $fullPath,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    return (int)$id;
}

$action = trim((string)($_GET['action'] ?? ($_POST['action'] ?? '')));
if ($action === '') api_error('action richiesto', 400);

if (!cnx_tenants_has_col($db, 'logo_file_id')) {
    api_success([
        'storage_available' => false,
        'missing' => ['tenants.logo_file_id'],
        'migration' => 'database/migrations/49_tenant_logo_file.sql (tools/apply_migration_49_tenant_logo_file.php)',
    ]);
}
if (!cnx_files_has_table($db)) {
    api_error('Storage file non disponibile (tabella files mancante)', 503);
}

if ($action === 'get') {
    $tenantId = (int)($_GET['tenant_id'] ?? 0);
    if ($tenantId <= 0) api_error('tenant_id richiesto', 400);

    $t = $db->fetchOne("SELECT id, denominazione, logo_file_id FROM tenants WHERE id = ? AND deleted_at IS NULL LIMIT 1", [$tenantId]);
    if (!$t) api_error('Tenant non trovato', 404);

    $logoFileId = (int)($t['logo_file_id'] ?? 0);
    api_success([
        'storage_available' => true,
        'tenant_id' => $tenantId,
        'tenant_name' => (string)($t['denominazione'] ?? ''),
        'logo_file_id' => $logoFileId,
        'open_logo_url' => $logoFileId > 0 ? ('files.php?open_file_id=' . $logoFileId . '&open_mode=view') : null,
    ]);
}

if ($action === 'clear') {
    $payload = json_decode(cnx_get_raw_request_body(), true);
    if (!is_array($payload)) api_error('Body JSON non valido', 400);
    $tenantId = (int)($payload['tenant_id'] ?? 0);
    if ($tenantId <= 0) api_error('tenant_id richiesto', 400);

    $db->update('tenants', ['logo_file_id' => null], ['id' => $tenantId]);
    api_success(['storage_available' => true, 'tenant_id' => $tenantId, 'logo_file_id' => null], 'Logo rimosso');
}

if ($action === 'upload') {
    $tenantId = (int)($_POST['tenant_id'] ?? 0);
    if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
    if (empty($_FILES['logo'])) api_error('File logo richiesto', 400);

    $t = $db->fetchOne("SELECT id, denominazione, logo_file_id FROM tenants WHERE id = ? AND deleted_at IS NULL LIMIT 1", [$tenantId]);
    if (!$t) api_error('Tenant non trovato', 404);

    $file = $_FILES['logo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        api_error('Errore upload file', 400);
    }

    $orig = (string)($file['name'] ?? 'logo');
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_file($tmp)) api_error('Upload temporaneo non valido', 400);
    if ($size <= 0 || $size > 3_000_000) api_error('Logo troppo grande (max 3MB)', 400);

    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!in_array($ext, ['png', 'jpg'], true)) {
        api_error('Formato logo non supportato (solo PNG/JPG)', 400);
    }

    // Ensure tenant root folder + physical upload dir
    $root = cnx_ensure_tenant_root_folder($db, $tenantId, (string)($t['denominazione'] ?? ('Tenant ' . $tenantId)));
    $rootId = (int)($root['folder_id'] ?? 0);
    if ($rootId <= 0) api_error('Impossibile determinare cartella root tenant', 500);

    // Ensure /IMS/Assets folders exist in files table
    $imsId = cnx_get_or_create_folder($db, $tenantId, $rootId, 'IMS', '/IMS');
    $assetsId = cnx_get_or_create_folder($db, $tenantId, $imsId, 'Assets', '/IMS/Assets');

    // Move physical file to uploads/{tenantId}/<unique>
    $uploadPath = FileHelper::getTenantUploadPath($tenantId);
    $safe = FileHelper::generateSafeFilename('tenant_logo.' . $ext, $uploadPath);
    $dstAbs = rtrim($uploadPath, '/\\') . '/' . $safe;
    if (!@move_uploaded_file($tmp, $dstAbs)) {
        api_error('Errore salvataggio file logo', 500);
    }

    $mime = FileHelper::getMimeType($dstAbs);
    $createdBy = (int)($_SESSION['user_id'] ?? 0);
    $now = date('Y-m-d H:i:s');

    // Soft-delete previous logo file (best-effort) to avoid clutter
    $prev = (int)($t['logo_file_id'] ?? 0);
    if ($prev > 0) {
        try {
            $db->update('files', ['deleted_at' => $now, 'updated_at' => $now], ['id' => $prev]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    $logoFileId = (int)$db->insert('files', [
        'folder_id' => $assetsId,
        'tenant_id' => $tenantId,
        'name' => 'logo.' . $ext,
        'original_name' => $orig,
        'file_size' => @filesize($dstAbs) ?: $size,
        'file_path' => $safe,
        'uploaded_by' => $createdBy > 0 ? $createdBy : null,
        'mime_type' => $mime,
        'status' => 'approvato',
        'is_folder' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->update('tenants', ['logo_file_id' => $logoFileId], ['id' => $tenantId]);

    api_success([
        'storage_available' => true,
        'tenant_id' => $tenantId,
        'logo_file_id' => $logoFileId,
        'open_logo_url' => 'files.php?open_file_id=' . $logoFileId . '&open_mode=view',
    ], 'Logo caricato');
}

api_error('Azione non valida', 400);

