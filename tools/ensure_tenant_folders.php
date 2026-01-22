<?php
/**
 * Tool (web): Ensure tenant root folders exist (one-shot backfill)
 *
 * Access:
 * - authenticated
 * - super_admin only
 * - CSRF required
 *
 * Output:
 * - JSON summary (created/already_present/errors)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_auth.php';
initializeApiEnvironment();

verifyApiAuthentication();
verifyApiCsrfToken();
requireApiRole('super_admin');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/tenant_folder_helper.php';

$db = Database::getInstance();

try {
    $tenants = $db->fetchAll(
        "SELECT id, COALESCE(denominazione, name) AS tenant_name
         FROM tenants
         WHERE deleted_at IS NULL
         ORDER BY id ASC"
    );

    $created = 0;
    $already = 0;
    $errors = [];
    $details = [];

    foreach ($tenants as $t) {
        $tid = (int)($t['id'] ?? 0);
        if ($tid <= 0) continue;

        $tname = (string)($t['tenant_name'] ?? ('Tenant ' . $tid));
        try {
            $res = cnx_ensure_tenant_root_folder($db, $tid, $tname);
            $details[] = [
                'tenant_id' => $tid,
                'tenant_name' => $tname,
                'folder_id' => (int)$res['folder_id'],
                'folder_name' => (string)$res['folder_name'],
                'created' => (bool)$res['created'],
            ];
            if (!empty($res['created'])) {
                $created++;
            } else {
                $already++;
            }
        } catch (Throwable $e) {
            $errors[] = [
                'tenant_id' => $tid,
                'tenant_name' => $tname,
                'error' => $e->getMessage(),
            ];
        }
    }

    apiSuccess([
        'total_tenants' => count($tenants),
        'created' => $created,
        'already_present' => $already,
        'errors_count' => count($errors),
        'errors' => $errors,
        'details' => $details,
    ], 'Tenant folder backfill completed');
} catch (Throwable $e) {
    logApiError('tools/ensure_tenant_folders', $e instanceof Exception ? $e : new Exception($e->getMessage()));
    apiError('Errore durante il backfill delle cartelle tenant', 500, DEBUG_MODE ? ['debug' => $e->getMessage()] : null);
}

