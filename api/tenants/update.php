<?php
/**
 * API: Aggiornamento Azienda (Tenant)
 *
 * Endpoint per aggiornare i dati di un'azienda esistente
 *
 * Method: PUT
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

// Riusa le funzioni di validazione da create.php
require_once __DIR__ . '/create.php';

try {
    // Leggi input JSON
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        apiError('Dati JSON non validi', 400);
    }

    // Validazione ID tenant obbligatorio
    if (empty($input['tenant_id'])) {
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
            apiError('Non hai i permessi per modificare questa azienda', 403);
        }
    }

    // Validazione campi (simile a create.php ma tutti opzionali)
    $errors = [];
    $updateData = [];

    // 1. Denominazione (opzionale in update)
    if (isset($input['denominazione'])) {
        if (empty(trim($input['denominazione']))) {
            $errors[] = 'Denominazione non può essere vuota';
        } else {
            $updateData['denominazione'] = trim($input['denominazione']);
            $updateData['name'] = trim($input['denominazione']); // Mantieni compatibilità
        }
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

    // Verifica che almeno uno tra CF e P.IVA sia presente dopo l'update
    $finalCf = $updateData['codice_fiscale'] ?? $existingTenant['codice_fiscale'];
    $finalPiva = $updateData['partita_iva'] ?? $existingTenant['partita_iva'];

    if (!$finalCf && !$finalPiva) {
        $errors[] = 'Almeno uno tra Codice Fiscale e Partita IVA deve essere presente';
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
        $updateData['settore_merceologico'] = !empty($input['settore_merceologico'])
            ? trim($input['settore_merceologico'])
            : null;
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

    // Se ci sono errori, restituiscili
    if (!empty($errors)) {
        apiError('Validazione fallita: ' . implode('; ', $errors), 400, ['errors' => $errors]);
    }

    // Se non ci sono dati da aggiornare
    if (empty($updateData)) {
        apiError('Nessun dato da aggiornare', 400);
    }

    // Aggiornamento in transazione
    $db->beginTransaction();

    try {
        // Aggiorna il tenant
        $db->update('tenants', $updateData, ['id' => $tenantId]);

        // Aggiorna sede legale in tenant_locations
        if ($updateSedeLegale && $newSedeLegale) {
            // Soft-delete existing sede legale
            $db->update(
                'tenant_locations',
                ['deleted_at' => date('Y-m-d H:i:s')],
                [
                    'tenant_id' => $tenantId,
                    'location_type' => 'sede_legale'
                ]
            );

            // Insert new sede legale
            $db->insert('tenant_locations', [
                'tenant_id' => $tenantId,
                'location_type' => 'sede_legale',
                'indirizzo' => trim($newSedeLegale['indirizzo']),
                'civico' => trim($newSedeLegale['civico']),
                'cap' => trim($newSedeLegale['cap']),
                'comune' => trim($newSedeLegale['comune']),
                'provincia' => strtoupper(trim($newSedeLegale['provincia'])),
                'telefono' => !empty($newSedeLegale['telefono']) ? trim($newSedeLegale['telefono']) : null,
                'email' => !empty($newSedeLegale['email']) ? trim($newSedeLegale['email']) : null,
                'is_primary' => 1,
                'is_active' => 1
            ]);
        }

        // Aggiorna sedi operative in tenant_locations
        if ($updateSediOperative) {
            // Soft-delete existing sedi operative
            $db->update(
                'tenant_locations',
                ['deleted_at' => date('Y-m-d H:i:s')],
                [
                    'tenant_id' => $tenantId,
                    'location_type' => 'sede_operativa'
                ]
            );

            // Insert new sedi operative
            foreach ($newSediOperative as $sedeOp) {
                $db->insert('tenant_locations', [
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
                ]);
            }
        }

        // Log audit
        $db->insert('audit_logs', [
            'tenant_id' => $tenantId,
            'user_id' => $userInfo['user_id'],
            'action' => 'update',
            'entity_type' => 'tenant',
            'entity_id' => $tenantId,
            'old_values' => json_encode($existingTenant),
            'new_values' => json_encode([
                'tenant' => $updateData,
                'sede_legale_updated' => $updateSedeLegale,
                'sedi_operative_updated' => $updateSediOperative,
                'sedi_operative_count' => count($newSediOperative)
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

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
            'updated_fields' => array_keys($updateData)
        ], 'Azienda aggiornata con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logApiError('tenants/update', $e);
    apiError('Errore durante l\'aggiornamento dell\'azienda', 500);
}
