<?php
/**
 * Tool (web): Apply Migration 24 - folder_zip_download_requests
 *
 * Why: allow production setup without phpMyAdmin/SSH.
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
require_once __DIR__ . '/../includes/api_auth.php'; // for verifyApiCsrfToken()
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

function cnx_table_exists_show_tables(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $stmt->fetch(PDO::FETCH_NUM) !== false;
    } catch (Throwable $e) {
        error_log('[apply_migration_24] table exists check failed: ' . $e->getMessage());
        return false;
    }
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$result = null;
$error = null;

if ($isPost) {
    // CSRF: allow token from POST (verifyApiCsrfToken reads POST/headers)
    verifyApiCsrfToken(true);

    try {
        $table = 'folder_zip_download_requests';
        $already = cnx_table_exists_show_tables($pdo, $table);
        if (!$already) {
            // NOTE: In MySQL/MariaDB, DDL (CREATE TABLE, ALTER, ...) performs an implicit commit.
            // Wrapping it in a transaction can cause "There is no active transaction" on commit().
            $sql = "CREATE TABLE IF NOT EXISTS folder_zip_download_requests (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        tenant_id INT NOT NULL,
                        folder_id INT NOT NULL,
                        requester_id INT NOT NULL,
                        status ENUM('pending','approved','rejected','consumed') NOT NULL DEFAULT 'pending',
                        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        decided_at DATETIME NULL,
                        decided_by INT NULL,
                        used_at DATETIME NULL,
                        note TEXT NULL,
                        INDEX idx_fzdr_tenant_folder (tenant_id, folder_id),
                        INDEX idx_fzdr_status (status),
                        INDEX idx_fzdr_requester_status (requester_id, status),
                        INDEX idx_fzdr_requested_at (requested_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $execResult = $pdo->exec($sql);
            if ($execResult === false) {
                $info = $pdo->errorInfo();
                throw new RuntimeException('CREATE TABLE failed: ' . implode(' | ', array_map('strval', $info)));
            }
        }

        $result = [
            'ok' => true,
            'table' => $table,
            'already_existed' => $already,
            'now_exists' => cnx_table_exists_show_tables($pdo, $table),
        ];
    } catch (Throwable $e) {
        $error = $e->getMessage();
        error_log('[apply_migration_24] failed: ' . $error);
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply Migration 24 - Nexio</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        .wrap { max-width: 760px; margin: 32px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px; }
        .muted { color:#6b7280; font-size: 13px; }
        pre { background:#0b1020; color:#e5e7eb; padding: 12px; border-radius: 10px; overflow:auto; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h2 style="margin-top:0;">Apply Migration 24</h2>
            <p class="muted" style="margin-top:6px;">
                Crea la tabella <code>folder_zip_download_requests</code> (approvazioni download ZIP cartelle).<br>
                Accesso: solo <strong>super_admin</strong>. Operazione idempotente.
            </p>

            <?php if ($isPost): ?>
                <?php if ($result && ($result['ok'] ?? false)): ?>
                    <div class="alert alert-success" style="margin:12px 0;">
                        Migrazione applicata. already_existed=<?php echo ($result['already_existed'] ? 'true' : 'false'); ?>, now_exists=<?php echo ($result['now_exists'] ? 'true' : 'false'); ?>
                    </div>
                    <pre><?php echo htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                    <p style="margin-top:12px;">
                        Ora puoi tornare su <code>/CollaboraNexio/files.php</code> e riprovare “Scarica” su una cartella come utente <code>user</code>.
                    </p>
                <?php else: ?>
                    <div class="alert alert-danger" style="margin:12px 0;">
                        Errore durante l’applicazione: <?php echo htmlspecialchars((string)$error); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="POST" style="margin-top:14px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <button type="submit" class="btn btn-primary">Applica Migration 24</button>
                <a class="btn btn-secondary" href="../zip_download_requests.php" style="margin-left:8px;">Vai a Richieste ZIP</a>
            </form>
        </div>
    </div>
</body>
</html>

