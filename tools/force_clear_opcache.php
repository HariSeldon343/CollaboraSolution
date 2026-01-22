<?php
/**
 * Tool: Force clear OPcache (Super Admin only)
 *
 * Purpose: instantly invalidate PHP opcode cache when code changes
 * are not reflected (common with OPcache + tunnels/CDN).
 *
 * Access: super_admin only (requires active session)
 * Method: GET shows status + button; POST executes reset
 */

declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../includes/session_init.php';
require_once __DIR__ . '/../includes/auth_simple.php';

$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: ../index.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    header('Location: ../index.php');
    exit;
}

$role = $currentUser['user_role'] ?? $currentUser['role'] ?? 'user';
if ($role !== 'super_admin') {
    http_response_code(403);
    echo 'Accesso negato';
    exit;
}

$csrfToken = $auth->generateCSRFToken();

$didReset = false;
$resetOk = null;
$status = null;
$messages = [];

// Debug info: file versions + DB schema presence
$phpVersion = PHP_VERSION;
$extOpcache = extension_loaded('Zend OPcache') || extension_loaded('opcache');

$paths = [
    'utenti.php' => realpath(__DIR__ . '/../utenti.php') ?: (__DIR__ . '/../utenti.php'),
    'aziende.php' => realpath(__DIR__ . '/../aziende.php') ?: (__DIR__ . '/../aziende.php'),
    'api/tenant-roles/list.php' => realpath(__DIR__ . '/../api/tenant-roles/list.php') ?: (__DIR__ . '/../api/tenant-roles/list.php'),
    'api/users/tenant_role.php' => realpath(__DIR__ . '/../api/users/tenant_role.php') ?: (__DIR__ . '/../api/users/tenant_role.php'),
    'api/tenants/update.php' => realpath(__DIR__ . '/../api/tenants/update.php') ?: (__DIR__ . '/../api/tenants/update.php'),
];

function cnx_file_info(string $path): array {
    $exists = is_file($path);
    return [
        'path' => $path,
        'exists' => $exists,
        'mtime' => $exists ? @filemtime($path) : null,
        'size' => $exists ? @filesize($path) : null,
    ];
}

function cnx_file_contains(string $path, string $needle): bool {
    if (!is_file($path)) return false;
    $c = @file_get_contents($path);
    if ($c === false) return false;
    return strpos($c, $needle) !== false;
}

$fileInfos = [];
$fileChecks = [];
foreach ($paths as $label => $p) {
    $fileInfos[$label] = cnx_file_info($p);
}

// Check that the new marker/logic is really present on the server files
$fileChecks['utenti_has_build_marker'] = cnx_file_contains($paths['utenti.php'], 'CNX_BUILD_ID');
$fileChecks['utenti_has_manager_fallback'] = cnx_file_contains($paths['utenti.php'], 'If Company Filter is NOT available/selected');
$fileChecks['tenant_roles_api_has_can_assign_flag'] = cnx_file_contains($paths['api/tenant-roles/list.php'], 'can_assign_custom_roles');

// DB check (optional - might fail if config/db not available)
$dbChecks = [
    'tenants_has_assignment_column' => null,
    'tenants_has_denominazione_column' => null,
    'sample_tenant_id' => null,
    'sample_tenant_name' => null,
    'sample_tenant_has_custom_roles' => null,
    'sample_tenant_assignment_roles' => null,
    'sample_tenant_roles_total' => null,
    'sample_tenant_roles_active' => null,
    'sample_tenant_roles_first' => null,
    'tenants_preview' => null,
    'error' => null,
];
$inspectTenantId = (int)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? 0);
if ($inspectTenantId <= 0 && isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null) {
    $inspectTenantId = (int)$_SESSION['company_filter_id'];
}

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/db.php';
    $db = Database::getInstance();

    $colDen = $db->fetchOne(
        "SELECT COUNT(*) AS c
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'tenants'
           AND COLUMN_NAME = 'denominazione'"
    );
    $hasDen = ((int)($colDen['c'] ?? 0) > 0);
    $dbChecks['tenants_has_denominazione_column'] = $hasDen;

    $col = $db->fetchOne(
        "SELECT COUNT(*) AS c
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'tenants'
           AND COLUMN_NAME = 'tenant_role_assignment_roles'"
    );
    $dbChecks['tenants_has_assignment_column'] = ((int)($col['c'] ?? 0) > 0);

    // Preview tenants (last 50) to quickly find the right ID
    $nameExpr = $hasDen ? "COALESCE(denominazione, name)" : "name";
    $preview = $db->fetchAll(
        "SELECT id,
                {$nameExpr} AS tenant_name,
                has_custom_roles,
                tenant_role_assignment_roles
         FROM tenants
         WHERE deleted_at IS NULL
         ORDER BY id DESC
         LIMIT 50"
    );
    $dbChecks['tenants_preview'] = array_map(static function ($t) {
        return [
            'id' => (int)($t['id'] ?? 0),
            'name' => (string)($t['tenant_name'] ?? ''),
            'has_custom_roles' => (bool)($t['has_custom_roles'] ?? false),
            'tenant_role_assignment_roles' => $t['tenant_role_assignment_roles'] ?? null,
        ];
    }, $preview ?: []);

    if ($dbChecks['tenants_has_assignment_column'] && $inspectTenantId > 0) {
        $row = $db->fetchOne(
            "SELECT id,
                    {$nameExpr} AS tenant_name,
                    has_custom_roles,
                    tenant_role_assignment_roles
             FROM tenants
             WHERE id = ?
               AND deleted_at IS NULL
             LIMIT 1",
            [$inspectTenantId]
        );
        if ($row) {
            $dbChecks['sample_tenant_id'] = (int)$row['id'];
            $dbChecks['sample_tenant_name'] = (string)($row['tenant_name'] ?? '');
            $dbChecks['sample_tenant_has_custom_roles'] = (bool)($row['has_custom_roles'] ?? false);
            $dbChecks['sample_tenant_assignment_roles'] = $row['tenant_role_assignment_roles'] ?? null;

            // Tenant roles counts + preview
            $counts = $db->fetchOne(
                "SELECT
                    (SELECT COUNT(*) FROM tenant_roles tr
                     WHERE tr.tenant_id = ?
                       AND tr.deleted_at IS NULL) AS total,
                    (SELECT COUNT(*) FROM tenant_roles tr
                     WHERE tr.tenant_id = ?
                       AND tr.is_active = 1
                       AND tr.deleted_at IS NULL) AS active",
                [$inspectTenantId, $inspectTenantId]
            );
            $dbChecks['sample_tenant_roles_total'] = (int)($counts['total'] ?? 0);
            $dbChecks['sample_tenant_roles_active'] = (int)($counts['active'] ?? 0);

            $firstRoles = $db->fetchAll(
                "SELECT id, name, code, is_active
                 FROM tenant_roles
                 WHERE tenant_id = ?
                   AND deleted_at IS NULL
                 ORDER BY is_active DESC, sort_order ASC, name ASC
                 LIMIT 10",
                [$inspectTenantId]
            );
            $dbChecks['sample_tenant_roles_first'] = array_map(static function ($r) {
                return [
                    'id' => (int)($r['id'] ?? 0),
                    'name' => (string)($r['name'] ?? ''),
                    'code' => $r['code'] ?? null,
                    'is_active' => (bool)($r['is_active'] ?? false),
                ];
            }, $firstRoles ?: []);
        }
    }
} catch (Throwable $e) {
    $dbChecks['error'] = $e->getMessage();
}

if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $posted = (string)($_POST['csrf_token'] ?? '');
    if (!$posted || !isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $posted)) {
        http_response_code(403);
        $messages[] = 'Token CSRF non valido.';
    } else {
        $didReset = true;

        if (function_exists('opcache_reset')) {
            $resetOk = opcache_reset();
            $messages[] = $resetOk ? 'OPcache resettata con successo.' : 'Reset OPcache fallito.';
        } else {
            $resetOk = null;
            $messages[] = 'OPcache non disponibile (funzione opcache_reset() assente).';
        }

        // Also clear filesystem stat cache
        clearstatcache(true);

        // Best-effort invalidate key files (if OPcache supports it)
        if (function_exists('opcache_invalidate')) {
            $toInvalidate = [
                __DIR__ . '/../utenti.php',
                __DIR__ . '/../aziende.php',
                __DIR__ . '/../api/tenant-roles/list.php',
                __DIR__ . '/../api/users/tenant_role.php',
                __DIR__ . '/../api/tenants/update.php',
            ];
            foreach ($toInvalidate as $p) {
                if (is_file($p)) {
                    @opcache_invalidate($p, true);
                }
            }
        }

        // Refresh status after reset
        if (function_exists('opcache_get_status')) {
            $status = opcache_get_status(false);
        }
    }
}

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

?><!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Force clear OPcache</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; padding: 24px; background: #0b1220; color: #e5e7eb; }
        .card { background: #111827; border: 1px solid #1f2937; border-radius: 10px; padding: 16px; margin: 16px 0; }
        .row { display:flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .btn { padding: 10px 14px; border-radius: 8px; border: 1px solid #374151; background: #2563eb; color: #fff; cursor:pointer; }
        .btn:disabled { opacity: .6; cursor:not-allowed; }
        .muted { color: #9ca3af; font-size: 13px; }
        .ok { color: #34d399; }
        .bad { color: #fb7185; }
        pre { background:#0b1020; border:1px solid #1f2937; border-radius:10px; padding: 12px; overflow:auto; }
        a { color:#93c5fd; }
    </style>
</head>
<body>
    <h1>Force clear OPcache (Super Admin)</h1>
    <div class="muted">Usa questa pagina se vedi “comportamento vecchio” dopo un deploy/modifica PHP.</div>

    <div class="card">
        <div class="row">
            <div><strong>Utente:</strong> <?php echo h($currentUser['name'] ?? ''); ?></div>
            <div class="muted"><strong>Ruolo:</strong> <?php echo h($role); ?></div>
        </div>
    </div>

    <?php if (!empty($messages)): ?>
        <div class="card">
            <?php foreach ($messages as $m): ?>
                <div><?php echo h($m); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Stato OPcache</h2>
        <?php if ($status === null): ?>
            <div class="bad">Estensione OPcache non disponibile.</div>
        <?php else: ?>
            <?php $enabled = (bool)($status['opcache_enabled'] ?? false); ?>
            <div><?php echo $enabled ? '<span class="ok">Abilitata</span>' : '<span class="muted">Disabilitata</span>'; ?></div>
            <?php if ($enabled): ?>
                <div class="muted">Cached scripts: <?php echo (int)($status['opcache_statistics']['num_cached_scripts'] ?? 0); ?></div>
                <div class="muted">Hits: <?php echo (int)($status['opcache_statistics']['hits'] ?? 0); ?> — Misses: <?php echo (int)($status['opcache_statistics']['misses'] ?? 0); ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Verifica versione servita (anti “comportamento vecchio”)</h2>
        <div class="muted">PHP version: <strong><?php echo h($phpVersion); ?></strong> — OPcache extension loaded: <strong><?php echo $extOpcache ? 'yes' : 'no'; ?></strong></div>

        <h3 style="margin-top:12px;">File timestamps</h3>
        <pre><?php echo h(json_encode($fileInfos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>

        <h3>Check rapidi codice</h3>
        <pre><?php echo h(json_encode($fileChecks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>

        <h3>DB schema/check</h3>
        <form method="GET" style="margin: 10px 0;">
            <label class="muted">Tenant ID da ispezionare (opzionale):</label>
            <input name="tenant_id" value="<?php echo (int)$inspectTenantId; ?>" style="margin-left:10px; padding:8px 10px; border-radius:8px; border:1px solid #374151; background:#0b1020; color:#e5e7eb;" />
            <button class="btn" type="submit" style="margin-left:10px;">Ispeziona</button>
        </form>
        <pre><?php echo h(json_encode($dbChecks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>

        <div class="muted">Se `utenti_has_build_marker=false` o `utenti_has_manager_fallback=false`, significa che sul server non è presente la versione aggiornata di `utenti.php`.</div>
    </div>

    <div class="card">
        <h2>Reset</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
            <button class="btn" type="submit">Reset OPcache ora</button>
            <div class="muted" style="margin-top: 10px;">
                Dopo il reset apri `utenti.php?_ts=<?php echo time(); ?>` e controlla il marker `CNX_BUILD_ID` nel sorgente.
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Link rapidi</h2>
        <div class="row">
            <a href="../utenti.php?_ts=<?php echo time(); ?>">Apri utenti.php (cache bypass)</a>
            <a href="../aziende.php?_ts=<?php echo time(); ?>">Apri aziende.php (cache bypass)</a>
        </div>
    </div>

    <?php if ($didReset): ?>
        <div class="card">
            <h2>Debug (opzionale)</h2>
            <pre><?php echo h(json_encode([
                'did_reset' => $didReset,
                'reset_ok' => $resetOk,
                'time' => date('c'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
        </div>
    <?php endif; ?>
</body>
</html>

