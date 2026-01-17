<?php
// GET: list consulting plans (tenant 28 tool)
require_once __DIR__ . '/_common.php';

try {
    $clientTenantId = isset($_GET['client_tenant_id']) ? (int)$_GET['client_tenant_id'] : 0;
    $status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $offset = ($page - 1) * $limit;

    // Build allowed client list (admin is restricted)
    $allowedClientIds = null;
    if (($userInfo['role'] ?? '') !== 'super_admin') {
        $allowed = cnx_consulting_allowed_clients($db, $userInfo);
        $allowedClientIds = array_values(array_map(static fn($c) => (int)$c['id'], $allowed));
    }

    if ($clientTenantId > 0 && !cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) {
        api_error('Accesso negato all’azienda cliente richiesta', 403);
    }

    $where = ['cp.deleted_at IS NULL'];
    $params = [];

    if ($clientTenantId > 0) {
        $where[] = 'cp.client_tenant_id = ?';
        $params[] = $clientTenantId;
    } elseif (is_array($allowedClientIds)) {
        if (empty($allowedClientIds)) {
            api_success(['plans' => [], 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'total_pages' => 1]]);
        }
        $ph = implode(',', array_fill(0, count($allowedClientIds), '?'));
        $where[] = "cp.client_tenant_id IN ($ph)";
        $params = array_merge($params, $allowedClientIds);
    }

    $validStatuses = ['draft','proposed','approved','scheduled','done','cancelled'];
    if ($status !== '' && in_array($status, $validStatuses, true)) {
        $where[] = 'cp.status = ?';
        $params[] = $status;
    }

    if ($search !== '') {
        $where[] = '(cp.title LIKE ? OR cp.notes LIKE ?)';
        $s = '%' . $search . '%';
        $params[] = $s;
        $params[] = $s;
    }

    $whereSql = implode(' AND ', $where);

    $count = $db->fetchOne("SELECT COUNT(*) AS total FROM consulting_plans cp WHERE $whereSql", $params);
    $total = (int)($count['total'] ?? 0);
    $totalPages = max(1, (int)ceil($total / $limit));

    $rows = $db->fetchAll(
        "SELECT
            cp.*,
            COALESCE(t.denominazione, t.name) AS client_name
         FROM consulting_plans cp
         JOIN tenants t ON t.id = cp.client_tenant_id
         WHERE $whereSql
         ORDER BY cp.updated_at DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );

    api_success([
        'plans' => $rows ?: [],
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1,
        ],
    ]);
} catch (Exception $e) {
    error_log('[CONSULTING_LIST] ' . $e->getMessage());
    api_error('Errore caricamento piani', 500);
}


