<?php
/**
 * Page Visibility Helper
 *
 * Provides functions to check page visibility based on role and tenant.
 * Includes in-memory caching for performance optimization.
 *
 * Logic Priority:
 * 1. Tenant-specific setting (if tenantId != null)
 * 2. Global setting (tenant_id IS NULL)
 * 3. Default: TRUE (visible)
 *
 * @author Staff Engineer
 * @version 2025-12-12
 */

declare(strict_types=1);

/**
 * In-memory cache for visibility settings
 * Structure: ['global' => [...], 'tenant_X' => [...]]
 */
$GLOBALS['_page_visibility_cache'] = null;
$GLOBALS['_page_visibility_cache_loaded'] = false;

/**
 * Check if a page is visible for a specific role and tenant
 *
 * @param string $pageName The page identifier (e.g., 'dashboard', 'tasks')
 * @param string $role User role ('super_admin', 'admin', 'manager', 'user')
 * @param int|null $tenantId Tenant ID for tenant-specific override, or null for global
 * @return bool TRUE if page is visible, FALSE otherwise
 */
function isPageVisibleForRole(string $pageName, string $role, ?int $tenantId = null): bool {
    // super_admin always sees all pages
    if ($role === 'super_admin') {
        return true;
    }

    // Valid roles for visibility settings
    $validRoles = ['admin', 'manager', 'user'];
    if (!in_array($role, $validRoles, true)) {
        return true; // Unknown role - default visible
    }

    // Load cache if not loaded
    loadVisibilityCache();

    // Priority 1: Check tenant-specific setting
    if ($tenantId !== null) {
        $tenantKey = 'tenant_' . $tenantId;
        if (isset($GLOBALS['_page_visibility_cache'][$tenantKey][$pageName][$role])) {
            return (bool)$GLOBALS['_page_visibility_cache'][$tenantKey][$pageName][$role];
        }
    }

    // Priority 2: Check global setting
    if (isset($GLOBALS['_page_visibility_cache']['global'][$pageName][$role])) {
        return (bool)$GLOBALS['_page_visibility_cache']['global'][$pageName][$role];
    }

    // Default: visible
    return true;
}

/**
 * Get all visible pages for a role and tenant
 *
 * @param string $role User role
 * @param int|null $tenantId Tenant ID or null for global
 * @return array List of visible page names
 */
function getVisiblePagesForRole(string $role, ?int $tenantId = null): array {
    // super_admin sees all pages
    if ($role === 'super_admin') {
        return getAllPageNames();
    }

    // Valid roles for visibility settings
    $validRoles = ['admin', 'manager', 'user'];
    if (!in_array($role, $validRoles, true)) {
        return getAllPageNames(); // Unknown role - all visible
    }

    // Load cache if not loaded
    loadVisibilityCache();

    $visiblePages = [];
    $allPages = getAllPageNames();

    foreach ($allPages as $pageName) {
        if (isPageVisibleForRole($pageName, $role, $tenantId)) {
            $visiblePages[] = $pageName;
        }
    }

    return $visiblePages;
}

/**
 * Get all page names defined in the system
 *
 * @return array List of all page names
 */
function getAllPageNames(): array {
    return [
        'dashboard', 'files', 'calendar', 'turni', 'tasks', 'ticket',
        'conformita', 'compliance', 'ai', 'aziende', 'utenti', 'audit_log', 'configurazioni',
        // Tenant 28 internal tools (still gated server-side by tenant28_access_check.php)
        'planning'
    ];
}

/**
 * Get page display names (Italian)
 *
 * @return array Associative array [page_name => display_name]
 */
function getPageDisplayNames(): array {
    return [
        'dashboard' => 'Dashboard',
        'files' => 'File Manager',
        'calendar' => 'Calendario',
        'turni' => 'Turni',
        'tasks' => 'Task',
        'ticket' => 'Ticket',
        'conformita' => 'Conformita',
        'compliance' => 'Compliance',
        'ai' => 'AI',
        'aziende' => 'Aziende',
        'utenti' => 'Utenti',
        'audit_log' => 'Audit Log',
        'configurazioni' => 'Configurazioni',
        'planning' => 'Pianificazione (S.CO)'
    ];
}

/**
 * Load visibility settings into cache
 * Uses in-memory caching for performance
 */
function loadVisibilityCache(): void {
    if ($GLOBALS['_page_visibility_cache_loaded']) {
        return;
    }

    $GLOBALS['_page_visibility_cache'] = [
        'global' => []
    ];

    try {
        require_once __DIR__ . '/db.php';
        $db = Database::getInstance();
        $conn = $db->getConnection();

        // Check if table exists
        $stmt = $conn->query("SHOW TABLES LIKE 'page_visibility_settings'");
        if ($stmt->rowCount() === 0) {
            // Table doesn't exist - all pages visible by default
            $GLOBALS['_page_visibility_cache_loaded'] = true;
            return;
        }

        // Load all active settings
        $sql = "
            SELECT tenant_id, page_name, role, is_visible
            FROM page_visibility_settings
            WHERE deleted_at IS NULL
            ORDER BY tenant_id, page_name, role
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tenantId = $row['tenant_id'];
            $pageName = $row['page_name'];
            $role = $row['role'];
            $isVisible = (bool)$row['is_visible'];

            if ($tenantId === null) {
                // Global setting
                if (!isset($GLOBALS['_page_visibility_cache']['global'][$pageName])) {
                    $GLOBALS['_page_visibility_cache']['global'][$pageName] = [];
                }
                $GLOBALS['_page_visibility_cache']['global'][$pageName][$role] = $isVisible;
            } else {
                // Tenant-specific setting
                $tenantKey = 'tenant_' . $tenantId;
                if (!isset($GLOBALS['_page_visibility_cache'][$tenantKey])) {
                    $GLOBALS['_page_visibility_cache'][$tenantKey] = [];
                }
                if (!isset($GLOBALS['_page_visibility_cache'][$tenantKey][$pageName])) {
                    $GLOBALS['_page_visibility_cache'][$tenantKey][$pageName] = [];
                }
                $GLOBALS['_page_visibility_cache'][$tenantKey][$pageName][$role] = $isVisible;
            }
        }

        $GLOBALS['_page_visibility_cache_loaded'] = true;

    } catch (Exception $e) {
        error_log('[PageVisibilityHelper] Cache load error: ' . $e->getMessage());
        // On error, mark as loaded to prevent repeated failures
        $GLOBALS['_page_visibility_cache_loaded'] = true;
    }
}

/**
 * Clear the visibility cache
 * Call this after updating visibility settings
 */
function clearVisibilityCache(): void {
    $GLOBALS['_page_visibility_cache'] = null;
    $GLOBALS['_page_visibility_cache_loaded'] = false;
}

/**
 * Check if current user can see a page based on session data
 *
 * @param string $pageName The page identifier
 * @return bool TRUE if visible
 */
function isPageVisibleForCurrentUser(string $pageName): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'user';
    $tenantId = $_SESSION['tenant_id'] ?? null;

    return isPageVisibleForRole($pageName, $role, $tenantId);
}

/**
 * Get sidebar navigation items filtered by visibility
 *
 * @param string $role User role
 * @param int|null $tenantId Tenant ID
 * @return array Navigation items configuration
 */
function getVisibleSidebarItems(string $role, ?int $tenantId = null): array {
    $displayNames = getPageDisplayNames();

    // Define navigation structure with categories
    $navStructure = [
        'main' => [
            'title' => 'Main',
            'items' => ['dashboard', 'files', 'calendar', 'turni', 'tasks', 'ticket', 'conformita', 'ai']
        ],
        'gestione' => [
            'title' => 'Gestione',
            'items' => ['aziende'],
            'minRole' => 'admin'
        ],
        'amministrazione' => [
            'title' => 'Amministrazione',
            'items' => ['utenti', 'audit_log', 'configurazioni'],
            'minRole' => 'super_admin'
        ]
    ];

    // Page to file mapping
    $pageFiles = [
        'dashboard' => 'dashboard.php',
        'files' => 'files.php',
        'calendar' => 'calendar.php',
        'turni' => 'turni.php',
        'tasks' => 'tasks.php',
        'ticket' => 'ticket.php',
        'conformita' => 'conformita.php',
        'ai' => 'ai.php',
        'aziende' => 'aziende.php',
        'utenti' => 'utenti.php',
        'audit_log' => 'audit_log.php',
        'configurazioni' => 'configurazioni.php'
    ];

    // Page icons
    $pageIcons = [
        'dashboard' => 'home',
        'files' => 'folder',
        'calendar' => 'calendar',
        'turni' => 'clock',
        'tasks' => 'check',
        'ticket' => 'ticket',
        'conformita' => 'shield',
        'ai' => 'cpu',
        'aziende' => 'building',
        'utenti' => 'users',
        'audit_log' => 'chart',
        'configurazioni' => 'settings'
    ];

    // Role hierarchy for section visibility
    $roleHierarchy = [
        'super_admin' => 4,
        'admin' => 3,
        'manager' => 2,
        'user' => 1
    ];

    $userLevel = $roleHierarchy[$role] ?? 1;
    $result = [];

    foreach ($navStructure as $sectionKey => $section) {
        // Check section minimum role
        $minRole = $section['minRole'] ?? 'user';
        $minLevel = $roleHierarchy[$minRole] ?? 1;

        if ($userLevel < $minLevel) {
            continue;
        }

        $visibleItems = [];

        foreach ($section['items'] as $pageName) {
            if (isPageVisibleForRole($pageName, $role, $tenantId)) {
                $visibleItems[] = [
                    'name' => $pageName,
                    'displayName' => $displayNames[$pageName] ?? ucfirst($pageName),
                    'file' => $pageFiles[$pageName] ?? $pageName . '.php',
                    'icon' => $pageIcons[$pageName] ?? 'file'
                ];
            }
        }

        if (!empty($visibleItems)) {
            $result[$sectionKey] = [
                'title' => $section['title'],
                'items' => $visibleItems
            ];
        }
    }

    return $result;
}

/**
 * Generate sidebar HTML based on visibility settings
 *
 * @param string $role User role
 * @param int|null $tenantId Tenant ID
 * @param string $currentPage Current page name for active state
 * @return string HTML for sidebar navigation
 */
function renderVisibleSidebar(string $role, ?int $tenantId = null, string $currentPage = ''): string {
    $sections = getVisibleSidebarItems($role, $tenantId);
    $html = '';

    foreach ($sections as $sectionKey => $section) {
        $html .= '<div class="nav-section">';
        $html .= '<div class="nav-section-title">' . htmlspecialchars($section['title']) . '</div>';

        foreach ($section['items'] as $item) {
            $isActive = ($item['name'] === $currentPage) ? ' active' : '';
            $html .= sprintf(
                '<a href="%s" class="nav-item%s"><i class="icon icon--%s"></i> %s</a>',
                htmlspecialchars($item['file']),
                $isActive,
                htmlspecialchars($item['icon']),
                htmlspecialchars($item['displayName'])
            );
        }

        $html .= '</div>';
    }

    return $html;
}
