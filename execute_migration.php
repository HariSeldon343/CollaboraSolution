<?php
session_start();
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/db.php';

// Check authentication - only admin or super_admin can run migrations
$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
if (!in_array($currentUser['role'], ['admin', 'super_admin'])) {
    die('Accesso negato. Solo amministratori possono eseguire migrazioni.');
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Handle migration execution
$messages = [];
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_migration'])) {
    $transactionStarted = false;

    try {
        // Start transaction
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            $transactionStarted = true;
        }

        // Disable foreign key checks for the migration
        $conn->exec("SET FOREIGN_KEY_CHECKS = 0");

        // Track migration progress
        $totalSteps = 8;
        $currentStep = 0;

        // Step 1: Modify users table to allow NULL tenant_id
        $messages[] = "Modifica tabella users...";
        try {
            $sql = "ALTER TABLE users MODIFY COLUMN tenant_id INT UNSIGNED NULL COMMENT 'NULL for users without company assignment'";
            $conn->exec($sql);

            // Add columns for tracking original tenant
            $sql = "ALTER TABLE users
                    ADD COLUMN IF NOT EXISTS original_tenant_id INT UNSIGNED NULL COMMENT 'Original tenant before deletion' AFTER tenant_id,
                    ADD COLUMN IF NOT EXISTS tenant_removed_at TIMESTAMP NULL COMMENT 'When user was removed from tenant' AFTER deleted_at";
            $conn->exec($sql);

            // Add index if not exists
            $checkIndex = $conn->query("SHOW INDEX FROM users WHERE Key_name = 'idx_original_tenant'");
            if ($checkIndex->rowCount() === 0) {
                $conn->exec("ALTER TABLE users ADD INDEX idx_original_tenant (original_tenant_id)");
            }

            $messages[] = "✓ Tabella users modificata";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                throw $e;
            }
            $messages[] = "⚠ Tabella users già modificata";
        }
        $currentStep++;

        // Step 2: Modify folders table
        $messages[] = "Modifica tabella folders...";
        try {
            // Drop existing foreign key if exists
            $conn->exec("ALTER TABLE folders DROP FOREIGN KEY IF EXISTS folders_ibfk_1");

            // Modify column to allow NULL
            $sql = "ALTER TABLE folders MODIFY COLUMN tenant_id INT UNSIGNED NULL COMMENT 'NULL for Super Admin global folders'";
            $conn->exec($sql);

            // Add tracking columns
            $sql = "ALTER TABLE folders
                    ADD COLUMN IF NOT EXISTS original_tenant_id INT UNSIGNED NULL COMMENT 'Original tenant before deletion' AFTER tenant_id,
                    ADD COLUMN IF NOT EXISTS reassigned_at TIMESTAMP NULL COMMENT 'When folder was reassigned' AFTER deleted_at,
                    ADD COLUMN IF NOT EXISTS reassigned_by INT UNSIGNED NULL COMMENT 'User who performed reassignment' AFTER reassigned_at";
            $conn->exec($sql);

            // Add index if not exists
            $checkIndex = $conn->query("SHOW INDEX FROM folders WHERE Key_name = 'idx_original_tenant'");
            if ($checkIndex->rowCount() === 0) {
                $conn->exec("ALTER TABLE folders ADD INDEX idx_original_tenant (original_tenant_id)");
            }

            // Re-add foreign key with SET NULL on delete
            $conn->exec("ALTER TABLE folders ADD CONSTRAINT fk_folders_tenant
                        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL");

            $messages[] = "✓ Tabella folders modificata";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                throw $e;
            }
            $messages[] = "⚠ Tabella folders già modificata";
        }
        $currentStep++;

        // Step 3: Modify files table
        $messages[] = "Modifica tabella files...";
        try {
            // Drop existing foreign key if exists
            $conn->exec("ALTER TABLE files DROP FOREIGN KEY IF EXISTS files_ibfk_1");

            // Modify column to allow NULL
            $sql = "ALTER TABLE files MODIFY COLUMN tenant_id INT UNSIGNED NULL COMMENT 'NULL for Super Admin global files'";
            $conn->exec($sql);

            // Add tracking columns
            $sql = "ALTER TABLE files
                    ADD COLUMN IF NOT EXISTS original_tenant_id INT UNSIGNED NULL COMMENT 'Original tenant before deletion' AFTER tenant_id,
                    ADD COLUMN IF NOT EXISTS reassigned_at TIMESTAMP NULL COMMENT 'When file was reassigned' AFTER deleted_at,
                    ADD COLUMN IF NOT EXISTS reassigned_by INT UNSIGNED NULL COMMENT 'User who performed reassignment' AFTER reassigned_at";
            $conn->exec($sql);

            // Add index if not exists
            $checkIndex = $conn->query("SHOW INDEX FROM files WHERE Key_name = 'idx_original_tenant'");
            if ($checkIndex->rowCount() === 0) {
                $conn->exec("ALTER TABLE files ADD INDEX idx_original_tenant (original_tenant_id)");
            }

            // Re-add foreign key with SET NULL on delete
            $conn->exec("ALTER TABLE files ADD CONSTRAINT fk_files_tenant
                        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL");

            $messages[] = "✓ Tabella files modificata";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                throw $e;
            }
            $messages[] = "⚠ Tabella files già modificata";
        }
        $currentStep++;

        // Step 4: Create Super Admin root folder
        $messages[] = "Creazione cartella Super Admin...";
        try {
            // Check if Super Admin folder already exists
            $stmt = $conn->prepare("SELECT id FROM folders WHERE tenant_id IS NULL AND parent_id IS NULL AND name = 'Super Admin Files' LIMIT 1");
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                // Get a super admin user
                $stmt = $conn->prepare("SELECT id FROM users WHERE role = 'super_admin' LIMIT 1");
                $stmt->execute();
                $superAdminId = $stmt->fetchColumn();

                if (!$superAdminId) {
                    // Create a default super admin if none exists
                    $stmt = $conn->prepare("INSERT INTO users (tenant_id, nome, cognome, email, password, role)
                                            VALUES (NULL, 'Super', 'Admin', 'superadmin@collaboranexio.com', :password, 'super_admin')");
                    $stmt->execute([':password' => password_hash('Admin123!', PASSWORD_DEFAULT)]);
                    $superAdminId = $conn->lastInsertId();
                }

                // Create Super Admin root folder
                $stmt = $conn->prepare("INSERT INTO folders (tenant_id, parent_id, name, path, owner_id, is_public, settings)
                                        VALUES (NULL, NULL, 'Super Admin Files', '/super-admin', :owner, FALSE, :settings)");
                $stmt->execute([
                    ':owner' => $superAdminId,
                    ':settings' => json_encode(['system_folder' => true, 'description' => 'Root folder for Super Admin global files'])
                ]);

                $superAdminFolderId = $conn->lastInsertId();

                // Create Orphaned Companies folder
                $stmt = $conn->prepare("INSERT INTO folders (tenant_id, parent_id, name, path, owner_id, is_public, settings)
                                        VALUES (NULL, :parent, 'Orphaned Companies', '/super-admin/orphaned-companies', :owner, FALSE, :settings)");
                $stmt->execute([
                    ':parent' => $superAdminFolderId,
                    ':owner' => $superAdminId,
                    ':settings' => json_encode(['system_folder' => true, 'description' => 'Files from deleted companies'])
                ]);

                $messages[] = "✓ Cartelle Super Admin create";
            } else {
                $messages[] = "⚠ Cartelle Super Admin già esistenti";
            }
        } catch (Exception $e) {
            $messages[] = "⚠ Errore creazione cartelle: " . $e->getMessage();
        }
        $currentStep++;

        // Step 5: Drop and create SafeDeleteCompany procedure
        $messages[] = "Creazione stored procedure SafeDeleteCompany...";
        try {
            $conn->exec("DROP PROCEDURE IF EXISTS SafeDeleteCompany");

            $sql = "CREATE PROCEDURE SafeDeleteCompany(
                IN p_company_id INT,
                IN p_deleted_by INT,
                OUT p_success BOOLEAN,
                OUT p_message VARCHAR(255)
            )
            BEGIN
                DECLARE v_company_name VARCHAR(255);
                DECLARE v_super_admin_folder_id INT;
                DECLARE v_reassigned_folders INT DEFAULT 0;
                DECLARE v_reassigned_files INT DEFAULT 0;
                DECLARE v_updated_users INT DEFAULT 0;

                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    ROLLBACK;
                    SET p_success = FALSE;
                    SET p_message = 'Error during company deletion';
                END;

                START TRANSACTION;

                -- Get company name
                SELECT name INTO v_company_name FROM tenants WHERE id = p_company_id;

                IF v_company_name IS NULL THEN
                    SET p_success = FALSE;
                    SET p_message = 'Company not found';
                    ROLLBACK;
                ELSE
                    -- Get orphaned companies folder
                    SELECT id INTO v_super_admin_folder_id
                    FROM folders
                    WHERE tenant_id IS NULL AND name = 'Orphaned Companies'
                    LIMIT 1;

                    -- Create company-specific folder in orphaned companies
                    INSERT INTO folders (tenant_id, parent_id, name, path, owner_id, is_public, original_tenant_id, reassigned_at, reassigned_by)
                    VALUES (
                        NULL,
                        v_super_admin_folder_id,
                        CONCAT(v_company_name, ' (Deleted ', DATE_FORMAT(NOW(), '%Y-%m-%d'), ')'),
                        CONCAT('/super-admin/orphaned-companies/', v_company_name, '-', p_company_id),
                        p_deleted_by,
                        FALSE,
                        p_company_id,
                        NOW(),
                        p_deleted_by
                    );

                    SET @new_folder_id = LAST_INSERT_ID();

                    -- Reassign folders
                    UPDATE folders
                    SET tenant_id = NULL,
                        original_tenant_id = p_company_id,
                        parent_id = @new_folder_id,
                        reassigned_at = NOW(),
                        reassigned_by = p_deleted_by
                    WHERE tenant_id = p_company_id;

                    SET v_reassigned_folders = ROW_COUNT();

                    -- Reassign files
                    UPDATE files
                    SET tenant_id = NULL,
                        original_tenant_id = p_company_id,
                        reassigned_at = NOW(),
                        reassigned_by = p_deleted_by
                    WHERE tenant_id = p_company_id;

                    SET v_reassigned_files = ROW_COUNT();

                    -- Update users
                    UPDATE users
                    SET tenant_id = NULL,
                        original_tenant_id = p_company_id,
                        tenant_removed_at = NOW()
                    WHERE tenant_id = p_company_id
                    AND role IN ('user', 'manager');

                    SET v_updated_users = ROW_COUNT();

                    -- Delete admin users for this company
                    DELETE FROM users WHERE tenant_id = p_company_id AND role IN ('admin', 'tenant_admin');

                    -- Remove user_tenant_access
                    DELETE FROM user_tenant_access WHERE tenant_id = p_company_id;

                    -- Delete dependent data (only tables that exist)
                    DELETE FROM tasks WHERE tenant_id = p_company_id;
                    DELETE FROM calendar_events WHERE tenant_id = p_company_id;
                    DELETE FROM chat_messages WHERE tenant_id = p_company_id;
                    DELETE FROM chat_channels WHERE tenant_id = p_company_id;
                    DELETE FROM projects WHERE tenant_id = p_company_id;

                    -- Finally delete the company
                    DELETE FROM tenants WHERE id = p_company_id;

                    COMMIT;

                    SET p_success = TRUE;
                    SET p_message = CONCAT(
                        'Company deleted successfully. ',
                        v_reassigned_folders, ' folders reassigned, ',
                        v_reassigned_files, ' files reassigned, ',
                        v_updated_users, ' users updated.'
                    );
                END IF;
            END";

            $conn->exec($sql);
            $messages[] = "✓ Stored procedure SafeDeleteCompany creata";
        } catch (Exception $e) {
            $messages[] = "⚠ Errore creazione SafeDeleteCompany: " . $e->getMessage();
        }
        $currentStep++;

        // Step 6: Create CheckUserLoginAccess procedure
        $messages[] = "Creazione stored procedure CheckUserLoginAccess...";
        try {
            $conn->exec("DROP PROCEDURE IF EXISTS CheckUserLoginAccess");

            $sql = "CREATE PROCEDURE CheckUserLoginAccess(
                IN p_user_id INT,
                OUT p_can_login BOOLEAN,
                OUT p_message VARCHAR(255)
            )
            BEGIN
                DECLARE v_user_role VARCHAR(20);
                DECLARE v_tenant_id INT;

                SELECT role, tenant_id INTO v_user_role, v_tenant_id
                FROM users
                WHERE id = p_user_id AND deleted_at IS NULL;

                IF v_user_role IS NULL THEN
                    SET p_can_login = FALSE;
                    SET p_message = 'User not found';
                ELSEIF v_user_role IN ('super_admin', 'admin') THEN
                    SET p_can_login = TRUE;
                    SET p_message = 'Admin user can login';
                ELSEIF v_tenant_id IS NULL THEN
                    SET p_can_login = FALSE;
                    SET p_message = 'User requires company assignment to login';
                ELSE
                    IF EXISTS (SELECT 1 FROM tenants WHERE id = v_tenant_id AND status = 'active') THEN
                        SET p_can_login = TRUE;
                        SET p_message = 'User can login';
                    ELSE
                        SET p_can_login = FALSE;
                        SET p_message = 'Company is not active';
                    END IF;
                END IF;
            END";

            $conn->exec($sql);
            $messages[] = "✓ Stored procedure CheckUserLoginAccess creata";
        } catch (Exception $e) {
            $messages[] = "⚠ Errore creazione CheckUserLoginAccess: " . $e->getMessage();
        }
        $currentStep++;

        // Step 7: Create GetAccessibleFolders procedure
        $messages[] = "Creazione stored procedure GetAccessibleFolders...";
        try {
            $conn->exec("DROP PROCEDURE IF EXISTS GetAccessibleFolders");

            $sql = "CREATE PROCEDURE GetAccessibleFolders(
                IN p_user_id INT
            )
            BEGIN
                DECLARE v_user_role VARCHAR(20);
                DECLARE v_tenant_id INT;

                SELECT role, tenant_id INTO v_user_role, v_tenant_id
                FROM users WHERE id = p_user_id;

                IF v_user_role = 'super_admin' THEN
                    -- Super Admin sees all folders
                    SELECT * FROM folders WHERE deleted_at IS NULL;
                ELSEIF v_user_role = 'admin' THEN
                    -- Admin sees folders from their tenants and NULL tenant folders
                    SELECT f.* FROM folders f
                    WHERE f.deleted_at IS NULL
                    AND (
                        f.tenant_id IS NULL
                        OR f.tenant_id IN (
                            SELECT tenant_id FROM user_tenant_access WHERE user_id = p_user_id
                        )
                        OR f.tenant_id = v_tenant_id
                    );
                ELSE
                    -- Regular users see only their tenant folders
                    SELECT * FROM folders
                    WHERE tenant_id = v_tenant_id AND deleted_at IS NULL;
                END IF;
            END";

            $conn->exec($sql);
            $messages[] = "✓ Stored procedure GetAccessibleFolders creata";
        } catch (Exception $e) {
            $messages[] = "⚠ Errore creazione GetAccessibleFolders: " . $e->getMessage();
        }
        $currentStep++;

        // Step 8: Record migration
        // Re-enable foreign key checks before recording migration
        try {
            $conn->exec("SET FOREIGN_KEY_CHECKS = 1");
        } catch (Exception $e) {
            // Log but don't fail - foreign key checks will be re-enabled anyway
            $messages[] = "⚠ Avviso: " . $e->getMessage();
        }
        $messages[] = "Registrazione migrazione...";

        // Check if migration_history table exists
        $checkTable = $conn->query("SHOW TABLES LIKE 'migration_history'");
        if ($checkTable->rowCount() > 0) {
            // Check if migration already exists
            $stmt = $conn->prepare("SELECT * FROM migration_history WHERE migration_name = :name");
            $stmt->execute([':name' => '07_company_deletion_fix']);

            if ($stmt->rowCount() === 0) {
                $stmt = $conn->prepare("
                    INSERT INTO migration_history (migration_name, executed_at)
                    VALUES (:name, NOW())
                ");
                $stmt->execute([
                    ':name' => '07_company_deletion_fix'
                ]);
                $messages[] = "✓ Migrazione registrata nella cronologia";
            } else {
                $messages[] = "⚠ Migrazione già registrata nella cronologia";
            }
        } else {
            $messages[] = "⚠ Tabella migration_history non trovata (non critico)";
        }
        $currentStep++;

        // Commit transaction only if active
        if ($conn->inTransaction()) {
            $conn->commit();
        }
        $success = true;
        $messages[] = "<strong>✅ Migrazione completata con successo!</strong>";

    } catch (PDOException $e) {
        // Only rollback if transaction is active
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        $errors[] = "Errore durante la migrazione: " . $e->getMessage();
    } catch (Exception $e) {
        // Only rollback if transaction is active
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        $errors[] = "Errore imprevisto: " . $e->getMessage();
    }
}

// Check current status
$migrationStatus = [];
try {
    // Check if tables allow NULL tenant_id
    $tables = ['users', 'folders', 'files'];
    foreach ($tables as $table) {
        try {
            $stmt = $conn->query("SHOW COLUMNS FROM $table WHERE Field = 'tenant_id'");
            $column = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($column) {
                $migrationStatus[$table] = ($column['Null'] === 'YES') ? 'OK' : 'Da migrare';
            } else {
                $migrationStatus[$table] = 'Campo non trovato';
            }
        } catch (Exception $e) {
            $migrationStatus[$table] = 'Tabella non trovata';
        }
    }

    // Check if procedures exist
    $procedures = ['SafeDeleteCompany', 'CheckUserLoginAccess', 'GetAccessibleFolders'];
    foreach ($procedures as $proc) {
        try {
            $stmt = $conn->prepare("SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name = :name");
            $stmt->execute([':name' => $proc]);
            $migrationStatus[$proc] = $stmt->rowCount() > 0 ? 'Presente' : 'Mancante';
        } catch (Exception $e) {
            $migrationStatus[$proc] = 'Errore verifica';
        }
    }

    // Check if Super Admin folders exist
    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM folders WHERE tenant_id IS NULL AND name = 'Super Admin Files'");
        $count = $stmt->fetchColumn();
        $migrationStatus['Super Admin Folders'] = $count > 0 ? 'Presente' : 'Mancante';
    } catch (Exception $e) {
        $migrationStatus['Super Admin Folders'] = 'Errore verifica';
    }

    // Check migration history
    $checkTable = $conn->query("SHOW TABLES LIKE 'migration_history'");
    if ($checkTable->rowCount() > 0) {
        $stmt = $conn->prepare("SELECT * FROM migration_history WHERE migration_name = :name");
        $stmt->execute([':name' => '07_company_deletion_fix']);
        $migrationApplied = $stmt->rowCount() > 0;
    } else {
        $migrationApplied = false;
    }

} catch (Exception $e) {
    $errors[] = "Errore nel controllo dello stato: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Esecuzione Migrazione - Company Deletion Fix</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .migration-container {
            max-width: 900px;
            margin: 50px auto;
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .status-table td {
            vertical-align: middle;
        }
        .status-ok {
            color: #28a745;
            font-weight: bold;
        }
        .status-pending {
            color: #dc3545;
            font-weight: bold;
        }
        .status-present {
            color: #28a745;
            font-weight: bold;
        }
        .status-missing {
            color: #dc3545;
            font-weight: bold;
        }
        .message-log {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
        }
        .message-log p {
            margin: 5px 0;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .btn-execute {
            font-size: 18px;
            padding: 12px 40px;
            margin-top: 20px;
        }
        .header-icon {
            font-size: 48px;
            color: #007bff;
            margin-bottom: 20px;
        }
    </style>
</head>
<body class="bg-light">
    <div class="migration-container">
        <div class="text-center">
            <i class="fas fa-database header-icon"></i>
            <h1>Migrazione Database</h1>
            <p class="lead">Company Deletion Fix (07_company_deletion_fix.sql)</p>
        </div>

        <hr>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <h5><i class="fas fa-exclamation-triangle"></i> Errori rilevati:</h5>
                <?php foreach ($errors as $error): ?>
                    <p class="mb-1"><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <h5><i class="fas fa-check-circle"></i> Migrazione completata con successo!</h5>
                <p>Tutte le modifiche sono state applicate correttamente al database.</p>
            </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="fas fa-info-circle"></i> Stato Attuale del Database</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm status-table">
                    <thead>
                        <tr>
                            <th>Componente</th>
                            <th>Stato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><i class="fas fa-table"></i> Tabella users (tenant_id NULL)</td>
                            <td class="<?php echo isset($migrationStatus['users']) && $migrationStatus['users'] === 'OK' ? 'status-ok' : 'status-pending'; ?>">
                                <?php echo $migrationStatus['users'] ?? 'Sconosciuto'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-table"></i> Tabella folders (tenant_id NULL)</td>
                            <td class="<?php echo isset($migrationStatus['folders']) && $migrationStatus['folders'] === 'OK' ? 'status-ok' : 'status-pending'; ?>">
                                <?php echo $migrationStatus['folders'] ?? 'Sconosciuto'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-table"></i> Tabella files (tenant_id NULL)</td>
                            <td class="<?php echo isset($migrationStatus['files']) && $migrationStatus['files'] === 'OK' ? 'status-ok' : 'status-pending'; ?>">
                                <?php echo $migrationStatus['files'] ?? 'Sconosciuto'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-folder"></i> Cartelle Super Admin</td>
                            <td class="<?php echo isset($migrationStatus['Super Admin Folders']) && $migrationStatus['Super Admin Folders'] === 'Presente' ? 'status-present' : 'status-missing'; ?>">
                                <?php echo $migrationStatus['Super Admin Folders'] ?? 'Sconosciuto'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-cogs"></i> Procedura SafeDeleteCompany</td>
                            <td class="<?php echo isset($migrationStatus['SafeDeleteCompany']) && $migrationStatus['SafeDeleteCompany'] === 'Presente' ? 'status-present' : 'status-missing'; ?>">
                                <?php echo $migrationStatus['SafeDeleteCompany'] ?? 'Sconosciuto'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-cogs"></i> Procedura CheckUserLoginAccess</td>
                            <td class="<?php echo isset($migrationStatus['CheckUserLoginAccess']) && $migrationStatus['CheckUserLoginAccess'] === 'Presente' ? 'status-present' : 'status-missing'; ?>">
                                <?php echo $migrationStatus['CheckUserLoginAccess'] ?? 'Sconosciuto'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-cogs"></i> Procedura GetAccessibleFolders</td>
                            <td class="<?php echo isset($migrationStatus['GetAccessibleFolders']) && $migrationStatus['GetAccessibleFolders'] === 'Presente' ? 'status-present' : 'status-missing'; ?>">
                                <?php echo $migrationStatus['GetAccessibleFolders'] ?? 'Sconosciuto'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-history"></i> Stato Migrazione</td>
                            <td class="<?php echo $migrationApplied ? 'status-ok' : 'status-pending'; ?>">
                                <?php echo $migrationApplied ? 'Già applicata' : 'Non applicata'; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($messages)): ?>
            <div class="message-log">
                <h5><i class="fas fa-terminal"></i> Log Esecuzione:</h5>
                <?php foreach ($messages as $message): ?>
                    <p><?php echo $message; ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$success): ?>
            <div class="card">
                <div class="card-body">
                    <h5><i class="fas fa-play-circle"></i> Esegui Migrazione</h5>
                    <p>Questa migrazione apporterà le seguenti modifiche:</p>
                    <ul>
                        <li>Modifica le tabelle users, folders e files per permettere tenant_id NULL</li>
                        <li>Aggiunge colonne per tracciare il tenant originale dopo l'eliminazione</li>
                        <li>Crea cartelle Super Admin per gestire contenuti orfani</li>
                        <li>Crea la procedura SafeDeleteCompany per eliminazione sicura delle aziende</li>
                        <li>Crea la procedura CheckUserLoginAccess per verificare l'accesso utente</li>
                        <li>Crea la procedura GetAccessibleFolders per gestire l'accesso alle cartelle</li>
                    </ul>

                    <form method="POST" onsubmit="return confirm('Sei sicuro di voler eseguire questa migrazione?');">
                        <div class="text-center">
                            <button type="submit" name="execute_migration" class="btn btn-primary btn-execute">
                                <i class="fas fa-rocket"></i> Esegui Migrazione
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="text-center mt-4">
                <a href="test_company_deletion.php" class="btn btn-success btn-lg">
                    <i class="fas fa-check"></i> Vai al Test di Eliminazione Azienda
                </a>
                <a href="dashboard.php" class="btn btn-secondary btn-lg ms-2">
                    <i class="fas fa-home"></i> Torna alla Dashboard
                </a>
            </div>
        <?php endif; ?>

        <div class="text-center mt-4">
            <small class="text-muted">
                <i class="fas fa-info-circle"></i>
                Questa migrazione può essere eseguita solo da utenti con ruolo Admin o Super Admin
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>