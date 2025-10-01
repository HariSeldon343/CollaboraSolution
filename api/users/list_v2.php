<?php
/**
 * API Endpoint: List Users V2 con Company Filter
 * Recupera lista utenti con filtro azienda multi-tenant
 */

declare(strict_types=1);

// Usa il sistema di autenticazione centralizzato
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/company_filter.php';
require_once __DIR__ . '/../../includes/query_helper.php';

// Inizializza l'ambiente API
initializeApiEnvironment();

try {
    // Verifica autenticazione
    verifyApiAuthentication();

    // Verifica CSRF token
    verifyApiCsrfToken(true);

    // Get current user info
    $userInfo = getApiUserInfo();

    // Get current user object for company filter
    $auth = new Auth();
    $currentUser = $auth->getCurrentUser();
    if (!$currentUser) {
        apiError('Utente non trovato', 401);
    }

    // Get query parameters
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $role = isset($_GET['role']) ? $_GET['role'] : '';
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 10;
    $offset = ($page - 1) * $limit;

    // Initialize query helper with company filter
    $queryHelper = new QueryHelper($currentUser);

    // Build base query
    $baseQuery = "
        SELECT
            u.id,
            u.name,
            u.email,
            u.role,
            u.status,
            u.created_at,
            u.last_login_at,
            u.tenant_id,
            t.name as company_name
        FROM users u
        LEFT JOIN tenants t ON u.tenant_id = t.id
    ";

    // Build WHERE conditions
    $whereConditions = [];
    $params = [];

    // Add search condition
    if (!empty($search)) {
        $whereConditions[] = "(u.name LIKE :search OR u.email LIKE :search)";
        $params[':search'] = "%$search%";
    }

    // Add role filter
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

    // Add WHERE clause if conditions exist
    if (!empty($whereConditions)) {
        $baseQuery .= " WHERE " . implode(' AND ', $whereConditions);
    }

    // Get total count with filter
    $countQuery = str_replace(
        'SELECT u.id, u.name, u.email, u.role, u.status, u.created_at, u.last_login_at, u.tenant_id, t.name as company_name',
        'SELECT COUNT(*) as total',
        $baseQuery
    );

    // Apply company filter and get count
    $countResult = $queryHelper->selectOneWithFilter($countQuery, $params, 'u.tenant_id');
    $totalUsers = $countResult['total'] ?? 0;

    // Add ORDER BY and LIMIT to main query
    $baseQuery .= " ORDER BY u.created_at DESC LIMIT :limit OFFSET :offset";
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;

    // Get users with company filter applied
    $users = $queryHelper->selectWithFilter($baseQuery, $params, 'u.tenant_id');

    // Process users data
    $processedUsers = [];
    foreach ($users as $user) {
        // Check if user is admin with multiple companies
        $userCompanies = [];
        if ($user['role'] === 'admin') {
            $companyQuery = "
                SELECT t.id, t.name
                FROM user_companies uc
                INNER JOIN tenants t ON uc.company_id = t.id
                WHERE uc.user_id = :user_id
                AND t.status = 'active'
                ORDER BY t.name
            ";

            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare($companyQuery);
            $stmt->execute([':user_id' => $user['id']]);
            $userCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $processedUsers[] = [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'status' => $user['status'],
            'company_name' => $user['company_name'],
            'tenant_id' => $user['tenant_id'],
            'created_at' => $user['created_at'],
            'last_login_at' => $user['last_login_at'],
            'companies' => $userCompanies,
            'has_multiple_companies' => count($userCompanies) > 0,
            'is_online' => false // Può essere implementato con un sistema di presenza
        ];
    }

    // Calculate pagination info
    $totalPages = ceil($totalUsers / $limit);

    // Prepare response
    $response = [
        'success' => true,
        'data' => [
            'users' => $processedUsers,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_items' => $totalUsers,
                'items_per_page' => $limit,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ],
            'filter_info' => [
                'company_filter' => $queryHelper->getCompanyFilter() ?
                    $queryHelper->getCompanyFilter()->getActiveFilterName() :
                    'Nessun filtro',
                'search' => $search,
                'role_filter' => $role
            ]
        ]
    ];

    // Send success response using centralized helper
    apiSuccess($response);

} catch (Exception $e) {
    // Log error
    error_log('Error in users/list_v2.php: ' . $e->getMessage());

    // Log and return error using centralized helper
    logApiError('list_v2.php', $e);
    apiError('Errore nel recupero degli utenti', 500);
}