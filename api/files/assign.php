<?php
/**
 * File/Folder Assignment API - Create Assignment
 *
 * Creates a new file or folder assignment for a user
 * Only managers and super_admins can create assignments
 *
 * Method: POST
 * Input: file_id OR folder_id, assigned_to_user_id, assignment_reason (optional), expires_at (optional)
 * Response: Assignment object with ID
 *
 * @package CollaboraNexio
 * @subpackage File Assignment API
 * @version 1.0.0
 * @since 2025-10-29
 */

declare(strict_types=1);

// API Authentication (BUG-011 pattern)
require_once __DIR__ . '/../../includes/api_auth.php';

initializeApiEnvironment();

// Force no-cache headers (BUG-040)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

verifyApiAuthentication();  // IMMEDIATELY after init

$userInfo = getApiUserInfo();
$tenantId = $userInfo['tenant_id'];
$userId = $userInfo['user_id'];
$userRole = $userInfo['role'];

verifyApiCsrfToken();

// Database connection
require_once __DIR__ . '/../../includes/db.php';
$db = Database::getInstance();

// Include workflow constants
require_once __DIR__ . '/../../includes/workflow_constants.php';

// ============================================
// REQUEST PROCESSING
// ============================================

// Handle both POST and DELETE methods
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' || (isset($_GET['action']) && $_GET['action'] === 'delete')) {
    // DELETE operation - Revoke assignment
    handleDeleteAssignment();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // POST operation - Create assignment
    handleCreateAssignment();
} else {
    http_response_code(405);
    header('Allow: POST, DELETE');
    api_error('Metodo non consentito. Usare POST per creare o DELETE per revocare.', 405);
}

/**
 * Handle assignment creation
 */
function handleCreateAssignment() {
    global $db, $userInfo, $tenantId, $userId, $userRole;

    // ============================================
    // AUTHORIZATION CHECK
    // ============================================

    // Only managers and super_admins can create assignments
    if (!in_array($userRole, ['manager', 'admin', 'super_admin'])) {
        api_error('Accesso negato. Solo amministratori possono creare assegnazioni.', 403);
    }

    // ============================================
    // INPUT VALIDATION
    // ============================================

    // Parse JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        api_error('Dati JSON non validi: ' . json_last_error_msg(), 400);
    }

    // Extract parameters
    $fileId = isset($input['file_id']) ? (int)$input['file_id'] : null;
    $folderId = isset($input['folder_id']) ? (int)$input['folder_id'] : null;
    $assignedToUserId = isset($input['assigned_to_user_id']) ? (int)$input['assigned_to_user_id'] : null;
    $assignmentReason = $input['assignment_reason'] ?? null;
    $expiresAt = $input['expires_at'] ?? null;

    // Validate: One of file_id OR folder_id required
    if (($fileId === null && $folderId === null) || ($fileId !== null && $folderId !== null)) {
        api_error('Specificare uno tra file_id o folder_id (non entrambi).', 400);
    }

    // Validate assigned_to_user_id
    if (!$assignedToUserId || $assignedToUserId <= 0) {
        api_error('assigned_to_user_id richiesto e deve essere positivo.', 400);
    }

    // Validate expires_at if provided
    if ($expiresAt !== null) {
        $expiresTimestamp = strtotime($expiresAt);
        if ($expiresTimestamp === false) {
            api_error('expires_at deve essere una data valida (formato: YYYY-MM-DD HH:MM:SS).', 400);
        }
        if ($expiresTimestamp <= time()) {
            api_error('expires_at deve essere una data futura.', 400);
        }
        $expiresAt = date('Y-m-d H:i:s', $expiresTimestamp);
    }

    // Determine entity type and ID
    $entityType = $fileId !== null ? ENTITY_TYPE_FILE : ENTITY_TYPE_FOLDER;
    $entityId = $fileId !== null ? $fileId : $folderId;

    // ============================================
    // VALIDATION - User exists in tenant
    // ============================================

    $db->beginTransaction();

    try {
        // Check if assigned user exists in tenant
        $userExists = $db->fetchOne(
            "SELECT u.id, u.name, u.email
             FROM users u
             JOIN user_tenant_access uta ON u.id = uta.user_id
             WHERE u.id = ?
               AND uta.tenant_id = ?
               AND u.deleted_at IS NULL
               AND uta.deleted_at IS NULL",
            [$assignedToUserId, $tenantId]
        );

        if ($userExists === false) {
            throw new Exception('Utente non trovato o non appartiene a questo tenant.');
        }

        // ============================================
        // VALIDATION - File/Folder exists in tenant
        // ============================================

        if ($entityType === ENTITY_TYPE_FILE) {
            $entityExists = $db->fetchOne(
                "SELECT id, file_name, uploaded_by
                 FROM files
                 WHERE id = ?
                   AND tenant_id = ?
                   AND deleted_at IS NULL",
                [$entityId, $tenantId]
            );

            if ($entityExists === false) {
                throw new Exception('File non trovato o non appartiene a questo tenant.');
            }

            $entityName = $entityExists['file_name'];
            $entityCreatorId = $entityExists['uploaded_by'];
        } else {
            $entityExists = $db->fetchOne(
                "SELECT id, folder_name, created_by
                 FROM folders
                 WHERE id = ?
                   AND tenant_id = ?
                   AND deleted_at IS NULL",
                [$entityId, $tenantId]
            );

            if ($entityExists === false) {
                throw new Exception('Cartella non trovata o non appartiene a questo tenant.');
            }

            $entityName = $entityExists['folder_name'];
            $entityCreatorId = $entityExists['created_by'];
        }

        // ============================================
        // VALIDATION - Not already assigned
        // ============================================

        $existingAssignment = $db->fetchOne(
            "SELECT id, expires_at
             FROM file_assignments
             WHERE " . ($entityType === ENTITY_TYPE_FILE ? "file_id" : "folder_id") . " = ?
               AND assigned_to_user_id = ?
               AND tenant_id = ?
               AND deleted_at IS NULL
               AND (expires_at IS NULL OR expires_at > NOW())",
            [$entityId, $assignedToUserId, $tenantId]
        );

        if ($existingAssignment !== false) {
            throw new Exception(
                sprintf(
                    'Questa %s è già assegnata a questo utente (ID assegnazione: %d).',
                    $entityType === ENTITY_TYPE_FILE ? 'file' : 'cartella',
                    $existingAssignment['id']
                )
            );
        }

        // ============================================
        // CREATE ASSIGNMENT
        // ============================================

        $assignmentData = [
            'tenant_id' => $tenantId,
            'assigned_by_user_id' => $userId,
            'assigned_to_user_id' => $assignedToUserId,
            'entity_type' => $entityType,
            'assignment_reason' => $assignmentReason,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Add appropriate entity ID
        if ($entityType === ENTITY_TYPE_FILE) {
            $assignmentData['file_id'] = $entityId;
            $assignmentData['folder_id'] = null;
        } else {
            $assignmentData['file_id'] = null;
            $assignmentData['folder_id'] = $entityId;
        }

        // Insert assignment
        $assignmentId = $db->insert('file_assignments', $assignmentData);

        if (!$assignmentId) {
            throw new Exception('Impossibile creare assegnazione nel database.');
        }

        // ============================================
        // COMMIT TRANSACTION (BUG-038/039/045)
        // ============================================

        if (!$db->commit()) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            throw new Exception('Impossibile confermare la transazione.');
        }

        // ============================================
        // AUDIT LOGGING (BUG-029/030)
        // ============================================

        try {
            require_once __DIR__ . '/../../includes/audit_helper.php';

            $auditData = [
                'assignment_id' => $assignmentId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'entity_name' => $entityName,
                'assigned_to' => $userExists['name'] . ' (' . $userExists['email'] . ')',
                'assigned_to_user_id' => $assignedToUserId,
                'reason' => $assignmentReason,
                'expires_at' => $expiresAt
            ];

            AuditLogger::logCreate(
                $userId,
                $tenantId,
                'file_assignment',
                $assignmentId,
                sprintf(
                    'Assegnata %s "%s" a %s',
                    $entityType === ENTITY_TYPE_FILE ? 'file' : 'cartella',
                    $entityName,
                    $userExists['name']
                ),
                $auditData
            );
        } catch (Exception $e) {
            error_log("[AUDIT] Failed to log assignment creation: " . $e->getMessage());
            // Non-blocking - continue
        }

        // ============================================
        // SEND EMAIL NOTIFICATIONS (NON-BLOCKING)
        // ============================================

        try {
            require_once __DIR__ . '/../../includes/workflow_email_notifier.php';
            WorkflowEmailNotifier::notifyFileAssigned($assignmentId, $tenantId);
        } catch (Exception $emailEx) {
            error_log("[FILE_ASSIGN] Email notification failed: " . $emailEx->getMessage());
            // DO NOT throw - operation already committed
        }

        // ============================================
        // PREPARE RESPONSE
        // ============================================

        $response = [
            'assignment' => [
                'id' => $assignmentId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'entity_name' => $entityName,
                'assigned_to' => [
                    'id' => $assignedToUserId,
                    'name' => $userExists['name'],
                    'email' => $userExists['email']
                ],
                'assigned_by' => [
                    'id' => $userId,
                    'name' => $userInfo['user_name'],
                    'email' => $userInfo['user_email']
                ],
                'assignment_reason' => $assignmentReason,
                'expires_at' => $expiresAt,
                'created_at' => $assignmentData['created_at']
            ]
        ];

        api_success(
            $response,
            sprintf(
                '%s assegnata con successo a %s.',
                $entityType === ENTITY_TYPE_FILE ? 'File' : 'Cartella',
                $userExists['name']
            )
        );

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();  // BEFORE api_error() (BUG-038)
        }

        error_log("[FILE_ASSIGNMENT_CREATE] Error: " . $e->getMessage());
        api_error('Errore durante creazione assegnazione: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle assignment deletion (revoke)
 */
function handleDeleteAssignment() {
    global $db, $userInfo, $tenantId, $userId, $userRole;

    // ============================================
    // AUTHORIZATION CHECK
    // ============================================

    // Only managers and super_admins can delete assignments
    if (!in_array($userRole, ['manager', 'admin', 'super_admin'])) {
        api_error('Accesso negato. Solo amministratori possono revocare assegnazioni.', 403);
    }

    // ============================================
    // INPUT VALIDATION
    // ============================================

    // Parse input based on method
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
    } else {
        $input = $_POST ?: $_GET;
    }

    $assignmentId = isset($input['assignment_id']) ? (int)$input['assignment_id'] : null;

    if (!$assignmentId || $assignmentId <= 0) {
        api_error('assignment_id richiesto e deve essere positivo.', 400);
    }

    // ============================================
    // REVOKE ASSIGNMENT
    // ============================================

    $db->beginTransaction();

    try {
        // Check if assignment exists in tenant
        $assignment = $db->fetchOne(
            "SELECT fa.*,
                    f.file_name,
                    fo.folder_name,
                    u.name as assigned_to_name
             FROM file_assignments fa
             LEFT JOIN files f ON fa.file_id = f.id
             LEFT JOIN folders fo ON fa.folder_id = fo.id
             LEFT JOIN users u ON fa.assigned_to_user_id = u.id
             WHERE fa.id = ?
               AND fa.tenant_id = ?
               AND fa.deleted_at IS NULL",
            [$assignmentId, $tenantId]
        );

        if ($assignment === false) {
            throw new Exception('Assegnazione non trovata o già revocata.');
        }

        // Soft delete the assignment
        $updated = $db->update(
            'file_assignments',
            [
                'deleted_at' => date('Y-m-d H:i:s'),
                'deleted_by' => $userId,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            ['id' => $assignmentId]
        );

        if (!$updated) {
            throw new Exception('Impossibile revocare assegnazione nel database.');
        }

        // ============================================
        // COMMIT TRANSACTION
        // ============================================

        if (!$db->commit()) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            throw new Exception('Impossibile confermare la transazione.');
        }

        // ============================================
        // AUDIT LOGGING
        // ============================================

        try {
            require_once __DIR__ . '/../../includes/audit_helper.php';

            $entityName = $assignment['entity_type'] === ENTITY_TYPE_FILE
                ? $assignment['file_name']
                : $assignment['folder_name'];

            AuditLogger::logDelete(
                $userId,
                $tenantId,
                'file_assignment',
                $assignmentId,
                sprintf(
                    'Revocata assegnazione %s "%s" da %s',
                    $assignment['entity_type'] === ENTITY_TYPE_FILE ? 'file' : 'cartella',
                    $entityName,
                    $assignment['assigned_to_name']
                ),
                $assignment
            );
        } catch (Exception $e) {
            error_log("[AUDIT] Failed to log assignment revocation: " . $e->getMessage());
            // Non-blocking - continue
        }

        // ============================================
        // RESPONSE
        // ============================================

        api_success(
            ['assignment_id' => $assignmentId],
            'Assegnazione revocata con successo.'
        );

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();  // BEFORE api_error()
        }

        error_log("[FILE_ASSIGNMENT_DELETE] Error: " . $e->getMessage());
        api_error('Errore durante revoca assegnazione: ' . $e->getMessage(), 500);
    }
}