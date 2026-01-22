<?php
/**
 * API Endpoint: List Users
 * Retrieves paginated list of users with search and filtering capabilities
 */

// Include centralized API authentication
require_once '../../includes/api_auth.php';

// Initialize API environment (session, headers, error handling)
initializeApiEnvironment();

try {
    // Include required files
    require_once '../../config.php';
    require_once '../../includes/db.php';

    // Verify authentication
    verifyApiAuthentication();

    // Get current user info from session
    $userInfo = getApiUserInfo();
    $currentUserId = $userInfo['user_id'];
    // BUG-146: Robust super_admin detection even if role/user_role mismatch
    $currentUserRole = $userInfo['role'];
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $isSuperAdmin = (($_SESSION['role'] ?? '') === 'super_admin' || ($_SESSION['user_role'] ?? '') === 'super_admin');
    if ($isSuperAdmin) {
        $currentUserRole = 'super_admin';
    }
    $tenant_id = $userInfo['tenant_id'];

    // Debug logging
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log('List API - User ID: ' . $currentUserId . ', Role: ' . $currentUserRole . ', Tenant: ' . $tenant_id);
    }

    // Verify CSRF token
    verifyApiCsrfToken();

    // Get query parameters
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $role = isset($_GET['role']) ? $_GET['role'] : '';
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 10;
    $offset = ($page - 1) * $limit;

    // Get database instance
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Build query
    $whereConditions = [];
    $params = [];

    // CRITICAL: Only show non-deleted users (soft delete filter)
    $whereConditions[] = "u.deleted_at IS NULL";

    // Tenant scope behavior:
    // - Default: super_admin can see all tenants; others are restricted to their tenant
    // - If scope=tenant: force tenant restriction even for super_admin (used by tasks assignment dropdown)
    $scope = isset($_GET['scope']) ? trim((string)$_GET['scope']) : '';
    $forceTenantScope = ($scope === 'tenant');

    // For super_admin + scope=tenant, use company filter tenant if present
    $effectiveTenantId = $tenant_id;
    if ($currentUserRole === 'super_admin' && $forceTenantScope) {
        if (isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null) {
            $effectiveTenantId = (int)$_SESSION['company_filter_id'];
        }
    }

    // If scope=tenant, list users relevant to the selected tenant:
    // - users whose primary tenant_id matches
    // - OR users having a user_tenant_access row for that tenant (admins/super_admins can be multi-tenant)
    if ($forceTenantScope && $effectiveTenantId) {
        $whereConditions[] = "(
            u.tenant_id = :tenant_scope_id
            OR EXISTS (
                SELECT 1
                FROM user_tenant_access uta_scope
                WHERE uta_scope.user_id = u.id
                  AND uta_scope.tenant_id = :tenant_scope_id_2
                  AND uta_scope.deleted_at IS NULL
            )
        )";
        $params[':tenant_scope_id'] = (int)$effectiveTenantId;
        $params[':tenant_scope_id_2'] = (int)$effectiveTenantId;
    } elseif ($currentUserRole !== 'super_admin' && $effectiveTenantId) {
        $whereConditions[] = "u.tenant_id = :tenant_id";
        $params[':tenant_id'] = (int)$effectiveTenantId;
    }

    // Add search condition if provided
    if (!empty($search)) {
        $whereConditions[] = "(u.name LIKE :search OR u.email LIKE :search)";
        $params[':search'] = "%$search%";
    }

    // Add role filter if provided (supports comma-separated roles)
    if (!empty($role)) {
        $roles = array_map('trim', explode(',', $role));
        $rolePlaceholders = [];
        foreach ($roles as $index => $r) {
            $key = ':role' . $index;
            $rolePlaceholders[] = $key;
            $params[$key] = $r;
        }
        $whereConditions[] = "u.role IN (" . implode(',', $rolePlaceholders) . ")";
    }

    // Build WHERE clause
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // Count total users for pagination
    $countQuery = "
        SELECT COUNT(*) as total
        FROM users u
        LEFT JOIN tenants t ON u.tenant_id = t.id
        $whereClause
    ";

    $countStmt = $conn->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalUsers = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalUsers / $limit);

    // Detect optional users.password_max_age_days column (migration 20) to avoid breaking older DBs
    $hasPasswordMaxAge = false;
    try {
        $col = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'password_max_age_days'
             LIMIT 1"
        );
        $hasPasswordMaxAge = ((int)($col['ok'] ?? 0) === 1);
    } catch (Exception $e) {
        $hasPasswordMaxAge = false;
    }

    $passwordMaxAgeSelect = $hasPasswordMaxAge ? "u.password_max_age_days," : "";

    // Optional user home city (migration 54) to support Planning defaults
    $hasHomeCity = false;
    try {
        $col = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'home_city'
             LIMIT 1"
        );
        $hasHomeCity = ((int)($col['ok'] ?? 0) === 1);
    } catch (Exception $e) {
        $hasHomeCity = false;
    }
    $homeCitySelect = $hasHomeCity ? "u.home_city," : "";

    // Optional professional profile fields (migration 58)
    $hasJobTitle = false;
    $hasSkillsText = false;
    $hasCertificationsText = false;
    try {
        $cols = $db->fetchAll(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME IN ('job_title','skills_text','certifications_text')"
        ) ?: [];
        foreach ($cols as $c) {
            $k = (string)($c['COLUMN_NAME'] ?? '');
            if ($k === 'job_title') $hasJobTitle = true;
            if ($k === 'skills_text') $hasSkillsText = true;
            if ($k === 'certifications_text') $hasCertificationsText = true;
        }
    } catch (Exception $e) {
        $hasJobTitle = false;
        $hasSkillsText = false;
        $hasCertificationsText = false;
    }
    $jobTitleSelect = $hasJobTitle ? "u.job_title," : "";
    $skillsSelect = $hasSkillsText ? "u.skills_text," : "";
    $certSelect = $hasCertificationsText ? "u.certifications_text," : "";

    // BUG-156 FIX: Detect optional tenant_roles feature (migration 19)
    // If migration 19 wasn't applied, don't include tenant_role columns in query
    $hasTenantRoles = false;
    try {
        $col = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_tenant_access'
               AND COLUMN_NAME = 'tenant_role_id'
             LIMIT 1"
        );
        $hasTenantRoles = ((int)($col['ok'] ?? 0) === 1);
    } catch (Exception $e) {
        $hasTenantRoles = false;
    }

    // Build tenant_roles SELECT and JOIN clauses only if migration 19 is applied
    $tenantRolesSelect = "";
    $tenantRolesJoin = "";
    if ($hasTenantRoles) {
        // Join tenant_role for the effective tenant when scope=tenant (company filter context),
        // otherwise use user's primary tenant_id.
        $utaTenantJoin = ($forceTenantScope && $effectiveTenantId) ? (string)((int)$effectiveTenantId) : 'u.tenant_id';
        // Note: comma at START of each column to follow previous column
        $tenantRolesSelect = ",
            uta.tenant_role_id,
            tr.name as tenant_role_name,
            tr.code as tenant_role_code,
            tr.color as tenant_role_color,
            tr.icon as tenant_role_icon";
        $tenantRolesJoin = "
        LEFT JOIN user_tenant_access uta ON u.id = uta.user_id
            AND uta.tenant_id = {$utaTenantJoin}
            AND uta.deleted_at IS NULL
        LEFT JOIN tenant_roles tr ON uta.tenant_role_id = tr.id
            AND tr.deleted_at IS NULL
            AND tr.is_active = 1";
    }

    // Get users with pagination
    // TENANT_ROLES: Added LEFT JOINs to user_tenant_access and tenant_roles for business role info
    $query = "
        SELECT
            u.id,
            u.tenant_id,
            u.email,
            {$homeCitySelect}
            {$jobTitleSelect}
            {$skillsSelect}
            {$certSelect}
            u.name,
            u.role,
            u.is_active,
            u.created_at,
            {$passwordMaxAgeSelect}
            t.name as tenant_name,
            t.code as tenant_code
            {$tenantRolesSelect}
        FROM users u
        LEFT JOIN tenants t ON u.tenant_id = t.id
        {$tenantRolesJoin}
        $whereClause
        ORDER BY u.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format users data
    // TENANT_ROLES: Added tenant_role object with business role info
    $formattedUsers = [];
    foreach ($users as $user) {
        $userData = [
            'id' => (int)$user['id'],
            'tenant_id' => (int)$user['tenant_id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role'],
            'is_active' => (bool)$user['is_active'],
            'status' => $user['is_active'] ? 'active' : 'inactive',
            'created_at' => $user['created_at'],
            'tenant_name' => $user['tenant_name'] ?? '',
            'tenant_code' => $user['tenant_code'] ?? ''
        ];

        // Optional profile field (migration 54)
        if (array_key_exists('home_city', $user)) {
            $hc = trim((string)($user['home_city'] ?? ''));
            $userData['home_city'] = ($hc !== '') ? $hc : null;
        } else {
            $userData['home_city'] = null;
        }

        // Optional professional profile fields (migration 58)
        if (array_key_exists('job_title', $user)) {
            $jt = trim((string)($user['job_title'] ?? ''));
            $userData['job_title'] = ($jt !== '') ? $jt : null;
        } else {
            $userData['job_title'] = null;
        }
        if (array_key_exists('skills_text', $user)) {
            $st = trim((string)($user['skills_text'] ?? ''));
            $userData['skills_text'] = ($st !== '') ? $st : null;
        } else {
            $userData['skills_text'] = null;
        }
        if (array_key_exists('certifications_text', $user)) {
            $ct = trim((string)($user['certifications_text'] ?? ''));
            $userData['certifications_text'] = ($ct !== '') ? $ct : null;
        } else {
            $userData['certifications_text'] = null;
        }

        // Password policy (per-user) if available in schema/query
        if (array_key_exists('password_max_age_days', $user)) {
            $userData['password_max_age_days'] = (int)($user['password_max_age_days'] ?? 90);
        } else {
            $userData['password_max_age_days'] = 90;
        }

        // TENANT_ROLES: Add tenant_role info if available (BUG-156: check array_key_exists first)
        if (array_key_exists('tenant_role_id', $user) && !empty($user['tenant_role_id'])) {
            $userData['tenant_role'] = [
                'id' => (int)$user['tenant_role_id'],
                'name' => $user['tenant_role_name'] ?? '',
                'code' => $user['tenant_role_code'] ?? '',
                'color' => $user['tenant_role_color'] ?? '#6366f1',
                'icon' => $user['tenant_role_icon'] ?? null
            ];
            $userData['tenant_role_id'] = (int)$user['tenant_role_id'];
        } else {
            $userData['tenant_role'] = null;
            $userData['tenant_role_id'] = null;
        }

        $formattedUsers[] = $userData;
    }

    // Clean any output buffer
    ob_clean();

    // Success response
    echo json_encode([
        'success' => true,
        'data' => [
            'users' => $formattedUsers,
            'page' => $page,
            'total_pages' => $totalPages,
            'total_users' => (int)$totalUsers
        ],
        'message' => 'Utenti recuperati con successo'
    ]);
    exit();

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log('User List PDO Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return user-friendly error
    http_response_code(500);
    echo json_encode(['error' => 'Errore database']);
    exit();

} catch (Exception $e) {
    // Log the error
    error_log('User List Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return generic error
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno del server']);
    exit();
}