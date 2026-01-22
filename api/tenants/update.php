<?php
/**
 * API: Aggiornamento Azienda (Tenant)
 *
 * Endpoint per aggiornare i dati di un'azienda esistente
 *
 * Method: PUT (compat) / POST
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
// NON includere create.php che eseguirebbe la sua logica di creazione!
require_once __DIR__ . '/tenant_validators.php';

function cnx_tenants_update_log(string $stage, array $ctx = []): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $ct = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    $raw = (string)($GLOBALS['CNX_RAW_BODY'] ?? '');
    $safeRaw = substr(preg_replace('/\s+/', ' ', $raw), 0, 200);
    $base = [
        'stage' => $stage,
        'method' => $method,
        'content_type' => $ct,
        'raw_len' => strlen($raw),
        'has_csrf_header' => !empty($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''),
        'has_csrf_body' => strpos($safeRaw, 'csrf_token') !== false,
        'has_tenant_id' => strpos($safeRaw, 'tenant_id') !== false,
        'raw_head' => $safeRaw,
    ];
    error_log('[tenants/update] ' . json_encode(array_merge($base, $ctx), JSON_UNESCAPED_UNICODE));
}

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
    // Allow POST as compatibility fallback for environments that block PUT bodies
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['PUT', 'POST'], true)) {
        apiError('Metodo non consentito', 405);
    }

    // Leggi input JSON
    $raw = $GLOBALS['CNX_RAW_BODY'] ?? file_get_contents('php://input');
    $input = json_decode($raw ?: '', true);

    if (!$input || !is_array($input)) {
        // Fallback for POST form data or when PUT body is stripped by server/proxy
        if (!empty($_POST) && is_array($_POST)) {
            $input = $_POST;
        } else {
            cnx_tenants_update_log('invalid_input', ['raw_len' => strlen((string)$raw)]);
            apiError('Dati di input non validi', 400);
        }
    }

    // Validazione ID tenant obbligatorio
    if (empty($input['tenant_id'])) {
        cnx_tenants_update_log('missing_tenant_id', ['input_keys' => array_keys($input)]);
        apiError('ID azienda obbligatorio', 400);
    }

    $tenantId = (int)$input['tenant_id'];

    // Verifica che il tenant esista
    $existingTenant = $db->fetchOne(
        'SELECT * FROM tenants WHERE id = ?',
        [$tenantId]
    );

    if (!$existingTenant) {
        apiError('Azienda non trovata', 404);
    }

    // Tenant isolation: Admin può modificare solo i suoi tenants
    // Super Admin può modificare tutti
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
            cnx_tenants_update_log('access_denied', ['tenant_id' => $tenantId, 'user_id' => $userInfo['user_id'] ?? null, 'role' => $userInfo['role'] ?? null]);
            apiError('Non hai i permessi per modificare questa azienda', 403);
        }
    }

    // Validazione campi (simile a create.php ma tutti opzionali)
    $errors = [];
    $updateData = [];
    $locationsStorageAvailable = cnx_table_exists($db, 'tenant_locations');
    $tenantCols = cnx_get_table_columns($db, 'tenants');
    $tenantHasCf = in_array('codice_fiscale', $tenantCols, true);
    $tenantHasPiva = in_array('partita_iva', $tenantCols, true);

    // 1. Denominazione (non obbligatoria)
    // Se viene inviata vuota, genera un default (invece di bloccare con errore)
    if (isset($input['denominazione'])) {
        $den = trim((string)$input['denominazione']);
        if ($den === '') {
            // usa CF/PIVA finale (se presenti) oppure quelli già esistenti
            $fallbackPiva = null;
            $fallbackCf = null;
            if (isset($input['partita_iva'])) {
                $t = trim((string)$input['partita_iva']);
                $fallbackPiva = $t !== '' ? $t : null;
            } else {
                $fallbackPiva = !empty($existingTenant['partita_iva']) ? (string)$existingTenant['partita_iva'] : null;
            }
            if (isset($input['codice_fiscale'])) {
                $t = trim((string)$input['codice_fiscale']);
                $fallbackCf = $t !== '' ? strtoupper($t) : null;
            } else {
                $fallbackCf = !empty($existingTenant['codice_fiscale']) ? (string)$existingTenant['codice_fiscale'] : null;
            }
            $suffix = $fallbackPiva ?: ($fallbackCf ?: '');
            $den = $suffix !== '' ? ('Azienda ' . $suffix) : 'Azienda';
        }
        $updateData['denominazione'] = $den;
        $updateData['name'] = $den; // Mantieni compatibilità
    }

    // 2. Codice Fiscale
    if (isset($input['codice_fiscale'])) {
        $cf = trim($input['codice_fiscale']);
        if (!empty($cf) && !validateCodiceFiscale($cf)) {
            $errors[] = 'Codice Fiscale non valido';
        }
        $updateData['codice_fiscale'] = !empty($cf) ? strtoupper($cf) : null;
    }

    // 3. Partita IVA
    if (isset($input['partita_iva'])) {
        $piva = trim($input['partita_iva']);
        if (!empty($piva) && !validatePartitaIva($piva)) {
            $errors[] = 'Partita IVA non valida';
        }
        $updateData['partita_iva'] = !empty($piva) ? $piva : null;
    }

    // Unicità CF/P.IVA (evita duplicati come quelli che stai vedendo in lista)
    // Controlla solo se si stanno modificando questi campi.
    if (($tenantHasCf || $tenantHasPiva) && (array_key_exists('codice_fiscale', $updateData) || array_key_exists('partita_iva', $updateData))) {
        $candidateCf = $updateData['codice_fiscale'] ?? $existingTenant['codice_fiscale'];
        $candidatePiva = $updateData['partita_iva'] ?? $existingTenant['partita_iva'];

        // If a column doesn't exist in this DB snapshot, ignore it in duplicate check.
        if (!$tenantHasCf) $candidateCf = null;
        if (!$tenantHasPiva) $candidatePiva = null;

        $dup = $db->fetchOne(
            "SELECT id
             FROM tenants
             WHERE deleted_at IS NULL
               AND id <> ?
               AND (
                 (? IS NOT NULL AND ? <> '' AND UPPER(codice_fiscale) = UPPER(?))
                 OR
                 (? IS NOT NULL AND ? <> '' AND partita_iva = ?)
               )
             LIMIT 1",
            [
                $tenantId,
                $candidateCf, $candidateCf, $candidateCf,
                $candidatePiva, $candidatePiva, $candidatePiva
            ]
        );

        if ($dup) {
            apiError(
                'Esiste già un\'azienda con lo stesso Codice Fiscale o Partita IVA (ID ' . (int)$dup['id'] . ').',
                409,
                ['duplicate_tenant_id' => (int)$dup['id']]
            );
        }
    }

    // Verifica che almeno uno tra CF e P.IVA sia presente SOLO quando si sta modificando CF/P.IVA
    // (evita blocchi su aggiornamenti non anagrafici, es. toggle has_custom_roles)
    if (($tenantHasCf || $tenantHasPiva) && (array_key_exists('codice_fiscale', $updateData) || array_key_exists('partita_iva', $updateData))) {
        $finalCf = $updateData['codice_fiscale'] ?? $existingTenant['codice_fiscale'];
        $finalPiva = $updateData['partita_iva'] ?? $existingTenant['partita_iva'];

        if (!$finalCf && !$finalPiva) {
            $errors[] = 'Almeno uno tra Codice Fiscale e Partita IVA deve essere presente';
        }
    }

    // 4. Sede legale - Validazione (aggiornamento verrà fatto in tenant_locations)
    $updateSedeLegale = false;
    $newSedeLegale = null;
    if (isset($input['sede_legale']) && !empty($input['sede_legale'])) {
        $sedeErrors = validateSedeLegale($input['sede_legale']);
        if (!empty($sedeErrors)) {
            $errors = array_merge($errors, $sedeErrors);
        } else {
            $updateSedeLegale = true;
            $newSedeLegale = $input['sede_legale'];

            // DEPRECATED: Mantieni sincronizzazione con colonne legacy
            $sede = $input['sede_legale'];
            $updateData['sede_legale_indirizzo'] = trim($sede['indirizzo']);
            $updateData['sede_legale_civico'] = trim($sede['civico']);
            $updateData['sede_legale_comune'] = trim($sede['comune']);
            $updateData['sede_legale_provincia'] = strtoupper(trim($sede['provincia']));
            $updateData['sede_legale_cap'] = trim($sede['cap']);
        }
    }

    // 5. Sedi operative - Validazione (aggiornamento verrà fatto in tenant_locations)
    $updateSediOperative = false;
    $newSediOperative = [];
    if (isset($input['sedi_operative'])) {
        if (!is_array($input['sedi_operative'])) {
            $errors[] = 'Sedi operative deve essere un array';
        } else {
            $sediErrors = validateSediOperative($input['sedi_operative']);
            if (!empty($sediErrors)) {
                $errors = array_merge($errors, $sediErrors);
            } else {
                $updateSediOperative = true;
                $newSediOperative = $input['sedi_operative'];

                // DEPRECATED: Mantieni sincronizzazione con colonna legacy
                $updateData['sedi_operative'] = json_encode($input['sedi_operative']);
            }
        }
    }

    // 6. Manager ID
    if (isset($input['manager_id'])) {
        if (!empty($input['manager_id'])) {
            $managerId = (int)$input['manager_id'];

            $managerExists = $db->exists('users', [
                'id' => $managerId,
                'deleted_at' => null
            ]);

            if (!$managerExists) {
                $errors[] = 'Manager non trovato';
            } else {
                $manager = $db->fetchOne(
                    'SELECT role FROM users WHERE id = ? AND deleted_at IS NULL',
                    [$managerId]
                );

                if (!in_array($manager['role'], ['manager', 'admin', 'super_admin'])) {
                    $errors[] = 'L\'utente selezionato non ha il ruolo di Manager/Admin';
                }
            }

            $updateData['manager_id'] = $managerId;
        } else {
            $updateData['manager_id'] = null;
        }
    }

    // 7. Informazioni aziendali
    if (isset($input['settore_merceologico'])) {
        if (empty($input['settore_merceologico'])) {
            $errors[] = 'Settore merceologico obbligatorio';
        } else {
            $updateData['settore_merceologico'] = trim((string)$input['settore_merceologico']);
        }
    }

    if (isset($input['numero_dipendenti'])) {
        $updateData['numero_dipendenti'] = !empty($input['numero_dipendenti'])
            ? (int)$input['numero_dipendenti']
            : null;
    }

    if (isset($input['capitale_sociale'])) {
        $updateData['capitale_sociale'] = !empty($input['capitale_sociale'])
            ? (float)$input['capitale_sociale']
            : null;
    }

    // 8. Contatti
    if (isset($input['telefono'])) {
        $tel = trim($input['telefono']);
        if (!empty($tel) && !validateTelefono($tel)) {
            $errors[] = 'Numero di telefono non valido';
        }
        $updateData['telefono'] = !empty($tel) ? $tel : null;
    }

    if (isset($input['email'])) {
        $email = trim($input['email']);
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email non valida';
        }
        $updateData['email'] = !empty($email) ? $email : null;
    }

    if (isset($input['pec'])) {
        $pec = trim($input['pec']);
        if (!empty($pec) && !filter_var($pec, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'PEC non valida';
        }
        $updateData['pec'] = !empty($pec) ? $pec : null;
    }

    // 9. Rappresentante legale
    if (isset($input['rappresentante_legale'])) {
        $updateData['rappresentante_legale'] = !empty($input['rappresentante_legale'])
            ? trim($input['rappresentante_legale'])
            : null;
    }

    // 10. Status
    if (isset($input['status'])) {
        $validStatuses = ['active', 'inactive', 'suspended'];
        if (!in_array($input['status'], $validStatuses)) {
            $errors[] = 'Status non valido';
        }
        $updateData['status'] = $input['status'];
    }

    // 11. Ruoli aziendali personalizzati (Tenant Roles)
    // Allow toggling has_custom_roles without forcing other validations.
    if (array_key_exists('has_custom_roles', $input)) {
        $updateData['has_custom_roles'] = (int)(bool)$input['has_custom_roles'];
    }

    // 12. Permessi assegnazione Ruolo Aziendale (per-tenant)
    // Default: solo super_admin può assegnare ruoli aziendali agli utenti.
    // Il super_admin può abilitare per-tenant quali ruoli di sistema possono assegnare ruoli aziendali.
    if (array_key_exists('tenant_role_assignment_roles', $input)) {
        if (($userInfo['role'] ?? 'user') !== 'super_admin') {
            apiError('Solo super admin può modificare i permessi di assegnazione ruoli aziendali', 403);
        }

        // Feature-detect column existence (BUG-156 pattern)
        $hasAssignmentCol = false;
        try {
            $col = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'tenants'
                   AND COLUMN_NAME = 'tenant_role_assignment_roles'
                 LIMIT 1"
            );
            $hasAssignmentCol = ((int)($col['ok'] ?? 0) === 1);
        } catch (Exception $e) {
            $hasAssignmentCol = false;
        }

        if ($hasAssignmentCol) {
            $roles = $input['tenant_role_assignment_roles'];
            if ($roles === null || $roles === '' || $roles === 'null') {
                $updateData['tenant_role_assignment_roles'] = null;
            } elseif (!is_array($roles)) {
                apiError('tenant_role_assignment_roles deve essere un array', 400);
            } else {
                $allowed = ['super_admin', 'admin', 'manager', 'user'];
                $clean = [];
                foreach ($roles as $r) {
                    $r = trim((string)$r);
                    if ($r !== '' && in_array($r, $allowed, true)) {
                        $clean[] = $r;
                    }
                }
                $clean = array_values(array_unique($clean));
                $updateData['tenant_role_assignment_roles'] = json_encode($clean, JSON_UNESCAPED_UNICODE);
            }
        }
    }

    // 13. Permessi gestione Ruoli Aziendali (creazione/modifica/eliminazione) (per-tenant)
    // Default: solo super_admin può gestire ruoli aziendali.
    // Il super_admin può abilitare per-tenant quali ruoli di sistema possono gestire tenant_roles.
    if (array_key_exists('tenant_role_management_roles', $input)) {
        if (($userInfo['role'] ?? 'user') !== 'super_admin') {
            apiError('Solo super admin può modificare i permessi di gestione ruoli aziendali', 403);
        }

        $hasManageCol = false;
        try {
            $col = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'tenants'
                   AND COLUMN_NAME = 'tenant_role_management_roles'
                 LIMIT 1"
            );
            $hasManageCol = ((int)($col['ok'] ?? 0) === 1);
        } catch (Exception $e) {
            $hasManageCol = false;
        }

        if (!$hasManageCol) {
            apiError(
                'Permessi gestione ruoli aziendali non disponibili su questa installazione (migrazione mancante)',
                503,
                ['migration' => 'database/migrations/59_tenant_role_management_permissions.sql']
            );
        }

        $roles = $input['tenant_role_management_roles'];
        if ($roles === null || $roles === '' || $roles === 'null') {
            $updateData['tenant_role_management_roles'] = null;
        } elseif (!is_array($roles)) {
            apiError('tenant_role_management_roles deve essere un array', 400);
        } else {
            $allowed = ['super_admin', 'admin', 'manager', 'user'];
            $clean = [];
            foreach ($roles as $r) {
                $r = trim((string)$r);
                if ($r !== '' && in_array($r, $allowed, true)) {
                    $clean[] = $r;
                }
            }
            $clean = array_values(array_unique($clean));
            $updateData['tenant_role_management_roles'] = json_encode($clean, JSON_UNESCAPED_UNICODE);
        }
    }

    // Se ci sono errori, restituiscili
    if (!empty($errors)) {
        cnx_tenants_update_log('validation_failed', ['tenant_id' => $tenantId, 'errors' => $errors]);
        apiError('Validazione fallita: ' . implode('; ', $errors), 400, ['errors' => $errors]);
    }

    // Se non ci sono dati da aggiornare
    if (empty($updateData)) {
        cnx_tenants_update_log('no_update_data', ['tenant_id' => $tenantId, 'input_keys' => array_keys($input)]);
        apiError('Nessun dato da aggiornare', 400);
    }

    // Aggiornamento in transazione
    $db->beginTransaction();

    try {
        // Filter updateData based on actual columns available in this DB (schema drift safe)
        $updateData = cnx_filter_table_data($db, 'tenants', $updateData);
        if (empty($updateData)) {
            cnx_tenants_update_log('no_update_data_after_filter', ['tenant_id' => $tenantId, 'input_keys' => array_keys($input)]);
            apiError('Nessun dato aggiornabile (schema non allineato)', 400);
        }

        // Aggiorna il tenant
        $db->update('tenants', $updateData, ['id' => $tenantId]);

        // Aggiorna sede legale in tenant_locations
        if ($updateSedeLegale && $newSedeLegale && $locationsStorageAvailable) {
            // IMPORTANT:
            // tenant_locations has a UNIQUE key on (tenant_id, location_type, is_primary).
            // Soft-delete + insert would violate the UNIQUE key because deleted rows still count.
            // So we UPDATE the existing primary sede_legale row in-place when it exists.

            $existingSedeLegale = $db->fetchOne(
                "SELECT id
                 FROM tenant_locations
                 WHERE tenant_id = ?
                   AND location_type = 'sede_legale'
                   AND is_primary = 1
                   AND deleted_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1",
                [$tenantId]
            );

            $sedeUpdate = [
                'indirizzo' => trim($newSedeLegale['indirizzo']),
                'civico' => trim($newSedeLegale['civico']),
                'cap' => trim($newSedeLegale['cap']),
                'comune' => trim($newSedeLegale['comune']),
                'provincia' => strtoupper(trim($newSedeLegale['provincia'])),
                'telefono' => !empty($newSedeLegale['telefono']) ? trim($newSedeLegale['telefono']) : null,
                'email' => !empty($newSedeLegale['email']) ? trim($newSedeLegale['email']) : null,
                'is_primary' => 1,
                'is_active' => 1,
                'deleted_at' => null
            ];
            $sedeUpdate = cnx_filter_table_data($db, 'tenant_locations', $sedeUpdate);

            if ($existingSedeLegale && !empty($existingSedeLegale['id'])) {
                if (!empty($sedeUpdate)) {
                    $db->update('tenant_locations', $sedeUpdate, ['id' => (int)$existingSedeLegale['id']]);
                }
            } else {
                $insertData = array_merge($sedeUpdate, [
                    'tenant_id' => $tenantId,
                    'location_type' => 'sede_legale'
                ]);
                $insertData = cnx_filter_table_data($db, 'tenant_locations', $insertData);
                if (!empty($insertData)) {
                    $db->insert('tenant_locations', $insertData);
                }
            }
        }

        // Aggiorna sedi operative in tenant_locations
        if ($updateSediOperative && $locationsStorageAvailable) {
            // Soft-delete existing sedi operative
            try {
                $softDeleteData = cnx_filter_table_data($db, 'tenant_locations', ['deleted_at' => date('Y-m-d H:i:s')]);
                if (!empty($softDeleteData)) {
                    $db->update(
                        'tenant_locations',
                        $softDeleteData,
                        [
                            'tenant_id' => $tenantId,
                            'location_type' => 'sede_operativa'
                        ]
                    );
                }
            } catch (Throwable $e) {
                $locationsStorageAvailable = false;
                error_log('[tenants/update] tenant_locations soft-delete failed: ' . $e->getMessage());
            }

            // Insert new sedi operative
            foreach ($newSediOperative as $sedeOp) {
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
                    try {
                        $db->insert('tenant_locations', $ins);
                    } catch (Throwable $e) {
                        $locationsStorageAvailable = false;
                        error_log('[tenants/update] tenant_locations insert failed: ' . $e->getMessage());
                        break;
                    }
                }
            }
        }

        // Log audit (NON-BLOCKING - must never break business operation)
        try {
            $db->insert('audit_logs', [
                'tenant_id' => $tenantId,
                'user_id' => !empty($userInfo['user_id']) ? (int)$userInfo['user_id'] : null,
                'action' => 'update',
                'entity_type' => 'tenant',
                'entity_id' => $tenantId,
                'old_values' => json_encode($existingTenant, JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode([
                    'tenant' => $updateData,
                    'sede_legale_updated' => $updateSedeLegale,
                    'sedi_operative_updated' => $updateSediOperative,
                    'sedi_operative_count' => count($newSediOperative)
                ], JSON_UNESCAPED_UNICODE),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log('[AUDIT] tenants/update audit insert failed: ' . $e->getMessage());
            // DO NOT throw
        }

        $db->commit();

        // Recupera i dati aggiornati
        $updatedTenant = $db->fetchOne(
            'SELECT * FROM tenants WHERE id = ?',
            [$tenantId]
        );

        // Risposta di successo
        apiSuccess([
            'tenant_id' => $tenantId,
            'denominazione' => $updatedTenant['denominazione'],
            'updated_fields' => array_keys($updateData),
            'locations_storage_available' => $locationsStorageAvailable
        ], 'Azienda aggiornata con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logApiError('tenants/update', $e);
    apiError('Errore durante l\'aggiornamento dell\'azienda', 500);
}
