<?php
/**
 * API: Eliminazione Azienda (Tenant)
 *
 * Endpoint per eliminare un'azienda (soft-delete)
 *
 * Method: POST
 * Auth: Super Admin only
 * CSRF: Required
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 */

declare(strict_types=1);

// Inizializza ambiente API
require_once '../../includes/api_auth.php';
initializeApiEnvironment();

// Verifica autenticazione
verifyApiAuthentication();
$userInfo = getApiUserInfo();

// Verifica CSRF token
verifyApiCsrfToken();

// Solo Super Admin può eliminare tenants
requireApiRole('super_admin');

// Carica database
require_once '../../includes/db.php';
$db = Database::getInstance();

try {
    // Leggi input
    $input = $_POST;

    // Se no POST data, prova JSON body
    if (empty($input)) {
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $input = $jsonInput;
        }
    }

    // Validazione tenant_id
    $tenantId = filter_var(
        $input['tenant_id'] ?? $input['id'] ?? 0,
        FILTER_VALIDATE_INT
    );

    if (!$tenantId || $tenantId <= 0) {
        apiError('ID azienda non valido', 400);
    }

    // Previeni eliminazione azienda di sistema (ID 1)
    if ($tenantId === 1) {
        apiError('Non è possibile eliminare l\'azienda di sistema', 400);
    }

    // Verifica che il tenant esista e non sia già eliminato
    $tenant = $db->fetchOne(
        'SELECT id, name, denominazione, status FROM tenants WHERE id = ? AND deleted_at IS NULL',
        [$tenantId]
    );

    if (!$tenant) {
        apiError('Azienda non trovata o già eliminata', 404);
    }

    // Conta risorse associate (per informazione)
    $userCount = $db->count('users', [
        'tenant_id' => $tenantId,
        'deleted_at' => null
    ]);

    $fileCount = $db->count('files', [
        'tenant_id' => $tenantId,
        'deleted_at' => null
    ]);

    $projectCount = $db->count('projects', [
        'tenant_id' => $tenantId,
        'deleted_at' => null
    ]);

    // Inizio transazione per soft-delete
    $db->beginTransaction();

    try {
        $deletedAt = date('Y-m-d H:i:s');

        // 1. Soft-delete del tenant
        $db->update(
            'tenants',
            ['deleted_at' => $deletedAt],
            ['id' => $tenantId]
        );

        // 2. Soft-delete degli utenti associati
        if ($userCount > 0) {
            $db->update(
                'users',
                ['deleted_at' => $deletedAt],
                ['tenant_id' => $tenantId]
            );
        }

        // 3. Soft-delete dei progetti associati
        if ($projectCount > 0) {
            $db->update(
                'projects',
                ['deleted_at' => $deletedAt],
                ['tenant_id' => $tenantId]
            );
        }

        // 4. Soft-delete dei file associati
        if ($fileCount > 0) {
            $db->update(
                'files',
                ['deleted_at' => $deletedAt],
                ['tenant_id' => $tenantId]
            );
        }

        // 5. Soft-delete delle location associate
        $locationCount = $db->count('tenant_locations', [
            'tenant_id' => $tenantId,
            'deleted_at' => null
        ]);

        if ($locationCount > 0) {
            $db->update(
                'tenant_locations',
                ['deleted_at' => $deletedAt],
                ['tenant_id' => $tenantId]
            );
        }

        // 6. Rimuovi accessi multi-tenant
        $conn = $db->getConnection();
        $stmt = $conn->prepare('DELETE FROM user_tenant_access WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $accessRemoved = $stmt->rowCount();

        // 7. Log audit
        $db->insert('audit_logs', [
            'tenant_id' => $userInfo['tenant_id'],
            'user_id' => $userInfo['user_id'],
            'action' => 'delete',
            'entity_type' => 'tenant',
            'entity_id' => $tenantId,
            'old_values' => json_encode([
                'tenant' => $tenant,
                'users_count' => $userCount,
                'files_count' => $fileCount,
                'projects_count' => $projectCount,
                'locations_count' => $locationCount,
                'accesses_removed' => $accessRemoved
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        $db->commit();

        // Risposta di successo
        apiSuccess([
            'tenant_id' => $tenantId,
            'denominazione' => $tenant['denominazione'] ?? $tenant['name'],
            'deleted_at' => $deletedAt,
            'cascade_info' => [
                'users_deleted' => $userCount,
                'files_deleted' => $fileCount,
                'projects_deleted' => $projectCount,
                'locations_deleted' => $locationCount,
                'accesses_removed' => $accessRemoved
            ]
        ], 'Azienda eliminata con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logApiError('tenants/delete', $e);
    apiError('Errore durante l\'eliminazione dell\'azienda', 500);
}
