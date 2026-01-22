<?php
// Cookie Policy (solo cookie necessari) - Nexio
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/legal_policy.php';
require_once __DIR__ . '/includes/company_filter.php';

$auth = new Auth();
$isAuthed = $auth->checkAuth();
$currentUser = $isAuthed ? $auth->getCurrentUser() : null;

$role = (string)($currentUser['role'] ?? ($_SESSION['role'] ?? 'user'));
$tenantId = (int)($currentUser['tenant_id'] ?? ($_SESSION['tenant_id'] ?? 0));
if (in_array($role, ['admin', 'super_admin'], true)) {
    $cfId = (int)($_SESSION['company_filter_id'] ?? 0);
    if ($cfId > 0) {
        $tenantId = $cfId;
    } else {
        $cfIds = $_SESSION['company_filter_ids'] ?? [];
        if (is_array($cfIds) && !empty($cfIds)) {
            $first = (int)($cfIds[0] ?? 0);
            if ($first > 0) $tenantId = $first;
        }
    }
}

$db = Database::getInstance();
$tenantName = $tenantId > 0 ? (cnx_get_tenant_display_name($db, $tenantId) ?? '') : '';
$tenantSector = $tenantId > 0 ? (cnx_get_tenant_sector($db, $tenantId) ?? '') : '';
$policyVersion = cnx_get_legal_policy_version();
$cookieParams = session_get_cookie_params();
$cookieName = session_name();
$cookieDomain = (string)($cookieParams['domain'] ?? '');
$cookiePath = (string)($cookieParams['path'] ?? '');
$cookieSecure = (bool)($cookieParams['secure'] ?? false);
$cookieHttpOnly = (bool)($cookieParams['httponly'] ?? false);
$cookieSameSite = (string)($cookieParams['samesite'] ?? 'Lax');

$pageTitle = 'Cookie Policy - Nexio';
$pageCss = ['assets/css/dashboard.css', 'assets/css/legal_pages.css'];
require __DIR__ . '/includes/layout_head.php';

function cnx_h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <!-- CNX_POLICY_VERSION: <?php echo cnx_h($policyVersion); ?> -->
</head>
<?php if ($isAuthed): ?>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
<?php endif; ?>

<div class="page-content legal-page">
    <div class="legal-header">
        <img class="legal-logo" src="assets/images/logo.svg" alt="Nexio">
        <div>
            <div class="legal-title">NEXIO — Cookie Policy (solo cookie necessari)</div>
            <div class="legal-meta">Versione: <strong>1.0</strong> — Data: <strong>29/12/2025</strong></div>
        </div>
    </div>

    <div class="legal-card">
        <h3>1. Cosa sono i cookie</h3>
        <p>I cookie sono piccoli file di testo che aiutano un sito a funzionare correttamente (es. sessione e sicurezza).</p>

        <h3>2. Cookie usati da Nexio</h3>
        <p>Nexio utilizza solo cookie tecnici necessari al funzionamento (autenticazione, sessione, sicurezza).</p>

        <div class="legal-table-wrap">
            <table class="legal-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Finalità</th>
                        <th>Durata</th>
                        <th>Tipo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>COLLAB_SID</strong></td>
                        <td>Sessione autenticata e sicurezza</td>
                        <td>Sessione (fino a chiusura browser)</td>
                        <td>Necessario</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h3>3. Attributi di sicurezza (sessione)</h3>
        <ul>
            <li>HttpOnly (non accessibile via JavaScript).</li>
            <li>SameSite=Lax (riduce invii cross‑site).</li>
            <li>Secure quando in HTTPS.</li>
            <li>Path limitato all’app.</li>
        </ul>

        <h3>4. Tecnologie locali (non-cookie)</h3>
        <p>Nexio può salvare preferenze tecniche (es. tema/sidebar) tramite localStorage; non è usato per profilazione.</p>

        <h3>5. Come gestire i cookie</h3>
        <p>Puoi bloccare/eliminare i cookie dal browser, ma se blocchi i cookie necessari potresti non riuscire ad accedere o mantenere la sessione attiva.</p>

        <hr class="legal-divider">
        <p><strong>Link cookie policy online</strong>:</p>
        <p><a class="legal-link" href="https://app.nexiosolution.it/CollaboraNexio/cookie-policy.php">https://app.nexiosolution.it/CollaboraNexio/cookie-policy.php</a></p>
    </div>
</div>

<?php if ($isAuthed): ?>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
<?php else: ?>
</body>
</html>
<?php endif; ?>


