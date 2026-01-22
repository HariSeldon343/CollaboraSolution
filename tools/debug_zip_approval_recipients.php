<?php
/**
 * Debug tool: ZIP approval recipients resolver
 *
 * Security:
 * - Auth required
 * - super_admin only
 * - CSRF required for POST (test email)
 */
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../includes/session_init.php';
require_once __DIR__ . '/../includes/auth_simple.php';
require_once __DIR__ . '/../includes/api_auth.php'; // verifyApiCsrfToken()
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/zip_approval_recipients.php';

$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: ../index.php');
    exit;
}
$currentUser = $auth->getCurrentUser();
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$csrfToken = $auth->generateCSRFToken();
$db = Database::getInstance();
$pdo = $db->getConnection();

$isPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
$action = (string)($_POST['action'] ?? '');

$tenantId = (int)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? 0);
$requestId = (int)($_GET['request_id'] ?? $_POST['request_id'] ?? 0);

$info = [
    'tenant' => null,
    'request' => null,
    'manager_user' => null,
    'manager_access_via_uta' => null,
    'recipients' => [],
    'diag' => null,
];

$error = null;
$sendResult = null;

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function cnx_try_fetch_request(PDO $pdo, int $requestId): ?array {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, tenant_id, folder_id, requester_id, status, requested_at, decided_at, decided_by, used_at
             FROM folder_zip_download_requests
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

try {
    if ($requestId > 0 && $tenantId <= 0) {
        $req = cnx_try_fetch_request($pdo, $requestId);
        if ($req) {
            $tenantId = (int)($req['tenant_id'] ?? 0);
            $info['request'] = $req;
        } else {
            $error = 'Request non trovata o tabella mancante/non accessibile.';
        }
    }

    if ($tenantId > 0) {
        $tenant = $db->fetchOne(
            "SELECT id, COALESCE(denominazione, name) AS tenant_name, manager_id
             FROM tenants
             WHERE id = ? AND deleted_at IS NULL
             LIMIT 1",
            [$tenantId]
        );
        $info['tenant'] = $tenant ?: null;

        $managerId = (int)($tenant['manager_id'] ?? 0);
        if ($managerId > 0) {
            $managerUser = $db->fetchOne(
                "SELECT id, name, email, role, tenant_id
                 FROM users
                 WHERE id = ? AND deleted_at IS NULL
                 LIMIT 1",
                [$managerId]
            );
            $info['manager_user'] = $managerUser ?: null;

            if ($managerUser) {
                $access = null;
                if ((int)($managerUser['tenant_id'] ?? 0) === $tenantId) {
                    $access = true;
                } else {
                    $uta = $db->fetchOne(
                        "SELECT 1
                         FROM user_tenant_access
                         WHERE user_id = ?
                           AND tenant_id = ?
                         LIMIT 1",
                        [$managerId, $tenantId]
                    );
                    $access = (bool)$uta;
                }
                $info['manager_access_via_uta'] = $access;
            }
        }

        $diag = null;
        $recipients = cnx_resolve_tenant_manager_recipients($db, $tenantId, $managerId, $diag);
        $info['recipients'] = $recipients;
        $info['diag'] = $diag;
    }

    if ($isPost && $action === 'send_test') {
        verifyApiCsrfToken(true);

        if ($tenantId <= 0) {
            throw new RuntimeException('tenant_id richiesto per invio test');
        }
        if (!$info['tenant']) {
            throw new RuntimeException('Tenant non trovato');
        }

        $tenantName = (string)($info['tenant']['tenant_name'] ?? ('Tenant ' . $tenantId));
        $recipients = (array)($info['recipients'] ?? []);
        if (empty($recipients)) {
            throw new RuntimeException('Nessun destinatario risolto: non invio email');
        }

        $baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : 'http://localhost:8888/CollaboraNexio';
        $subject = 'TEST: ZIP approval recipients - ' . $tenantName . ' (tenant_id=' . $tenantId . ')';

        $emails = array_values(array_filter(array_map(static function ($r) {
            return (string)($r['email'] ?? '');
        }, $recipients)));
        $html = '<p>Questo è un test di diagnostica destinatari per approvazione download ZIP.</p>'
            . '<p><strong>Tenant:</strong> ' . h($tenantName) . ' (ID ' . (int)$tenantId . ')</p>'
            . '<p><strong>Base URL:</strong> ' . h($baseUrl) . '</p>'
            . '<p><strong>Destinatari:</strong><br>' . h(implode(', ', $emails)) . '</p>';
        $text = "TEST destinatari ZIP approval\nTenant: $tenantName (ID $tenantId)\nDestinatari: " . implode(', ', $emails) . "\n";

        $sent = [];
        $failed = [];
        foreach ($recipients as $r) {
            $to = (string)($r['email'] ?? '');
            if ($to === '') continue;
            $ok = sendEmail($to, $subject, $html, $text, [
                'context' => [
                    'action' => 'debug_zip_approval_recipients_test',
                    'tenant_id' => $tenantId,
                    'tool' => 'tools/debug_zip_approval_recipients.php',
                ],
            ]);
            if ($ok) $sent[] = $to; else $failed[] = $to;
        }

        $sendResult = [
            'sent' => $sent,
            'failed' => $failed,
            'count' => ['sent' => count($sent), 'failed' => count($failed)],
        ];
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug ZIP approval recipients</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        .wrap { max-width: 980px; margin: 32px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px; }
        pre { background:#0b1020; color:#e5e7eb; padding: 12px; border-radius: 10px; overflow:auto; }
        .row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
        .row > div { flex: 1 1 240px; }
        label { display:block; font-size: 12px; color:#6b7280; margin-bottom:6px; }
        input { width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:10px; }
        .muted { color:#6b7280; font-size: 13px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h2 style="margin-top:0;">Debug: destinatari email approvazione ZIP</h2>
        <p class="muted" style="margin-top:6px;">
            Risolve i destinatari secondo le regole attuali (preferenza manager_id valido, altrimenti manager del tenant).
        </p>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin:12px 0;"><?php echo h($error); ?></div>
        <?php endif; ?>

        <?php if ($sendResult): ?>
            <div class="alert alert-info" style="margin:12px 0;">
                Test email completato. Inviate: <?php echo (int)($sendResult['count']['sent'] ?? 0); ?>,
                fallite: <?php echo (int)($sendResult['count']['failed'] ?? 0); ?>.
            </div>
        <?php endif; ?>

        <form method="GET" style="margin-top:14px;">
            <div class="row">
                <div>
                    <label for="tenant_id">tenant_id</label>
                    <input id="tenant_id" name="tenant_id" type="number" value="<?php echo h((string)$tenantId); ?>" placeholder="es. 11">
                </div>
                <div>
                    <label for="request_id">request_id (opzionale)</label>
                    <input id="request_id" name="request_id" type="number" value="<?php echo h((string)$requestId); ?>" placeholder="es. 123">
                </div>
                <div style="flex:0 0 auto;">
                    <button type="submit" class="btn btn-primary">Calcola</button>
                    <a class="btn btn-secondary" href="./debug_zip_approval_recipients.php" style="margin-left:8px;">Reset</a>
                </div>
            </div>
        </form>

        <div style="margin-top:16px;">
            <h3 style="margin: 12px 0 8px;">Risultato</h3>
            <pre><?php echo h(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
        </div>

        <form method="POST" style="margin-top:12px;">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
            <input type="hidden" name="tenant_id" value="<?php echo h((string)$tenantId); ?>">
            <input type="hidden" name="request_id" value="<?php echo h((string)$requestId); ?>">
            <input type="hidden" name="action" value="send_test">
            <button type="submit" class="btn btn-secondary">Invia email test ai destinatari risolti</button>
        </form>
    </div>
</div>
</body>
</html>

