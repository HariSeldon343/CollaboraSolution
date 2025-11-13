<?php
/**
 * API per disabilitare il workflow su file/cartelle
 *
 * Endpoint: POST /api/workflow/settings/disable.php
 *
 * Parametri richiesti:
 * - entity_type: 'file' o 'folder'
 * - entity_id: ID del file o cartella
 * - apply_to_children: boolean (solo per folder, default false)
 *
 * Autorizzazione: manager, admin, super_admin
 *
 * @package CollaboraNexio
 * @subpackage API\Workflow\Settings
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/audit_helper.php';

// Inizializzazione ambiente API e autenticazione
initializeApiEnvironment();

// Headers no-cache CRITICI per prevenire errori 403/500 da cache browser
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Verifica autenticazione IMMEDIATAMENTE dopo initializeApiEnvironment()
verifyApiAuthentication();

// Recupero informazioni utente e verifica CSRF
$userInfo = getApiUserInfo();
verifyApiCsrfToken();

// Controllo autorizzazione - solo manager/admin/super_admin
if (!in_array($userInfo['role'], ['manager', 'admin', 'super_admin'])) {
    api_error('Accesso negato. Solo manager, admin e super admin possono gestire le impostazioni workflow', 403);
}

// Validazione metodo HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Metodo non supportato. Usa POST', 405);
}

// Parsing del body JSON
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    api_error('JSON non valido nel corpo della richiesta', 400);
}

// Validazione parametri obbligatori
$entityType = $input['entity_type'] ?? null;
$entityId = $input['entity_id'] ?? null;
$applyToChildren = isset($input['apply_to_children']) ? filter_var($input['apply_to_children'], FILTER_VALIDATE_BOOLEAN) : false;

if (empty($entityType) || !in_array($entityType, ['file', 'folder'])) {
    api_error('Tipo di entità non valido. Usa "file" o "folder"', 400);
}

if (empty($entityId) || !is_numeric($entityId)) {
    api_error('ID entità non valido', 400);
}

$entityId = (int)$entityId;
$db = Database::getInstance();
$disabledCount = 0;
$childrenCount = 0;
$errors = [];

try {
    // Inizio transazione con pattern 3-layer defense
    if (!$db->beginTransaction()) {
        api_error('Impossibile avviare la transazione', 500);
    }

    // Verifica esistenza e permessi sull'entità
    if ($entityType === 'file') {
        $query = "SELECT id, file_name, folder_id, tenant_id
                 FROM files
                 WHERE id = ? AND deleted_at IS NULL";

        if ($userInfo['role'] !== 'super_admin') {
            $query .= " AND tenant_id = ?";
            $entity = $db->fetchOne($query, [$entityId, $userInfo['tenant_id']]);
        } else {
            $entity = $db->fetchOne($query, [$entityId]);
        }

        if (!$entity) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            api_error('File non trovato o non autorizzato', 404);
        }

        $entityName = $entity['file_name'];

    } else { // folder
        $query = "SELECT id, folder_name, parent_folder_id, tenant_id
                 FROM folders
                 WHERE id = ? AND deleted_at IS NULL";

        if ($userInfo['role'] !== 'super_admin') {
            $query .= " AND tenant_id = ?";
            $entity = $db->fetchOne($query, [$entityId, $userInfo['tenant_id']]);
        } else {
            $entity = $db->fetchOne($query, [$entityId]);
        }

        if (!$entity) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            api_error('Cartella non trovata o non autorizzata', 404);
        }

        $entityName = $entity['folder_name'];
    }

    $tenantId = $entity['tenant_id'];

    // Verifica se il workflow esiste ed è abilitato
    $checkQuery = "SELECT id, workflow_enabled
                  FROM workflow_settings
                  WHERE entity_type = ?
                    AND entity_id = ?
                    AND tenant_id = ?
                    AND deleted_at IS NULL";

    $existing = $db->fetchOne($checkQuery, [$entityType, $entityId, $tenantId]);

    if (!$existing) {
        // Non esiste alcuna configurazione, niente da disabilitare
        if ($db->inTransaction()) {
            $db->rollback();
        }
        api_error('Nessuna configurazione workflow trovata per questa entità', 404);
    }

    if ($existing['workflow_enabled'] == 0) {
        // Già disabilitato, ma procediamo se apply_to_children è true per le cartelle
        if ($entityType === 'folder' && $applyToChildren) {
            // Continua per disabilitare i children
        } else {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            api_error('Workflow già disabilitato per questa entità', 409);
        }
    } else {
        // È abilitato, disabilitiamo
        $updateQuery = "UPDATE workflow_settings
                       SET workflow_enabled = 0,
                           updated_by_user_id = ?,
                           updated_at = NOW()
                       WHERE id = ?";

        if (!$db->query($updateQuery, [$userInfo['user_id'], $existing['id']])) {
            throw new Exception('Errore durante la disabilitazione del workflow');
        }

        $disabledCount++;

        // Audit log non-blocking
        try {
            AuditLogger::logUpdate(
                $userInfo['user_id'],
                $tenantId,
                'workflow_settings',
                $existing['id'],
                "Workflow disabilitato per $entityType: $entityName",
                ['workflow_enabled' => 1],
                ['workflow_enabled' => 0]
            );
        } catch (Exception $auditEx) {
            error_log('[AUDIT LOG FAILURE - disable workflow] ' . $auditEx->getMessage());
        }
    }

    // Se è una cartella e apply_to_children è true, disabilita ricorsivamente
    if ($entityType === 'folder' && $applyToChildren) {
        // Funzione ricorsiva per ottenere tutti i figli
        $processChildren = function($parentId) use ($db, $tenantId, $userInfo, &$childrenCount, &$errors) {
            // Processa file nella cartella
            $filesQuery = "SELECT id, file_name
                          FROM files
                          WHERE folder_id = ?
                            AND tenant_id = ?
                            AND deleted_at IS NULL";

            $files = $db->fetchAll($filesQuery, [$parentId, $tenantId]);

            foreach ($files as $file) {
                // Verifica se esiste e se è abilitato
                $checkFileQuery = "SELECT id, workflow_enabled
                                  FROM workflow_settings
                                  WHERE entity_type = 'file'
                                    AND entity_id = ?
                                    AND tenant_id = ?
                                    AND deleted_at IS NULL";

                $existingFile = $db->fetchOne($checkFileQuery, [$file['id'], $tenantId]);

                if ($existingFile && $existingFile['workflow_enabled'] == 1) {
                    try {
                        // Disabilita
                        $updateFileQuery = "UPDATE workflow_settings
                                          SET workflow_enabled = 0,
                                              updated_by_user_id = ?,
                                              updated_at = NOW()
                                          WHERE id = ?";

                        if ($db->query($updateFileQuery, [$userInfo['user_id'], $existingFile['id']])) {
                            $childrenCount++;
                        }
                    } catch (Exception $e) {
                        $errors[] = "Errore per file {$file['file_name']}: " . $e->getMessage();
                    }
                }
            }

            // Processa sottocartelle
            $subfoldersQuery = "SELECT id, folder_name
                               FROM folders
                               WHERE parent_folder_id = ?
                                 AND tenant_id = ?
                                 AND deleted_at IS NULL";

            $subfolders = $db->fetchAll($subfoldersQuery, [$parentId, $tenantId]);

            foreach ($subfolders as $subfolder) {
                // Verifica/disabilita per la sottocartella
                $checkSubfolderQuery = "SELECT id, workflow_enabled
                                       FROM workflow_settings
                                       WHERE entity_type = 'folder'
                                         AND entity_id = ?
                                         AND tenant_id = ?
                                         AND deleted_at IS NULL";

                $existingSubfolder = $db->fetchOne($checkSubfolderQuery, [$subfolder['id'], $tenantId]);

                if ($existingSubfolder && $existingSubfolder['workflow_enabled'] == 1) {
                    try {
                        // Disabilita
                        $updateSubfolderQuery = "UPDATE workflow_settings
                                                SET workflow_enabled = 0,
                                                    updated_by_user_id = ?,
                                                    updated_at = NOW()
                                                WHERE id = ?";

                        if ($db->query($updateSubfolderQuery, [$userInfo['user_id'], $existingSubfolder['id']])) {
                            $childrenCount++;
                        }
                    } catch (Exception $e) {
                        $errors[] = "Errore per cartella {$subfolder['folder_name']}: " . $e->getMessage();
                    }
                }

                // Ricorsione per processare i figli della sottocartella
                $processChildren($subfolder['id']);
            }
        };

        // Avvia elaborazione ricorsiva
        $processChildren($entityId);
    }

    // Verifica se ci sono workflow attivi da terminare
    if ($entityType === 'file') {
        // Controlla se ci sono workflow in corso per questo file
        $activeWorkflowQuery = "SELECT id, current_state
                               FROM document_workflow
                               WHERE file_id = ?
                                 AND tenant_id = ?
                                 AND current_state NOT IN ('approvato', 'rifiutato')
                                 AND deleted_at IS NULL";

        $activeWorkflow = $db->fetchOne($activeWorkflowQuery, [$entityId, $tenantId]);

        if ($activeWorkflow) {
            // Termina il workflow attivo
            $terminateQuery = "UPDATE document_workflow
                              SET current_state = 'rifiutato',
                                  rejection_reason = 'Workflow disabilitato dall\'amministratore',
                                  updated_at = NOW()
                              WHERE id = ?";

            if ($db->query($terminateQuery, [$activeWorkflow['id']])) {
                // Registra nella storia
                $historyInsert = $db->insert(
                    'document_workflow_history',
                    [
                        'tenant_id' => $tenantId,
                        'file_id' => $entityId,
                        'from_state' => $activeWorkflow['current_state'],
                        'to_state' => 'rifiutato',
                        'performed_by_user_id' => $userInfo['user_id'],
                        'comments' => 'Workflow terminato per disabilitazione delle impostazioni workflow'
                    ]
                );
            }
        }
    }

    // Commit della transazione con controllo 3-layer defense
    if (!$db->commit()) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        error_log('[WORKFLOW DISABLE] Commit fallito per entity_type: ' . $entityType . ', entity_id: ' . $entityId);
        api_error('Errore durante il salvataggio delle modifiche', 500);
    }

    // Preparazione risposta
    $response = [
        'workflow_status' => [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'workflow_enabled' => false,
            'disabled_count' => $disabledCount,
            'updated_at' => date('Y-m-d H:i:s')
        ]
    ];

    if ($applyToChildren && $childrenCount > 0) {
        $response['workflow_status']['children_disabled'] = $childrenCount;
    }

    if (!empty($errors)) {
        $response['workflow_status']['warnings'] = $errors;
    }

    // Risposta wrapped come da pattern CollaboraNexio
    api_success($response, 'Workflow disabilitato con successo');

} catch (Exception $e) {
    // Rollback in caso di errore con pattern 3-layer defense
    if ($db->inTransaction()) {
        $db->rollback();
    }

    error_log('[WORKFLOW DISABLE ERROR] ' . $e->getMessage());
    api_error('Errore durante la disabilitazione del workflow: ' . $e->getMessage(), 500);
}