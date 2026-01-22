<?php
/**
 * Tool (web): Apply Migration 22 - Work Shifts feature
 *
 * Why: enable shifts feature in environments without phpMyAdmin/SSH access.
 *
 * Security:
 * - Auth required
 * - super_admin only
 * - CSRF required
 *
 * Idempotent: safe to run multiple times.
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

function cnx_dbname(PDO $pdo): string {
    try {
        $row = $pdo->query('SELECT DATABASE() AS db')->fetch(PDO::FETCH_ASSOC);
        return (string)($row['db'] ?? '');
    } catch (Throwable $e) {
        return '';
    }
}

function cnx_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table]);
        return ($stmt->fetch(PDO::FETCH_NUM) !== false);
    } catch (Throwable $e) {
        error_log('[apply_migration_22] table exists check failed: ' . $e->getMessage());
        return false;
    }
}

function cnx_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table, $column]);
        return ($stmt->fetch(PDO::FETCH_NUM) !== false);
    } catch (Throwable $e) {
        error_log('[apply_migration_22] column exists check failed: ' . $e->getMessage());
        return false;
    }
}

function cnx_index_exists(PDO $pdo, string $table, string $index): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table, $index]);
        return ($stmt->fetch(PDO::FETCH_NUM) !== false);
    } catch (Throwable $e) {
        error_log('[apply_migration_22] index exists check failed: ' . $e->getMessage());
        return false;
    }
}

$isPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
$result = null;
$error = null;

$tables = ['shift_types', 'work_shifts', 'shift_change_requests'];

if ($isPost) {
    // CSRF: allow token from POST/headers
    verifyApiCsrfToken(true);

    try {
        $dbName = cnx_dbname($pdo);

        // 1) Create shift_types
        if (!cnx_table_exists($pdo, 'shift_types')) {
            $sql = "CREATE TABLE IF NOT EXISTS shift_types (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant that owns this shift type',
                name VARCHAR(100) NOT NULL COMMENT 'Display name',
                code VARCHAR(50) NOT NULL COMMENT 'Short code',
                description TEXT NULL COMMENT 'Optional description',
                start_time TIME NOT NULL COMMENT 'Shift start time',
                end_time TIME NOT NULL COMMENT 'Shift end time',
                duration_minutes INT UNSIGNED NULL COMMENT 'Optional explicit duration in minutes',
                color VARCHAR(7) NOT NULL DEFAULT '#3B82F6' COMMENT 'HEX color',
                icon VARCHAR(50) NULL COMMENT 'Optional icon',
                sort_order INT UNSIGNED DEFAULT 0 COMMENT 'Display order',
                is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Can be temporarily disabled',
                deleted_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_by INT UNSIGNED NULL COMMENT 'User who created this shift type',
                PRIMARY KEY (id),
                CONSTRAINT fk_shift_types_tenant
                    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                CONSTRAINT fk_shift_types_created_by
                    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                UNIQUE KEY uk_shift_types_code (tenant_id, code, deleted_at),
                UNIQUE KEY uk_shift_types_name (tenant_id, name, deleted_at),
                INDEX idx_shift_types_tenant_created (tenant_id, created_at),
                INDEX idx_shift_types_tenant_deleted (tenant_id, deleted_at),
                INDEX idx_shift_types_tenant_active (tenant_id, is_active, deleted_at),
                INDEX idx_shift_types_sort (tenant_id, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Shift type definitions per tenant (Tipi di Turno)'";
            if ($pdo->exec($sql) === false) {
                $info = $pdo->errorInfo();
                throw new RuntimeException('CREATE TABLE shift_types failed: ' . implode(' | ', array_map('strval', $info)));
            }
        }

        // 2) Create work_shifts
        if (!cnx_table_exists($pdo, 'work_shifts')) {
            $sql = "CREATE TABLE IF NOT EXISTS work_shifts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant isolation',
                shift_type_id INT UNSIGNED NOT NULL COMMENT 'FK to shift_types.id',
                user_id INT UNSIGNED NOT NULL COMMENT 'FK to users.id',
                shift_date DATE NOT NULL COMMENT 'The specific date of the shift',
                start_time_override TIME NULL COMMENT 'Override start time',
                end_time_override TIME NULL COMMENT 'Override end time',
                status ENUM('scheduled','confirmed','in_progress','completed','cancelled','no_show')
                    NOT NULL DEFAULT 'scheduled' COMMENT 'Shift status lifecycle',
                notes TEXT NULL COMMENT 'Manager/system notes',
                user_notes TEXT NULL COMMENT 'Worker notes',
                actual_start_time DATETIME NULL COMMENT 'When worker actually started',
                actual_end_time DATETIME NULL COMMENT 'When worker actually ended',
                deleted_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_by INT UNSIGNED NULL COMMENT 'User who created this assignment',
                updated_by INT UNSIGNED NULL COMMENT 'User who last modified this assignment',
                PRIMARY KEY (id),
                CONSTRAINT fk_work_shifts_tenant
                    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                CONSTRAINT fk_work_shifts_shift_type
                    FOREIGN KEY (shift_type_id) REFERENCES shift_types(id) ON DELETE RESTRICT,
                CONSTRAINT fk_work_shifts_user
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_work_shifts_created_by
                    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_work_shifts_updated_by
                    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                UNIQUE KEY uk_work_shifts_user_date_type (tenant_id, user_id, shift_date, shift_type_id, deleted_at),
                INDEX idx_work_shifts_tenant_created (tenant_id, created_at),
                INDEX idx_work_shifts_tenant_deleted (tenant_id, deleted_at),
                INDEX idx_work_shifts_tenant_date (tenant_id, shift_date, deleted_at),
                INDEX idx_work_shifts_date_range (tenant_id, shift_date, status, deleted_at),
                INDEX idx_work_shifts_user_date (tenant_id, user_id, shift_date, deleted_at),
                INDEX idx_work_shifts_user_status (tenant_id, user_id, status, deleted_at),
                INDEX idx_work_shifts_status (tenant_id, status, shift_date),
                INDEX idx_work_shifts_type_date (tenant_id, shift_type_id, shift_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Work shift assignments to users for specific dates (Turni Assegnati)'";
            if ($pdo->exec($sql) === false) {
                $info = $pdo->errorInfo();
                throw new RuntimeException('CREATE TABLE work_shifts failed: ' . implode(' | ', array_map('strval', $info)));
            }
        }

        // 3) Create shift_change_requests
        if (!cnx_table_exists($pdo, 'shift_change_requests')) {
            $sql = "CREATE TABLE IF NOT EXISTS shift_change_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant isolation',
                work_shift_id INT UNSIGNED NOT NULL COMMENT 'FK to work_shifts.id',
                requester_id INT UNSIGNED NOT NULL COMMENT 'FK to users.id',
                request_type ENUM('change','swap','cancel') NOT NULL COMMENT 'change/swap/cancel',
                new_shift_type_id INT UNSIGNED NULL COMMENT 'FK to shift_types.id (change)',
                target_user_id INT UNSIGNED NULL COMMENT 'FK to users.id (swap)',
                target_work_shift_id INT UNSIGNED NULL COMMENT 'FK to work_shifts.id (swap)',
                reason TEXT NOT NULL COMMENT 'User explanation',
                preferred_date DATE NULL COMMENT 'Optional alternative date',
                status ENUM('pending','approved','rejected','cancelled','expired')
                    NOT NULL DEFAULT 'pending' COMMENT 'Request status',
                manager_id INT UNSIGNED NULL COMMENT 'FK to users.id (manager)',
                decision_at TIMESTAMP NULL COMMENT 'When decided',
                manager_notes TEXT NULL COMMENT 'Manager notes',
                target_accepted TINYINT(1) NULL COMMENT 'Swap acceptance',
                target_accepted_at TIMESTAMP NULL COMMENT 'When target user responded',
                target_notes TEXT NULL COMMENT 'Target user notes',
                deleted_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT fk_shift_requests_tenant
                    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                CONSTRAINT fk_shift_requests_work_shift
                    FOREIGN KEY (work_shift_id) REFERENCES work_shifts(id) ON DELETE CASCADE,
                CONSTRAINT fk_shift_requests_requester
                    FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_shift_requests_new_type
                    FOREIGN KEY (new_shift_type_id) REFERENCES shift_types(id) ON DELETE SET NULL,
                CONSTRAINT fk_shift_requests_target_user
                    FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_shift_requests_target_shift
                    FOREIGN KEY (target_work_shift_id) REFERENCES work_shifts(id) ON DELETE SET NULL,
                CONSTRAINT fk_shift_requests_manager
                    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_shift_requests_tenant_created (tenant_id, created_at),
                INDEX idx_shift_requests_tenant_deleted (tenant_id, deleted_at),
                INDEX idx_shift_requests_tenant_status (tenant_id, status, deleted_at),
                INDEX idx_shift_requests_pending (tenant_id, status, created_at),
                INDEX idx_shift_requests_requester (tenant_id, requester_id, status, deleted_at),
                INDEX idx_shift_requests_target (tenant_id, target_user_id, status, deleted_at),
                INDEX idx_shift_requests_work_shift (work_shift_id, status),
                INDEX idx_shift_requests_manager (tenant_id, manager_id, decision_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Shift change/swap/cancel requests with approval workflow (Richieste Modifica Turno)'";
            if ($pdo->exec($sql) === false) {
                $info = $pdo->errorInfo();
                throw new RuntimeException('CREATE TABLE shift_change_requests failed: ' . implode(' | ', array_map('strval', $info)));
            }
        }

        // 4) tenants.has_shift_management (optional feature flag)
        if (!cnx_column_exists($pdo, 'tenants', 'has_shift_management')) {
            $sql = "ALTER TABLE tenants
                    ADD COLUMN has_shift_management TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT 'TRUE if tenant uses work shift management feature'";
            if ($pdo->exec($sql) === false) {
                $info = $pdo->errorInfo();
                throw new RuntimeException('ALTER TABLE tenants ADD has_shift_management failed: ' . implode(' | ', array_map('strval', $info)));
            }
        }
        if (!cnx_index_exists($pdo, 'tenants', 'idx_tenants_shift_management')) {
            $sql = "ALTER TABLE tenants ADD INDEX idx_tenants_shift_management (has_shift_management)";
            // Index creation is optional; don't fail hard if it errors on some environments.
            $pdo->exec($sql);
        }

        $result = [
            'ok' => true,
            'database' => $dbName,
            'tables' => [
                'shift_types' => cnx_table_exists($pdo, 'shift_types'),
                'work_shifts' => cnx_table_exists($pdo, 'work_shifts'),
                'shift_change_requests' => cnx_table_exists($pdo, 'shift_change_requests'),
            ],
            'tenants_has_shift_management' => cnx_column_exists($pdo, 'tenants', 'has_shift_management'),
        ];
    } catch (Throwable $e) {
        $error = $e->getMessage();
        error_log('[apply_migration_22] failed: ' . $error);
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply Migration 22 - Nexio</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        .wrap { max-width: 820px; margin: 32px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px; }
        .muted { color:#6b7280; font-size: 13px; }
        pre { background:#0b1020; color:#e5e7eb; padding: 12px; border-radius: 10px; overflow:auto; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h2 style="margin-top:0;">Apply Migration 22</h2>
            <p class="muted" style="margin-top:6px;">
                Crea le tabelle <code>shift_types</code>, <code>work_shifts</code>, <code>shift_change_requests</code> e aggiunge <code>tenants.has_shift_management</code>.<br>
                Accesso: solo <strong>super_admin</strong>. Operazione idempotente.
            </p>

            <?php if ($isPost): ?>
                <?php if ($result && ($result['ok'] ?? false)): ?>
                    <div class="alert alert-success" style="margin:12px 0;">
                        Migrazione applicata/verificata.
                    </div>
                    <pre><?php echo htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                    <p style="margin-top:12px;">
                        Ora puoi tornare su <code>/CollaboraNexio/turni.php</code> e riprovare a creare un tipo turno.
                    </p>
                <?php else: ?>
                    <div class="alert alert-danger" style="margin:12px 0;">
                        Errore durante l’applicazione: <?php echo htmlspecialchars((string)$error); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="POST" style="margin-top:14px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <button type="submit" class="btn btn-primary">Applica/Verifica Migration 22</button>
                <a class="btn btn-secondary" href="../turni.php" style="margin-left:8px;">Vai a Turni</a>
            </form>
        </div>
    </div>
</body>
</html>

