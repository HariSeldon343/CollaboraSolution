<?php
// PRIMA COSA: Includi session_init.php per configurare sessione correttamente
require_once __DIR__ . '/../../includes/session_init.php';

// POI: Headers (DOPO session_start di session_init.php)
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once '../../config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

// Start output buffering for clean error handling
ob_start();

try {
    // Authentication validation
    if (!isset($_SESSION['user_id'])) {
        ob_clean();
        http_response_code(401);
        die(json_encode(['error' => 'Non autorizzato']));
    }

    // Current session context
    $tenant_id = $_SESSION['tenant_id'] ?? null;
    $current_user_role = $_SESSION['role'] ?? 'user';

    // Check permissions - only super_admin and admin can manage users
    if (!in_array($current_user_role, ['super_admin', 'admin'])) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Non autorizzato a gestire utenti']));
    }

    // Input sanitization - Get user_id from query params
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

    // CSRF token validation for GET requests (via header)
    $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $auth = new Auth();
    if (!$auth->verifyCSRFToken($csrf_token)) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido']));
    }

    // Validation
    if ($user_id <= 0) {
        ob_clean();
        http_response_code(400);
        die(json_encode(['error' => 'ID utente non valido']));
    }

    $db = Database::getInstance();

    // First check if the user exists and get their role (only non-deleted users)
    $user = $db->fetchOne(
        "SELECT id, role, email, tenant_id FROM users WHERE id = :id AND deleted_at IS NULL",
        [':id' => $user_id]
    );

    if (!$user) {
        ob_clean();
        http_response_code(404);
        die(json_encode(['error' => 'Utente non trovato o eliminato']));
    }

    // Helper: detect optional deleted_at column on user_tenant_access
    $utaHasDeletedAt = false;
    try {
        $col = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_tenant_access'
               AND COLUMN_NAME = 'deleted_at'
             LIMIT 1"
        );
        $utaHasDeletedAt = (bool)($col['ok'] ?? false);
    } catch (Exception $e) {
        $utaHasDeletedAt = false;
    }
    $utaNotDeletedSql = $utaHasDeletedAt ? " AND uta.deleted_at IS NULL" : "";

    // Determine if user_tenant_access exists (multi-tenant mapping table)
    $utaExists = false;
    try {
        $existsRow = $db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = 'user_tenant_access'
             LIMIT 1"
        );
        $utaExists = (bool)($existsRow['ok'] ?? false);
    } catch (Exception $e) {
        $utaExists = false;
    }

    // If current user is admin, verify they have permission to view this user
    if ($current_user_role === 'admin') {
        // Admin cannot view super_admin users
        if ($user['role'] === 'super_admin') {
            ob_clean();
            http_response_code(403);
            die(json_encode(['error' => 'Non puoi visualizzare i dati di un super admin']));
        }

        // Build admin allowed tenant list (primary tenant_id + user_tenant_access)
        $adminAllowedTenantIds = [];
        if (!empty($_SESSION['tenant_id'])) {
            $adminAllowedTenantIds[] = (int)$_SESSION['tenant_id'];
        }
        if ($utaExists) {
            $rows = $db->fetchAll(
                "SELECT DISTINCT uta.tenant_id
                 FROM user_tenant_access uta
                 WHERE uta.user_id = :uid{$utaNotDeletedSql}",
                [':uid' => (int)$_SESSION['user_id']]
            );
            foreach ((array)$rows as $r) {
                if (isset($r['tenant_id'])) $adminAllowedTenantIds[] = (int)$r['tenant_id'];
            }
        }
        $adminAllowedTenantIds = array_values(array_unique(array_filter($adminAllowedTenantIds)));

        // If target is admin: must share at least one tenant assignment (via user_tenant_access or primary tenant)
        if ($user['role'] === 'admin') {
            $targetTenantIds = [];
            if (!empty($user['tenant_id'])) $targetTenantIds[] = (int)$user['tenant_id'];
            if ($utaExists) {
                $rows = $db->fetchAll(
                    "SELECT DISTINCT uta.tenant_id
                     FROM user_tenant_access uta
                     WHERE uta.user_id = :uid{$utaNotDeletedSql}",
                    [':uid' => $user_id]
                );
                foreach ((array)$rows as $r) {
                    if (isset($r['tenant_id'])) $targetTenantIds[] = (int)$r['tenant_id'];
                }
            }
            $targetTenantIds = array_values(array_unique(array_filter($targetTenantIds)));

            $shared = array_intersect($adminAllowedTenantIds, $targetTenantIds);
            if (empty($shared)) {
                ob_clean();
                http_response_code(403);
                die(json_encode(['error' => 'Non hai accesso a questo utente']));
            }
        } else {
            // Target is manager/user: must have access to their tenant_id
            if (!empty($user['tenant_id']) && !in_array((int)$user['tenant_id'], $adminAllowedTenantIds, true)) {
                ob_clean();
                http_response_code(403);
                die(json_encode(['error' => 'Non hai accesso a questo utente']));
            }
        }
    }

    // Build companies list (IDs) expected by utenti.php loadUserCompanies()
    // NOTE: The legacy table user_companies does not exist in this project.
    // Use user_tenant_access for admin/super_admin multi-tenant assignments.
    $companies = []; // array of tenant IDs

    // For admin: return tenants assigned via user_tenant_access (+ primary tenant_id for safety)
    if ($user['role'] === 'admin') {
        if (!empty($user['tenant_id'])) {
            $companies[] = (int)$user['tenant_id'];
        }
        if ($utaExists) {
            $rows = $db->fetchAll(
                "SELECT DISTINCT uta.tenant_id
                 FROM user_tenant_access uta
                 WHERE uta.user_id = :uid{$utaNotDeletedSql}",
                [':uid' => $user_id]
            );
            foreach ((array)$rows as $r) {
                if (isset($r['tenant_id'])) $companies[] = (int)$r['tenant_id'];
            }
        }
        $companies = array_values(array_unique(array_filter($companies)));
    }

    // For super_admin: return all tenants as IDs (they have access to all)
    if ($user['role'] === 'super_admin') {
        $allIds = $db->fetchAll("SELECT id FROM tenants ORDER BY name");
        foreach ((array)$allIds as $row) {
            if (isset($row['id'])) $companies[] = (int)$row['id'];
        }
    }

    // For manager/user roles, return their assigned tenant ID
    if (in_array($user['role'], ['manager', 'user'], true) && !empty($user['tenant_id'])) {
        $companies = [(int)$user['tenant_id']];
    }

    // If current user is admin, filter to only show companies they have access to
    if ($current_user_role === 'admin' && !empty($companies)) {
        $adminAllowedTenantIds = [];
        if (!empty($_SESSION['tenant_id'])) $adminAllowedTenantIds[] = (int)$_SESSION['tenant_id'];
        if ($utaExists) {
            $rows = $db->fetchAll(
                "SELECT DISTINCT uta.tenant_id
                 FROM user_tenant_access uta
                 WHERE uta.user_id = :uid{$utaNotDeletedSql}",
                [':uid' => (int)$_SESSION['user_id']]
            );
            foreach ((array)$rows as $r) {
                if (isset($r['tenant_id'])) $adminAllowedTenantIds[] = (int)$r['tenant_id'];
            }
        }
        $adminAllowedTenantIds = array_values(array_unique(array_filter($adminAllowedTenantIds)));

        $companies = array_values(array_filter($companies, function ($tid) use ($adminAllowedTenantIds) {
            return in_array((int)$tid, $adminAllowedTenantIds, true);
        }));
    }

    ob_clean();
    // Response shape expected by utenti.php:
    // { success: true, companies: [ids...] }
    $response = [
        'success' => true,
        'companies' => array_values(array_unique(array_map('intval', (array)$companies))),
        'data' => [
            'user_id' => $user_id,
            'user_email' => $user['email'],
            'user_role' => $user['role'],
            'total' => count($companies)
        ]
    ];
    if (!$utaExists) {
        $response['warning'] = 'Tabella user_tenant_access non disponibile: impossibile leggere assegnazioni multi-azienda.';
    }
    echo json_encode($response);

} catch (Exception $e) {
    ob_clean();
    error_log('Get User Companies Error: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => 'Errore nel recupero delle aziende']));
}

ob_end_flush();
?>