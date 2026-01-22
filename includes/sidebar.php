<?php
/**
 * CollaboraNexio - Sidebar Navigation Component (CSS Mask Icons)
 * Include questo file in tutte le pagine per avere una sidebar consistente
 * Integrato con sistema di Page Visibility per controllo accesso basato su ruolo
 */

// Ottieni il nome del file corrente per evidenziare la voce attiva
$current_page = basename($_SERVER['PHP_SELF']);

// Get current user info (should be available from auth)
$currentUser = $currentUser ?? $_SESSION['user'] ?? ['name' => 'Utente', 'role' => 'user'];

// Base URL (fix links when included from /tools or other subdirs)
$baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
$hrefPrefix = $baseUrl !== '' ? ($baseUrl . '/') : '';

// Include page visibility helper
require_once __DIR__ . '/page_visibility_helper.php';
require_once __DIR__ . '/tenant28_access_check.php';

// Get user role and tenant_id for visibility checks
$userRole = $currentUser['role'] ?? 'user';
$userTenantId = $currentUser['tenant_id'] ?? null;

// Super admin always sees everything
$isSuperAdmin = ($userRole === 'super_admin');

// Tenant 28 planning feature gate (visible only if user can access tenant 28 tools)
$canSeeTenant28Planning = false;
try {
    $t28 = cnxCheckTenant28Access($currentUser);
    $canSeeTenant28Planning = (bool)($t28['ok'] ?? false);
} catch (Exception $e) {
    $canSeeTenant28Planning = false;
}

/**
 * Check if a page should be visible in sidebar
 */
function shouldShowPage($pageName, $role, $tenantId, $isSuperAdmin) {
    if ($isSuperAdmin) return true;
    return isPageVisibleForRole($pageName, $role, $tenantId);
}
?>

<div class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <img src="<?php echo htmlspecialchars($hrefPrefix . 'assets/images/logo.png'); ?>" alt="CollaboraNexio" class="logo-img">
            <span class="logo-text">NEXIO</span>
        </div>
        <div class="sidebar-subtitle">Semplifica, Connetti, Cresci Insieme</div>
    </div>

    <nav class="sidebar-nav">
        <?php
        // AREA OPERATIVA - Check if any items are visible
        $operativeItems = [
            ['dashboard', 'dashboard.php', 'icon--home', 'Dashboard'],
            ['files', 'files.php', 'icon--folder', 'File Manager'],
            ['calendar', 'calendar.php', 'icon--calendar', 'Calendario'],
            ['turni', 'turni.php', 'icon--clock', 'Turni'],
            ['tasks', 'tasks.php', 'icon--check', 'Task'],
            ['ticket', 'ticket.php', 'icon--ticket', 'Ticket'],
            ['conformita', 'conformita.php', 'icon--shield', 'Conformità'],
            ['compliance', 'compliance.php', 'icon--shield', 'Compliance'],
            ['ai', 'ai.php', 'icon--cpu', 'AI']
        ];

        $hasOperativeItems = false;
        foreach ($operativeItems as $item) {
            if (shouldShowPage($item[0], $userRole, $userTenantId, $isSuperAdmin)) {
                $hasOperativeItems = true;
                break;
            }
        }

        if ($hasOperativeItems): ?>
        <div class="nav-section">
            <div class="nav-section-title">AREA OPERATIVA</div>
            <?php foreach ($operativeItems as $item):
                if (shouldShowPage($item[0], $userRole, $userTenantId, $isSuperAdmin)): ?>
                <a href="<?php echo htmlspecialchars($hrefPrefix . $item[1]); ?>" class="nav-item <?php echo $current_page === $item[1] ? 'active' : ''; ?>">
                    <i class="icon <?php echo $item[2]; ?>"></i> <?php echo $item[3]; ?>
                </a>
            <?php endif; endforeach; ?>
        </div>
        <?php endif; ?>

        <?php
        // GESTIONE - Check if visible
        if (shouldShowPage('aziende', $userRole, $userTenantId, $isSuperAdmin)): ?>
        <div class="nav-section">
            <div class="nav-section-title">GESTIONE</div>
            <a href="<?php echo htmlspecialchars($hrefPrefix . 'aziende.php'); ?>" class="nav-item <?php echo $current_page === 'aziende.php' ? 'active' : ''; ?>">
                <i class="icon icon--building"></i> Aziende
            </a>
        </div>
        <?php endif; ?>

        <?php
        // AMMINISTRAZIONE - Check if any items are visible
        $adminItems = [
            ['utenti', 'utenti.php', 'icon--users', 'Utenti'],
            ['audit_log', 'audit_log.php', 'icon--chart', 'Audit Log'],
            ['configurazioni', 'configurazioni.php', 'icon--settings', 'Configurazioni'],
            // Tenant 28 internal tools (also gated by tenant28_access_check.php on the page)
            ['planning', 'planning.php', 'icon--calendar', 'Pianificazione (S.CO)']
        ];

        $hasAdminItems = false;
        foreach ($adminItems as $item) {
            // Special gate: planning is only shown if tenant28 gate passes
            if ($item[0] === 'planning' && !$canSeeTenant28Planning && !$isSuperAdmin) {
                continue;
            }
            if (shouldShowPage($item[0], $userRole, $userTenantId, $isSuperAdmin)) {
                $hasAdminItems = true;
                break;
            }
        }

        if ($hasAdminItems): ?>
        <div class="nav-section">
            <div class="nav-section-title">AMMINISTRAZIONE</div>
            <?php foreach ($adminItems as $item):
                if ($item[0] === 'planning' && !$canSeeTenant28Planning && !$isSuperAdmin) continue;
                if (shouldShowPage($item[0], $userRole, $userTenantId, $isSuperAdmin)): ?>
                <a href="<?php echo htmlspecialchars($hrefPrefix . $item[1]); ?>" class="nav-item <?php echo $current_page === $item[1] ? 'active' : ''; ?>">
                    <i class="icon <?php echo $item[2]; ?>"></i> <?php echo $item[3]; ?>
                </a>
            <?php endif; endforeach; ?>

            <?php if ($isSuperAdmin): ?>
                <a href="<?php echo htmlspecialchars($hrefPrefix . 'email_manual.php'); ?>" class="nav-item <?php echo $current_page === 'email_manual.php' ? 'active' : ''; ?>">
                    <i class="icon icon--file"></i> Email manuali
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="nav-section">
            <div class="nav-section-title">ACCOUNT</div>
            <a href="<?php echo htmlspecialchars($hrefPrefix . 'profilo.php'); ?>" class="nav-item <?php echo $current_page === 'profilo.php' ? 'active' : ''; ?>">
                <i class="icon icon--user"></i> Il Mio Profilo
            </a>
            <a href="<?php echo htmlspecialchars($hrefPrefix . 'logout.php'); ?>" class="nav-item <?php echo $current_page === 'logout.php' ? 'active' : ''; ?>">
                <i class="icon icon--logout"></i> Esci
            </a>
            <a href="<?php echo htmlspecialchars($hrefPrefix . 'privacy.php'); ?>" class="nav-item <?php echo $current_page === 'privacy.php' ? 'active' : ''; ?>">
                <i class="icon icon--shield"></i> Privacy
            </a>
            <a href="<?php echo htmlspecialchars($hrefPrefix . 'cookie-policy.php'); ?>" class="nav-item <?php echo $current_page === 'cookie-policy.php' ? 'active' : ''; ?>">
                <i class="icon icon--file"></i> Cookie Policy
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar">
                <?php
                echo isset($currentUser['name']) ? strtoupper(substr($currentUser['name'], 0, 2)) : 'UN';
                ?>
            </div>
            <div class="user-details">
                <div class="user-name">
                    <?php echo htmlspecialchars($currentUser['name'] ?? 'Utente'); ?>
                </div>
                <div class="user-badge">
                    <?php echo strtoupper(str_replace('_', ' ', $currentUser['role'] ?? 'USER')); ?>
                </div>
            </div>
        </div>
    </div>
</div>
