<?php
/**
 * File/Folder Assignment API - Check Access Permission
 *
 * Checks if a user has access to a specific file or folder
 * Access granted if: assigned OR creator OR manager OR super_admin
 *
 * Method: GET
 * Input: file_id OR folder_id, user_id (optional - defaults to current user)
 * Response: {"has_access": boolean, "reason": string}
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
$currentUserId = $userInfo['user_id'];
$currentUserRole = $userInfo['role'];

verifyApiCsrfToken();

// Database connection
require_once __DIR__ . '/../../includes/db.php';
$db = Database::getInstance();

// Include workflow constants
require_once __DIR__ . '/../../includes/workflow_constants.php';

// ============================================
// REQUEST VALIDATION
// ============================================

// Only GET method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    api_error('Metodo non consentito. Usare GET.', 405);
}

// ============================================
// PARSE QUERY PARAMETERS
// ============================================

$fileId = isset($_GET['file_id']) ? (int)$_GET['file_id'] : null;
$folderId = isset($_GET['folder_id']) ? (int)$_GET['folder_id'] : null;
$checkUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $currentUserId;

// ============================================
// INPUT VALIDATION
// ============================================

// Validate: One of file_id OR folder_id required
if (($fileId === null && $folderId === null) || ($fileId !== null && $folderId !== null)) {
    api_error('Specificare uno tra file_id o folder_id (non entrambi).', 400);
}

// Managers and super_admins can check access for any user
if ($checkUserId !== $currentUserId) {
    if (!in_array($currentUserRole, ['manager', 'admin', 'super_admin'])) {
        api_error('Solo amministratori possono verificare accessi per altri utenti.', 403);
    }
}

// Determine entity type
$entityType = $fileId !== null ? ENTITY_TYPE_FILE : ENTITY_TYPE_FOLDER;
$entityId = $fileId !== null ? $fileId : $folderId;

// ============================================
// CHECK ACCESS LOGIC
// ============================================

try {
    $hasAccess = false;
    $accessReason = 'Accesso negato';
    $accessDetails = [];

    // Get user details for check
    $checkUser = $db->fetchOne(
        "SELECT u.id, u.name, u.email, uta.role
         FROM users u
         JOIN user_tenant_access uta ON u.id = uta.user_id
         WHERE u.id = ?
           AND uta.tenant_id = ?
           AND u.deleted_at IS NULL
           AND uta.deleted_at IS NULL",
        [$checkUserId, $tenantId]
    );

    if ($checkUser === false) {
        api_error('Utente non trovato nel tenant corrente.', 404);
    }

    $checkUserRole = $checkUser['role'];

    // ============================================
    // REASON 1: Super Admin - Always has access
    // ============================================

    if ($checkUserRole === 'super_admin') {
        $hasAccess = true;
        $accessReason = 'Super Admin ha accesso completo al sistema';
        $accessDetails['access_type'] = 'super_admin';
    }

    // ============================================
    // REASON 2: Manager - Always has access in tenant
    // ============================================

    elseif (in_array($checkUserRole, ['manager', 'admin'])) {
        $hasAccess = true;
        $accessReason = 'Amministratore ha accesso completo nel tenant';
        $accessDetails['access_type'] = 'admin';
    }

    // ============================================
    // CHECK ENTITY EXISTENCE AND OWNERSHIP
    // ============================================

    else {
        if ($entityType === ENTITY_TYPE_FILE) {
            // Get file details
            $entity = $db->fetchOne(
                "SELECT id, file_name, uploaded_by, folder_id
                 FROM files
                 WHERE id = ?
                   AND tenant_id = ?
                   AND deleted_at IS NULL",
                [$entityId, $tenantId]
            );

            if ($entity === false) {
                api_error('File non trovato nel tenant corrente.', 404);
            }

            $entityName = $entity['file_name'];
            $entityCreatorId = $entity['uploaded_by'];
            $parentFolderId = $entity['folder_id'];

        } else {
            // Get folder details
            $entity = $db->fetchOne(
                "SELECT id, folder_name, created_by, parent_id
                 FROM folders
                 WHERE id = ?
                   AND tenant_id = ?
                   AND deleted_at IS NULL",
                [$entityId, $tenantId]
            );

            if ($entity === false) {
                api_error('Cartella non trovata nel tenant corrente.', 404);
            }

            $entityName = $entity['folder_name'];
            $entityCreatorId = $entity['created_by'];
            $parentFolderId = $entity['parent_id'];
        }

        // ============================================
        // REASON 3: Creator - Always has access to own content
        // ============================================

        if ($entityCreatorId === $checkUserId) {
            $hasAccess = true;
            $accessReason = sprintf(
                'Creatore della %s',
                $entityType === ENTITY_TYPE_FILE ? 'file' : 'cartella'
            );
            $accessDetails['access_type'] = 'creator';
        }

        // ============================================
        // REASON 4: Direct Assignment - Explicit permission
        // ============================================

        if (!$hasAccess) {
            $assignment = $db->fetchOne(
                "SELECT id, assignment_reason, expires_at, assigned_by_user_id
                 FROM file_assignments
                 WHERE " . ($entityType === ENTITY_TYPE_FILE ? "file_id" : "folder_id") . " = ?
                   AND assigned_to_user_id = ?
                   AND tenant_id = ?
                   AND deleted_at IS NULL
                   AND (expires_at IS NULL OR expires_at > NOW())",
                [$entityId, $checkUserId, $tenantId]
            );

            if ($assignment !== false) {
                $hasAccess = true;
                $accessReason = 'Assegnazione diretta';
                $accessDetails['access_type'] = 'assigned';
                $accessDetails['assignment'] = [
                    'id' => (int)$assignment['id'],
                    'reason' => $assignment['assignment_reason'],
                    'expires_at' => $assignment['expires_at'],
                    'assigned_by' => (int)$assignment['assigned_by_user_id']
                ];

                // Check if expiring soon
                if ($assignment['expires_at'] !== null) {
                    $expiresTimestamp = strtotime($assignment['expires_at']);
                    $daysUntilExpiry = floor(($expiresTimestamp - time()) / 86400);

                    if ($daysUntilExpiry <= ASSIGNMENT_EXPIRATION_WARNING_DAYS) {
                        $accessDetails['assignment']['expiring_soon'] = true;
                        $accessDetails['assignment']['days_until_expiry'] = $daysUntilExpiry;
                    }
                }
            }
        }

        // ============================================
        // REASON 5: Parent Folder Assignment (for files only)
        // ============================================

        if (!$hasAccess && $entityType === ENTITY_TYPE_FILE && $parentFolderId !== null) {
            // Check if user has access to parent folder
            $folderAssignment = $db->fetchOne(
                "SELECT id, assignment_reason, expires_at
                 FROM file_assignments
                 WHERE folder_id = ?
                   AND assigned_to_user_id = ?
                   AND tenant_id = ?
                   AND deleted_at IS NULL
                   AND (expires_at IS NULL OR expires_at > NOW())",
                [$parentFolderId, $checkUserId, $tenantId]
            );

            if ($folderAssignment !== false) {
                $hasAccess = true;
                $accessReason = 'Accesso tramite cartella padre';
                $accessDetails['access_type'] = 'parent_folder';
                $accessDetails['parent_folder_assignment'] = [
                    'id' => (int)$folderAssignment['id'],
                    'folder_id' => (int)$parentFolderId,
                    'reason' => $folderAssignment['assignment_reason']
                ];
            }
        }

        // ============================================
        // REASON 6: Check Workflow Roles (for document workflow)
        // ============================================

        if (!$hasAccess && $entityType === ENTITY_TYPE_FILE) {
            // Check if user is validator/approver and file is in workflow
            $workflowState = $db->fetchOne(
                "SELECT dw.state, dw.current_validator_id, dw.current_approver_id
                 FROM document_workflow dw
                 WHERE dw.file_id = ?
                   AND dw.tenant_id = ?
                   AND dw.deleted_at IS NULL",
                [$entityId, $tenantId]
            );

            if ($workflowState !== false) {
                // Check if user is assigned validator/approver
                if (($workflowState['state'] === WORKFLOW_STATE_IN_VALIDATION &&
                     $workflowState['current_validator_id'] === $checkUserId) ||
                    ($workflowState['state'] === WORKFLOW_STATE_IN_APPROVAL &&
                     $workflowState['current_approver_id'] === $checkUserId)) {

                    $hasAccess = true;
                    $accessReason = sprintf(
                        'Assegnato come %s nel workflow',
                        $workflowState['state'] === WORKFLOW_STATE_IN_VALIDATION ? 'validatore' : 'approvatore'
                    );
                    $accessDetails['access_type'] = 'workflow_role';
                    $accessDetails['workflow'] = [
                        'state' => $workflowState['state'],
                        'role' => $workflowState['state'] === WORKFLOW_STATE_IN_VALIDATION ? 'validator' : 'approver'
                    ];
                }
            }
        }
    }

    // ============================================
    // PREPARE RESPONSE
    // ============================================

    $response = [
        'has_access' => $hasAccess,
        'reason' => $accessReason,
        'entity' => [
            'type' => $entityType,
            'id' => $entityId,
            'name' => $entityName ?? null
        ],
        'user' => [
            'id' => $checkUserId,
            'name' => $checkUser['name'],
            'email' => $checkUser['email'],
            'role' => $checkUserRole
        ]
    ];

    // Add access details if available
    if (!empty($accessDetails)) {
        $response['details'] = $accessDetails;
    }

    // ============================================
    // OPTIONAL AUDIT LOG (for security monitoring)
    // ============================================

    if ($hasAccess && $checkUserId !== $currentUserId) {
        // Log when admins check access for other users (security audit)
        try {
            require_once __DIR__ . '/../../includes/audit_helper.php';

            AuditLogger::logGeneric(
                $currentUserId,
                $tenantId,
                'access_check',
                $entityType,
                $entityId,
                sprintf(
                    'Verificato accesso di %s a %s "%s" - Risultato: %s',
                    $checkUser['name'],
                    $entityType === ENTITY_TYPE_FILE ? 'file' : 'cartella',
                    $entityName ?? 'Unknown',
                    $hasAccess ? 'CONSENTITO' : 'NEGATO'
                ),
                $response
            );
        } catch (Exception $e) {
            error_log("[AUDIT] Failed to log access check: " . $e->getMessage());
            // Non-blocking - continue
        }
    }

    // ============================================
    // SEND RESPONSE
    // ============================================

    api_success($response, 'Verifica accesso completata.');

} catch (Exception $e) {
    error_log("[FILE_CHECK_ACCESS] Error: " . $e->getMessage());
    api_error('Errore durante verifica accesso: ' . $e->getMessage(), 500);
}