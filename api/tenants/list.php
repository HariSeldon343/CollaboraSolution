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
    // Schema drift safety: optional columns
    $hasTenantRoleManagementRoles = false;
    try {
        $col = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tenants'
               AND COLUMN_NAME = 'tenant_role_management_roles'
             LIMIT 1"
        );
        $hasTenantRoleManagementRoles = ((int)($col['ok'] ?? 0) === 1);
    } catch (Exception $e) {
        $hasTenantRoleManagementRoles = false;
    }

    // Parametri di filtro
    $status = $_GET['status'] ?? null;
    $settore = $_GET['settore_merceologico'] ?? null;

    // Query base con JOIN per ottenere il nome del manager e dati sede legale da tenant_locations
    // NOTE: la UI (aziende.php) si aspetta anche campi strutturati (sede_legale, sedi_operative, flag ruoli custom)
    $tenantRoleManagementSelect = $hasTenantRoleManagementRoles ? "t.tenant_role_management_roles," : "NULL AS tenant_role_management_roles,";
    $sql = "
        SELECT
            t.id,
            t.denominazione,
            t.partita_iva,
            t.codice_fiscale,
            t.status,
            t.settore_merceologico,
            t.numero_dipendenti,
            t.capitale_sociale,
            t.telefono,
            t.email,
            t.pec,
            t.manager_id,
            t.rappresentante_legale,
            t.has_custom_roles,
            t.tenant_role_assignment_roles,
            {$tenantRoleManagementSelect}
            t.sedi_operative,
            t.sede_legale_indirizzo as sede_legale_indirizzo_legacy,
            t.sede_legale_civico as sede_legale_civico_legacy,
            t.sede_legale_cap as sede_legale_cap_legacy,
            t.sede_legale_comune as sede_legale_comune_legacy,
            t.sede_legale_provincia as sede_legale_provincia_legacy,
            u.name as manager_name,
            t.created_at,
            t.updated_at,
            tl.comune as sede_legale_comune,
            tl.provincia as sede_legale_provincia,
            tl.indirizzo as sede_legale_indirizzo,
            tl.civico as sede_legale_civico,
            tl.cap as sede_legale_cap,
            tl.telefono as sede_legale_telefono,
            tl.email as sede_legale_email,
            (SELECT COUNT(*)
             FROM tenant_locations
             WHERE tenant_id = t.id
               AND location_type = 'sede_operativa'
               AND deleted_at IS NULL
               AND is_active = 1) as sedi_operative_count
        FROM tenants t
        LEFT JOIN users u ON t.manager_id = u.id AND u.deleted_at IS NULL
        LEFT JOIN tenant_locations tl ON tl.id = (
            SELECT id
            FROM tenant_locations
            WHERE tenant_id = t.id
              AND location_type = 'sede_legale'
              AND is_primary = 1
              AND deleted_at IS NULL
            ORDER BY id DESC
            LIMIT 1
        )
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
        // Sede legale: preferisci la tabella nuova tenant_locations, fallback su colonne legacy se presenti
        $sedeLegale = null;
        if (!empty($tenant['sede_legale_comune']) || !empty($tenant['sede_legale_indirizzo'])) {
            $sedeLegale = [
                'indirizzo' => $tenant['sede_legale_indirizzo'],
                'civico' => $tenant['sede_legale_civico'],
                'cap' => $tenant['sede_legale_cap'],
                'comune' => $tenant['sede_legale_comune'],
                'provincia' => $tenant['sede_legale_provincia'],
                'telefono' => $tenant['sede_legale_telefono'] ?? null,
                'email' => $tenant['sede_legale_email'] ?? null
            ];
        } elseif (!empty($tenant['sede_legale_comune_legacy']) || !empty($tenant['sede_legale_indirizzo_legacy'])) {
            // Fallback su colonne legacy in tenants
            $sedeLegale = [
                'indirizzo' => $tenant['sede_legale_indirizzo_legacy'] ?? null,
                'civico' => $tenant['sede_legale_civico_legacy'] ?? null,
                'cap' => $tenant['sede_legale_cap_legacy'] ?? null,
                'comune' => $tenant['sede_legale_comune_legacy'] ?? null,
                'provincia' => $tenant['sede_legale_provincia_legacy'] ?? null
            ];
        }

        // Sedi operative: preferisci JSON legacy (tenants.sedi_operative) mantenuto sincronizzato dalle API
        $sediOperative = [];
        if (!empty($tenant['sedi_operative'])) {
            $decoded = json_decode((string)$tenant['sedi_operative'], true);
            if (is_array($decoded)) {
                $sediOperative = $decoded;
            }
        }

        return [
            'id' => (int)$tenant['id'],
            'denominazione' => $tenant['denominazione'],
            'partita_iva' => $tenant['partita_iva'],
            'codice_fiscale' => $tenant['codice_fiscale'],
            'status' => $tenant['status'],
            'settore_merceologico' => $tenant['settore_merceologico'],
            'numero_dipendenti' => $tenant['numero_dipendenti'] ? (int)$tenant['numero_dipendenti'] : null,
            'capitale_sociale' => $tenant['capitale_sociale'] !== null ? (float)$tenant['capitale_sociale'] : null,
            'telefono' => $tenant['telefono'],
            'email' => $tenant['email'],
            // Alias per UI legacy (aziende.php usa email_aziendale in alcuni punti)
            'email_aziendale' => $tenant['email'],
            'pec' => $tenant['pec'],
            'rappresentante_legale' => $tenant['rappresentante_legale'],
            'sede_comune' => $tenant['sede_legale_comune'],
            'sede_provincia' => $tenant['sede_legale_provincia'],
            'sede_indirizzo' => $tenant['sede_legale_indirizzo'],
            'sede_civico' => $tenant['sede_legale_civico'],
            'sede_cap' => $tenant['sede_legale_cap'],
            // Shape atteso dalla UI moderna
            'sede_legale' => $sedeLegale,
            'sedi_operative' => $sediOperative,
            'sedi_operative_count' => (int)$tenant['sedi_operative_count'],
            'manager_id' => $tenant['manager_id'] ? (int)$tenant['manager_id'] : null,
            // Alias per UI legacy (aziende.php usa manager_user_id in alcuni punti)
            'manager_user_id' => $tenant['manager_id'] ? (int)$tenant['manager_id'] : null,
            'manager_name' => $tenant['manager_name'],
            'has_custom_roles' => (bool)$tenant['has_custom_roles'],
            'tenant_role_assignment_roles' => $tenant['tenant_role_assignment_roles'] ?? null,
            'tenant_role_management_roles' => $tenant['tenant_role_management_roles'] ?? null,
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
