<?php
/**
 * Page Visibility Settings API
 *
 * Manages page visibility settings per role and tenant.
 * Only super_admin can access this API.
 *
 * Endpoints:
 * - GET  ?action=get     - Get all visibility settings
 * - POST ?action=save    - Save visibility settings
 * - POST ?action=reset   - Reset all settings to default (all visible)
 *
 * @author Staff Engineer
 * @version 2025-12-12
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api_auth.php';

// Initialize API environment
initializeApiEnvironment();

// Set cache headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Verify authentication
verifyApiAuthentication();

// Get user info
$userInfo = getApiUserInfo();

// Only super_admin can access page visibility settings
if ($userInfo['role'] !== 'super_admin') {
    api_error('Accesso negato. Solo super admin puo gestire la visibilita delle pagine.', 403);
}

// CSRF verification for POST/PUT/DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    verifyApiCsrfToken();
}

// Get database connection
require_once __DIR__ . '/../../includes/db.php';
$db = Database::getInstance();
$conn = $db->getConnection();

// Get action and input
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Define valid pages
// IMPORTANT: keep this list in sync with the helper used by `checkPageAccess()`.
require_once __DIR__ . '/../../includes/page_visibility_helper.php';

/**
 * Auto-discover pages by scanning root PHP files for `checkPageAccess('page_key')`.
 * This makes new pages (e.g. compliance.php) appear automatically in the Visibility matrix.
 *
 * @return string[]
 */
function cnx_discover_pages_from_root(): array {
    $root = realpath(__DIR__ . '/../../');
    if (!$root) return [];
    $files = glob($root . DIRECTORY_SEPARATOR . '*.php') ?: [];
    $out = [];
    foreach ($files as $fp) {
        // Skip obviously irrelevant entrypoints
        $base = basename($fp);
        if ($base === 'index.php') continue;
        $txt = @file_get_contents($fp);
        if (!is_string($txt) || $txt === '') continue;
        if (preg_match_all("/checkPageAccess\\(\\s*['\\\"]([^'\\\"]+)['\\\"]\\s*\\)/", $txt, $m)) {
            foreach ($m[1] as $page) {
                $p = trim((string)$page);
                if ($p !== '') $out[] = $p;
            }
        }
    }
    $out = array_values(array_unique($out));
    return $out;
}

$validPages = array_values(array_unique(array_merge(
    getAllPageNames(),
    cnx_discover_pages_from_root()
)));

// Define valid roles
$validRoles = ['admin', 'manager', 'user'];

// Page display names (Italian)
$pageNames = getPageDisplayNames();
// Provide display names for newly discovered pages (fallback)
foreach ($validPages as $p) {
    if (!isset($pageNames[$p])) {
        $pageNames[$p] = ucfirst(str_replace('_', ' ', (string)$p));
    }
}

try {
    // Auto-migration: Check if table exists, create if not
    ensureTableExists($conn, $validPages, $validRoles, $pageNames);

    switch ($action) {
        case 'get':
            handleGet($conn, $validPages, $validRoles, $pageNames);
            break;

        case 'save':
            handleSave($conn, $input, $validPages, $validRoles, $userInfo);
            break;

        case 'reset':
            handleReset($conn, $validPages, $validRoles, $userInfo);
            break;

        default:
            api_error('Azione non valida. Azioni supportate: get, save, reset', 400);
    }

} catch (Exception $e) {
    error_log('[PageVisibility API] Error: ' . $e->getMessage());
    api_error('Errore server: ' . $e->getMessage(), 500);
}

/**
 * Ensure the page_visibility_settings table exists
 * Auto-migration feature for first-time use
 */
function ensureTableExists(PDO $conn, array $validPages, array $validRoles, array $pageNames): void {
    try {
        // Check if table exists
        $stmt = $conn->query("SHOW TABLES LIKE 'page_visibility_settings'");
        if ($stmt->rowCount() > 0) {
            // Table exists - ensure any newly-added pages are present (e.g., turni)
            ensureDefaultsPresent($conn, $validPages, $validRoles, $pageNames);
            return;
        }

        error_log('[PageVisibility API] Table not found, running auto-migration...');

        // Create table
        $createSql = "
            CREATE TABLE page_visibility_settings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT UNSIGNED NULL COMMENT 'NULL for global config',
                page_name VARCHAR(100) NOT NULL,
                role ENUM('admin', 'manager', 'user') NOT NULL,
                is_visible BOOLEAN NOT NULL DEFAULT TRUE,
                description VARCHAR(255) NULL,
                deleted_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT fk_page_visibility_tenant FOREIGN KEY (tenant_id)
                    REFERENCES tenants(id) ON DELETE CASCADE,
                UNIQUE INDEX uk_page_visibility_config (page_name, role, tenant_id, deleted_at),
                INDEX idx_page_visibility_tenant_created (tenant_id, created_at),
                INDEX idx_page_visibility_tenant_deleted (tenant_id, deleted_at),
                INDEX idx_page_visibility_page (page_name),
                INDEX idx_page_visibility_role (role),
                INDEX idx_page_visibility_visible (is_visible),
                INDEX idx_page_visibility_lookup (tenant_id, page_name, role, deleted_at, is_visible)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Page visibility settings per role and tenant'
        ";

        $conn->exec($createSql);

        // Insert default data (all pages visible for all roles)
        $insertSql = "INSERT INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at) VALUES (?, ?, ?, TRUE, ?, NOW())";
        $stmt = $conn->prepare($insertSql);

        foreach ($validPages as $page) {
            foreach ($validRoles as $role) {
                $description = ($pageNames[$page] ?? ucfirst($page)) . ' - Default visibility';
                $stmt->execute([null, $page, $role, $description]);
            }
        }

        error_log('[PageVisibility API] Auto-migration complete. Created table with ' . (count($validPages) * count($validRoles)) . ' default settings.');

    } catch (Exception $e) {
        error_log('[PageVisibility API] Auto-migration error: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Ensure default global settings exist for all valid pages/roles.
 * This allows adding new pages without a manual migration.
 */
function ensureDefaultsPresent(PDO $conn, array $validPages, array $validRoles, array $pageNames): void {
    $selectSql = "
        SELECT 1
        FROM page_visibility_settings
        WHERE tenant_id IS NULL
          AND page_name = ?
          AND role = ?
          AND deleted_at IS NULL
        LIMIT 1
    ";
    $insertSql = "
        INSERT INTO page_visibility_settings (tenant_id, page_name, role, is_visible, description, created_at)
        VALUES (NULL, ?, ?, TRUE, ?, NOW())
    ";

    $selectStmt = $conn->prepare($selectSql);
    $insertStmt = $conn->prepare($insertSql);

    $inserted = 0;
    foreach ($validPages as $page) {
        foreach ($validRoles as $role) {
            $selectStmt->execute([$page, $role]);
            if ($selectStmt->fetchColumn() !== false) {
                continue;
            }

            $description = ($pageNames[$page] ?? ucfirst($page)) . ' - Default visibility';
            $insertStmt->execute([$page, $role, $description]);
            $inserted++;
        }
    }

    if ($inserted > 0) {
        error_log('[PageVisibility API] Inserted ' . $inserted . ' missing default visibility rows.');
    }
}

/**
 * GET handler - Retrieve all visibility settings
 */
function handleGet(PDO $conn, array $validPages, array $validRoles, array $pageNames): void {
    // Get all global settings (tenant_id IS NULL)
    $sql = "
        SELECT page_name, role, is_visible, description
        FROM page_visibility_settings
        WHERE tenant_id IS NULL
          AND deleted_at IS NULL
        ORDER BY page_name, role
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build settings matrix
    $settings = [];
    foreach ($rows as $row) {
        if (!isset($settings[$row['page_name']])) {
            $settings[$row['page_name']] = [
                'name' => $row['page_name'],
                'displayName' => $pageNames[$row['page_name']] ?? ucfirst($row['page_name']),
                'roles' => []
            ];
        }
        $settings[$row['page_name']]['roles'][$row['role']] = (bool)$row['is_visible'];
    }

    // Ensure all pages and roles are present (fill missing with default TRUE)
    foreach ($validPages as $page) {
        if (!isset($settings[$page])) {
            $settings[$page] = [
                'name' => $page,
                'displayName' => $pageNames[$page] ?? ucfirst($page),
                'roles' => []
            ];
        }
        foreach ($validRoles as $role) {
            if (!isset($settings[$page]['roles'][$role])) {
                $settings[$page]['roles'][$role] = true; // Default visible
            }
        }
    }

    // Convert to indexed array for JSON
    $settingsArray = array_values($settings);

    // Sort by page order (Area Operativa, Gestione, Amministrazione)
    $pageOrder = array_flip($validPages);
    usort($settingsArray, function($a, $b) use ($pageOrder) {
        return ($pageOrder[$a['name']] ?? 999) - ($pageOrder[$b['name']] ?? 999);
    });

    api_success([
        'settings' => $settingsArray,
        'roles' => $validRoles,
        'pages' => $validPages
    ], 'Impostazioni di visibilita caricate con successo');
}

/**
 * POST handler - Save visibility settings
 */
function handleSave(PDO $conn, array $input, array $validPages, array $validRoles, array $userInfo): void {
    if (empty($input['settings']) || !is_array($input['settings'])) {
        api_error('Parametro settings mancante o non valido', 400);
    }

    $settings = $input['settings'];
    $conn->beginTransaction();

    try {
        // Load AuditLogger
        require_once __DIR__ . '/../../includes/audit_logger.php';
        $auditLogger = new AuditLogger();

        // Get current settings for audit comparison
        $currentSettings = [];
        $stmt = $conn->prepare("
            SELECT id, page_name, role, is_visible
            FROM page_visibility_settings
            WHERE tenant_id IS NULL AND deleted_at IS NULL
        ");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $currentSettings[$row['page_name'] . '_' . $row['role']] = $row;
        }

        $updated = 0;
        $inserted = 0;
        $changes = [];

        foreach ($settings as $setting) {
            // Validate page_name
            $pageName = $setting['name'] ?? $setting['page_name'] ?? null;
            if (!$pageName || !in_array($pageName, $validPages, true)) {
                continue; // Skip invalid pages
            }

            // Process each role
            $roles = $setting['roles'] ?? [];
            foreach ($roles as $role => $isVisible) {
                if (!in_array($role, $validRoles, true)) {
                    continue; // Skip invalid roles
                }

                $isVisible = (bool)$isVisible;
                $key = $pageName . '_' . $role;

                // Check if setting exists
                if (isset($currentSettings[$key])) {
                    $currentRow = $currentSettings[$key];
                    $currentVisible = (bool)$currentRow['is_visible'];

                    // Only update if changed
                    if ($currentVisible !== $isVisible) {
                        $updateStmt = $conn->prepare("
                            UPDATE page_visibility_settings
                            SET is_visible = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$isVisible ? 1 : 0, $currentRow['id']]);
                        $updated++;

                        $changes[] = [
                            'page' => $pageName,
                            'role' => $role,
                            'old' => $currentVisible,
                            'new' => $isVisible
                        ];
                    }
                } else {
                    // Insert new setting
                    $insertStmt = $conn->prepare("
                        INSERT INTO page_visibility_settings
                        (tenant_id, page_name, role, is_visible, created_at)
                        VALUES (NULL, ?, ?, ?, NOW())
                    ");
                    $insertStmt->execute([$pageName, $role, $isVisible ? 1 : 0]);
                    $inserted++;

                    $changes[] = [
                        'page' => $pageName,
                        'role' => $role,
                        'old' => null,
                        'new' => $isVisible
                    ];
                }
            }
        }

        $conn->commit();

        // Audit log for changes
        if (!empty($changes)) {
            try {
                $auditLogger->log([
                    'action' => AuditLogger::ACTION_UPDATE,
                    'entity_type' => AuditLogger::ENTITY_SYSTEM_SETTING,
                    'entity_id' => null,
                    'old_values' => ['changes_count' => count($changes)],
                    'new_values' => ['changes' => $changes],
                    'description' => "Page visibility settings updated: $updated changed, $inserted inserted",
                    'severity' => AuditLogger::SEVERITY_INFO,
                    'status' => AuditLogger::STATUS_SUCCESS
                ]);
            } catch (Exception $e) {
                error_log('[PageVisibility API] Audit log error: ' . $e->getMessage());
                // Don't fail the request for audit log errors
            }
        }

        api_success([
            'updated' => $updated,
            'inserted' => $inserted,
            'changes' => $changes
        ], "Impostazioni salvate con successo. Aggiornate: $updated, Inserite: $inserted");

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * POST handler - Reset all settings to default (all visible)
 */
function handleReset(PDO $conn, array $validPages, array $validRoles, array $userInfo): void {
    $conn->beginTransaction();

    try {
        // Load AuditLogger
        require_once __DIR__ . '/../../includes/audit_logger.php';
        $auditLogger = new AuditLogger();

        // Count current non-default settings
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count
            FROM page_visibility_settings
            WHERE tenant_id IS NULL
              AND deleted_at IS NULL
              AND is_visible = FALSE
        ");
        $stmt->execute();
        $hiddenCount = (int)$stmt->fetchColumn();

        // Reset all global settings to visible
        $updateStmt = $conn->prepare("
            UPDATE page_visibility_settings
            SET is_visible = TRUE, updated_at = NOW()
            WHERE tenant_id IS NULL
              AND deleted_at IS NULL
        ");
        $updateStmt->execute();
        $resetCount = $updateStmt->rowCount();

        $conn->commit();

        // Audit log
        try {
            $auditLogger->log([
                'action' => AuditLogger::ACTION_UPDATE,
                'entity_type' => AuditLogger::ENTITY_SYSTEM_SETTING,
                'entity_id' => null,
                'old_values' => ['hidden_pages' => $hiddenCount],
                'new_values' => ['all_visible' => true],
                'description' => "Page visibility settings reset to default. $hiddenCount pages restored to visible.",
                'severity' => AuditLogger::SEVERITY_WARNING,
                'status' => AuditLogger::STATUS_SUCCESS
            ]);
        } catch (Exception $e) {
            error_log('[PageVisibility API] Audit log error: ' . $e->getMessage());
        }

        api_success([
            'reset_count' => $resetCount,
            'previously_hidden' => $hiddenCount
        ], "Impostazioni ripristinate ai valori predefiniti. $hiddenCount pagine ripristinate a visibili.");

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}
