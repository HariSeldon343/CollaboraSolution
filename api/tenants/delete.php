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
 * Input:
 * - tenant_id|id: int (required) - ID del tenant da eliminare
 * - confirm_system_tenant: bool (optional) - Conferma esplicita per eliminare tenant ID 1
 *
 * Special Cases:
 * - Tenant ID 1 richiede confirm_system_tenant=true per conferma esplicita
 *
 * @author CollaboraNexio Development Team
 * @version 2.0.0
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

    // Verifica che il tenant esista e non sia già eliminato
    $tenant = $db->fetchOne(
        'SELECT id, name, denominazione, status FROM tenants WHERE id = ? AND deleted_at IS NULL',
        [$tenantId]
    );

    if (!$tenant) {
        apiError('Azienda non trovata o già eliminata', 404);
    }

    // PROTEZIONE SPECIALE: Tenant ID 1 (tenant di sistema) richiede conferma esplicita
    if ($tenantId === 1) {
        $confirmSystemTenant = filter_var(
            $input['confirm_system_tenant'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$confirmSystemTenant) {
            apiError(
                'Richiesta conferma: eliminare il tenant di sistema (ID 1) richiede conferma esplicita. Invia di nuovo con confirm_system_tenant: true',
                400
            );
        }

        // Log della conferma esplicita per audit
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('CRITICAL: System tenant (ID 1) deletion confirmed by user: ' . $userInfo['user_id']);
        }
    }

    // Esegui soft-delete completo tramite stored procedure
    try {
        $conn = $db->getConnection();

        // Prepara chiamata a stored procedure
        $stmt = $conn->prepare('CALL sp_soft_delete_tenant_complete(?, ?, @success, @message, @records)');
        $stmt->execute([$tenantId, $userInfo['user_id']]);

        // Chiudi cursor prima di leggere gli output parameters
        $stmt->closeCursor();

        // Ottieni risultati della stored procedure
        $result = $conn->query('SELECT @success as success, @message as message, @records as records')->fetch(PDO::FETCH_ASSOC);

        // Verifica successo operazione
        if (!$result || !$result['success']) {
            $errorMessage = $result['message'] ?? 'Errore sconosciuto durante la stored procedure';
            logApiError('tenants/delete', new Exception($errorMessage));
            apiError($errorMessage, 500);
        }

        // Decodifica informazioni sui record eliminati
        $recordsDeleted = json_decode($result['records'], true);

        // Log dettagliato in debug mode
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('Tenant soft-delete completed: ' . json_encode([
                'tenant_id' => $tenantId,
                'deleted_by' => $userInfo['user_id'],
                'cascade_info' => $recordsDeleted
            ]));
        }

        // Costruisci risposta di successo
        $responseData = [
            'tenant_id' => $tenantId,
            'denominazione' => $tenant['denominazione'] ?? $tenant['name'],
            'deleted_at' => date('Y-m-d H:i:s'),
            'cascade_info' => $recordsDeleted,
            'message' => $result['message']
        ];

        // Aggiungi warning se è stato eliminato il tenant di sistema
        if ($tenantId === 1) {
            $responseData['warning'] = 'ATTENZIONE: Eliminato il tenant di sistema (ID 1). Questa è un\'operazione critica.';
        }

        // Risposta di successo con dettagli cascata
        apiSuccess($responseData, 'Azienda eliminata con successo');

    } catch (PDOException $e) {
        // Gestione errori database specifici
        logApiError('tenants/delete', $e);

        $errorMessage = 'Errore database durante l\'eliminazione dell\'azienda';

        // In debug mode, mostra dettagli errore
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            $errorMessage .= ': ' . $e->getMessage();
        }

        apiError($errorMessage, 500);
    }

} catch (Exception $e) {
    // Gestione errori generici
    logApiError('tenants/delete', $e);

    $errorMessage = 'Errore durante l\'eliminazione dell\'azienda';

    // In debug mode, mostra dettagli errore
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        $errorMessage .= ': ' . $e->getMessage();
    }

    apiError($errorMessage, 500);
}
