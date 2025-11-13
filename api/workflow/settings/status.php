<?php
/**
 * API per verificare lo stato del workflow su file/cartelle
 *
 * Endpoint: GET /api/workflow/settings/status.php
 *
 * Parametri richiesti (query string):
 * - entity_type: 'file' o 'folder'
 * - entity_id: ID del file o cartella
 *
 * Autorizzazione: Qualsiasi utente autenticato del tenant
 *
 * Risposta:
 * - workflow_enabled: boolean (true se abilitato direttamente o per ereditarietà)
 * - inherited: boolean (true se ereditato da cartella parent)
 * - parent_folder_id: ID della cartella da cui eredita (se applicabile)
 * - parent_folder_name: Nome della cartella da cui eredita (se applicabile)
 *
 * @package CollaboraNexio
 * @subpackage API\Workflow\Settings
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_auth.php';
require_once __DIR__ . '/../../../includes/db.php';

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

// Validazione metodo HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Metodo non supportato. Usa GET', 405);
}

// Validazione parametri dalla query string
$entityType = $_GET['entity_type'] ?? null;
$entityId = $_GET['entity_id'] ?? null;

if (empty($entityType) || !in_array($entityType, ['file', 'folder'])) {
    api_error('Tipo di entità non valido. Usa "file" o "folder"', 400);
}

if (empty($entityId) || !is_numeric($entityId)) {
    api_error('ID entità non valido', 400);
}

$entityId = (int)$entityId;
$db = Database::getInstance();

try {
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
            api_error('File non trovato o non autorizzato', 404);
        }

        $entityName = $entity['file_name'];
        $folderId = $entity['folder_id'];

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
            api_error('Cartella non trovata o non autorizzata', 404);
        }

        $entityName = $entity['folder_name'];
        $folderId = $entityId;
    }

    $tenantId = $entity['tenant_id'];

    // Usa la funzione MySQL per verificare lo stato del workflow
    // CRITICAL: Function signature is get_workflow_enabled_for_folder(tenant_id, folder_id)
    $statusQuery = "SELECT get_workflow_enabled_for_folder(?, ?) AS workflow_enabled";
    $statusResult = $db->fetchOne($statusQuery, [$tenantId, $folderId]);

    $workflowEnabled = $statusResult && $statusResult['workflow_enabled'] == 1;

    // Verifica se è un'impostazione diretta o ereditata
    $directQuery = "SELECT id, workflow_enabled
                   FROM workflow_settings
                   WHERE entity_type = ?
                     AND entity_id = ?
                     AND tenant_id = ?
                     AND deleted_at IS NULL";

    $directSetting = $db->fetchOne($directQuery, [$entityType, $entityId, $tenantId]);

    $isDirect = false;
    $isInherited = false;
    $parentFolderId = null;
    $parentFolderName = null;

    if ($directSetting && $directSetting['workflow_enabled'] == 1) {
        // È impostato direttamente
        $isDirect = true;
    } else if ($workflowEnabled) {
        // È ereditato da una cartella parent
        $isInherited = true;

        // Trova la cartella parent da cui eredita
        if ($entityType === 'file' && $entity['folder_id']) {
            // Per i file, verifica la cartella contenitore
            $currentFolderId = $entity['folder_id'];
        } else if ($entityType === 'folder' && $entity['parent_folder_id']) {
            // Per le cartelle, verifica la cartella parent
            $currentFolderId = $entity['parent_folder_id'];
        } else {
            $currentFolderId = null;
        }

        // Risali l'albero delle cartelle per trovare chi ha il workflow abilitato
        while ($currentFolderId) {
            $folderQuery = "SELECT f.id, f.folder_name, f.parent_folder_id,
                                  ws.workflow_enabled
                           FROM folders f
                           LEFT JOIN workflow_settings ws ON ws.entity_type = 'folder'
                                                          AND ws.entity_id = f.id
                                                          AND ws.tenant_id = f.tenant_id
                                                          AND ws.deleted_at IS NULL
                           WHERE f.id = ?
                             AND f.tenant_id = ?
                             AND f.deleted_at IS NULL";

            $folderInfo = $db->fetchOne($folderQuery, [$currentFolderId, $tenantId]);

            if ($folderInfo && $folderInfo['workflow_enabled'] == 1) {
                // Trovata la cartella da cui eredita
                $parentFolderId = $folderInfo['id'];
                $parentFolderName = $folderInfo['folder_name'];
                break;
            }

            // Continua a risalire
            $currentFolderId = $folderInfo ? $folderInfo['parent_folder_id'] : null;
        }
    }

    // Conta il numero di workflow attivi se è un file
    $activeWorkflows = 0;
    $workflowState = null;

    if ($entityType === 'file') {
        $workflowQuery = "SELECT current_state
                         FROM document_workflow
                         WHERE file_id = ?
                           AND tenant_id = ?
                           AND deleted_at IS NULL
                         ORDER BY created_at DESC
                         LIMIT 1";

        $workflow = $db->fetchOne($workflowQuery, [$entityId, $tenantId]);

        if ($workflow) {
            $workflowState = $workflow['current_state'];
            if (!in_array($workflowState, ['approvato', 'rifiutato'])) {
                $activeWorkflows = 1;
            }
        }
    }

    // Preparazione risposta
    $response = [
        'workflow_status' => [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'workflow_enabled' => $workflowEnabled,
            'is_direct' => $isDirect,
            'is_inherited' => $isInherited,
            'tenant_id' => $tenantId
        ]
    ];

    // Aggiungi informazioni sull'ereditarietà se applicabile
    if ($isInherited && $parentFolderId) {
        $response['workflow_status']['inherited_from'] = [
            'folder_id' => $parentFolderId,
            'folder_name' => $parentFolderName
        ];
    }

    // Aggiungi informazioni sul workflow attivo se è un file
    if ($entityType === 'file') {
        $response['workflow_status']['active_workflows'] = $activeWorkflows;
        if ($workflowState) {
            $response['workflow_status']['current_workflow_state'] = $workflowState;
        }
    }

    // Se è una cartella, conta quanti figli hanno il workflow abilitato
    if ($entityType === 'folder') {
        // Conta file diretti con workflow
        $filesCountQuery = "SELECT COUNT(DISTINCT f.id) as count
                           FROM files f
                           INNER JOIN workflow_settings ws ON ws.entity_type = 'file'
                                                            AND ws.entity_id = f.id
                                                            AND ws.tenant_id = f.tenant_id
                                                            AND ws.workflow_enabled = 1
                                                            AND ws.deleted_at IS NULL
                           WHERE f.folder_id = ?
                             AND f.tenant_id = ?
                             AND f.deleted_at IS NULL";

        $filesCount = $db->fetchOne($filesCountQuery, [$entityId, $tenantId]);

        // Conta sottocartelle dirette con workflow
        $subfoldersCountQuery = "SELECT COUNT(DISTINCT f.id) as count
                                FROM folders f
                                INNER JOIN workflow_settings ws ON ws.entity_type = 'folder'
                                                                 AND ws.entity_id = f.id
                                                                 AND ws.tenant_id = f.tenant_id
                                                                 AND ws.workflow_enabled = 1
                                                                 AND ws.deleted_at IS NULL
                                WHERE f.parent_folder_id = ?
                                  AND f.tenant_id = ?
                                  AND f.deleted_at IS NULL";

        $subfoldersCount = $db->fetchOne($subfoldersCountQuery, [$entityId, $tenantId]);

        $response['workflow_status']['children_with_workflow'] = [
            'files' => (int)($filesCount['count'] ?? 0),
            'folders' => (int)($subfoldersCount['count'] ?? 0)
        ];
    }

    // Verifica chi può gestire le impostazioni
    $canManage = in_array($userInfo['role'], ['manager', 'admin', 'super_admin']);
    $response['workflow_status']['can_manage'] = $canManage;

    // Aggiungi timestamp
    $response['workflow_status']['checked_at'] = date('Y-m-d H:i:s');

    // Risposta wrapped come da pattern CollaboraNexio
    api_success($response, 'Stato workflow recuperato con successo');

} catch (Exception $e) {
    error_log('[WORKFLOW STATUS ERROR] ' . $e->getMessage());
    api_error('Errore durante il recupero dello stato workflow: ' . $e->getMessage(), 500);
}