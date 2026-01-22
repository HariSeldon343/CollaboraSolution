<?php
// Page: File download requests (approval UI)
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/tenant_access_check.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    header('Location: index.php');
    exit;
}

requireTenantAccess($currentUser['id'], $currentUser['role']);

if (!in_array($currentUser['role'], ['manager', 'admin', 'super_admin'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$csrfToken = $auth->generateCSRFToken();
$db = Database::getInstance();

$requestIdFilter = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

// Determine visible tenant ids
$tenantIds = [];
if ($currentUser['role'] === 'super_admin') {
    $rows = $db->fetchAll("SELECT id FROM tenants WHERE deleted_at IS NULL ORDER BY id ASC");
    $tenantIds = array_map(fn($r) => (int)$r['id'], $rows);
} elseif (in_array($currentUser['role'], ['admin', 'manager'], true)) {
    $rows = $db->fetchAll(
        "SELECT tenant_id AS id FROM user_tenant_access WHERE user_id = ?",
        [(int)$currentUser['id']]
    );
    $tenantIds = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $rows)));
    if (empty($tenantIds)) {
        $tenantIds = [(int)($currentUser['tenant_id'] ?? 0)];
    }
} else {
    $tenantIds = [(int)($currentUser['tenant_id'] ?? 0)];
}

$tenantIds = array_values(array_filter($tenantIds, fn($id) => $id > 0));

// Fetch pending requests for visible tenants (or a specific request_id)
$requests = [];
if (!empty($tenantIds)) {
    $placeholders = implode(',', array_fill(0, count($tenantIds), '?'));
    $params = $tenantIds;

    $sql = "
        SELECT r.id,
               r.tenant_id,
               COALESCE(t.denominazione, t.name) AS tenant_name,
               r.file_id,
               f.name AS file_name,
               r.requester_id,
               u.name AS requester_name,
               u.email AS requester_email,
               r.status,
               r.requested_at,
               r.note
        FROM file_download_requests r
        INNER JOIN tenants t ON t.id = r.tenant_id AND t.deleted_at IS NULL
        INNER JOIN files f ON f.id = r.file_id AND f.deleted_at IS NULL
        INNER JOIN users u ON u.id = r.requester_id AND u.deleted_at IS NULL
        WHERE r.tenant_id IN ($placeholders)
    ";

    if ($requestIdFilter > 0) {
        $sql .= " AND r.id = ?";
        $params[] = $requestIdFilter;
    } else {
        $sql .= " AND r.status = 'pending'";
    }

    // Manager role: restrict to configured manager if set (same policy as zip_download_requests.php)
    if ($currentUser['role'] === 'manager') {
        $sql .= " AND (
            t.manager_id IS NULL
            OR t.manager_id = 0
            OR NOT EXISTS (
                SELECT 1
                FROM users m
                WHERE m.id = t.manager_id
                  AND m.deleted_at IS NULL
                  AND m.role = 'manager'
                  AND TRIM(COALESCE(m.email, '')) <> ''
                  AND (
                        m.tenant_id = t.id
                        OR EXISTS (
                            SELECT 1
                            FROM user_tenant_access uta_m
                            WHERE uta_m.user_id = m.id
                              AND uta_m.tenant_id = t.id
                        )
                  )
            )
            OR t.manager_id = ?
        )";
        $params[] = (int)$currentUser['id'];
    }

    $sql .= " ORDER BY r.requested_at DESC, r.id DESC";
    $requests = $db->fetchAll($sql, $params);
}

// Quick health check: approval table exists?
$approvalTableExists = false;
try {
    $stmt = $db->getConnection()->prepare("SHOW TABLES LIKE ?");
    $stmt->execute(['file_download_requests']);
    $approvalTableExists = ($stmt->fetch(PDO::FETCH_NUM) !== false);
} catch (Throwable $e) {
    $approvalTableExists = false;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Richieste Download File - Nexio</title>
    <?php require_once __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/sidebar-responsive.css">
    <style>
        .container { padding: 24px; }
        .card { background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden; }
        .card-header { padding:16px 18px;border-bottom:1px solid #eef0f3;display:flex;align-items:center;justify-content:space-between; }
        .card-title { margin:0;font-size:18px;font-weight:700;color:#111827; }
        table { width:100%; border-collapse: collapse; }
        th, td { padding: 12px 14px; border-bottom: 1px solid #eef0f3; text-align:left; font-size: 14px; color:#111827; }
        th { background:#fafafa; font-weight:700; }
        .muted { color:#6b7280; font-size:12px; }
        .actions { display:flex; gap:8px; align-items:center; }
        .btn-sm { padding: 8px 10px; border-radius: 8px; font-size: 13px; }
        .status-pill { display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700; }
        .status-pending { background:#fff7ed;color:#9a3412;border:1px solid #fed7aa; }
        .status-approved { background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0; }
        .status-rejected { background:#fef2f2;color:#991b1b;border:1px solid #fecaca; }
        .note { width: 220px; }
        .note input { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; }
    </style>
</head>
<body>
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">

    <div class="main-layout">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <button class="sidebar-toggle" id="sidebarToggle">☰</button>
                    <h1 class="header-title">Richieste Download File</h1>
                </div>
            </header>

            <div class="container">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Richieste</h2>
                        <div class="muted">
                            <?php if (!$approvalTableExists): ?>
                                Tabella <code>file_download_requests</code> non presente (applica migrazione DB).
                            <?php else: ?>
                                <?php echo $requestIdFilter > 0 ? 'Vista singola richiesta' : 'Solo richieste pending'; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="overflow:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tenant</th>
                                    <th>File</th>
                                    <th>Richiedente</th>
                                    <th>Status</th>
                                    <th>Richiesta</th>
                                    <th>Note</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($requests)): ?>
                                <tr>
                                    <td colspan="8" class="muted" style="padding:16px;">
                                        <?php if (!$approvalTableExists): ?>
                                            Sistema non configurato: applica la migrazione DB per attivare le richieste download file.
                                        <?php else: ?>
                                            Nessuna richiesta trovata.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($requests as $r): ?>
                                    <tr data-request-id="<?php echo (int)$r['id']; ?>">
                                        <td><?php echo (int)$r['id']; ?></td>
                                        <td>
                                            <div><?php echo htmlspecialchars((string)$r['tenant_name']); ?></div>
                                            <div class="muted">Tenant ID: <?php echo (int)$r['tenant_id']; ?></div>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars((string)$r['file_name']); ?></div>
                                            <div class="muted">File ID: <?php echo (int)$r['file_id']; ?></div>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars((string)$r['requester_name']); ?></div>
                                            <div class="muted"><?php echo htmlspecialchars((string)$r['requester_email']); ?></div>
                                        </td>
                                        <td>
                                            <?php
                                                $st = (string)$r['status'];
                                                $cls = $st === 'approved' ? 'status-approved' : ($st === 'rejected' ? 'status-rejected' : 'status-pending');
                                            ?>
                                            <span class="status-pill <?php echo $cls; ?>"><?php echo htmlspecialchars($st); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars((string)$r['requested_at']); ?></td>
                                        <td class="note">
                                            <input type="text" placeholder="(opzionale)" value="<?php echo htmlspecialchars((string)($r['note'] ?? '')); ?>">
                                        </td>
                                        <td>
                                            <div class="actions">
                                                <button class="btn btn-primary btn-sm" data-action="approve">Approva</button>
                                                <button class="btn btn-secondary btn-sm" data-action="reject">Rifiuta</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        (function() {
            const csrfToken = document.getElementById('csrfToken')?.value || '';
            const apiUrl = '/CollaboraNexio/api/files_tenant.php';

            function post(action, payload) {
                return fetch(apiUrl + '?action=' + encodeURIComponent(action), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload || {})
                }).then(r => r.json().then(j => ({ ok: r.ok, status: r.status, json: j })));
            }

            document.addEventListener('click', async (e) => {
                const btn = e.target.closest('button[data-action]');
                if (!btn) return;
                const row = btn.closest('tr[data-request-id]');
                if (!row) return;

                const requestId = parseInt(row.getAttribute('data-request-id'), 10);
                const note = row.querySelector('input')?.value || '';
                const action = btn.getAttribute('data-action');

                btn.disabled = true;
                try {
                    if (action === 'approve') {
                        const res = await post('approve_file_download_request', { request_id: requestId, note });
                        if (!res.ok || res.json?.success === false) throw new Error(res.json?.error || 'Errore approvazione');
                        row.querySelector('.status-pill')?.classList.remove('status-pending');
                        row.querySelector('.status-pill')?.classList.add('status-approved');
                        row.querySelector('.status-pill').textContent = 'approved';
                        row.querySelectorAll('button[data-action]').forEach(b => b.disabled = true);
                    } else if (action === 'reject') {
                        const res = await post('reject_file_download_request', { request_id: requestId, note });
                        if (!res.ok || res.json?.success === false) throw new Error(res.json?.error || 'Errore rifiuto');
                        row.querySelector('.status-pill')?.classList.remove('status-pending');
                        row.querySelector('.status-pill')?.classList.add('status-rejected');
                        row.querySelector('.status-pill').textContent = 'rejected';
                        row.querySelectorAll('button[data-action]').forEach(b => b.disabled = true);
                    }
                } catch (err) {
                    alert(err && err.message ? err.message : 'Errore');
                    btn.disabled = false;
                }
            });

            document.getElementById('sidebarToggle')?.addEventListener('click', () => {
                document.querySelector('.sidebar')?.classList.toggle('collapsed');
            });
        })();
    </script>
</body>
</html>


