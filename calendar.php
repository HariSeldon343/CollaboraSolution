<?php
/**
 * CollaboraNexio - Calendar Page
 *
 * MINIMAL DESIGN - Consistent with dashboard.php and files.php
 * NO gradients, NO glassmorphism - Clean enterprise design
 *
 * Created: 2025-11-18
 * Pattern: CLAUDE.md 8-step authentication + Minimal UI (matches dashboard/files)
 */

// Step 1: Session & Authentication
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/company_filter.php';

// Force no-cache headers for calendar.php (prevents stale JS/CSS in production)
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}

// Step 2: Get current user
$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    header('Location: index.php');
    exit;
}

// Step 3: Tenant access check
require_once __DIR__ . '/includes/tenant_access_check.php';
requireTenantAccess($currentUser['id'], $currentUser['role']);

// Enforce Page Visibility access rules (configurazioni.php -> Visibilità Pagine)
require_once __DIR__ . '/includes/page_access_check.php';
checkPageAccess('calendar');

// Step 4: Audit logging
require_once __DIR__ . '/includes/audit_page_access.php';
trackPageAccess('calendar');

// Step 5: Company filter
$companyFilter = new CompanyFilter($currentUser);

// Step 6: Generate CSRF token
$csrfToken = $auth->generateCSRFToken();

// BUG-144 FIX: Removed active_tenant_id fallback (column does not exist)
// tenant_id is the only valid source

// Asset versions (file-based) to prevent stale caches while keeping stable URLs across reloads
$calendarJsVersion = @filemtime(__DIR__ . '/assets/js/calendar.js') ?: time();
$calendarCssVersion = @filemtime(__DIR__ . '/assets/css/calendar.css') ?: time();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Calendario - Nexio';
    $pageMeta = [
        '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate, max-age=0">',
        '<meta http-equiv="Pragma" content="no-cache">',
        '<meta http-equiv="Expires" content="0">',
        '<meta http-equiv="Last-Modified" content="' . htmlspecialchars(gmdate('D, d M Y H:i:s') . ' GMT') . '">',
    ];
    $pageCss = ['assets/css/calendar.css?v=' . (int)$calendarCssVersion];
    require __DIR__ . '/includes/layout_head.php';
?>

    <!-- BUG-118 FIX: Sidebar CSS consistency with dashboard.php -->
    <style>
        /* Additional dashboard specific styles */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: var(--space-6);
            margin-bottom: var(--space-8);
        }

        .stat-card {
            background: var(--color-white);
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            transition: box-shadow var(--transition-fast);
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
        }

        .stat-label {
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            color: var(--color-gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: var(--space-2);
        }

        .stat-value {
            font-size: var(--text-3xl);
            font-weight: var(--font-bold);
            color: var(--color-gray-900);
            line-height: 1.2;
        }

        .stat-change {
            font-size: var(--text-sm);
            color: var(--color-gray-600);
            margin-top: var(--space-2);
        }

        .stat-change.positive {
            color: var(--color-success);
        }

        .stat-change.negative {
            color: var(--color-error);
        }
    </style>
</head>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
            <!-- Header (SIMPLE - matches dashboard.php pattern) -->
            <div class="header">
                <h1 class="page-title">Calendario</h1>
                <div class="flex items-center gap-4">
                    <!-- View Selector -->
                    <div class="calendar-view-selector bg-gray-100 p-1 rounded-lg flex gap-1">
                        <button class="calendar-view-btn active px-3 py-1 text-sm font-medium rounded-md transition-all" data-view="month" onclick="window.calendar.changeView('month')">Mese</button>
                        <button class="calendar-view-btn px-3 py-1 text-sm font-medium text-gray-600 hover:text-gray-900 rounded-md transition-all" data-view="week" onclick="window.calendar.changeView('week')">Settimana</button>
                        <button class="calendar-view-btn px-3 py-1 text-sm font-medium text-gray-600 hover:text-gray-900 rounded-md transition-all" data-view="day" onclick="window.calendar.changeView('day')">Giorno</button>
                    </div>

                    <?php if ($companyFilter->canUseCompanyFilter()): ?>
                        <?php echo $companyFilter->renderDropdown(); ?>
                    <?php endif; ?>
                    <span class="text-sm text-muted">Benvenuto, <?php echo htmlspecialchars($currentUser['name']); ?></span>
                </div>
            </div>

            <!-- Page Content -->
            <div class="page-content">
                <!-- Calendar Container (CalendarApp will inject everything here) -->
                <div id="calendar-container"></div>
            </div>

    <!-- Hidden Inputs (MANDATORY for CalendarApp) -->
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" id="currentUserId" value="<?php echo htmlspecialchars($currentUser['id']); ?>">
    <input type="hidden" id="currentUserRole" value="<?php echo htmlspecialchars($currentUser['role']); ?>">
    <?php
        // Expose selected tenant(s) from Company Filter to JS.
        // - For admin/super_admin: can be multiple tenants or "all"
        // - For other roles: single tenant only
        $selectedTenantIds = null; // null => all (for eligible roles)
        if (isset($companyFilter) && $companyFilter instanceof CompanyFilter && $companyFilter->canUseCompanyFilter()) {
            $selectedTenantIds = $companyFilter->getActiveFilterIds(); // null means "Tutte le aziende"
            if ($selectedTenantIds === null) {
                // All companies selected: expose all accessible companies IDs to JS so calendar can load everything.
                $selectedTenantIds = array_values(array_map(static fn($c) => (int)($c['id'] ?? 0), $companyFilter->getAvailableCompanies()));
                $selectedTenantIds = array_values(array_filter($selectedTenantIds, static fn($id) => $id > 0));
            }
        }
        if (!is_array($selectedTenantIds) || empty($selectedTenantIds)) {
            $fallback = (int)($currentUser['tenant_id'] ?? 0);
            $selectedTenantIds = $fallback > 0 ? [$fallback] : [];
        }

        $activeTenantIdForCalendar = $selectedTenantIds[0] ?? ($currentUser['tenant_id'] ?? null);
    ?>
    <input type="hidden" id="currentTenantId" value="<?php echo htmlspecialchars((string)($activeTenantIdForCalendar ?? '')); ?>">
    <input type="hidden" id="currentTenantIds" value="<?php echo htmlspecialchars(json_encode($selectedTenantIds, JSON_UNESCAPED_SLASHES)); ?>">

    <!-- Calendar JavaScript -->
    <script src="assets/js/calendar.js?v=<?php echo (int)$calendarJsVersion; ?>"></script>

    <script>
        // Initialize CalendarApp when DOM is ready
        document.addEventListener('DOMContentLoaded', () => {
            console.log('[Calendar Page] Initializing CalendarApp...');
            window.calendar = new CalendarApp('calendar-container');
            console.log('[Calendar Page] CalendarApp initialized');
        });
    </script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
