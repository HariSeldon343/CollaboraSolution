<?php
/**
 * API: Eliminazione multipla Aziende (Tenants)
 *
 * Endpoint per eliminare più aziende in un'unica operazione (soft-delete via stored procedure).
 *
 * Method: POST
 * Auth: Super Admin only
 * CSRF: Required
 *
 * Input (JSON o form):
 * - tenant_ids: int[] (required)
 * - confirm_system_tenant: bool (optional) - necessario se tenant_ids include 1
 *
 * Response:
 * - success: true
 * - data: { results: [...], summary: {...} }
 */

declare(strict_types=1);

require_once '../../includes/api_auth.php';
initializeApiEnvironment();

verifyApiAuthentication();
$userInfo = getApiUserInfo();

verifyApiCsrfToken();
requireApiRole('super_admin');

require_once '../../includes/db.php';
$db = Database::getInstance();

try {
    $input = $_POST;
    if (empty($input)) {
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonInput)) {
            $input = $jsonInput;
        }
    }

    $tenantIdsRaw = $input['tenant_ids'] ?? $input['tenant_id'] ?? $input['ids'] ?? null;
    if (!is_array($tenantIdsRaw)) {
        apiError('tenant_ids non valido', 400);
    }

    $tenantIds = array_values(array_unique(array_filter(array_map(static function ($v) {
        $id = filter_var($v, FILTER_VALIDATE_INT);
        return ($id && $id > 0) ? (int)$id : null;
    }, $tenantIdsRaw))));

    if (empty($tenantIds)) {
        apiError('Nessun tenant selezionato', 400);
    }

    // Protezione tenant 1
    $confirmSystemTenant = filter_var($input['confirm_system_tenant'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (in_array(1, $tenantIds, true) && !$confirmSystemTenant) {
        apiError(
            'Conferma richiesta: eliminare il tenant di sistema (ID 1) richiede confirm_system_tenant=true',
            400
        );
    }

    $conn = $db->getConnection();

    $results = [];
    $summary = [
        'requested' => count($tenantIds),
        'deleted' => 0,
        'skipped_not_found' => 0,
        'failed' => 0,
        'system_tenant_deleted' => false,
    ];

    foreach ($tenantIds as $tenantId) {
        // Verify tenant exists and not already deleted
        $tenant = $db->fetchOne(
            'SELECT id, name, denominazione FROM tenants WHERE id = ? AND deleted_at IS NULL',
            [$tenantId]
        );

        if (!$tenant) {
            $summary['skipped_not_found']++;
            $results[] = [
                'tenant_id' => $tenantId,
                'status' => 'skipped',
                'message' => 'Azienda non trovata o già eliminata',
            ];
            continue;
        }

        try {
            // Initialize OUT parameters
            $conn->exec("SET @p_success = FALSE, @p_message = '', @p_records = NULL");

            // Session flag for system tenant deletion
            if ($tenantId === 1) {
                $conn->exec('SET @ALLOW_SYSTEM_TENANT_DELETE = TRUE');
            } else {
                $conn->exec('SET @ALLOW_SYSTEM_TENANT_DELETE = FALSE');
            }

            $stmt = $conn->prepare('CALL sp_soft_delete_tenant_complete(?, ?, @p_success, @p_message, @p_records)');
            $stmt->execute([$tenantId, $userInfo['user_id']]);
            $stmt->closeCursor();

            // Reset flag
            $conn->exec('SET @ALLOW_SYSTEM_TENANT_DELETE = FALSE');

            $out = $conn->query('SELECT @p_success as success, @p_message as message, @p_records as records')->fetch(PDO::FETCH_ASSOC);
            $ok = $out && !empty($out['success']);
            if (!$ok) {
                $summary['failed']++;
                $results[] = [
                    'tenant_id' => $tenantId,
                    'denominazione' => $tenant['denominazione'] ?? $tenant['name'],
                    'status' => 'failed',
                    'message' => $out['message'] ?? 'Errore durante eliminazione',
                ];
                continue;
            }

            $recordsDeleted = [];
            if (!empty($out['records'])) {
                $decoded = json_decode((string)$out['records'], true);
                if (is_array($decoded)) $recordsDeleted = $decoded;
            }

            $summary['deleted']++;
            if ($tenantId === 1) $summary['system_tenant_deleted'] = true;

            $results[] = [
                'tenant_id' => $tenantId,
                'denominazione' => $tenant['denominazione'] ?? $tenant['name'],
                'status' => 'deleted',
                'message' => $out['message'] ?? 'OK',
                'cascade_info' => $recordsDeleted,
            ];
        } catch (Throwable $e) {
            try { $conn->exec('SET @ALLOW_SYSTEM_TENANT_DELETE = FALSE'); } catch (Throwable $ignored) {}
            $summary['failed']++;
            logApiError('tenants/bulk_delete', $e);
            $results[] = [
                'tenant_id' => $tenantId,
                'denominazione' => $tenant['denominazione'] ?? $tenant['name'],
                'status' => 'failed',
                'message' => defined('DEBUG_MODE') && DEBUG_MODE ? $e->getMessage() : 'Errore durante eliminazione',
            ];
        }
    }

    apiSuccess([
        'results' => $results,
        'summary' => $summary,
    ], 'Operazione completata');

} catch (Exception $e) {
    logApiError('tenants/bulk_delete', $e);
    apiError('Errore durante l\'eliminazione multipla', 500);
}

