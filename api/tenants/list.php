<?php
/**
 * API: Lista Aziende (Tenants)
 *
 * Endpoint per ottenere la lista delle aziende con filtri opzionali
 *
 * Method: GET
 * Auth: Qualsiasi ruolo autenticato
 * Filtri: status, settore_merceologico
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

// Carica database
require_once '../../includes/db.php';
$db = Database::getInstance();

try {
    // Parametri di filtro
    $status = $_GET['status'] ?? null;
    $settore = $_GET['settore_merceologico'] ?? null;

    // Query base con JOIN per ottenere il nome del manager e dati sede legale da tenant_locations
    $sql = "
        SELECT
            t.id,
            t.denominazione,
            t.partita_iva,
            t.codice_fiscale,
            t.status,
            t.settore_merceologico,
            t.numero_dipendenti,
            t.telefono,
            t.email,
            t.manager_id,
            u.name as manager_name,
            t.created_at,
            t.updated_at,
            tl.comune as sede_legale_comune,
            tl.provincia as sede_legale_provincia,
            tl.indirizzo as sede_legale_indirizzo,
            tl.civico as sede_legale_civico,
            tl.cap as sede_legale_cap,
            (SELECT COUNT(*)
             FROM tenant_locations
             WHERE tenant_id = t.id
               AND location_type = 'sede_operativa'
               AND deleted_at IS NULL
               AND is_active = 1) as sedi_operative_count
        FROM tenants t
        LEFT JOIN users u ON t.manager_id = u.id AND u.deleted_at IS NULL
        LEFT JOIN tenant_locations tl ON t.id = tl.tenant_id
            AND tl.location_type = 'sede_legale'
            AND tl.is_primary = 1
            AND tl.deleted_at IS NULL
        WHERE t.deleted_at IS NULL
    ";

    $params = [];

    // Tenant isolation
    if ($userInfo['role'] === 'super_admin') {
        // Super admin vede tutte le aziende
        // Nessun filtro aggiuntivo
    } elseif ($userInfo['role'] === 'admin') {
        // Admin vede solo le aziende a cui ha accesso
        $accessibleTenants = [];

        // Tenant primario
        if ($userInfo['tenant_id']) {
            $accessibleTenants[] = $userInfo['tenant_id'];
        }

        // Tenants aggiuntivi da user_tenant_access
        $additionalTenants = $db->fetchAll(
            'SELECT DISTINCT tenant_id FROM user_tenant_access WHERE user_id = ?',
            [$userInfo['user_id']]
        );

        foreach ($additionalTenants as $tenant) {
            if (!in_array($tenant['tenant_id'], $accessibleTenants)) {
                $accessibleTenants[] = $tenant['tenant_id'];
            }
        }

        if (!empty($accessibleTenants)) {
            $placeholders = implode(',', array_fill(0, count($accessibleTenants), '?'));
            $sql .= " AND t.id IN ($placeholders)";
            $params = array_merge($params, $accessibleTenants);
        } else {
            // Nessun accesso
            apiSuccess([], 'Nessuna azienda accessibile');
            exit;
        }
    } else {
        // Manager e User vedono solo la propria azienda
        if ($userInfo['tenant_id']) {
            $sql .= " AND t.id = ?";
            $params[] = $userInfo['tenant_id'];
        } else {
            apiSuccess([], 'Nessuna azienda associata');
            exit;
        }
    }

    // Filtro per status
    if ($status) {
        $validStatuses = ['active', 'inactive', 'suspended'];
        if (in_array($status, $validStatuses)) {
            $sql .= " AND t.status = ?";
            $params[] = $status;
        }
    }

    // Filtro per settore merceologico
    if ($settore) {
        $sql .= " AND t.settore_merceologico LIKE ?";
        $params[] = '%' . $settore . '%';
    }

    // Ordinamento
    $sql .= " ORDER BY t.denominazione ASC";

    // Esegui query
    $tenants = $db->fetchAll($sql, $params);

    // Formatta i risultati
    $result = array_map(function($tenant) {
        return [
            'id' => (int)$tenant['id'],
            'denominazione' => $tenant['denominazione'],
            'partita_iva' => $tenant['partita_iva'],
            'codice_fiscale' => $tenant['codice_fiscale'],
            'status' => $tenant['status'],
            'settore_merceologico' => $tenant['settore_merceologico'],
            'numero_dipendenti' => $tenant['numero_dipendenti'] ? (int)$tenant['numero_dipendenti'] : null,
            'telefono' => $tenant['telefono'],
            'email' => $tenant['email'],
            'sede_comune' => $tenant['sede_legale_comune'],
            'sede_provincia' => $tenant['sede_legale_provincia'],
            'sede_indirizzo' => $tenant['sede_legale_indirizzo'],
            'sede_civico' => $tenant['sede_legale_civico'],
            'sede_cap' => $tenant['sede_legale_cap'],
            'sedi_operative_count' => (int)$tenant['sedi_operative_count'],
            'manager_id' => $tenant['manager_id'] ? (int)$tenant['manager_id'] : null,
            'manager_name' => $tenant['manager_name'],
            'created_at' => $tenant['created_at'],
            'updated_at' => $tenant['updated_at']
        ];
    }, $tenants);

    // Risposta di successo
    apiSuccess([
        'tenants' => $result,
        'total' => count($result),
        'filters' => [
            'status' => $status,
            'settore_merceologico' => $settore
        ]
    ], 'Lista aziende recuperata con successo');

} catch (Exception $e) {
    logApiError('tenants/list', $e);
    apiError('Errore durante il recupero delle aziende', 500);
}
