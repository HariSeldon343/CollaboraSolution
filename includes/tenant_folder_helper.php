<?php
/**
 * Tenant folder helper
 *
 * Guarantees that a tenant has a root folder record in `files`
 * (folder_id IS NULL, is_folder=1) and that the physical directory exists.
 *
 * Used by:
 * - Tenant creation (auto-create)
 * - One-shot backfill tool
 * - Lazy backfill on file manager root browse (non super_admin)
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Ensure tenant root folder exists.
 *
 * Criteria:
 * - tenant_id = ?
 * - folder_id IS NULL
 * - is_folder = 1
 * - deleted_at IS NULL
 *
 * @return array{folder_id:int, folder_name:string, created:bool}
 * @throws Exception on DB/FS failures
 */
function cnx_ensure_tenant_root_folder(Database $db, int $tenantId, string $tenantName): array {
    $tenantId = (int)$tenantId;
    $tenantName = trim((string)$tenantName);
    if ($tenantName === '') {
        $tenantName = 'Tenant ' . $tenantId;
    }

    // 1) Check existing root folder
    $existing = $db->fetchOne(
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

    if ($existing && !empty($existing['id'])) {
        // Ensure physical directory exists even if DB record exists
        cnx_ensure_tenant_upload_dir($tenantId);
        return [
            'folder_id' => (int)$existing['id'],
            'folder_name' => (string)($existing['name'] ?? $tenantName),
            'created' => false,
        ];
    }

    // 2) Deduplicate name within tenant root level
    $baseName = $tenantName;
    $candidate = $baseName;
    $i = 2;
    while (true) {
        $dup = $db->fetchOne(
            "SELECT id
             FROM files
             WHERE tenant_id = ?
               AND folder_id IS NULL
               AND is_folder = 1
               AND deleted_at IS NULL
               AND name = ?
             LIMIT 1",
            [$tenantId, $candidate]
        );
        if (!$dup) {
            break;
        }
        $candidate = $baseName . ' (' . $i . ')';
        $i++;
        if ($i > 50) {
            throw new Exception('Impossibile determinare un nome univoco per la cartella tenant');
        }
    }

    // 3) Create physical directory first
    cnx_ensure_tenant_upload_dir($tenantId);

    // 4) Create DB record
    $createdBy = (int)($_SESSION['user_id'] ?? 0);
    if ($createdBy <= 0) {
        // In pratica, tutte le chiamate arrivano da sessione autenticata.
        throw new Exception('Impossibile creare cartella tenant: user_id non disponibile in sessione');
    }

    $now = date('Y-m-d H:i:s');
    $folderId = $db->insert('files', [
        'name' => $candidate,
        'folder_id' => null,
        'tenant_id' => $tenantId,
        'uploaded_by' => $createdBy,
        'is_folder' => 1,
        // Keep coherence with api/files_tenant.php createRootFolder()
        'file_path' => '/',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'folder_id' => (int)$folderId,
        'folder_name' => $candidate,
        'created' => true,
    ];
}

/**
 * Ensure uploads/{tenantId} physical directory exists.
 *
 * @throws Exception if directory cannot be created
 */
function cnx_ensure_tenant_upload_dir(int $tenantId): void {
    $tenantId = (int)$tenantId;

    // Prefer UPLOAD_PATH constant; fallback to project /uploads
    $base = defined('UPLOAD_PATH') ? (string)UPLOAD_PATH : (dirname(__DIR__) . '/uploads');
    $dir = rtrim($base, '\\/') . DIRECTORY_SEPARATOR . $tenantId;

    if (is_dir($dir)) {
        return;
    }

    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new Exception('Impossibile creare la directory fisica del tenant: ' . $dir);
    }
}

