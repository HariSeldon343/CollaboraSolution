<?php
/**
 * Centralized file/folder access logic for assignment-based permissions.
 *
 * Policy:
 * - super_admin/admin/manager: always allowed (tenant-aware)
 * - If there is ANY active assignment on the entity (file/folder) OR its parent folder (for files):
 *   only assigned users or users belonging to the assigned tenant_role can access.
 *   Creator does NOT keep access when assigned.
 * - If there are NO active assignments: legacy allow for tenant members.
 * - Workflow participants (current validator/approver) can access when in the relevant state.
 */
declare(strict_types=1);

require_once __DIR__ . '/workflow_constants.php';

/**
 * @param Database $db
 * @param int $entityId files.id (file or folder; folders are files with is_folder=1)
 * @param int $userId
 * @param string $userRole
 * @param int $tenantId tenant context of the entity
 * @return array{has_access:bool,reason:string,details:array,entity:array}
 * @throws Exception
 */
function hasFileOrFolderAccess(Database $db, int $entityId, int $userId, string $userRole, int $tenantId): array
{
    $details = [
        'tenant_id' => $tenantId,
        'has_active_assignments' => false,
        'access_type' => null,
        'assignment' => null,
    ];

    // Compliance Document Wizard: visible snapshot versions are restricted
    // Only manager + super_admin can access them (even admins should not, per product requirement).
    try {
        $hasVersions = $db->fetchOne(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'compliance_file_versions'
             LIMIT 1"
        );
        if ($hasVersions) {
            $isVersion = $db->fetchOne(
                "SELECT 1
                 FROM compliance_file_versions
                 WHERE tenant_id = ?
                   AND version_file_id = ?
                 LIMIT 1",
                [$tenantId, $entityId]
            );
            if ($isVersion && !in_array($userRole, ['manager', 'super_admin'], true)) {
                return [
                    'has_access' => false,
                    'reason' => 'Accesso negato: versione documento riservata',
                    'details' => array_merge($details, ['access_type' => 'compliance_version_restricted']),
                    'entity' => ['id' => $entityId],
                ];
            }
        }
    } catch (Throwable $e) {
        // best-effort, do not block legacy access flows
    }

    if (in_array($userRole, ['super_admin', 'admin', 'manager'], true)) {
        return [
            'has_access' => true,
            'reason' => 'Ruolo amministrativo: accesso consentito',
            'details' => array_merge($details, ['access_type' => 'admin']),
            'entity' => ['id' => $entityId],
        ];
    }

    // Verify user belongs to tenant (single-tenant or multi-tenant membership)
    $userRow = $db->fetchOne(
        "SELECT u.id, u.role
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
        [$userId, $tenantId, $tenantId]
    );

    if (!$userRow) {
        return [
            'has_access' => false,
            'reason' => 'Utente non appartiene al tenant',
            'details' => $details,
            'entity' => ['id' => $entityId],
        ];
    }

    // Load entity (file or folder)
    $entity = $db->fetchOne(
        "SELECT id, name, uploaded_by, folder_id, is_folder
         FROM files
         WHERE id = ?
           AND tenant_id = ?
           AND (deleted_at IS NULL OR deleted_at = '')
         LIMIT 1",
        [$entityId, $tenantId]
    );

    if (!$entity) {
        return [
            'has_access' => false,
            'reason' => 'File/Cartella non trovato',
            'details' => $details,
            'entity' => ['id' => $entityId],
        ];
    }

    $isFolder = ((int)($entity['is_folder'] ?? 0) === 1);
    $parentFolderId = (!$isFolder && !empty($entity['folder_id'])) ? (int)$entity['folder_id'] : null;
    $creatorId = (int)($entity['uploaded_by'] ?? 0);

    $candidateIds = [$entityId];
    if ($parentFolderId) {
        $candidateIds[] = $parentFolderId;
    }

    // Multi-role support: if user_tenant_roles exists, use it for role-membership checks too.
    $hasUserTenantRoles = false;
    try {
        $ok = $db->fetchOne(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_tenant_roles'
             LIMIT 1"
        );
        $hasUserTenantRoles = !empty($ok);
    } catch (Exception $e) {
        $hasUserTenantRoles = false;
    }

    // Check if any active assignment exists on entity or its parent folder (for files)
    $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
    $cntRow = $db->fetchOne(
        "SELECT COUNT(1) AS cnt
         FROM file_assignments fa
         WHERE fa.tenant_id = ?
           AND fa.deleted_at IS NULL
           AND (fa.expires_at IS NULL OR fa.expires_at > NOW())
           AND fa.file_id IN ($placeholders)",
        array_merge([$tenantId], $candidateIds)
    );
    $hasAnyAssignments = ((int)($cntRow['cnt'] ?? 0)) > 0;
    $details['has_active_assignments'] = $hasAnyAssignments;

    // If there are assignments, enforce them (creator is NOT a bypass)
    if ($hasAnyAssignments) {
        // Match either a direct user assignment or a tenant_role assignment that the user belongs to.
        // IMPORTANT (schema drift safety): do NOT reference user_tenant_roles unless the table exists,
        // otherwise MySQL will fail to prepare the statement even if guarded by a constant condition.
        $roleMembershipSql = "
            EXISTS (
              SELECT 1
              FROM user_tenant_access uta
              WHERE uta.user_id = ?
                AND uta.tenant_id = ?
                AND uta.deleted_at IS NULL
                AND uta.tenant_role_id = fa.assigned_to_tenant_role_id
              LIMIT 1
            )
        ";
        $roleMembershipParams = [$userId, $tenantId];
        if ($hasUserTenantRoles) {
            $roleMembershipSql .= "
            OR EXISTS (
              SELECT 1
              FROM user_tenant_roles utr
              WHERE utr.user_id = ?
                AND utr.tenant_id = ?
                AND utr.deleted_at IS NULL
                AND utr.tenant_role_id = fa.assigned_to_tenant_role_id
              LIMIT 1
            )
            ";
            $roleMembershipParams[] = $userId;
            $roleMembershipParams[] = $tenantId;
        }

        $sql = "SELECT fa.id, fa.file_id, fa.assigned_to_user_id, fa.assigned_to_tenant_role_id, fa.expires_at, fa.assignment_reason
                FROM file_assignments fa
                WHERE fa.tenant_id = ?
                  AND fa.deleted_at IS NULL
                  AND (fa.expires_at IS NULL OR fa.expires_at > NOW())
                  AND fa.file_id IN ($placeholders)
                  AND (
                    fa.assigned_to_user_id = ?
                    OR (
                       fa.assigned_to_tenant_role_id IS NOT NULL
                       AND (
                         $roleMembershipSql
                       )
                    )
                  )
                ORDER BY CASE WHEN fa.file_id = ? THEN 0 ELSE 1 END, fa.created_at DESC
                LIMIT 1";

        $match = $db->fetchOne(
            $sql,
            array_merge([$tenantId], $candidateIds, [$userId], $roleMembershipParams, [$entityId])
        );

        if ($match) {
            $isDirect = !empty($match['assigned_to_user_id']);
            $isParent = ((int)$match['file_id'] !== $entityId);
            $details['access_type'] = $isParent ? 'parent_folder_assignment' : 'assignment';
            $details['assignment'] = [
                'id' => (int)$match['id'],
                'file_id' => (int)$match['file_id'],
                'target_type' => $isDirect ? 'user' : 'tenant_role',
                'target_id' => $isDirect ? (int)$match['assigned_to_user_id'] : (int)$match['assigned_to_tenant_role_id'],
                'expires_at' => $match['expires_at'],
                'reason' => $match['assignment_reason'] ?? null,
            ];

            return [
                'has_access' => true,
                'reason' => $isParent ? 'Accesso tramite assegnazione cartella padre' : 'Accesso tramite assegnazione',
                'details' => $details,
                'entity' => [
                    'id' => (int)$entity['id'],
                    'name' => $entity['name'],
                    'is_folder' => $isFolder,
                    'parent_folder_id' => $parentFolderId,
                ],
            ];
        }

        // Allow workflow participants even if assigned (needed for operational workflow access)
        if (!$isFolder) {
            $wf = $db->fetchOne(
                "SELECT dw.current_state, dw.current_validator_id, dw.current_approver_id
                 FROM document_workflow dw
                 WHERE dw.file_id = ?
                   AND dw.tenant_id = ?
                   AND (dw.deleted_at IS NULL OR dw.deleted_at = '')
                 LIMIT 1",
                [$entityId, $tenantId]
            );

            if ($wf) {
                $state = (string)($wf['current_state'] ?? '');
                if ($state === WORKFLOW_STATE_IN_VALIDATION && (int)$wf['current_validator_id'] === $userId) {
                    return [
                        'has_access' => true,
                        'reason' => 'Accesso come validatore del workflow',
                        'details' => array_merge($details, ['access_type' => 'workflow_validator']),
                        'entity' => ['id' => $entityId, 'name' => $entity['name'], 'is_folder' => false],
                    ];
                }
                if ($state === WORKFLOW_STATE_IN_APPROVAL && (int)$wf['current_approver_id'] === $userId) {
                    return [
                        'has_access' => true,
                        'reason' => 'Accesso come approvatore del workflow',
                        'details' => array_merge($details, ['access_type' => 'workflow_approver']),
                        'entity' => ['id' => $entityId, 'name' => $entity['name'], 'is_folder' => false],
                    ];
                }
            }
        }

        return [
            'has_access' => false,
            'reason' => 'Accesso negato: file/cartella assegnato ad altri',
            'details' => $details,
            'entity' => [
                'id' => (int)$entity['id'],
                'name' => $entity['name'],
                'is_folder' => $isFolder,
                'parent_folder_id' => $parentFolderId,
            ],
        ];
    }

    // No assignments: creator still has access, but we keep legacy allow for tenant members
    if ($creatorId > 0 && $creatorId === $userId) {
        return [
            'has_access' => true,
            'reason' => 'Creatore (nessuna assegnazione attiva)',
            'details' => array_merge($details, ['access_type' => 'creator']),
            'entity' => ['id' => (int)$entity['id'], 'name' => $entity['name'], 'is_folder' => $isFolder],
        ];
    }

    return [
        'has_access' => true,
        'reason' => 'Nessuna assegnazione attiva: accesso tenant (legacy)',
        'details' => array_merge($details, ['access_type' => 'tenant_member']),
        'entity' => ['id' => (int)$entity['id'], 'name' => $entity['name'], 'is_folder' => $isFolder],
    ];
}


