<?php
/**
 * File/Folder Assignment API - List Assignments
 *
 * Lists file and folder assignments with filtering options
 * Managers see all assignments, users see only their own
 *
 * Method: GET
 * Input: file_id (optional), folder_id (optional), user_id (optional)
 * Response: Array of assignments with user details
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
// so listing aligns with the tenant selected in the UI (matches api/files/assign.php).
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
$filterUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$includeExpired = isset($_GET['include_expired']) && $_GET['include_expired'] === 'true';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? min(100, max(1, (int)$_GET['per_page'])) : 20;

// Validate: Cannot filter by both file_id and folder_id
if ($fileId !== null && $folderId !== null) {
    api_error('Non è possibile filtrare per file_id e folder_id contemporaneamente.', 400);
}

// ============================================
// BUILD QUERY
// ============================================

// BUG-144c FIX: Removed fa.folder_id (column doesn't exist in file_assignments table)
// Assignments are only for files, entity_type is for future extension
$baseQuery = "SELECT
    fa.id,
    fa.entity_type,
    fa.file_id,
    fa.assigned_to_tenant_role_id,
    fa.assignment_reason,
    fa.expires_at,
    fa.created_at,
    fa.updated_at,

    -- File details
    -- BUG-146c FIX: Column is 'name' not 'file_name' in files table
    f.name as file_name,
    f.file_size,
    f.mime_type,
    f.uploaded_by as file_creator_id,

    -- Assigned to user (nullable)
    u_to.id as assigned_to_id,
    u_to.name as assigned_to_name,
    u_to.email as assigned_to_email,

    -- Assigned to tenant role (group) (nullable)
    tr.id as assigned_to_role_id,
    tr.name as assigned_to_role_name,
    tr.code as assigned_to_role_code,
    tr.color as assigned_to_role_color,

    -- Assigned by user
    u_by.id as assigned_by_id,
    u_by.name as assigned_by_name,
    u_by.email as assigned_by_email,

    -- Check if expired
    CASE
        WHEN fa.expires_at IS NULL THEN 0
        WHEN fa.expires_at > NOW() THEN 0
        ELSE 1
    END as is_expired,

    -- Check if expiring soon (within 7 days)
    CASE
        WHEN fa.expires_at IS NULL THEN 0
        WHEN fa.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY) AND fa.expires_at > NOW() THEN 1
        ELSE 0
    END as is_expiring_soon

FROM file_assignments fa
LEFT JOIN files f ON fa.file_id = f.id
LEFT JOIN users u_to ON fa.assigned_to_user_id = u_to.id
LEFT JOIN tenant_roles tr ON fa.assigned_to_tenant_role_id = tr.id
LEFT JOIN users u_by ON fa.assigned_by_user_id = u_by.id
WHERE fa.tenant_id = ?
  AND fa.deleted_at IS NULL";

$params = [$tenantId];

// ============================================
// APPLY FILTERS
// ============================================

// Authorization filter
if (!in_array($userRole, ['manager', 'admin', 'super_admin'])) {
    // Regular users see only their own assignments
    $baseQuery .= " AND fa.assigned_to_user_id = ?";
    $params[] = $userId;
} elseif ($filterUserId !== null) {
    // Managers can filter by specific user
    $baseQuery .= " AND fa.assigned_to_user_id = ?";
    $params[] = $filterUserId;
}

// File filter
if ($fileId !== null) {
    $baseQuery .= " AND fa.file_id = ?";
    $params[] = $fileId;
}

// BUG-144c FIX: Removed folder_id filter (column doesn't exist)
// Folder assignments not supported in current schema

// Expired filter
if (!$includeExpired) {
    $baseQuery .= " AND (fa.expires_at IS NULL OR fa.expires_at > NOW())";
}

// ============================================
// COUNT TOTAL RECORDS
// ============================================

$countQuery = "SELECT COUNT(*) as total FROM ($baseQuery) as subquery";
$totalResult = $db->fetchOne($countQuery, $params);
$totalRecords = $totalResult['total'] ?? 0;

// ============================================
// ADD PAGINATION AND ORDERING
// ============================================

$baseQuery .= " ORDER BY
    is_expiring_soon DESC,
    is_expired ASC,
    fa.created_at DESC
    LIMIT ? OFFSET ?";

$params[] = $perPage;
$params[] = ($page - 1) * $perPage;

// ============================================
// EXECUTE QUERY
// ============================================

try {
    $assignments = $db->fetchAll($baseQuery, $params);

    // ============================================
    // FORMAT RESPONSE DATA
    // ============================================

    $formattedAssignments = [];

    foreach ($assignments as $assignment) {
        $targetType = null;
        if (!empty($assignment['assigned_to_id'])) {
            $targetType = 'user';
        } elseif (!empty($assignment['assigned_to_role_id'])) {
            $targetType = 'tenant_role';
        }

        $formatted = [
            'id' => (int)$assignment['id'],
            'entity_type' => $assignment['entity_type'],
            'entity' => null,
            'assigned_to_type' => $targetType,
            'assigned_to' => $targetType === 'tenant_role' ? [
                'tenant_role_id' => (int)$assignment['assigned_to_role_id'],
                'name' => $assignment['assigned_to_role_name'],
                'code' => $assignment['assigned_to_role_code'],
                'color' => $assignment['assigned_to_role_color'] ?? '#6366f1'
            ] : [
                'id' => (int)$assignment['assigned_to_id'],
                'name' => $assignment['assigned_to_name'],
                'email' => $assignment['assigned_to_email']
            ],
            'assigned_by' => [
                'id' => (int)$assignment['assigned_by_id'],
                'name' => $assignment['assigned_by_name'],
                'email' => $assignment['assigned_by_email']
            ],
            'assignment_reason' => $assignment['assignment_reason'],
            'expires_at' => $assignment['expires_at'],
            'is_expired' => (bool)$assignment['is_expired'],
            'is_expiring_soon' => (bool)$assignment['is_expiring_soon'],
            'created_at' => $assignment['created_at'],
            'updated_at' => $assignment['updated_at']
        ];

        // Backward-compat fields expected by assets/js/file_assignment.js
        if ($targetType === 'tenant_role') {
            $formatted['assigned_user_name'] = (string)($assignment['assigned_to_role_name'] ?? 'Ruolo Aziendale');
            $formatted['assigned_user_email'] = (string)('Gruppo');
            $formatted['assigned_target_badge'] = '👥';
            $formatted['assigned_target_color'] = (string)($assignment['assigned_to_role_color'] ?? '#6366f1');
        } else {
            $formatted['assigned_user_name'] = (string)($assignment['assigned_to_name'] ?? '');
            $formatted['assigned_user_email'] = (string)($assignment['assigned_to_email'] ?? '');
            $formatted['assigned_target_badge'] = '👤';
            $formatted['assigned_target_color'] = '#6b7280';
        }
        $formatted['created_by_name'] = (string)($assignment['assigned_by_name'] ?? '');
        $formatted['created_by'] = (int)($assignment['assigned_by_id'] ?? 0);
        $formatted['reason'] = $formatted['assignment_reason'];

        // BUG-144c FIX: Add entity details (only file supported, folder_id doesn't exist)
        $formatted['entity'] = [
            'id' => (int)$assignment['file_id'],
            'name' => $assignment['file_name'],
            'size' => (int)($assignment['file_size'] ?? 0),
            'mime_type' => $assignment['mime_type'],
            'creator_id' => (int)($assignment['file_creator_id'] ?? 0)
        ];

        $formattedAssignments[] = $formatted;
    }

    // ============================================
    // PAGINATION METADATA
    // ============================================

    $totalPages = ceil($totalRecords / $perPage);

    $pagination = [
        'current_page' => $page,
        'per_page' => $perPage,
        'total_records' => $totalRecords,
        'total_pages' => $totalPages,
        'has_next' => $page < $totalPages,
        'has_prev' => $page > 1
    ];

    // ============================================
    // STATISTICS (for managers only)
    // ============================================

    $statistics = null;

    if (in_array($userRole, ['manager', 'admin', 'super_admin'])) {
        // BUG-144c FIX: Count stats (removed folder_id - column doesn't exist)
        $statsQuery = "SELECT
            COUNT(CASE WHEN expires_at IS NULL OR expires_at > NOW() THEN 1 END) as active_count,
            COUNT(CASE WHEN expires_at IS NOT NULL AND expires_at <= NOW() THEN 1 END) as expired_count,
            COUNT(CASE WHEN expires_at IS NOT NULL
                       AND expires_at > NOW()
                       AND expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 1 END) as expiring_soon_count,
            COUNT(DISTINCT file_id) as unique_files,
            COUNT(DISTINCT assigned_to_user_id) as unique_users
        FROM file_assignments
        WHERE tenant_id = ?
          AND deleted_at IS NULL";

        $stats = $db->fetchOne($statsQuery, [$tenantId]);

        if ($stats !== false) {
            $statistics = [
                'active' => (int)$stats['active_count'],
                'expired' => (int)$stats['expired_count'],
                'expiring_soon' => (int)$stats['expiring_soon_count'],
                'unique_files' => (int)$stats['unique_files'],
                'unique_users' => (int)$stats['unique_users']
            ];
        }
    }

    // ============================================
    // AUDIT LOG (optional - list views are usually not logged)
    // ============================================

    // Not logging list operations to avoid audit log bloat

    // ============================================
    // RESPONSE (BUG-040 pattern - wrap arrays)
    // ============================================

    $response = [
        'assignments' => $formattedAssignments,
        'pagination' => $pagination
    ];

    // Add statistics for managers
    if ($statistics !== null) {
        $response['statistics'] = $statistics;
    }

    api_success($response, 'Lista assegnazioni caricata con successo.');

} catch (Exception $e) {
    error_log("[FILE_ASSIGNMENTS_LIST] Error: " . $e->getMessage());
    api_error('Errore durante recupero assegnazioni: ' . $e->getMessage(), 500);
}