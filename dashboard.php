<?php
// Initialize session with proper configuration
require_once __DIR__ . '/includes/session_init.php';
// Authentication check - redirect to login if not authenticated
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/company_filter.php';
$auth = new Auth();

if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}

// Get current user data
$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    header('Location: index.php');
    exit;
}
// BUG-144 FIX: Removed active_tenant_id fallback (column does not exist)
// tenant_id is the only valid source

// Require active tenant access (super_admins bypass this check)
require_once __DIR__ . '/includes/tenant_access_check.php';
requireTenantAccess($currentUser['id'], $currentUser['role']);

// Enforce Page Visibility access rules (configurazioni.php -> Visibilità Pagine)
require_once __DIR__ . '/includes/page_access_check.php';
checkPageAccess('dashboard');

// Track page access for audit logging
require_once __DIR__ . '/includes/audit_page_access.php';
trackPageAccess('dashboard');

// Initialize company filter
$companyFilter = new CompanyFilter($currentUser);

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Dashboard - Nexio';
    $pageCss = ['assets/css/dashboard.css?v=' . (time() . '_v2')];
    require __DIR__ . '/includes/layout_head.php';

    // Cache-bust dashboard manager JS on change (prevents stale behavior in prod)
    $dashboardManagerJsVersion = @filemtime(__DIR__ . '/assets/js/dashboard_manager.js') ?: time();
?>

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

        /* Dashboard widgets */
        .dash-clock-time {
            font-size: 40px;
            font-weight: var(--font-bold);
            letter-spacing: 0.02em;
            color: var(--color-gray-900);
            line-height: 1.1;
        }

        .dash-clock-date {
            margin-top: 6px;
            color: var(--color-gray-600);
            font-size: var(--text-sm);
        }

        .dash-mini-list {
            margin: var(--space-4) 0 0 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .dash-mini-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 12px;
            border: 1px solid var(--color-gray-200);
            border-radius: var(--radius-md);
            background: var(--color-gray-50);
        }

        .dash-mini-left {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }

        .dash-mini-title {
            font-weight: 600;
            color: var(--color-gray-900);
            font-size: var(--text-sm);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dash-mini-sub {
            color: var(--color-gray-600);
            font-size: var(--text-xs);
        }

        .dash-mini-right {
            color: var(--color-gray-700);
            font-size: var(--text-xs);
            white-space: nowrap;
            margin-top: 2px;
        }

        .dash-progress {
            margin-top: var(--space-4);
        }

        .dash-progress-bar {
            height: 10px;
            background: var(--color-gray-200);
            border-radius: 999px;
            overflow: hidden;
        }

        .dash-progress-fill {
            height: 100%;
            width: 0%;
            background: var(--color-primary);
            border-radius: 999px;
            transition: width 200ms ease;
        }

        .dash-progress-meta {
            margin-top: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: var(--text-sm);
            color: var(--color-gray-700);
        }

        .dash-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            background: var(--color-gray-100);
            color: var(--color-gray-700);
            white-space: nowrap;
        }

        .dash-pill.warn { background: #FFFBEB; color: #92400e; }
        .dash-pill.danger { background: #FEF2F2; color: #991b1b; }
    </style>
</head>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
            <div class="header">
                <h1 class="page-title">Dashboard</h1>
                <div class="flex items-center gap-4">
                    <?php if ($companyFilter->canUseCompanyFilter()): ?>
                        <?php echo $companyFilter->renderDropdown(); ?>
                    <?php endif; ?>
                    <span class="text-sm text-muted">Benvenuto, <?php echo htmlspecialchars($currentUser['name']); ?></span>
                </div>
            </div>

            <div class="page-content">
                <!-- Hidden current user id for JS helpers (dashboard widgets) -->
                <input type="hidden" id="currentUserId" value="<?php echo htmlspecialchars((string)($currentUser['id'] ?? '')); ?>">
                <input type="hidden" id="currentUserRole" value="<?php echo htmlspecialchars((string)($currentUser['role'] ?? 'user')); ?>">
                <?php
                    // Expose selected tenant(s) from Company Filter to JS (same logic as calendar.php)
                    $selectedTenantIds = null; // null => all (for eligible roles)
                    if (isset($companyFilter) && $companyFilter instanceof CompanyFilter && $companyFilter->canUseCompanyFilter()) {
                        $selectedTenantIds = $companyFilter->getActiveFilterIds(); // null means "Tutte le aziende"
                        if ($selectedTenantIds === null) {
                            // All companies selected: expose all accessible companies IDs to JS
                            $selectedTenantIds = array_values(array_map(static fn($c) => (int)($c['id'] ?? 0), $companyFilter->getAvailableCompanies()));
                            $selectedTenantIds = array_values(array_filter($selectedTenantIds, static fn($id) => $id > 0));
                        }
                    }
                    if (!is_array($selectedTenantIds) || empty($selectedTenantIds)) {
                        $fallback = (int)($currentUser['tenant_id'] ?? 0);
                        $selectedTenantIds = $fallback > 0 ? [$fallback] : [];
                    }
                    $activeTenantIdForDashboard = $selectedTenantIds[0] ?? ($currentUser['tenant_id'] ?? null);
                ?>
                <input type="hidden" id="currentTenantId" value="<?php echo htmlspecialchars((string)($activeTenantIdForDashboard ?? '')); ?>">
                <input type="hidden" id="currentTenantIds" value="<?php echo htmlspecialchars(json_encode($selectedTenantIds, JSON_UNESCAPED_SLASHES)); ?>">
                <!-- Stats Grid - 4 Cards -->
                <div class="dashboard-grid">
                    <div class="stat-card">
                        <div class="stat-label">Ora</div>
                        <div class="dash-clock-time" id="dashboardClockTime">--:--:--</div>
                        <div class="dash-clock-date" id="dashboardClockDate">—</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">I miei turni</div>
                        <div class="stat-value" id="dashboardMyShiftsCount">—</div>
                        <div class="stat-change">Prossimi 30 giorni</div>
                        <ul class="dash-mini-list" id="dashboardMyShiftsList">
                            <li class="dash-mini-item"><div class="text-muted">Caricamento...</div></li>
                        </ul>
                        <div style="margin-top: 12px;">
                            <a class="btn btn-outline" href="turni.php">Apri Turni</a>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Task assegnati</div>
                        <div class="stat-value" id="dashboardTasksAssignedCount">—</div>
                        <div class="dash-progress">
                            <div class="dash-progress-bar">
                                <div class="dash-progress-fill" id="dashboardTasksProgressFill"></div>
                            </div>
                            <div class="dash-progress-meta">
                                <span id="dashboardTasksProgressText">Caricamento...</span>
                                <span class="dash-pill" id="dashboardTasksOverduePill" style="display:none;">⏰ 0 scaduti</span>
                            </div>
                        </div>
                        <div style="margin-top: 12px;">
                            <a class="btn btn-outline" href="tasks.php">Apri Task</a>
                        </div>
                    </div>
                </div>

                <!-- Two Column Section: Activities + Calendario -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Recent Activity (Left Column) -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Attività Recente</h2>
                        </div>
                        <div class="card-body">
                            <ul class="simple-list" id="activity-list">
                                <li class="list-item">
                                    <div class="text-muted">Caricamento...</div>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Calendario (Right Column) -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Calendario</h2>
                        </div>
                        <div class="card-body">
                            <div id="miniCalendar" class="mini-calendar">
                                <div class="mini-calendar-loading text-muted">Caricamento...</div>
                            </div>
                            <div style="margin-top: 12px;">
                                <a class="btn btn-primary" href="calendar.php">Apri Calendario</a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Three Column Section: Documents + Events + Tickets -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Recent Documents -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Documenti Recenti</h2>
                        </div>
                        <div class="card-body">
                            <ul class="simple-list" id="documents-list">
                                <li class="list-item">
                                    <div class="text-muted">Caricamento...</div>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Upcoming Events -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Prossimi Eventi</h2>
                        </div>
                        <div class="card-body">
                            <ul class="simple-list" id="events-list">
                                <li class="list-item">
                                    <div class="text-muted">Caricamento...</div>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Recent Tickets -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Ticket Recenti</h2>
                        </div>
                        <div class="card-body">
                            <ul class="simple-list" id="tickets-list">
                                <li class="list-item">
                                    <div class="text-muted">Caricamento...</div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

    <!-- Hidden CSRF token -->
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">

    <!-- Dashboard Manager Script -->
    <script src="assets/js/dashboard_manager.js?v=<?php echo (int)$dashboardManagerJsVersion; ?>"></script>

    <script>
        // Initialize Dashboard Manager when DOM is ready
        document.addEventListener('DOMContentLoaded', () => {
            // Expose role/tenant to JS (for dashboard_manager)
            window.userRole = '<?php echo htmlspecialchars($currentUser['role']); ?>';
            window.userTenantId = '<?php echo htmlspecialchars($currentUser['tenant_id'] ?? ''); ?>';
            // Initialize dashboard manager
            window.dashboardManager = new DashboardManager();

            // Mobile sidebar toggle handler (kept from original)
            const sidebarToggle = document.getElementById('sidebarToggle');
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', () => {
                    document.querySelector('.sidebar').classList.toggle('open');
                });
            }

            console.log('[Dashboard] Initialized with dynamic data loading');
        });
    </script>

    <style>
        /* Additional responsive styles */
        .list-item {
            display: flex;
            align-items: flex-start;
            gap: var(--space-3);
            padding: var(--space-3) 0;
            border-bottom: 1px solid var(--color-gray-200);
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .list-icon {
            width: 8px;
            height: 8px;
            background: var(--color-primary);
            border-radius: var(--radius-full);
            margin-top: 6px;
            flex-shrink: 0;
        }

        .list-content {
            flex: 1;
        }

        .list-title {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
            margin-bottom: 2px;
        }

        .list-description {
            font-size: var(--text-xs);
            color: var(--color-gray-600);
        }

        .progress-item {
            padding: var(--space-4) 0;
            border-bottom: 1px solid var(--color-gray-200);
        }

        .progress-item:last-child {
            border-bottom: none;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-2);
        }

        .progress-title {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
        }

        .progress-bar {
            height: 4px;
            background: var(--color-gray-200);
            border-radius: var(--radius-full);
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--color-primary);
            border-radius: var(--radius-full);
            transition: width var(--transition-base);
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: var(--text-xs);
            font-weight: var(--font-medium);
            border-radius: var(--radius-sm);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .badge-blue {
            background: #EFF6FF;
            color: var(--color-primary);
        }

        .badge-green {
            background: #F0FDF4;
            color: var(--color-success);
        }

        .badge-yellow {
            background: #FFFBEB;
            color: var(--color-warning);
        }

        .badge-red {
            background: #FEF2F2;
            color: var(--color-error);
        }

        .activity-dup {
            display: inline-block;
            margin-left: 6px;
            padding: 1px 6px;
            border-radius: 999px;
            font-size: 10px;
            color: var(--color-gray-500);
            background: #F3F4F6;
        }

        .empty-link {
            font-size: var(--text-xs);
            color: var(--color-primary);
            text-decoration: underline;
            margin-top: var(--space-2);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .empty-link svg {
            width: 12px;
            height: 12px;
        }
    </style>
<?php require __DIR__ . '/includes/layout_end.php'; ?>