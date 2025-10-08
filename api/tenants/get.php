<?php
/**
 * API: Dettaglio Azienda (Tenant)
 *
 * Endpoint per ottenere tutti i dati completi di un'azienda specifica
 *
 * Method: GET
 * Auth: Admin o Super Admin
 * Params: tenant_id (required)
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

// Richiede ruolo Admin o superiore
requireApiRole('admin');

// Carica database
require_once '../../includes/db.php';
$db = Database::getInstance();

try {
    // Validazione parametro tenant_id
    if (empty($_GET['tenant_id'])) {
        apiError('Parametro tenant_id obbligatorio', 400);
    }

    $tenantId = (int)$_GET['tenant_id'];

    // Tenant isolation
    if ($userInfo['role'] !== 'super_admin') {
        // Verifica che l'admin abbia accesso a questo tenant
        $hasAccess = false;

        // Controlla tenant primario
        if ($userInfo['tenant_id'] == $tenantId) {
            $hasAccess = true;
        } else {
            // Controlla accessi multi-tenant
            $accessCheck = $db->fetchOne(
                'SELECT id FROM user_tenant_access WHERE user_id = ? AND tenant_id = ?',
                [$userInfo['user_id'], $tenantId]
            );
            if ($accessCheck) {
                $hasAccess = true;
            }
        }

        if (!$hasAccess) {
            apiError('Non hai i permessi per accedere a questa azienda', 403);
        }
    }

    // Query completa con JOIN per manager
    $sql = "
        SELECT
            t.*,
            u.name as manager_name,
            u.email as manager_email,
            u.phone as manager_phone
        FROM tenants t
        LEFT JOIN users u ON t.manager_id = u.id AND u.deleted_at IS NULL
        WHERE t.id = ?
    ";

    $tenant = $db->fetchOne($sql, [$tenantId]);

    if (!$tenant) {
        apiError('Azienda non trovata', 404);
    }

    // Fetch sede legale from tenant_locations table
    $sedeLegale = $db->fetchOne(
        'SELECT * FROM tenant_locations
         WHERE tenant_id = ?
           AND location_type = "sede_legale"
           AND deleted_at IS NULL
         LIMIT 1',
        [$tenantId]
    );

    // Format sede legale
    if ($sedeLegale) {
        $sedeLegale = [
            'id' => (int)$sedeLegale['id'],
            'indirizzo' => $sedeLegale['indirizzo'],
            'civico' => $sedeLegale['civico'],
            'cap' => $sedeLegale['cap'],
            'comune' => $sedeLegale['comune'],
            'provincia' => $sedeLegale['provincia'],
            'telefono' => $sedeLegale['telefono'],
            'email' => $sedeLegale['email'],
            'is_primary' => (bool)$sedeLegale['is_primary']
        ];
    }

    // Fetch sedi operative from tenant_locations table
    $sediOperativeRaw = $db->fetchAll(
        'SELECT * FROM tenant_locations
         WHERE tenant_id = ?
           AND location_type = "sede_operativa"
           AND deleted_at IS NULL
         ORDER BY created_at ASC',
        [$tenantId]
    );

    // Format sedi operative
    $sediOperative = [];
    foreach ($sediOperativeRaw as $sede) {
        $sediOperative[] = [
            'id' => (int)$sede['id'],
            'indirizzo' => $sede['indirizzo'],
            'civico' => $sede['civico'],
            'cap' => $sede['cap'],
            'comune' => $sede['comune'],
            'provincia' => $sede['provincia'],
            'telefono' => $sede['telefono'],
            'email' => $sede['email'],
            'manager_nome' => $sede['manager_nome'],
            'note' => $sede['note'],
            'is_active' => (bool)$sede['is_active']
        ];
    }

    // Formatta risposta completa
    $result = [
        // Identificazione
        'id' => (int)$tenant['id'],
        'denominazione' => $tenant['denominazione'],
        'name' => $tenant['name'], // Campo legacy
        'codice_fiscale' => $tenant['codice_fiscale'],
        'partita_iva' => $tenant['partita_iva'],

        // Sede legale
        'sede_legale' => $sedeLegale,

        // Sedi operative
        'sedi_operative' => $sediOperative,

        // Informazioni aziendali
        'settore_merceologico' => $tenant['settore_merceologico'],
        'numero_dipendenti' => $tenant['numero_dipendenti'] ? (int)$tenant['numero_dipendenti'] : null,
        'capitale_sociale' => $tenant['capitale_sociale'] ? (float)$tenant['capitale_sociale'] : null,

        // Contatti
        'telefono' => $tenant['telefono'],
        'email' => $tenant['email'],
        'pec' => $tenant['pec'],

        // Manager
        'manager_id' => $tenant['manager_id'] ? (int)$tenant['manager_id'] : null,
        'manager_name' => $tenant['manager_name'],
        'manager_email' => $tenant['manager_email'],
        'manager_phone' => $tenant['manager_phone'],

        // Rappresentante legale
        'rappresentante_legale' => $tenant['rappresentante_legale'],

        // Status e limiti
        'status' => $tenant['status'],
        'max_users' => $tenant['max_users'] ? (int)$tenant['max_users'] : null,
        'max_storage_gb' => $tenant['max_storage_gb'] ? (int)$tenant['max_storage_gb'] : null,

        // Settings JSON
        'settings' => !empty($tenant['settings']) ? json_decode($tenant['settings'], true) : null,

        // Timestamp
        'created_at' => $tenant['created_at'],
        'updated_at' => $tenant['updated_at']
    ];

    // Statistiche aggiuntive (opzionale)
    $stats = [
        'total_users' => $db->count('users', [
            'tenant_id' => $tenantId,
            'deleted_at' => null
        ]),
        'active_users' => $db->count('users', [
            'tenant_id' => $tenantId,
            'status' => 'active',
            'deleted_at' => null
        ]),
        'total_projects' => $db->count('projects', [
            'tenant_id' => $tenantId
        ]),
        'total_files' => $db->count('files', [
            'tenant_id' => $tenantId,
            'deleted_at' => null
        ])
    ];

    $result['statistics'] = $stats;

    // Risposta di successo
    apiSuccess($result, 'Dettagli azienda recuperati con successo');

} catch (Exception $e) {
    logApiError('tenants/get', $e);
    apiError('Errore durante il recupero dell\'azienda', 500);
}
