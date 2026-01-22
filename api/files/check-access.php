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
require_once __DIR__ . '/../../includes/file_access.php';

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

// In this codebase folders are rows in `files` with is_folder=1.
// For backward compatibility, we accept folder_id but treat it as files.id.
$entityId = $fileId !== null ? $fileId : $folderId;

// ============================================
// CHECK ACCESS LOGIC
// ============================================

try {
    // Align tenant context for privileged users with Company Filter (matches assign.php / assignments.php)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $isSuperAdmin = (
        ($currentUserRole === 'super_admin') ||
        (($_SESSION['role'] ?? '') === 'super_admin') ||
        (($_SESSION['user_role'] ?? '') === 'super_admin')
    );

    // Resolve entity tenant (needed when Company Filter is "Tutte le aziende")
    $entityRow = $db->fetchOne(
        "SELECT id, tenant_id, folder_id, is_folder
         FROM files
         WHERE id = ?
           AND (deleted_at IS NULL OR deleted_at = '')
         LIMIT 1",
        [$entityId]
    );
    if (!$entityRow) {
        api_error('File/Cartella non trovato.', 404);
    }
    $entityTenantId = (int)($entityRow['tenant_id'] ?? 0);

    // If company filter is set to a specific tenant, use it.
    // If company filter is NOT set (multi-company "all"), fall back to entity tenant (tenant-aware)
    if (($isSuperAdmin || $currentUserRole === 'admin') && isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null) {
        $tenantId = (int)$_SESSION['company_filter_id'];
    } elseif ($isSuperAdmin) {
        $tenantId = $entityTenantId;
    } elseif ($currentUserRole === 'admin') {
        // Admin must have access to the entity tenant via user_tenant_access OR primary tenant_id
        $primaryTenantId = (int)($userInfo['tenant_id'] ?? 0);
        $hasTenantAccess = ($primaryTenantId > 0 && $primaryTenantId === $entityTenantId);
        if (!$hasTenantAccess && $entityTenantId > 0) {
            $uta = $db->fetchOne(
                "SELECT 1
                 FROM user_tenant_access
                 WHERE user_id = ?
                   AND tenant_id = ?
                 LIMIT 1",
                [(int)$currentUserId, (int)$entityTenantId]
            );
            $hasTenantAccess = (bool)$uta;
        }
        if (!$hasTenantAccess) {
            api_error('Non hai accesso a questo tenant.', 403);
        }
        $tenantId = $entityTenantId;
    }

    // Load checked user details (for response metadata)
    $checkUser = $db->fetchOne(
        "SELECT u.id, u.name, u.email, u.role
         FROM users u
         WHERE u.id = ?
           AND u.deleted_at IS NULL
         LIMIT 1",
        [$checkUserId]
    );

    if ($checkUser === false) {
        api_error('Utente non trovato.', 404);
    }

    $checkUserRole = (string)($checkUser['role'] ?? 'user');

    // Centralized access decision (assignment-aware)
    $access = hasFileOrFolderAccess($db, (int)$entityId, (int)$checkUserId, $checkUserRole, (int)$tenantId);

    // Add non-sensitive assignment target label for UI (works even if user is not assignee)
    $assignmentTargetLabel = 'Non assegnato';
    try {
        $candidateIds = [(int)$entityId];
        $isFolder = ((int)($entityRow['is_folder'] ?? 0) === 1);
        $parentFolderId = (!$isFolder && !empty($entityRow['folder_id'])) ? (int)$entityRow['folder_id'] : null;
        if ($parentFolderId) {
            $candidateIds[] = $parentFolderId;
        }

        $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
        $row = $db->fetchOne(
            "SELECT fa.file_id, fa.assigned_to_user_id, fa.assigned_to_tenant_role_id
             FROM file_assignments fa
             WHERE fa.tenant_id = ?
               AND fa.deleted_at IS NULL
               AND (fa.expires_at IS NULL OR fa.expires_at > NOW())
               AND fa.file_id IN ($placeholders)
             ORDER BY CASE WHEN fa.file_id = ? THEN 0 ELSE 1 END, fa.created_at DESC
             LIMIT 1",
            array_merge([(int)$tenantId], $candidateIds, [(int)$entityId])
        );

        if ($row) {
            if (!empty($row['assigned_to_tenant_role_id'])) {
                $tr = $db->fetchOne(
                    "SELECT name
                     FROM tenant_roles
                     WHERE id = ?
                       AND tenant_id = ?
                     LIMIT 1",
                    [(int)$row['assigned_to_tenant_role_id'], (int)$tenantId]
                );
                $roleName = $tr ? (string)($tr['name'] ?? '') : '';
                $assignmentTargetLabel = $roleName !== '' ? ('Ruolo: ' . $roleName) : 'Ruolo assegnato';
            } elseif (!empty($row['assigned_to_user_id'])) {
                $assignmentTargetLabel = 'Utente assegnato';
            }
        }
    } catch (Throwable $e) {
        // non-blocking
    }

    // ============================================
    // PREPARE RESPONSE
    // ============================================

    $response = [
        // Backward + forward compat: both keys are provided
        'access' => (bool)$access['has_access'],
        'has_access' => (bool)$access['has_access'],
        'reason' => (string)$access['reason'],
        'entity' => $access['entity'] ?? ['id' => $entityId],
        'details' => $access['details'] ?? [],
        'user' => [
            'id' => $checkUserId,
            'name' => $checkUser['name'],
            'email' => $checkUser['email'],
            'role' => $checkUserRole
        ]
    ];

    // Ensure details contains non-sensitive assignment label
    if (!isset($response['details']) || !is_array($response['details'])) {
        $response['details'] = [];
    }
    $response['details']['assignment_target_label'] = $assignmentTargetLabel;

    // ============================================
    // OPTIONAL AUDIT LOG (for security monitoring)
    // ============================================

    if ($access['has_access'] && $checkUserId !== $currentUserId) {
        // Log when admins check access for other users (security audit)
        try {
            require_once __DIR__ . '/../../includes/audit_helper.php';

            $entityType = (($access['entity']['is_folder'] ?? false) ? 'folder' : 'file');

            AuditLogger::logGeneric(
                $currentUserId,
                $tenantId,
                'access_check',
                $entityType,
                $entityId,
                sprintf(
                    'Verificato accesso di %s a %s "%s" - Risultato: %s',
                    $checkUser['name'],
                    ($access['entity']['is_folder'] ?? false) ? 'cartella' : 'file',
                    $access['entity']['name'] ?? 'Unknown',
                    $access['has_access'] ? 'CONSENTITO' : 'NEGATO'
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