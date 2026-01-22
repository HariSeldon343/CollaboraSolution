<?php
/**
 * API: Creazione Azienda (Tenant)
 *
 * Endpoint per creare una nuova azienda con tutti i dati completi
 *
 * Method: POST
 * Auth: Admin o Super Admin
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

// Richiede ruolo Admin o superiore
requireApiRole('admin');

// Carica database
require_once '../../includes/db.php';
$db = Database::getInstance();

// BUG-155 FIX: Carica funzioni di validazione dal file condiviso
// Evita duplicazione codice e conflitti con update.php
require_once __DIR__ . '/tenant_validators.php';
// Ensure tenant root folder exists (files.php file manager)
require_once __DIR__ . '/../../includes/tenant_folder_helper.php';

/**
 * Schema-drift helpers (avoid 500 when columns/tables are missing across installs)
 */
function cnx_table_exists(Database $db, string $table): bool {
    try {
        $row = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1",
            [$table]
        );
        return ((int)($row['ok'] ?? 0) === 1);
    } catch (Throwable $e) {
        return false;
    }
}

function cnx_get_table_columns(Database $db, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $cols = [];
    try {
        $rows = $db->fetchAll(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?",
            [$table]
        );
        foreach ($rows as $r) {
            if (!empty($r['COLUMN_NAME'])) {
                $cols[] = (string)$r['COLUMN_NAME'];
            }
        }
    } catch (Throwable $e) {
        $cols = [];
    }

    $cache[$table] = $cols;
    return $cols;
}

function cnx_filter_table_data(Database $db, string $table, array $data): array {
    if (empty($data)) return [];
    $cols = cnx_get_table_columns($db, $table);
    if (empty($cols)) return [];
    $allowed = array_flip($cols);
    return array_intersect_key($data, $allowed);
}

try {
    // Leggi input JSON (body may have been read already by CSRF validation; use cached raw body if present)
    $raw = $GLOBALS['CNX_RAW_BODY'] ?? file_get_contents('php://input');
    $input = json_decode($raw ?: '', true);

    if (!$input || !is_array($input)) {
        // Fallback to form data
        if (!empty($_POST) && is_array($_POST)) {
            $input = $_POST;
        } else {
            apiError('Dati di input non validi', 400);
        }
    }

    // Validazione campi obbligatori
    $errors = [];
    $locationsStorageAvailable = cnx_table_exists($db, 'tenant_locations');
    $tenantCols = cnx_get_table_columns($db, 'tenants');
    $tenantHasCf = in_array('codice_fiscale', $tenantCols, true);
    $tenantHasPiva = in_array('partita_iva', $tenantCols, true);

    // 1. Denominazione NON obbligatoria (UI: richiesta solo CF/PIVA + sede legale + settore + stato)
    // Se mancante/vuota, generiamo una denominazione di default per non rompere DB/API.

    // 2. CF OR P.IVA obbligatorio (almeno uno)
    $cf = !empty($input['codice_fiscale']) ? trim($input['codice_fiscale']) : null;
    $piva = !empty($input['partita_iva']) ? trim($input['partita_iva']) : null;

    // Enforce only if DB actually has at least one of those columns
    if (($tenantHasCf || $tenantHasPiva) && !$cf && !$piva) {
        $errors[] = 'Codice Fiscale o Partita IVA obbligatorio (almeno uno)';
    }

    // Valida CF se presente
    if ($tenantHasCf && $cf && !validateCodiceFiscale($cf)) {
        $errors[] = 'Codice Fiscale non valido (deve essere 16 caratteri alfanumerici)';
    }

    // Valida P.IVA se presente
    if ($tenantHasPiva && $piva && !validatePartitaIva($piva)) {
        $errors[] = 'Partita IVA non valida (deve essere 11 cifre con checksum corretto)';
    }

    // Unicità: impedisci duplicati per CF/P.IVA (soft delete aware)
    if (($tenantHasCf || $tenantHasPiva) && ($cf || $piva)) {
        if (!$tenantHasCf) $cf = null;
        if (!$tenantHasPiva) $piva = null;
        $dup = $db->fetchOne(
            "SELECT id, denominazione, codice_fiscale, partita_iva
             FROM tenants
             WHERE deleted_at IS NULL
               AND (
                 (:cf IS NOT NULL AND :cf <> '' AND UPPER(codice_fiscale) = UPPER(:cf))
                 OR
                 (:piva IS NOT NULL AND :piva <> '' AND partita_iva = :piva)
               )
             LIMIT 1",
            [':cf' => $cf, ':piva' => $piva]
        );
        if ($dup) {
            apiError(
                'Esiste già un\'azienda con lo stesso Codice Fiscale o Partita IVA (ID ' . (int)$dup['id'] . ').',
                409,
                ['duplicate_tenant_id' => (int)$dup['id']]
            );
        }
    }

    // 3. Sede legale completa obbligatoria
    if (empty($input['sede_legale'])) {
        $errors[] = 'Sede legale obbligatoria';
    } else {
        $sedeErrors = validateSedeLegale($input['sede_legale']);
        $errors = array_merge($errors, $sedeErrors);
    }

    // 3b. Settore merceologico obbligatorio (richiesto dal form)
    if (empty($input['settore_merceologico'])) {
        $errors[] = 'Settore merceologico obbligatorio';
    }

    // 4. Valida sedi operative (opzionale, max 5)
    if (!empty($input['sedi_operative'])) {
        if (!is_array($input['sedi_operative'])) {
            $errors[] = 'Sedi operative deve essere un array';
        } else {
            $sediErrors = validateSediOperative($input['sedi_operative']);
            $errors = array_merge($errors, $sediErrors);
        }
    }

    // 5. Valida manager_id (deve esistere)
    if (!empty($input['manager_id'])) {
        $managerId = (int)$input['manager_id'];

        // Verifica che il manager esista
        $managerExists = $db->exists('users', [
            'id' => $managerId,
            'deleted_at' => null
        ]);

        if (!$managerExists) {
            $errors[] = 'Manager non trovato (ID: ' . $managerId . ')';
        } else {
            // Verifica che il manager abbia il ruolo corretto
            $manager = $db->fetchOne(
                'SELECT role FROM users WHERE id = ? AND deleted_at IS NULL',
                [$managerId]
            );

            if (!in_array($manager['role'], ['manager', 'admin', 'super_admin'])) {
                $errors[] = 'L\'utente selezionato non ha il ruolo di Manager/Admin';
            }
        }
    }

    // 6. Valida email e PEC
    if (!empty($input['email']) && !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email non valida';
    }

    if (!empty($input['pec']) && !filter_var($input['pec'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'PEC non valida';
    }

    // 7. Valida telefono
    if (!empty($input['telefono']) && !validateTelefono($input['telefono'])) {
        $errors[] = 'Numero di telefono non valido (formato italiano richiesto)';
    }

    // 8. Valida status
    $validStatuses = ['active', 'inactive', 'suspended'];
    $status = $input['status'] ?? 'active';
    if (!in_array($status, $validStatuses)) {
        $errors[] = 'Status non valido (deve essere: active, inactive, suspended)';
    }

    // Se ci sono errori, restituiscili
    if (!empty($errors)) {
        apiError('Validazione fallita: ' . implode('; ', $errors), 400, ['errors' => $errors]);
    }

    // Denominazione: se vuota, usa un default informativo
    $denRaw = isset($input['denominazione']) ? trim((string)$input['denominazione']) : '';
    if ($denRaw === '') {
        $suffix = $piva ?: ($cf ?: '');
        $denRaw = $suffix !== '' ? ('Azienda ' . $suffix) : 'Azienda';
    }

    // Prepara i dati per l'inserimento
    $tenantData = [
        'name' => $denRaw, // Mantieni compatibilità con campo legacy
        'denominazione' => $denRaw,
        'codice_fiscale' => $cf ? strtoupper($cf) : null,
        'partita_iva' => $piva,
        'status' => $status
    ];

    // Sede legale - DEPRECATED: Mantieni per backward compatibility
    if (!empty($input['sede_legale'])) {
        $sede = $input['sede_legale'];
        $tenantData['sede_legale_indirizzo'] = trim($sede['indirizzo']);
        $tenantData['sede_legale_civico'] = trim($sede['civico']);
        $tenantData['sede_legale_comune'] = trim($sede['comune']);
        $tenantData['sede_legale_provincia'] = strtoupper(trim($sede['provincia']));
        $tenantData['sede_legale_cap'] = trim($sede['cap']);
    }

    // Sedi operative - DEPRECATED: Mantieni per backward compatibility
    if (!empty($input['sedi_operative']) && is_array($input['sedi_operative'])) {
        $tenantData['sedi_operative'] = json_encode($input['sedi_operative']);
    }

    // Informazioni aziendali
    if (!empty($input['settore_merceologico'])) {
        $tenantData['settore_merceologico'] = trim($input['settore_merceologico']);
    }

    if (isset($input['numero_dipendenti'])) {
        $tenantData['numero_dipendenti'] = (int)$input['numero_dipendenti'];
    }

    if (isset($input['capitale_sociale'])) {
        $tenantData['capitale_sociale'] = (float)$input['capitale_sociale'];
    }

    // Contatti
    if (!empty($input['telefono'])) {
        $tenantData['telefono'] = trim($input['telefono']);
    }

    if (!empty($input['email'])) {
        $tenantData['email'] = trim($input['email']);
    }

    if (!empty($input['pec'])) {
        $tenantData['pec'] = trim($input['pec']);
    }

    // Manager e rappresentante legale
    if (!empty($input['manager_id'])) {
        $tenantData['manager_id'] = (int)$input['manager_id'];
    }

    if (!empty($input['rappresentante_legale'])) {
        $tenantData['rappresentante_legale'] = trim($input['rappresentante_legale']);
    }

    // Inserimento in transazione
    $db->beginTransaction();

    try {
        // Filter tenantData based on actual columns available in this DB (schema drift safe)
        $tenantData = cnx_filter_table_data($db, 'tenants', $tenantData);
        if (empty($tenantData)) {
            apiError('Impossibile creare azienda: schema tenants non allineato', 503);
        }

        // Inserisci il tenant
        $tenantId = $db->insert('tenants', $tenantData);

        // Inserisci sede legale nella nuova tabella tenant_locations
        if (!empty($input['sede_legale']) && $locationsStorageAvailable) {
            $sedeLegale = $input['sede_legale'];

            $ins = [
                'tenant_id' => $tenantId,
                'location_type' => 'sede_legale',
                'indirizzo' => trim($sedeLegale['indirizzo']),
                'civico' => trim($sedeLegale['civico']),
                'cap' => trim($sedeLegale['cap']),
                'comune' => trim($sedeLegale['comune']),
                'provincia' => strtoupper(trim($sedeLegale['provincia'])),
                'telefono' => !empty($sedeLegale['telefono']) ? trim($sedeLegale['telefono']) : null,
                'email' => !empty($sedeLegale['email']) ? trim($sedeLegale['email']) : null,
                'is_primary' => 1,
                'is_active' => 1
            ];
            $ins = cnx_filter_table_data($db, 'tenant_locations', $ins);
            if (!empty($ins)) {
                $db->insert('tenant_locations', $ins);
            }
        }

        // Inserisci sedi operative nella nuova tabella tenant_locations
        if (!empty($input['sedi_operative']) && is_array($input['sedi_operative']) && $locationsStorageAvailable) {
            foreach ($input['sedi_operative'] as $sedeOp) {
                $ins = [
                    'tenant_id' => $tenantId,
                    'location_type' => 'sede_operativa',
                    'indirizzo' => trim($sedeOp['indirizzo']),
                    'civico' => !empty($sedeOp['civico']) ? trim($sedeOp['civico']) : 'SN',
                    'cap' => !empty($sedeOp['cap']) ? trim($sedeOp['cap']) : '00000',
                    'comune' => trim($sedeOp['comune']),
                    'provincia' => !empty($sedeOp['provincia']) ? strtoupper(trim($sedeOp['provincia'])) : 'XX',
                    'telefono' => !empty($sedeOp['telefono']) ? trim($sedeOp['telefono']) : null,
                    'email' => !empty($sedeOp['email']) ? trim($sedeOp['email']) : null,
                    'manager_nome' => !empty($sedeOp['manager_nome']) ? trim($sedeOp['manager_nome']) : null,
                    'note' => !empty($sedeOp['note']) ? trim($sedeOp['note']) : null,
                    'is_primary' => 0,
                    'is_active' => 1
                ];
                $ins = cnx_filter_table_data($db, 'tenant_locations', $ins);
                if (!empty($ins)) {
                    $db->insert('tenant_locations', $ins);
                }
            }
        }

        // Log audit (NON-BLOCKING)
        try {
            $db->insert('audit_logs', [
                'tenant_id' => $tenantId,
                'user_id' => $userInfo['user_id'],
                'action' => 'create',
                'entity_type' => 'tenant',
                'entity_id' => $tenantId,
                'new_values' => json_encode([
                    'tenant' => $tenantData,
                    'locations_created' => (isset($input['sede_legale']) ? 1 : 0) +
                                           (isset($input['sedi_operative']) ? count($input['sedi_operative']) : 0)
                ], JSON_UNESCAPED_UNICODE),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log('[AUDIT] tenants/create audit insert failed: ' . $e->getMessage());
        }

        $db->commit();

        // Auto-create tenant root folder (non-blocking)
        try {
            cnx_ensure_tenant_root_folder(
                $db,
                (int)$tenantId,
                (string)($tenantData['denominazione'] ?? $tenantData['name'] ?? ('Tenant ' . (int)$tenantId))
            );
        } catch (Exception $e) {
            error_log('[tenants/create] ensure tenant root folder failed: ' . $e->getMessage());
        }

        // Risposta di successo
        apiSuccess([
            'tenant_id' => $tenantId,
            'denominazione' => $tenantData['denominazione'],
            'locations_created' => (isset($input['sede_legale']) ? 1 : 0) +
                                   (isset($input['sedi_operative']) ? count($input['sedi_operative']) : 0),
            'locations_storage_available' => $locationsStorageAvailable
        ], 'Azienda creata con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logApiError('tenants/create', $e);
    apiError('Errore durante la creazione dell\'azienda', 500);
}
