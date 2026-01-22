<?php
// Manual email sender (Super Admin only)
declare(strict_types=1);

require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/company_filter.php';

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

// Require active tenant access (super_admins bypass this check)
require_once __DIR__ . '/includes/tenant_access_check.php';
requireTenantAccess($currentUser['id'], $currentUser['role']);

// Enforce Page Visibility access rules (super_admin bypasses)
require_once __DIR__ . '/includes/page_access_check.php';
checkPageAccess('email_manual');

$userRole = (string)($currentUser['role'] ?? 'user');
if ($userRole !== 'super_admin') {
    header('Location: dashboard.php');
    exit;
}

// CSRF token
$csrfToken = $auth->generateCSRFToken();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mailer.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

$companyFilter = new CompanyFilter($currentUser);
$activeTenantIds = $companyFilter->canUseCompanyFilter() ? $companyFilter->getActiveFilterIds() : null; // null => all
$activeTenantName = $companyFilter->canUseCompanyFilter() ? $companyFilter->getActiveFilterName() : 'Tutte le aziende';

// Schema drift helpers
$colExists = static function (string $table, string $col) use ($db): bool {
    try {
        return (bool)$db->fetchOne(
            "SELECT 1 AS ok
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$table, $col]
        );
    } catch (Throwable $e) {
        return false;
    }
};
$hasUsersIsActive = $colExists('users', 'is_active');
$utaHasDeletedAt = $colExists('user_tenant_access', 'deleted_at');

$flash = null; // ['type' => 'success'|'error'|'warning', 'message' => string]

/**
 * Convert user message to safe HTML paragraphs.
 */
$messageToHtml = static function (string $text): string {
    $text = trim($text);
    if ($text === '') return '';
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $parts = preg_split("/\n{2,}/", $text) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '') continue;
        $safe = htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
        $safe = nl2br($safe, false);
        $out[] = '<p style="margin:0 0 12px 0;">' . $safe . '</p>';
    }
    return implode("\n", $out);
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['cnx_action'] ?? '') === 'send_manual_email') {
    // CSRF verify
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = (string)($_POST['csrf_token'] ?? '');
    $sess = (string)($_SESSION['csrf_token'] ?? '');
    if ($token === '' || $sess === '' || !hash_equals($sess, $token)) {
        $flash = ['type' => 'error', 'message' => 'Token CSRF non valido'];
    } else {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $recipientIdsRaw = $_POST['recipient_ids'] ?? [];
        $recipientIds = [];
        if (is_array($recipientIdsRaw)) {
            foreach ($recipientIdsRaw as $v) {
                $id = (int)$v;
                if ($id > 0) $recipientIds[] = $id;
            }
        }
        $recipientIds = array_values(array_unique($recipientIds));

        if ($subject === '') {
            $flash = ['type' => 'error', 'message' => 'Oggetto richiesto'];
        } elseif (mb_strlen($subject, 'UTF-8') > 160) {
            $flash = ['type' => 'error', 'message' => 'Oggetto troppo lungo (max 160 caratteri)'];
        } elseif ($message === '') {
            $flash = ['type' => 'error', 'message' => 'Messaggio richiesto'];
        } elseif (empty($recipientIds)) {
            $flash = ['type' => 'error', 'message' => 'Seleziona almeno un destinatario'];
        } elseif (count($recipientIds) > 200) {
            $flash = ['type' => 'error', 'message' => 'Troppi destinatari (max 200). Restringi la selezione.'];
        } else {
            // Load recipients from DB and enforce tenant filter (best-effort)
            $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));

            $whereTenant = '';
            $tenantParams = [];
            if (is_array($activeTenantIds) && !empty($activeTenantIds)) {
                $tPh = implode(',', array_fill(0, count($activeTenantIds), '?'));
                $utaNotDeleted = $utaHasDeletedAt ? " AND uta.deleted_at IS NULL" : "";
                $whereTenant = " AND (
                    u.tenant_id IN ($tPh)
                    OR EXISTS (
                        SELECT 1 FROM user_tenant_access uta
                        WHERE uta.user_id = u.id
                          AND uta.tenant_id IN ($tPh)
                          {$utaNotDeleted}
                    )
                )";
                $tenantParams = array_merge($activeTenantIds, $activeTenantIds);
            }

            $isActiveWhere = $hasUsersIsActive ? " AND u.is_active = 1" : "";
            $sql = "
                SELECT u.id, u.name, u.email, u.tenant_id, t.denominazione AS tenant_name
                FROM users u
                LEFT JOIN tenants t ON t.id = u.tenant_id
                WHERE u.id IN ($placeholders)
                  AND u.deleted_at IS NULL
                  {$isActiveWhere}
                  {$whereTenant}
                ORDER BY u.id ASC
            ";

            $stmt = $pdo->prepare($sql);
            $idx = 1;
            foreach ($recipientIds as $id) { $stmt->bindValue($idx++, $id, PDO::PARAM_INT); }
            foreach ($tenantParams as $v) { $stmt->bindValue($idx++, (int)$v, PDO::PARAM_INT); }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($rows)) {
                $flash = ['type' => 'error', 'message' => 'Nessun destinatario valido (verifica filtro azienda e selezione utenti)'];
            } else {
                $ok = 0;
                $fail = 0;
                $failList = [];

                $baseUrl = defined('BASE_URL') ? (string)BASE_URL : '';
                $bodyContent = $messageToHtml($message);
                $preheader = mb_substr(preg_replace('/\s+/', ' ', $message), 0, 140, 'UTF-8');

                foreach ($rows as $r) {
                    $to = trim((string)($r['email'] ?? ''));
                    if ($to === '') {
                        $fail++;
                        $failList[] = 'User #' . (int)$r['id'] . ': email mancante';
                        continue;
                    }
                    $name = trim((string)($r['name'] ?? ''));
                    $tenantName = trim((string)($r['tenant_name'] ?? ''));
                    $tenantId = (int)($r['tenant_id'] ?? 0);

                    $greet = $name !== '' ? ('Ciao ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',') : 'Ciao,';
                    $htmlBody = ''
                        . '<p style="margin:0 0 12px 0;">' . $greet . '</p>'
                        . $bodyContent
                        . '<p style="margin:16px 0 0 0;color:#6b7280;font-size:12px;">'
                        . 'Messaggio inviato manualmente da un Super Admin.'
                        . '</p>';

                    $html = renderEmailLayout($subject, $htmlBody, [
                        'BASE_URL' => $baseUrl,
                        'TENANT_NAME' => $tenantName,
                        'YEAR' => date('Y'),
                    ], [
                        'preheader' => $preheader,
                    ]);

                    $sent = sendEmail($to, $subject, $html, '', [
                        'context' => [
                            'tenant_id' => $tenantId > 0 ? $tenantId : null,
                            'user_id' => (int)($currentUser['id'] ?? 0),
                            'action' => 'manual_email',
                        ],
                    ]);

                    if ($sent) {
                        $ok++;
                    } else {
                        $fail++;
                        $failList[] = ($name !== '' ? $name : ('#' . (int)$r['id'])) . ' <' . $to . '>';
                    }
                }

                if ($fail === 0) {
                    $flash = ['type' => 'success', 'message' => "Email inviate: {$ok}"];
                } else {
                    $msg = "Email inviate: {$ok}. Errori: {$fail}.";
                    if (!empty($failList)) {
                        $msg .= " Non inviate a: " . implode(', ', array_slice($failList, 0, 12));
                        if (count($failList) > 12) $msg .= '…';
                    }
                    $flash = ['type' => 'warning', 'message' => $msg];
                }
            }
        }
    }
}

// Load selectable users (best-effort, filtered by company filter)
$users = [];
try {
    $where = "u.deleted_at IS NULL";
    $params = [];
    if ($hasUsersIsActive) {
        $where .= " AND u.is_active = 1";
    }
    if (is_array($activeTenantIds) && !empty($activeTenantIds)) {
        $tPh = implode(',', array_fill(0, count($activeTenantIds), '?'));
        $utaNotDeleted = $utaHasDeletedAt ? " AND uta.deleted_at IS NULL" : "";
        $where .= " AND (
            u.tenant_id IN ($tPh)
            OR EXISTS (
                SELECT 1 FROM user_tenant_access uta
                WHERE uta.user_id = u.id
                  AND uta.tenant_id IN ($tPh)
                  {$utaNotDeleted}
            )
        )";
        $params = array_merge($params, $activeTenantIds, $activeTenantIds);
    }

    $sql = "
        SELECT u.id, u.name, u.email, u.role, u.tenant_id, t.denominazione AS tenant_name
        FROM users u
        LEFT JOIN tenants t ON t.id = u.tenant_id
        WHERE {$where}
        ORDER BY t.denominazione ASC, u.role ASC, u.name ASC, u.id ASC
        LIMIT 2000
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $i => $v) {
        $stmt->bindValue($i + 1, (int)$v, PDO::PARAM_INT);
    }
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $users = [];
}

$cnxBuildId = 'email_manual.php@' . (string)@filemtime(__FILE__);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Email Manuali - Nexio';
    $pageCss = ['assets/css/dashboard.css'];
    require __DIR__ . '/includes/layout_head.php';
?>
    <!-- CNX_BUILD_ID: <?php echo htmlspecialchars($cnxBuildId); ?> -->
    <style>
        .mail-card { background: var(--color-white); border: 1px solid var(--color-gray-200); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); }
        .mail-card-h { padding: var(--space-4) var(--space-5); border-bottom: 1px solid var(--color-gray-200); display:flex; align-items:center; justify-content:space-between; gap: var(--space-3); }
        .mail-card-b { padding: var(--space-5); }
        .mail-grid { display:grid; grid-template-columns: 1.2fr 1fr; gap: var(--space-6); }
        @media (max-width: 1100px) { .mail-grid { grid-template-columns: 1fr; } }
        .user-list { border: 1px solid var(--color-gray-200); border-radius: var(--radius-lg); overflow:hidden; }
        .user-list-head { padding: 10px 12px; background: var(--color-gray-50); display:flex; align-items:center; justify-content:space-between; gap: 10px; flex-wrap:wrap; }
        .user-list-body { max-height: 520px; overflow:auto; }
        table.user-table { width:100%; border-collapse: collapse; }
        table.user-table th, table.user-table td { border-bottom: 1px solid var(--color-gray-100); padding: 10px 10px; font-size: 13px; vertical-align: top; }
        table.user-table th { background: var(--color-gray-50); color: var(--color-gray-600); font-size: 12px; text-transform: uppercase; letter-spacing: .03em; text-align:left; }
        .muted { color: var(--color-gray-600); font-size: 13px; }
        .pill { display:inline-flex; align-items:center; gap:6px; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; background: var(--color-gray-100); color: var(--color-gray-700); }
        .flash { padding: 10px 12px; border-radius: 10px; margin-bottom: 12px; border: 1px solid var(--color-gray-200); }
        .flash.success { background: #ecfdf5; border-color: #bbf7d0; color: #065f46; }
        .flash.error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .flash.warning { background: #fffbeb; border-color: #fde68a; color: #92400e; }
        .field { display:flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
        .field label { font-weight: 700; font-size: 13px; color: var(--color-gray-700); }
        .field input[type="text"], .field textarea { width:100%; padding: 10px 12px; border: 1px solid var(--color-gray-300); border-radius: 10px; font-size: 14px; }
        .field textarea { min-height: 180px; resize: vertical; }
        .actions { display:flex; justify-content:flex-end; gap: 10px; }
    </style>
</head>
<?php require __DIR__ . '/includes/layout_start.php'; ?>

<div class="header">
    <h1 class="page-title">Email manuali</h1>
    <div class="flex items-center gap-4">
        <?php if ($companyFilter->canUseCompanyFilter()): ?>
            <?php echo $companyFilter->renderDropdown(); ?>
        <?php endif; ?>
        <span class="text-sm text-muted">Invio manuale email (solo Super Admin)</span>
    </div>
</div>

<div class="page-content">
    <div class="mail-card">
        <div class="mail-card-h">
            <div style="font-weight:800;">Invia email</div>
            <div class="muted">Filtro azienda: <span class="pill"><?php echo htmlspecialchars($activeTenantName); ?></span></div>
        </div>
        <div class="mail-card-b">
            <?php if ($flash): ?>
                <div class="flash <?php echo htmlspecialchars($flash['type']); ?>">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="cnx_action" value="send_manual_email">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <div class="mail-grid">
                    <div>
                        <div class="user-list">
                            <div class="user-list-head">
                                <div style="display:flex; align-items:center; gap: 10px; flex-wrap:wrap;">
                                    <div style="font-weight:700;">Destinatari (<?php echo (int)count($users); ?>)</div>
                                    <div class="muted">Seleziona gli utenti a cui inviare l’email.</div>
                                </div>
                                <div style="display:flex; align-items:center; gap: 8px; flex-wrap:wrap;">
                                    <input type="text" id="userSearch" placeholder="Cerca..." style="padding:8px 10px; border:1px solid var(--color-gray-300); border-radius:10px; font-size:13px;">
                                    <button type="button" class="btn btn-secondary btn-sm" id="selectAllBtn">Seleziona tutti</button>
                                    <button type="button" class="btn btn-secondary btn-sm" id="clearAllBtn">Svuota</button>
                                </div>
                            </div>
                            <div class="user-list-body">
                                <table class="user-table" id="userTable">
                                    <thead>
                                        <tr>
                                            <th style="width:44px;"></th>
                                            <th>Nome</th>
                                            <th>Email</th>
                                            <th>Ruolo</th>
                                            <th>Azienda</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($users as $u): ?>
                                        <tr data-search="<?php echo htmlspecialchars(mb_strtolower(($u['name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['role'] ?? '') . ' ' . ($u['tenant_name'] ?? ''), 'UTF-8')); ?>">
                                            <td>
                                                <input type="checkbox" name="recipient_ids[]" value="<?php echo (int)$u['id']; ?>">
                                            </td>
                                            <td><?php echo htmlspecialchars((string)($u['name'] ?? '')); ?></td>
                                            <td><?php echo htmlspecialchars((string)($u['email'] ?? '')); ?></td>
                                            <td><span class="pill"><?php echo htmlspecialchars((string)($u['role'] ?? '')); ?></span></td>
                                            <td class="muted"><?php echo htmlspecialchars((string)($u['tenant_name'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php if (empty($users)): ?>
                                    <div class="muted" style="padding: 14px 12px;">Nessun utente disponibile per il filtro selezionato.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="field">
                            <label for="subject">Oggetto</label>
                            <input type="text" id="subject" name="subject" maxlength="160" value="<?php echo htmlspecialchars((string)($_POST['subject'] ?? '')); ?>" placeholder="Oggetto email...">
                        </div>
                        <div class="field">
                            <label for="message">Messaggio</label>
                            <textarea id="message" name="message" placeholder="Scrivi il messaggio..."><?php echo htmlspecialchars((string)($_POST['message'] ?? '')); ?></textarea>
                            <div class="muted">Il messaggio viene inviato con lo stesso layout grafico delle email automatiche.</div>
                        </div>
                        <div class="actions">
                            <button type="submit" class="btn btn-primary">Invia email</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(() => {
  const q = document.getElementById('userSearch');
  const table = document.getElementById('userTable');
  const rows = table ? Array.from(table.querySelectorAll('tbody tr')) : [];
  const selectAllBtn = document.getElementById('selectAllBtn');
  const clearAllBtn = document.getElementById('clearAllBtn');

  const applyFilter = () => {
    const needle = (q?.value || '').trim().toLowerCase();
    rows.forEach(tr => {
      const hay = (tr.getAttribute('data-search') || '');
      const ok = !needle || hay.indexOf(needle) !== -1;
      tr.style.display = ok ? '' : 'none';
    });
  };

  q?.addEventListener('input', applyFilter);

  selectAllBtn?.addEventListener('click', () => {
    rows.forEach(tr => {
      if (tr.style.display === 'none') return;
      const cb = tr.querySelector('input[type="checkbox"]');
      if (cb) cb.checked = true;
    });
  });

  clearAllBtn?.addEventListener('click', () => {
    rows.forEach(tr => {
      const cb = tr.querySelector('input[type="checkbox"]');
      if (cb) cb.checked = false;
    });
  });

  applyFilter();
})();
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
</html>

