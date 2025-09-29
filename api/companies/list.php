<?php
// Include centralized API authentication
require_once '../../includes/api_auth.php';

// Initialize API environment (session, headers, error handling)
initializeApiEnvironment();

try {
    // Include required files
    require_once '../../config.php';
    require_once '../../includes/db.php';

    // Initialize response
    $response = ['success' => false, 'message' => '', 'data' => null];

    // Verify authentication
    verifyApiAuthentication();

    // Get current user details from session
    $userInfo = getApiUserInfo();
    $currentUserId = $userInfo['user_id'];
    $userRole = $userInfo['role'];
    $isSuperAdmin = ($userRole === 'super_admin');
    $currentTenantId = $userInfo['tenant_id'];

    // Verify CSRF token
    verifyApiCsrfToken();

    // Get pagination and search parameters
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $search = $_GET['search'] ?? '';
    $limit = 10;
    $offset = ($page - 1) * $limit;

    // Get database instance
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // First, check what columns exist in the tenants table to build a resilient query
    $checkColumnsQuery = "SHOW COLUMNS FROM tenants";
    $columnsStmt = $conn->prepare($checkColumnsQuery);
    $columnsStmt->execute();
    $availableColumns = [];
    while ($col = $columnsStmt->fetch(PDO::FETCH_ASSOC)) {
        $availableColumns[$col['Field']] = true;
    }

    // Helper function to check if column exists
    $hasColumn = function($column) use ($availableColumns) {
        return isset($availableColumns[$column]);
    };

    // Build the query based on user role
    if ($isSuperAdmin) {
        // Super admin can see all companies
        $countQuery = "SELECT COUNT(*) as total FROM tenants WHERE 1=1";

        // Build select clause dynamically based on available columns
        $selectParts = ['t.id', 't.name'];

        // Add optional columns with IFNULL defaults if they exist
        if ($hasColumn('denominazione')) {
            $selectParts[] = "t.denominazione";
        } else {
            $selectParts[] = "t.name as denominazione";
        }

        if ($hasColumn('code')) {
            $selectParts[] = "IFNULL(t.code, '') as code";
        } else {
            $selectParts[] = "'' as code";
        }

        if ($hasColumn('codice_fiscale')) {
            $selectParts[] = "IFNULL(t.codice_fiscale, '') as codice_fiscale";
        } else {
            $selectParts[] = "'' as codice_fiscale";
        }

        if ($hasColumn('partita_iva')) {
            $selectParts[] = "IFNULL(t.partita_iva, '') as partita_iva";
        } else {
            $selectParts[] = "'' as partita_iva";
        }

        if ($hasColumn('sede_legale')) {
            $selectParts[] = "IFNULL(t.sede_legale, '') as sede_legale";
        } else {
            $selectParts[] = "'' as sede_legale";
        }

        if ($hasColumn('sede_operativa')) {
            $selectParts[] = "IFNULL(t.sede_operativa, '') as sede_operativa";
        } else {
            $selectParts[] = "'' as sede_operativa";
        }

        if ($hasColumn('settore_merceologico')) {
            $selectParts[] = "IFNULL(t.settore_merceologico, '') as settore_merceologico";
        } else {
            $selectParts[] = "'' as settore_merceologico";
        }

        if ($hasColumn('numero_dipendenti')) {
            $selectParts[] = "IFNULL(t.numero_dipendenti, 0) as numero_dipendenti";
        } else {
            $selectParts[] = "0 as numero_dipendenti";
        }

        if ($hasColumn('telefono')) {
            $selectParts[] = "IFNULL(t.telefono, '') as telefono";
        } else {
            $selectParts[] = "'' as telefono";
        }

        if ($hasColumn('email_aziendale')) {
            $selectParts[] = "IFNULL(t.email_aziendale, '') as email_aziendale";
        } else {
            $selectParts[] = "'' as email_aziendale";
        }

        if ($hasColumn('pec')) {
            $selectParts[] = "IFNULL(t.pec, '') as pec";
        } else {
            $selectParts[] = "'' as pec";
        }

        if ($hasColumn('data_costituzione')) {
            $selectParts[] = "t.data_costituzione";
        } else {
            $selectParts[] = "NULL as data_costituzione";
        }

        if ($hasColumn('capitale_sociale')) {
            $selectParts[] = "IFNULL(t.capitale_sociale, 0) as capitale_sociale";
        } else {
            $selectParts[] = "0 as capitale_sociale";
        }

        if ($hasColumn('rappresentante_legale')) {
            $selectParts[] = "IFNULL(t.rappresentante_legale, '') as rappresentante_legale";
        } else {
            $selectParts[] = "'' as rappresentante_legale";
        }

        if ($hasColumn('manager_user_id')) {
            $selectParts[] = "t.manager_user_id";
            $selectParts[] = "u.name as manager_name";
        } else {
            $selectParts[] = "NULL as manager_user_id";
            $selectParts[] = "NULL as manager_name";
        }

        // Handle status vs is_active column difference
        if ($hasColumn('status')) {
            $selectParts[] = "IFNULL(t.status, 'active') as status";
        } elseif ($hasColumn('is_active')) {
            $selectParts[] = "IF(t.is_active = 1, 'active', 'inactive') as status";
        } else {
            $selectParts[] = "'active' as status";
        }

        // Handle subscription_tier vs plan_type
        if ($hasColumn('plan_type')) {
            $selectParts[] = "IFNULL(t.plan_type, 'basic') as plan_type";
        } elseif ($hasColumn('subscription_tier')) {
            $selectParts[] = "IFNULL(t.subscription_tier, 'free') as plan_type";
        } else {
            $selectParts[] = "'basic' as plan_type";
        }

        // Handle max_users - it exists in the actual schema
        if ($hasColumn('max_users')) {
            $selectParts[] = "IFNULL(t.max_users, 10) as max_users";
        } else {
            $selectParts[] = "10 as max_users";
        }

        if ($hasColumn('created_at')) {
            $selectParts[] = "t.created_at";
        } else {
            $selectParts[] = "NOW() as created_at";
        }

        if ($hasColumn('updated_at')) {
            $selectParts[] = "IFNULL(t.updated_at, t.created_at) as updated_at";
        } elseif ($hasColumn('created_at')) {
            $selectParts[] = "t.created_at as updated_at";
        } else {
            $selectParts[] = "NOW() as updated_at";
        }

        $query = "SELECT " . implode(", ", $selectParts) . " FROM tenants t";

        // Add LEFT JOIN only if manager_user_id exists
        if ($hasColumn('manager_user_id')) {
            // Check if users table exists
            try {
                $checkUsersTable = $conn->query("SHOW TABLES LIKE 'users'");
                if ($checkUsersTable->rowCount() > 0) {
                    $query .= " LEFT JOIN users u ON t.manager_user_id = u.id";
                }
            } catch (PDOException $e) {
                // Users table doesn't exist, skip the join
            }
        }

        $query .= " WHERE 1=1";

        $params = [];

        // Add search condition if provided - check which columns exist
        if (!empty($search)) {
            $searchConditions = ["t.name LIKE :search"];
            $searchParams = [':search' => "%{$search}%"];
            $paramCounter = 2;

            if ($hasColumn('denominazione')) {
                $searchConditions[] = "t.denominazione LIKE :search{$paramCounter}";
                $searchParams[":search{$paramCounter}"] = "%{$search}%";
                $paramCounter++;
            }

            if ($hasColumn('codice_fiscale')) {
                $searchConditions[] = "t.codice_fiscale LIKE :search{$paramCounter}";
                $searchParams[":search{$paramCounter}"] = "%{$search}%";
                $paramCounter++;
            }

            if ($hasColumn('partita_iva')) {
                $searchConditions[] = "t.partita_iva LIKE :search{$paramCounter}";
                $searchParams[":search{$paramCounter}"] = "%{$search}%";
                $paramCounter++;
            }

            $searchCondition = " AND (" . implode(" OR ", $searchConditions) . ")";
            $countQuery .= $searchCondition;
            $query .= $searchCondition;
            $params = array_merge($params, $searchParams);
        }

        // Add ordering and pagination - use created_at if it exists, otherwise use id
        if ($hasColumn('created_at')) {
            $query .= " ORDER BY t.created_at DESC";
        } else {
            $query .= " ORDER BY t.id DESC";
        }
        $query .= " LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

    } else {
        // Regular users can only see their own company
        if (!$currentTenantId) {
            $response['message'] = 'Nessuna azienda associata';
            $response['success'] = true;
            $response['data'] = ['companies' => [], 'total_pages' => 0, 'current_page' => 1];
            echo json_encode($response);
            exit;
        }

        $countQuery = "SELECT COUNT(*) as total FROM tenants WHERE id = :tenant_id";

        // Build select for regular users - simpler version
        $selectParts = ['t.id', 't.name'];

        if ($hasColumn('denominazione')) {
            $selectParts[] = "t.denominazione";
        } else {
            $selectParts[] = "t.name as denominazione";
        }

        if ($hasColumn('code')) {
            $selectParts[] = "IFNULL(t.code, '') as code";
        } else {
            $selectParts[] = "'' as code";
        }

        // Add all Italian business fields with defaults
        $italianFields = [
            'codice_fiscale' => "''",
            'partita_iva' => "''",
            'sede_legale' => "''",
            'sede_operativa' => "''",
            'settore_merceologico' => "''",
            'telefono' => "''",
            'email_aziendale' => "''",
            'pec' => "''",
            'rappresentante_legale' => "''"
        ];

        foreach ($italianFields as $field => $default) {
            if ($hasColumn($field)) {
                $selectParts[] = "IFNULL(t.{$field}, {$default}) as {$field}";
            } else {
                $selectParts[] = "{$default} as {$field}";
            }
        }

        // Numeric fields
        if ($hasColumn('numero_dipendenti')) {
            $selectParts[] = "IFNULL(t.numero_dipendenti, 0) as numero_dipendenti";
        } else {
            $selectParts[] = "0 as numero_dipendenti";
        }

        if ($hasColumn('capitale_sociale')) {
            $selectParts[] = "IFNULL(t.capitale_sociale, 0) as capitale_sociale";
        } else {
            $selectParts[] = "0 as capitale_sociale";
        }

        // Date fields
        if ($hasColumn('data_costituzione')) {
            $selectParts[] = "t.data_costituzione";
        } else {
            $selectParts[] = "NULL as data_costituzione";
        }

        // Manager fields
        if ($hasColumn('manager_user_id')) {
            $selectParts[] = "t.manager_user_id";
            $selectParts[] = "u.name as manager_name";
        } else {
            $selectParts[] = "NULL as manager_user_id";
            $selectParts[] = "NULL as manager_name";
        }

        // Handle status vs is_active column difference
        if ($hasColumn('status')) {
            $selectParts[] = "IFNULL(t.status, 'active') as status";
        } elseif ($hasColumn('is_active')) {
            $selectParts[] = "IF(t.is_active = 1, 'active', 'inactive') as status";
        } else {
            $selectParts[] = "'active' as status";
        }

        // Handle subscription_tier vs plan_type
        if ($hasColumn('plan_type')) {
            $selectParts[] = "IFNULL(t.plan_type, 'basic') as plan_type";
        } elseif ($hasColumn('subscription_tier')) {
            $selectParts[] = "IFNULL(t.subscription_tier, 'free') as plan_type";
        } else {
            $selectParts[] = "'basic' as plan_type";
        }

        // Handle max_users - it exists in the actual schema
        if ($hasColumn('max_users')) {
            $selectParts[] = "IFNULL(t.max_users, 10) as max_users";
        } else {
            $selectParts[] = "10 as max_users";
        }

        if ($hasColumn('created_at')) {
            $selectParts[] = "t.created_at";
        } else {
            $selectParts[] = "NOW() as created_at";
        }

        if ($hasColumn('updated_at')) {
            $selectParts[] = "IFNULL(t.updated_at, t.created_at) as updated_at";
        } elseif ($hasColumn('created_at')) {
            $selectParts[] = "t.created_at as updated_at";
        } else {
            $selectParts[] = "NOW() as updated_at";
        }

        $query = "SELECT " . implode(", ", $selectParts) . " FROM tenants t";

        // Add LEFT JOIN only if manager_user_id exists and users table exists
        if ($hasColumn('manager_user_id')) {
            try {
                $checkUsersTable = $conn->query("SHOW TABLES LIKE 'users'");
                if ($checkUsersTable->rowCount() > 0) {
                    $query .= " LEFT JOIN users u ON t.manager_user_id = u.id";
                }
            } catch (PDOException $e) {
                // Users table doesn't exist, skip the join
            }
        }

        $query .= " WHERE t.id = :tenant_id";

        $params = [':tenant_id' => $currentTenantId];

        // Add ordering and pagination - use created_at if it exists, otherwise use id
        if ($hasColumn('created_at')) {
            $query .= " ORDER BY t.created_at DESC";
        } else {
            $query .= " ORDER BY t.id DESC";
        }
        $query .= " LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
    }

    // Get total count
    $countStmt = $conn->prepare($countQuery);
    foreach ($params as $key => $value) {
        if ($key !== ':limit' && $key !== ':offset') {
            $countStmt->bindValue($key, $value);
        }
    }
    $countStmt->execute();
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get companies
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    $stmt->execute();

    $companies = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Map status field to is_active for compatibility
        // The query returns 'status' field which is already normalized from is_active or status column
        $isActive = isset($row['status']) ? ($row['status'] === 'active') : true;

        // All fields should be present due to our query construction, but use safe defaults
        $companies[] = [
            'id' => (int)$row['id'],
            'denominazione' => $row['denominazione'] ?? $row['name'] ?? '',
            'name' => $row['name'] ?? '',
            'code' => $row['code'] ?? '',
            'codice_fiscale' => $row['codice_fiscale'] ?? '',
            'partita_iva' => $row['partita_iva'] ?? '',
            'sede_legale' => $row['sede_legale'] ?? '',
            'sede_operativa' => $row['sede_operativa'] ?? '',
            'settore_merceologico' => $row['settore_merceologico'] ?? '',
            'numero_dipendenti' => (int)($row['numero_dipendenti'] ?? 0),
            'telefono' => $row['telefono'] ?? '',
            'email_aziendale' => $row['email_aziendale'] ?? '',
            'pec' => $row['pec'] ?? '',
            'data_costituzione' => $row['data_costituzione'] ?? null,
            'capitale_sociale' => (float)($row['capitale_sociale'] ?? 0),
            'rappresentante_legale' => $row['rappresentante_legale'] ?? '',
            'manager_user_id' => isset($row['manager_user_id']) ? $row['manager_user_id'] : null,
            'manager_name' => isset($row['manager_name']) ? $row['manager_name'] : null,
            'status' => $row['status'] ?? 'active',
            'is_active' => $isActive,
            'plan_type' => $row['plan_type'] ?? 'basic',
            'max_users' => isset($row['max_users']) ? (int)$row['max_users'] : 10,
            'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $row['updated_at'] ?? $row['created_at'] ?? date('Y-m-d H:i:s')
        ];
    }

    // Calculate total pages
    $totalPages = ceil($totalCount / $limit);

    // Clean output buffer
    ob_clean();

    // Prepare response
    $response['success'] = true;
    $response['data'] = [
        'companies' => $companies,
        'total' => $totalCount,
        'current_page' => $page,
        'total_pages' => $totalPages,
        'per_page' => $limit
    ];

    echo json_encode($response);
    exit();

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log('Companies List PDO Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return user-friendly error
    http_response_code(500);
    echo json_encode(['error' => 'Errore database', 'success' => false]);
    exit();

} catch (Exception $e) {
    // Log the error
    error_log('Companies List Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return generic error
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'success' => false]);
    exit();
}
?>