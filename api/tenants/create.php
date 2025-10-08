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

/**
 * Valida Codice Fiscale italiano
 */
function validateCodiceFiscale(string $cf): bool {
    // Pattern regex per CF italiano (16 caratteri alfanumerici)
    // 6 lettere + 2 numeri + 1 lettera + 2 numeri + 1 lettera + 3 numeri + 1 lettera
    $pattern = '/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/i';
    return preg_match($pattern, strtoupper($cf)) === 1;
}

/**
 * Valida Partita IVA italiana
 */
function validatePartitaIva(string $piva): bool {
    // Rimuove spazi e caratteri non numerici
    $piva = preg_replace('/[^0-9]/', '', $piva);

    // Deve essere esattamente 11 cifre
    if (strlen($piva) !== 11) {
        return false;
    }

    // Verifica checksum con algoritmo Luhn modificato per P.IVA italiana
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $digit = (int)$piva[$i];

        if ($i % 2 === 0) {
            // Posizioni dispari (0, 2, 4, 6, 8)
            $sum += $digit;
        } else {
            // Posizioni pari (1, 3, 5, 7, 9)
            $double = $digit * 2;
            $sum += ($double > 9) ? ($double - 9) : $double;
        }
    }

    $checkDigit = (10 - ($sum % 10)) % 10;

    return $checkDigit === (int)$piva[10];
}

/**
 * Valida indirizzo sede legale completo
 */
function validateSedeLegale(array $sede): array {
    $errors = [];

    if (empty($sede['indirizzo'])) {
        $errors[] = 'Indirizzo sede legale obbligatorio';
    }
    if (empty($sede['civico'])) {
        $errors[] = 'Civico sede legale obbligatorio';
    }
    if (empty($sede['comune'])) {
        $errors[] = 'Comune sede legale obbligatorio';
    }
    if (empty($sede['provincia'])) {
        $errors[] = 'Provincia sede legale obbligatoria';
    } elseif (strlen($sede['provincia']) !== 2) {
        $errors[] = 'Provincia deve essere 2 caratteri (es. MI, RM)';
    }
    if (empty($sede['cap'])) {
        $errors[] = 'CAP sede legale obbligatorio';
    } elseif (!preg_match('/^\d{5}$/', $sede['cap'])) {
        $errors[] = 'CAP deve essere 5 cifre';
    }

    return $errors;
}

/**
 * Valida formato telefono italiano
 */
function validateTelefono(string $tel): bool {
    // Pattern per telefoni italiani: +39 seguito da 6-11 cifre
    // Accetta formati: +39 02 1234567, +39 02 12345678, +39 3331234567, 0212345678
    $pattern = '/^(\+39\s?)?0?\d{6,11}$/';
    return preg_match($pattern, str_replace([' ', '-', '.'], '', $tel)) === 1;
}

/**
 * Valida sedi operative (max 5)
 */
function validateSediOperative(array $sedi): array {
    $errors = [];

    if (count($sedi) > 5) {
        $errors[] = 'Massimo 5 sedi operative consentite';
    }

    foreach ($sedi as $index => $sede) {
        if (empty($sede['indirizzo'])) {
            $errors[] = "Sede operativa #" . ($index + 1) . ": indirizzo obbligatorio";
        }
        if (empty($sede['comune'])) {
            $errors[] = "Sede operativa #" . ($index + 1) . ": comune obbligatorio";
        }
        if (!empty($sede['cap']) && !preg_match('/^\d{5}$/', $sede['cap'])) {
            $errors[] = "Sede operativa #" . ($index + 1) . ": CAP deve essere 5 cifre";
        }
        if (!empty($sede['provincia']) && strlen($sede['provincia']) !== 2) {
            $errors[] = "Sede operativa #" . ($index + 1) . ": provincia deve essere 2 caratteri";
        }
    }

    return $errors;
}

try {
    // Leggi input JSON
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        apiError('Dati JSON non validi', 400);
    }

    // Validazione campi obbligatori
    $errors = [];

    // 1. Denominazione obbligatoria
    if (empty($input['denominazione'])) {
        $errors[] = 'Denominazione azienda obbligatoria';
    }

    // 2. CF OR P.IVA obbligatorio (almeno uno)
    $cf = !empty($input['codice_fiscale']) ? trim($input['codice_fiscale']) : null;
    $piva = !empty($input['partita_iva']) ? trim($input['partita_iva']) : null;

    if (!$cf && !$piva) {
        $errors[] = 'Codice Fiscale o Partita IVA obbligatorio (almeno uno)';
    }

    // Valida CF se presente
    if ($cf && !validateCodiceFiscale($cf)) {
        $errors[] = 'Codice Fiscale non valido (deve essere 16 caratteri alfanumerici)';
    }

    // Valida P.IVA se presente
    if ($piva && !validatePartitaIva($piva)) {
        $errors[] = 'Partita IVA non valida (deve essere 11 cifre con checksum corretto)';
    }

    // 3. Sede legale completa obbligatoria
    if (empty($input['sede_legale'])) {
        $errors[] = 'Sede legale obbligatoria';
    } else {
        $sedeErrors = validateSedeLegale($input['sede_legale']);
        $errors = array_merge($errors, $sedeErrors);
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

    // Prepara i dati per l'inserimento
    $tenantData = [
        'name' => trim($input['denominazione']), // Mantieni compatibilità con campo legacy
        'denominazione' => trim($input['denominazione']),
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
        // Inserisci il tenant
        $tenantId = $db->insert('tenants', $tenantData);

        // Inserisci sede legale nella nuova tabella tenant_locations
        if (!empty($input['sede_legale'])) {
            $sedeLegale = $input['sede_legale'];

            $db->insert('tenant_locations', [
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
            ]);
        }

        // Inserisci sedi operative nella nuova tabella tenant_locations
        if (!empty($input['sedi_operative']) && is_array($input['sedi_operative'])) {
            foreach ($input['sedi_operative'] as $sedeOp) {
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
            'action' => 'create',
            'entity_type' => 'tenant',
            'entity_id' => $tenantId,
            'new_values' => json_encode([
                'tenant' => $tenantData,
                'locations_created' => (isset($input['sede_legale']) ? 1 : 0) +
                                       (isset($input['sedi_operative']) ? count($input['sedi_operative']) : 0)
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        $db->commit();

        // Risposta di successo
        apiSuccess([
            'tenant_id' => $tenantId,
            'denominazione' => $tenantData['denominazione'],
            'locations_created' => (isset($input['sede_legale']) ? 1 : 0) +
                                   (isset($input['sedi_operative']) ? count($input['sedi_operative']) : 0)
        ], 'Azienda creata con successo');

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    logApiError('tenants/create', $e);
    apiError('Errore durante la creazione dell\'azienda', 500);
}
