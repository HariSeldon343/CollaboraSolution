<?php
// Page: Folder ZIP download requests (approval UI)
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
               r.folder_id,
               f.name AS folder_name,
               r.requester_id,
               u.name AS requester_name,
               u.email AS requester_email,
               r.status,
               r.requested_at,
               r.note
        FROM folder_zip_download_requests r
        INNER JOIN tenants t ON t.id = r.tenant_id AND t.deleted_at IS NULL
        INNER JOIN files f ON f.id = r.folder_id AND f.deleted_at IS NULL
        INNER JOIN users u ON u.id = r.requester_id AND u.deleted_at IS NULL
        WHERE r.tenant_id IN ($placeholders)
    ";

    if ($requestIdFilter > 0) {
        $sql .= " AND r.id = ?";
        $params[] = $requestIdFilter;
    } else {
        $sql .= " AND r.status = 'pending'";
    }

    // Manager role: restrict to configured manager if set
    if ($currentUser['role'] === 'manager') {
        // Restrict ONLY when tenant.manager_id is a valid manager assignment.
        // If manager_id is NULL/0 or invalid (deleted/non-manager/no access), fallback: any manager with tenant access can see.
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

// If single-request view is empty, compute a better hint message.
$singleRequestHint = null;
if ($requestIdFilter > 0 && empty($requests)) {
    try {
        $req = $db->fetchOne(
            "SELECT id, tenant_id, status
             FROM folder_zip_download_requests
             WHERE id = ?
             LIMIT 1",
            [$requestIdFilter]
        );

        if (!$req) {
            $singleRequestHint = 'Richiesta non trovata.';
        } else {
            $reqTenantId = (int)($req['tenant_id'] ?? 0);
            if ($reqTenantId <= 0) {
                $singleRequestHint = 'Richiesta non valida (tenant_id mancante).';
            } elseif (!in_array($reqTenantId, $tenantIds, true)) {
                $singleRequestHint = 'Richiesta esistente ma non autorizzata per il tuo account (tenant non accessibile).';
            } elseif (($currentUser['role'] ?? '') === 'manager') {
                // Check if tenant has a valid manager_id assignment and it's not this user.
                $t = $db->fetchOne(
                    "SELECT id, manager_id
                     FROM tenants
                     WHERE id = ? AND deleted_at IS NULL
                     LIMIT 1",
                    [$reqTenantId]
                );
                $managerId = (int)($t['manager_id'] ?? 0);
                if ($managerId > 0 && $managerId !== (int)$currentUser['id']) {
                    $m = $db->fetchOne(
                        "SELECT id, email, role, tenant_id, deleted_at
                         FROM users
                         WHERE id = ?
                         LIMIT 1",
                        [$managerId]
                    );
                    $isDeleted = $m ? ($m['deleted_at'] !== null) : true;
                    $role = $m ? (string)($m['role'] ?? '') : '';
                    $email = $m ? trim((string)($m['email'] ?? '')) : '';
                    $mTenantId = $m ? (int)($m['tenant_id'] ?? 0) : 0;

                    $hasAccess = false;
                    if (!$isDeleted && $role === 'manager' && $email !== '') {
                        if ($mTenantId === $reqTenantId) {
                            $hasAccess = true;
                        } else {
                            $uta = $db->fetchOne(
                                "SELECT 1
                                 FROM user_tenant_access
                                 WHERE user_id = ?
                                   AND tenant_id = ?
                                 LIMIT 1",
                                [$managerId, $reqTenantId]
                            );
                            $hasAccess = (bool)$uta;
                        }
                    }

                    if ($hasAccess) {
                        $singleRequestHint = 'Richiesta esistente ma assegnata a un altro manager configurato per il tenant.';
                    }
                }

                if ($singleRequestHint === null) {
                    $singleRequestHint = 'Richiesta esistente ma non visibile (dati collegati mancanti o non accessibili).';
                }
            } else {
                $singleRequestHint = 'Richiesta esistente ma non visibile (dati collegati mancanti o non accessibili).';
            }
        }
    } catch (Throwable $e) {
        $singleRequestHint = 'Richiesta non disponibile (errore interno).';
    }
}

// Quick health check: approval table exists?
$approvalTableExists = false;
try {
    $stmt = $db->getConnection()->prepare("SHOW TABLES LIKE ?");
    $stmt->execute(['folder_zip_download_requests']);
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
    <title>Richieste Download ZIP - Nexio</title>
    <?php require_once __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/sidebar-responsive.css">
    <style>
        /* Sidebar CSS is centralized in assets/css/styles.css */

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
                    <h1 class="header-title">Richieste Download ZIP</h1>
                </div>
            </header>

            <div class="container">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Richieste</h2>
                        <div class="muted">
                            <?php if ($requestIdFilter > 0): ?>
                                Vista singola richiesta (ID <?php echo (int)$requestIdFilter; ?>)
                            <?php else: ?>
                                Solo richieste in attesa (pending)
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($currentUser['role'] === 'super_admin' && !$approvalTableExists): ?>
                        <div style="padding:14px 18px;border-bottom:1px solid #eef0f3;background:#fff7ed;color:#9a3412;">
                            <strong>Setup richiesto:</strong> la tabella approvazioni non esiste ancora su questo DB.
                            <a href="/CollaboraNexio/tools/apply_migration_24_folder_zip_download_requests.php" style="margin-left:8px;color:#9a3412;text-decoration:underline;">
                                Applica Migration 24
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($requests)): ?>
                        <div style="padding:18px;color:#6b7280;">
                            <?php if ($requestIdFilter > 0 && $singleRequestHint): ?>
                                <?php echo htmlspecialchars((string)$singleRequestHint, ENT_QUOTES, 'UTF-8'); ?>
                            <?php else: ?>
                                Nessuna richiesta da mostrare.
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tenant</th>
                                    <th>Cartella</th>
                                    <th>Richiedente</th>
                                    <th>Stato</th>
                                    <th>Richiesta</th>
                                    <th>Note</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $r): ?>
                                    <tr>
                                        <td><?php echo (int)$r['id']; ?></td>
                                        <td><?php echo htmlspecialchars((string)$r['tenant_name']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$r['folder_name']); ?></td>
                                        <td>
                                            <div><?php echo htmlspecialchars((string)$r['requester_name']); ?></div>
                                            <div class="muted"><?php echo htmlspecialchars((string)$r['requester_email']); ?></div>
                                        </td>
                                        <td>
                                            <?php
                                                $status = (string)$r['status'];
                                                $cls = $status === 'approved' ? 'status-approved' : ($status === 'rejected' ? 'status-rejected' : 'status-pending');
                                            ?>
                                            <span class="status-pill <?php echo $cls; ?>"><?php echo htmlspecialchars($status); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars((string)$r['requested_at']); ?></td>
                                        <td class="note">
                                            <input type="text" placeholder="Nota (opzionale)" data-note-for="<?php echo (int)$r['id']; ?>" value="">
                                        </td>
                                        <td>
                                            <div class="actions">
                                                <button class="btn btn-primary btn-sm" onclick="approveReq(<?php echo (int)$r['id']; ?>)">Approva</button>
                                                <button class="btn btn-secondary btn-sm" onclick="rejectReq(<?php echo (int)$r['id']; ?>)">Rifiuta</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <script>
        function getNote(requestId) {
            const el = document.querySelector(`[data-note-for="${requestId}"]`);
            return el ? (el.value || '').trim() : '';
        }

        async function approveReq(requestId) {
            const note = getNote(requestId);
            const csrf = document.getElementById('csrfToken')?.value || '';
            const resp = await fetch('/CollaboraNexio/api/files_tenant.php?action=approve_folder_zip_request', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({ request_id: requestId, note, csrf_token: csrf })
            });
            const data = await resp.json().catch(() => null);
            if (data && data.success) {
                window.location.reload();
            } else {
                alert((data && (data.error || data.message)) ? (data.error || data.message) : 'Errore approvazione');
            }
        }

        async function rejectReq(requestId) {
            const note = getNote(requestId);
            const csrf = document.getElementById('csrfToken')?.value || '';
            const resp = await fetch('/CollaboraNexio/api/files_tenant.php?action=reject_folder_zip_request', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({ request_id: requestId, note, csrf_token: csrf })
            });
            const data = await resp.json().catch(() => null);
            if (data && data.success) {
                window.location.reload();
            } else {
                alert((data && (data.error || data.message)) ? (data.error || data.message) : 'Errore rifiuto');
            }
        }
    </script>
</body>
</html>

