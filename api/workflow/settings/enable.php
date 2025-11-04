<?php
/**
 * API per abilitare il workflow su file/cartelle
 *
 * Endpoint: POST /api/workflow/settings/enable.php
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
$enabledCount = 0;
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

    // Verifica se il workflow è già abilitato
    $checkQuery = "SELECT id, workflow_enabled
                  FROM workflow_settings
                  WHERE entity_type = ?
                    AND entity_id = ?
                    AND tenant_id = ?
                    AND deleted_at IS NULL";

    $existing = $db->fetchOne($checkQuery, [$entityType, $entityId, $tenantId]);

    if ($existing) {
        if ($existing['workflow_enabled'] == 1) {
            // Già abilitato, ma procediamo se apply_to_children è true per le cartelle
            if ($entityType === 'folder' && $applyToChildren) {
                // Continua per abilitare i children
            } else {
                if ($db->inTransaction()) {
                    $db->rollback();
                }
                api_error('Workflow già abilitato per questa entità', 409);
            }
        } else {
            // Esiste ma disabilitato, aggiorniamo
            $updateQuery = "UPDATE workflow_settings
                           SET workflow_enabled = 1,
                               updated_by_user_id = ?,
                               updated_at = NOW()
                           WHERE id = ?";

            if (!$db->query($updateQuery, [$userInfo['user_id'], $existing['id']])) {
                throw new Exception('Errore durante l\'aggiornamento del workflow');
            }

            $enabledCount++;

            // Audit log non-blocking
            try {
                AuditLogger::logUpdate(
                    $userInfo['user_id'],
                    $tenantId,
                    'workflow_settings',
                    $existing['id'],
                    "Workflow riabilitato per $entityType: $entityName",
                    ['workflow_enabled' => 0],
                    ['workflow_enabled' => 1]
                );
            } catch (Exception $auditEx) {
                error_log('[AUDIT LOG FAILURE - enable workflow] ' . $auditEx->getMessage());
            }
        }
    } else {
        // Non esiste, creiamo nuovo record
        $insertQuery = "INSERT INTO workflow_settings
                       (tenant_id, entity_type, entity_id, workflow_enabled,
                        created_by_user_id, updated_by_user_id, created_at, updated_at)
                       VALUES (?, ?, ?, 1, ?, ?, NOW(), NOW())";

        $insertId = $db->insert(
            'workflow_settings',
            [
                'tenant_id' => $tenantId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'workflow_enabled' => 1,
                'created_by_user_id' => $userInfo['user_id'],
                'updated_by_user_id' => $userInfo['user_id']
            ]
        );

        if (!$insertId) {
            throw new Exception('Errore durante l\'inserimento del workflow');
        }

        $enabledCount++;

        // Audit log non-blocking
        try {
            AuditLogger::logCreate(
                $userInfo['user_id'],
                $tenantId,
                'workflow_settings',
                $insertId,
                "Workflow abilitato per $entityType: $entityName",
                ['entity_type' => $entityType, 'entity_id' => $entityId, 'workflow_enabled' => 1]
            );
        } catch (Exception $auditEx) {
            error_log('[AUDIT LOG FAILURE - enable workflow] ' . $auditEx->getMessage());
        }
    }

    // Se è una cartella e apply_to_children è true, abilita ricorsivamente
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
                // Verifica se esiste già
                $checkFileQuery = "SELECT id, workflow_enabled
                                  FROM workflow_settings
                                  WHERE entity_type = 'file'
                                    AND entity_id = ?
                                    AND tenant_id = ?
                                    AND deleted_at IS NULL";

                $existingFile = $db->fetchOne($checkFileQuery, [$file['id'], $tenantId]);

                try {
                    if ($existingFile) {
                        if ($existingFile['workflow_enabled'] == 0) {
                            // Abilita
                            $updateFileQuery = "UPDATE workflow_settings
                                              SET workflow_enabled = 1,
                                                  updated_by_user_id = ?,
                                                  updated_at = NOW()
                                              WHERE id = ?";

                            if ($db->query($updateFileQuery, [$userInfo['user_id'], $existingFile['id']])) {
                                $childrenCount++;
                            }
                        }
                    } else {
                        // Crea nuovo
                        $insertFileId = $db->insert(
                            'workflow_settings',
                            [
                                'tenant_id' => $tenantId,
                                'entity_type' => 'file',
                                'entity_id' => $file['id'],
                                'workflow_enabled' => 1,
                                'created_by_user_id' => $userInfo['user_id'],
                                'updated_by_user_id' => $userInfo['user_id']
                            ]
                        );

                        if ($insertFileId) {
                            $childrenCount++;
                        }
                    }
                } catch (Exception $e) {
                    $errors[] = "Errore per file {$file['file_name']}: " . $e->getMessage();
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
                // Verifica/crea per la sottocartella
                $checkSubfolderQuery = "SELECT id, workflow_enabled
                                       FROM workflow_settings
                                       WHERE entity_type = 'folder'
                                         AND entity_id = ?
                                         AND tenant_id = ?
                                         AND deleted_at IS NULL";

                $existingSubfolder = $db->fetchOne($checkSubfolderQuery, [$subfolder['id'], $tenantId]);

                try {
                    if ($existingSubfolder) {
                        if ($existingSubfolder['workflow_enabled'] == 0) {
                            // Abilita
                            $updateSubfolderQuery = "UPDATE workflow_settings
                                                    SET workflow_enabled = 1,
                                                        updated_by_user_id = ?,
                                                        updated_at = NOW()
                                                    WHERE id = ?";

                            if ($db->query($updateSubfolderQuery, [$userInfo['user_id'], $existingSubfolder['id']])) {
                                $childrenCount++;
                            }
                        }
                    } else {
                        // Crea nuovo
                        $insertSubfolderId = $db->insert(
                            'workflow_settings',
                            [
                                'tenant_id' => $tenantId,
                                'entity_type' => 'folder',
                                'entity_id' => $subfolder['id'],
                                'workflow_enabled' => 1,
                                'created_by_user_id' => $userInfo['user_id'],
                                'updated_by_user_id' => $userInfo['user_id']
                            ]
                        );

                        if ($insertSubfolderId) {
                            $childrenCount++;
                        }
                    }
                } catch (Exception $e) {
                    $errors[] = "Errore per cartella {$subfolder['folder_name']}: " . $e->getMessage();
                }

                // Ricorsione per processare i figli della sottocartella
                $processChildren($subfolder['id']);
            }
        };

        // Avvia elaborazione ricorsiva
        $processChildren($entityId);
    }

    // Commit della transazione con controllo 3-layer defense
    if (!$db->commit()) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        error_log('[WORKFLOW ENABLE] Commit fallito per entity_type: ' . $entityType . ', entity_id: ' . $entityId);
        api_error('Errore durante il salvataggio delle modifiche', 500);
    }

    // Preparazione risposta
    $response = [
        'workflow_status' => [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'workflow_enabled' => true,
            'enabled_count' => $enabledCount,
            'updated_at' => date('Y-m-d H:i:s')
        ]
    ];

    if ($applyToChildren && $childrenCount > 0) {
        $response['workflow_status']['children_enabled'] = $childrenCount;
    }

    if (!empty($errors)) {
        $response['workflow_status']['warnings'] = $errors;
    }

    // Risposta wrapped come da pattern CollaboraNexio
    api_success($response, 'Workflow abilitato con successo');

} catch (Exception $e) {
    // Rollback in caso di errore con pattern 3-layer defense
    if ($db->inTransaction()) {
        $db->rollback();
    }

    error_log('[WORKFLOW ENABLE ERROR] ' . $e->getMessage());
    api_error('Errore durante l\'abilitazione del workflow: ' . $e->getMessage(), 500);
}