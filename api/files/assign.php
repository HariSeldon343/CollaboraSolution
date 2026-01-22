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

// For privileged multi-tenant roles, prefer the Company Filter tenant context (if set)
// so assignments validate against the tenant currently selected in the UI.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isSuperAdmin = (
    ($userRole === 'super_admin') ||
    (($_SESSION['role'] ?? '') === 'super_admin') ||
    (($_SESSION['user_role'] ?? '') === 'super_admin')
);

if (($isSuperAdmin || $userRole === 'admin') && isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null) {
    $tenantId = (int)$_SESSION['company_filter_id'];
}

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
// Also accept legacy POST { action: 'revoke', assignment_id } from file_assignment.js
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' || (isset($_GET['action']) && $_GET['action'] === 'delete')) {
    // DELETE operation - Revoke assignment
    handleDeleteAssignment();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $peek = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($peek) && isset($peek['action']) && in_array((string)$peek['action'], ['revoke', 'delete'], true)) {
        handleDeleteAssignment($peek);
    } else {
        // POST operation - Create assignment
        handleCreateAssignment();
    }
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
    // NOTE: In this codebase folders are rows in `files` with is_folder=1, so we only accept file_id.
    $fileId = isset($input['file_id']) ? (int)$input['file_id'] : null;
    $assignedToUserId = isset($input['assigned_to_user_id']) ? (int)$input['assigned_to_user_id'] : null;
    $assignedToTenantRoleId = isset($input['assigned_to_tenant_role_id']) ? (int)$input['assigned_to_tenant_role_id'] : null;
    $assignmentReason = $input['assignment_reason'] ?? null;
    $expiresAt = $input['expires_at'] ?? null;
    $forceReassign = isset($input['force_reassign']) && ($input['force_reassign'] === true || $input['force_reassign'] === 1 || $input['force_reassign'] === '1');

    // Validate: file_id required (applies to files and folders)
    if (!$fileId || $fileId <= 0) {
        api_error('file_id richiesto e deve essere positivo.', 400);
    }

    // Validate assignment target: exactly one between user and tenant role
    $hasUserTarget = ($assignedToUserId !== null && $assignedToUserId > 0);
    $hasRoleTarget = ($assignedToTenantRoleId !== null && $assignedToTenantRoleId > 0);
    if (($hasUserTarget && $hasRoleTarget) || (!$hasUserTarget && !$hasRoleTarget)) {
        api_error('Specificare uno tra assigned_to_user_id o assigned_to_tenant_role_id (non entrambi).', 400);
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

    // Determine entity ID (always files table)
    $entityId = $fileId;

    // ============================================
    // VALIDATION - User exists in tenant
    // ============================================

    $db->beginTransaction();

    try {
        // Validate target exists in tenant:
        // - user target: accept single-tenant (users.tenant_id) OR multi-tenant (user_tenant_access)
        // - role target: tenant_roles record in this tenant
        if ($hasUserTarget) {
            $userExists = $db->fetchOne(
                "SELECT u.id, u.name, u.email
                 FROM users u
                 WHERE u.id = ?
                   AND u.deleted_at IS NULL
                   AND (
                       u.tenant_id = ?
                       OR EXISTS (
                           SELECT 1
                           FROM user_tenant_access uta
                           WHERE uta.user_id = u.id
                             AND uta.tenant_id = ?
                             AND uta.deleted_at IS NULL
                           LIMIT 1
                       )
                   )
                 LIMIT 1",
                [$assignedToUserId, $tenantId, $tenantId]
            );
            if ($userExists === false) {
                throw new Exception('Utente non trovato o non appartiene a questo tenant.');
            }
        } else {
            $roleExists = $db->fetchOne(
                "SELECT id, name
                 FROM tenant_roles
                 WHERE id = ?
                   AND tenant_id = ?
                   AND is_active = 1
                   AND deleted_at IS NULL
                 LIMIT 1",
                [$assignedToTenantRoleId, $tenantId]
            );
            if ($roleExists === false) {
                throw new Exception('Ruolo aziendale non trovato o non appartiene a questo tenant.');
            }
        }

        // ============================================
        // VALIDATION - File/Folder exists in tenant
        // ============================================

        // Entity exists in tenant (files table stores both files and folders)
        $entityExists = $db->fetchOne(
            "SELECT id, name, uploaded_by, is_folder
             FROM files
             WHERE id = ?
               AND tenant_id = ?
               AND (deleted_at IS NULL OR deleted_at = '')",
            [$entityId, $tenantId]
        );

        if ($entityExists === false) {
            throw new Exception('File/Cartella non trovato o non appartiene a questo tenant.');
        }

        $entityName = $entityExists['name'];
        $entityCreatorId = $entityExists['uploaded_by'];
        $entityType = ((int)($entityExists['is_folder'] ?? 0) === 1) ? ENTITY_TYPE_FOLDER : ENTITY_TYPE_FILE;

        // ============================================
        // VALIDATION - Not already assigned (unless force_reassign)
        // ============================================

        $existingActiveAssignment = false;
        if ($hasUserTarget) {
            $existingActiveAssignment = $db->fetchOne(
                "SELECT id, expires_at
                 FROM file_assignments
                 WHERE file_id = ?
                   AND assigned_to_user_id = ?
                   AND tenant_id = ?
                   AND deleted_at IS NULL
                   AND (expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1",
                [$entityId, $assignedToUserId, $tenantId]
            );
        } else {
            $existingActiveAssignment = $db->fetchOne(
                "SELECT id, expires_at
                 FROM file_assignments
                 WHERE file_id = ?
                   AND assigned_to_tenant_role_id = ?
                   AND tenant_id = ?
                   AND deleted_at IS NULL
                   AND (expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1",
                [$entityId, $assignedToTenantRoleId, $tenantId]
            );
        }

        if ($existingActiveAssignment !== false && !$forceReassign) {
            // Business-level conflict: do NOT return 500.
            if ($db->inTransaction()) {
                $db->rollback();
            }

            $targetType = $hasUserTarget ? 'user' : 'tenant_role';
            $targetLabel = $hasUserTarget
                ? (($userExists['name'] ?? 'Utente') . (!empty($userExists['email']) ? ' (' . $userExists['email'] . ')' : ''))
                : ($roleExists['name'] ?? 'Ruolo Aziendale');

            api_error(
                sprintf(
                    'Questa %s è già assegnata a %s (ID assegnazione: %d).',
                    $entityType === ENTITY_TYPE_FILE ? 'file' : 'cartella',
                    $targetLabel,
                    (int)$existingActiveAssignment['id']
                ),
                409,
                [
                    'can_reassign' => true,
                    'reassign_scope' => 'exclusive_all',
                    'existing_assignment' => [
                        'id' => (int)$existingActiveAssignment['id'],
                        'target_type' => $targetType,
                        'target_label' => $targetLabel,
                        'expires_at' => $existingActiveAssignment['expires_at'] ?? null
                    ]
                ]
            );
        }

        // If force_reassign is requested, make the assignment exclusive:
        // revoke all other active assignments for this entity (user and role targets).
        if ($forceReassign) {
            $reuseAssignmentId = 0;
            if ($hasUserTarget) {
                $reuse = $db->fetchOne(
                    "SELECT id
                     FROM file_assignments
                     WHERE tenant_id = ?
                       AND file_id = ?
                       AND assigned_to_user_id = ?
                     ORDER BY id DESC
                     LIMIT 1",
                    [$tenantId, $entityId, $assignedToUserId]
                );
                if ($reuse !== false) {
                    $reuseAssignmentId = (int)$reuse['id'];
                }
            } else {
                $reuse = $db->fetchOne(
                    "SELECT id
                     FROM file_assignments
                     WHERE tenant_id = ?
                       AND file_id = ?
                       AND assigned_to_tenant_role_id = ?
                     ORDER BY id DESC
                     LIMIT 1",
                    [$tenantId, $entityId, $assignedToTenantRoleId]
                );
                if ($reuse !== false) {
                    $reuseAssignmentId = (int)$reuse['id'];
                }
            }

            // Revoke all active assignments except the one we may reuse.
            if ($reuseAssignmentId > 0) {
                $db->query(
                    "UPDATE file_assignments
                     SET deleted_at = NOW(), updated_at = NOW()
                     WHERE tenant_id = ?
                       AND file_id = ?
                       AND deleted_at IS NULL
                       AND id <> ?",
                    [$tenantId, $entityId, $reuseAssignmentId]
                );
            } else {
                $db->query(
                    "UPDATE file_assignments
                     SET deleted_at = NOW(), updated_at = NOW()
                     WHERE tenant_id = ?
                       AND file_id = ?
                       AND deleted_at IS NULL",
                    [$tenantId, $entityId]
                );
            }

            // If there is a reusable assignment row for this target, reactivate/update it.
            if ($reuseAssignmentId > 0) {
                $updatedReuse = $db->update(
                    'file_assignments',
                    [
                        'tenant_id' => $tenantId,
                        'file_id' => $entityId,
                        'entity_type' => $entityType,
                        'assigned_by_user_id' => $userId,
                        'assigned_to_user_id' => $hasUserTarget ? $assignedToUserId : null,
                        'assigned_to_tenant_role_id' => $hasRoleTarget ? $assignedToTenantRoleId : null,
                        'assignment_reason' => $assignmentReason,
                        'expires_at' => $expiresAt,
                        'deleted_at' => null,
                        'updated_at' => date('Y-m-d H:i:s')
                    ],
                    ['id' => $reuseAssignmentId]
                );

                if (!$updatedReuse) {
                    throw new Exception('Impossibile aggiornare l’assegnazione esistente per la riassegnazione.');
                }

                $assignmentId = $reuseAssignmentId;
            }
        }

        // ============================================
        // CREATE ASSIGNMENT
        // ============================================

        // If force_reassign updated an existing row, we already have $assignmentId.
        if (!isset($assignmentId) || (int)$assignmentId <= 0) {
        $assignmentData = [
            'tenant_id' => $tenantId,
            'assigned_by_user_id' => $userId,
            'assigned_to_user_id' => $hasUserTarget ? $assignedToUserId : null,
            'assigned_to_tenant_role_id' => $hasRoleTarget ? $assignedToTenantRoleId : null,
            'entity_type' => $entityType,
            'assignment_reason' => $assignmentReason,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Entity id (files table)
        $assignmentData['file_id'] = $entityId;

        // Insert assignment
            $assignmentId = $db->insert('file_assignments', $assignmentData);

            if (!$assignmentId) {
                throw new Exception('Impossibile creare assegnazione nel database.');
            }
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

            $assignedToText = $hasUserTarget
                ? ($userExists['name'] . ' (' . $userExists['email'] . ')')
                : ($roleExists['name'] ?? 'Ruolo Aziendale');

            $auditData = [
                'assignment_id' => $assignmentId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'entity_name' => $entityName,
                'assigned_to' => $assignedToText,
                'assigned_to_user_id' => $hasUserTarget ? $assignedToUserId : null,
                'assigned_to_tenant_role_id' => $hasRoleTarget ? $assignedToTenantRoleId : null,
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
                    $assignedToText
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

        $assignedToPayload = null;
        if ($hasUserTarget) {
            $assignedToPayload = [
                'type' => 'user',
                'id' => $assignedToUserId,
                'name' => $userExists['name'],
                'email' => $userExists['email']
            ];
        } else {
            $assignedToPayload = [
                'type' => 'tenant_role',
                'id' => $assignedToTenantRoleId,
                'name' => $roleExists['name'] ?? 'Ruolo Aziendale'
            ];
        }

        $response = [
            'assignment' => [
                'id' => $assignmentId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'entity_name' => $entityName,
                'assigned_to' => $assignedToPayload,
                'assigned_by' => [
                    'id' => $userId,
                    'name' => $userInfo['user_name'],
                    'email' => $userInfo['user_email']
                ],
                'assignment_reason' => $assignmentReason,
                'expires_at' => $expiresAt,
                'created_at' => isset($assignmentData) && isset($assignmentData['created_at']) ? $assignmentData['created_at'] : null
            ]
        ];

        $targetLabel = 'destinatario';
        if ($hasUserTarget) {
            $targetLabel = $userExists['name'] ?? 'Utente';
        } elseif (isset($roleExists) && is_array($roleExists)) {
            $targetLabel = $roleExists['name'] ?? 'Ruolo Aziendale';
        }

        api_success(
            $response,
            sprintf(
                '%s assegnata con successo a %s.',
                $entityType === ENTITY_TYPE_FILE ? 'File' : 'Cartella',
                $targetLabel
            )
        );

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();  // BEFORE api_error() (BUG-038)
        }

        error_log("[FILE_ASSIGNMENT_CREATE] Error: " . $e->getMessage());
        // If this is a duplicate key (race condition), return 409 so UI can offer reassignment.
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate entry') !== false || stripos($msg, 'SQLSTATE[23000]') !== false) {
            api_error('Assegnazione già esistente. Aggiorna la pagina o conferma la riassegnazione.', 409, [
                'can_reassign' => true,
                'reassign_scope' => 'exclusive_all'
            ]);
        }

        api_error('Errore durante creazione assegnazione: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle assignment deletion (revoke)
 */
function handleDeleteAssignment(?array $preParsedInput = null) {
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
    if (is_array($preParsedInput)) {
        $input = $preParsedInput;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
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
        // BUG-144c FIX: Check if assignment exists in tenant (removed folder_id join - column doesn't exist)
        // BUG-146c FIX: Column is 'name' not 'file_name' in files table
        $assignment = $db->fetchOne(
            "SELECT fa.*,
                    f.name as file_name,
                    u.name as assigned_to_name
             FROM file_assignments fa
             LEFT JOIN files f ON fa.file_id = f.id
             LEFT JOIN users u ON fa.assigned_to_user_id = u.id
             WHERE fa.id = ?
               AND fa.tenant_id = ?
               AND fa.deleted_at IS NULL",
            [$assignmentId, $tenantId]
        );

        if ($assignment === false) {
            throw new Exception('Assegnazione non trovata o già revocata.');
        }

        // Soft delete the assignment (schema-safe: file_assignments doesn't always have deleted_by)
        $updated = $db->update(
            'file_assignments',
            [
                'deleted_at' => date('Y-m-d H:i:s'),
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