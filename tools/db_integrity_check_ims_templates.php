<?php
/**
 * IMS Templates/Modules Integrity Check (read-only)
 *
 * Verifies:
 * - presence of storage tables/columns (m45 + m46)
 * - orphan module items (template_key missing)
 * - templates configured as copy_source with missing source files
 * - master folder /Templates/IMS existence in tenant 28
 *
 * Output: JSON
 * - Web: requires authenticated super_admin
 * - CLI: allowed (best-effort)
 */
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=utf-8');

$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/db.php';

    if (!$isCli) {
        require_once __DIR__ . '/../includes/session_init.php';
        require_once __DIR__ . '/../includes/auth_simple.php';

        $auth = new Auth();
        if (!$auth->checkAuth()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        $u = $auth->getCurrentUser();
        $role = (string)($u['role'] ?? '');
        if ($role !== 'super_admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden: super_admin required']);
            exit;
        }
    }

    $db = Database::getInstance();

    $checkTable = function (string $t) use ($db): bool {
        try {
            return (bool)$db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                 LIMIT 1",
                [$t]
            );
        } catch (Throwable $e) {
            return false;
        }
    };

    $checkColumn = function (string $table, string $col) use ($db): bool {
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

    $dbName = '';
    $hostName = '';
    try { $dbName = (string)($db->fetchOne("SELECT DATABASE() AS db")['db'] ?? ''); } catch (Throwable $e) {}
    try { $hostName = (string)($db->fetchOne("SELECT @@hostname AS h")['h'] ?? ''); } catch (Throwable $e) {}

    $tables = [
        'compliance_standards',
        'compliance_artifact_templates',
        'compliance_program_standards',
        'compliance_template_modules',
        'compliance_template_module_items',
        'files',
        'tenants',
    ];
    $missingTables = [];
    foreach ($tables as $t) {
        if (!$checkTable($t)) $missingTables[] = $t;
    }

    $cols = [
        'source_file_id',
        'source_tenant_id',
        'content_mode',
        'doc_code_template',
    ];
    $missingCols = [];
    foreach ($cols as $c) {
        if (!$checkColumn('compliance_artifact_templates', $c)) $missingCols[] = $c;
    }

    // Master folder /Templates/IMS (tenant 28) best-effort
    $master = [
        'tenant_id' => 28,
        'path' => '/Templates/IMS',
        'available' => false,
        'folder_id' => null,
    ];
    try {
        $root = $db->fetchOne("SELECT id FROM files WHERE tenant_id = 28 AND folder_id IS NULL AND is_folder = 1 AND deleted_at IS NULL LIMIT 1");
        if ($root && !empty($root['id'])) {
            $templates = $db->fetchOne(
                "SELECT id FROM files
                 WHERE tenant_id = 28 AND is_folder = 1 AND deleted_at IS NULL
                   AND folder_id = ? AND name = 'Templates'
                 LIMIT 1",
                [(int)$root['id']]
            );
            if ($templates && !empty($templates['id'])) {
                $ims = $db->fetchOne(
                    "SELECT id FROM files
                     WHERE tenant_id = 28 AND is_folder = 1 AND deleted_at IS NULL
                       AND folder_id = ? AND name = 'IMS'
                     LIMIT 1",
                    [(int)$templates['id']]
                );
                if ($ims && !empty($ims['id'])) {
                    $master['available'] = true;
                    $master['folder_id'] = (int)$ims['id'];
                }
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Orphan module items
    $orphanItems = [];
    if ($checkTable('compliance_template_module_items') && $checkTable('compliance_template_modules') && $checkTable('compliance_artifact_templates')) {
        try {
            $rows = $db->fetchAll(
                "SELECT m.module_key, i.template_key
                 FROM compliance_template_module_items i
                 JOIN compliance_template_modules m ON m.id = i.module_id
                 LEFT JOIN compliance_artifact_templates t ON t.template_key = i.template_key
                 WHERE t.template_key IS NULL
                 ORDER BY m.module_key, i.template_key
                 LIMIT 200"
            ) ?: [];
            foreach ($rows as $r) {
                $orphanItems[] = [
                    'module_key' => (string)($r['module_key'] ?? ''),
                    'template_key' => (string)($r['template_key'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    // copy_source templates with missing master file
    $copySourceIssues = [];
    if ($checkTable('compliance_artifact_templates') && $checkColumn('compliance_artifact_templates', 'content_mode')) {
        try {
            $rows = $db->fetchAll(
                "SELECT template_key, content_mode, source_tenant_id, source_file_id
                 FROM compliance_artifact_templates
                 WHERE is_active = 1
                 LIMIT 500"
            ) ?: [];
            foreach ($rows as $r) {
                $mode = (string)($r['content_mode'] ?? 'placeholder');
                $srcTid = (int)($r['source_tenant_id'] ?? 28);
                $srcFid = (int)($r['source_file_id'] ?? 0);
                if ($mode !== 'copy_source') continue;
                if ($srcFid <= 0) {
                    $copySourceIssues[] = ['template_key' => (string)$r['template_key'], 'issue' => 'copy_source without source_file_id'];
                    continue;
                }
                $ok = false;
                try {
                    $f = $db->fetchOne(
                        "SELECT id
                         FROM files
                         WHERE tenant_id = ?
                           AND id = ?
                           AND deleted_at IS NULL
                           AND is_folder = 0
                         LIMIT 1",
                        [$srcTid, $srcFid]
                    );
                    $ok = (bool)$f;
                } catch (Throwable $e) {
                    $ok = false;
                }
                if (!$ok) {
                    $copySourceIssues[] = [
                        'template_key' => (string)$r['template_key'],
                        'issue' => 'source file missing/not accessible',
                        'source_tenant_id' => $srcTid,
                        'source_file_id' => $srcFid,
                    ];
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $ok = empty($missingTables) && empty($orphanItems) && empty($copySourceIssues);

    echo json_encode([
        'success' => true,
        'data' => [
            'db_name' => $dbName,
            'db_host' => $hostName,
            'ok' => $ok,
            'missing_tables' => $missingTables,
            'missing_columns_m46' => $missingCols,
            'master_folder' => $master,
            'orphan_module_items' => $orphanItems,
            'copy_source_issues' => $copySourceIssues,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

