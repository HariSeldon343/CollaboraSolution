<?php
/**
 * Script to run database migrations for document approval system
 * Run this from command line or browser to apply the updates
 *
 * IMPORTANTE: Questo script è idempotente - può essere eseguito più volte in sicurezza
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Set execution time limit
set_time_limit(300);

// Track migration steps
$migrationSteps = [];
$completedSteps = [];
$failedSteps = [];

// Output function
function output($message, $type = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $color = match($type) {
        'success' => "\033[32m",  // Green
        'error' => "\033[31m",     // Red
        'warning' => "\033[33m",   // Yellow
        default => "\033[37m"      // White
    };
    $reset = "\033[0m";

    if (PHP_SAPI === 'cli') {
        echo "[{$timestamp}] {$color}{$message}{$reset}\n";
    } else {
        $htmlColor = match($type) {
            'success' => 'green',
            'error' => 'red',
            'warning' => 'orange',
            default => 'black'
        };
        echo "<div style='color: {$htmlColor};'>[{$timestamp}] {$message}</div>";
        flush();
    }
}

// Check if a table exists
function tableExists($pdo, $tableName) {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE :table");
        $stmt->execute([':table' => $tableName]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        return false;
    }
}

// Check if a column exists
function columnExists($pdo, $tableName, $columnName) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$tableName` LIKE :column");
        $stmt->execute([':column' => $columnName]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        return false;
    }
}

// Check if a constraint exists
function constraintExists($pdo, $tableName, $constraintName) {
    try {
        $stmt = $pdo->prepare("
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :table
            AND CONSTRAINT_NAME = :constraint
        ");
        $stmt->execute([
            ':table' => $tableName,
            ':constraint' => $constraintName
        ]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        return false;
    }
}

// HTML header for browser
if (PHP_SAPI !== 'cli') {
    echo "<!DOCTYPE html>
<html>
<head>
    <title>Database Migration - CollaboraNexio</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #333; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; }
        .progress { margin: 20px 0; padding: 10px; background: #f0f0f0; border-radius: 4px; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🚀 Database Migration for Document Approval System</h1>
        <pre>";
}

try {
    output("Starting database migration (idempotent mode)...", 'info');
    output("This script can be safely run multiple times", 'info');
    output(str_repeat("-", 60), 'info');

    // Get database connection
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Create migration tracking table if not exists (DDL auto-commits in MySQL)
    output("Setting up migration tracking...", 'info');
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS migration_history (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration_name VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_migration_name (migration_name)
            ) ENGINE=InnoDB
        ");
        output("Migration tracking table ready", 'info');
    } catch (Exception $e) {
        // Table might already exist, which is fine
        output("Migration tracking table check completed", 'info');
    }

    // Function to check if migration step was completed
    function isMigrationCompleted($pdo, $migrationName) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM migration_history WHERE migration_name = :name");
        $stmt->execute([':name' => $migrationName]);
        return $stmt->fetchColumn() > 0;
    }

    // Function to mark migration as completed
    function markMigrationCompleted($pdo, $migrationName) {
        try {
            // Start a new transaction for this insert if not already in one
            $inTransaction = $pdo->inTransaction();
            if (!$inTransaction) {
                $pdo->beginTransaction();
            }

            $stmt = $pdo->prepare("INSERT IGNORE INTO migration_history (migration_name) VALUES (:name)");
            $stmt->execute([':name' => $migrationName]);

            // Only commit if we started the transaction
            if (!$inTransaction) {
                $pdo->commit();
            }
        } catch (Exception $e) {
            // Rollback if we started the transaction
            if (!$inTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Ignore duplicate entry errors
        }
    }

    // ============================================
    // Step 1: Update role enum in users table
    // ============================================
    $migrationName = 'update_user_roles_enum';
    output("\n[Step 1] Updating role values in users table...", 'info');

    if (isMigrationCompleted($pdo, $migrationName)) {
        output("✓ Role enum already updated - skipping", 'warning');
        $completedSteps[] = $migrationName;
    } else {
        try {
            $pdo->beginTransaction();

            // Check current role values
            $stmt = $pdo->query("SELECT DISTINCT role FROM users");
            $currentRoles = $stmt->fetchAll(PDO::FETCH_COLUMN);
            output("Current roles found: " . implode(', ', $currentRoles), 'info');

            // First, update any non-standard role values to standard ones
            $mappings = [
                'guest' => 'user',
                'utente' => 'user',
                'administrator' => 'admin'
            ];

            foreach ($mappings as $old => $new) {
                $stmt = $pdo->prepare("UPDATE users SET role = :new WHERE role = :old");
                $stmt->execute([':new' => $new, ':old' => $old]);
                if ($stmt->rowCount() > 0) {
                    output("Updated {$stmt->rowCount()} users from role '{$old}' to '{$new}'", 'info');
                }
            }

            $pdo->commit();

            // Now alter the column (this will auto-commit)
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('user', 'manager', 'admin', 'super_admin') DEFAULT 'user'");

            markMigrationCompleted($pdo, $migrationName);
            output("✓ Role column updated successfully", 'success');
            $completedSteps[] = $migrationName;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            output("✗ Failed to update role column: " . $e->getMessage(), 'error');
            $failedSteps[] = $migrationName;
        }
    }

    // ============================================
    // Step 2: Add approval fields to files table
    // ============================================
    $migrationName = 'add_approval_fields_to_files';
    output("\n[Step 2] Adding approval fields to files table...", 'info');

    if (isMigrationCompleted($pdo, $migrationName)) {
        output("✓ Approval fields already added - skipping", 'warning');
        $completedSteps[] = $migrationName;
    } else {
        try {
            // Check which columns already exist
            $columnsToAdd = [];
            $allColumnsExist = true;

            if (!columnExists($pdo, 'files', 'status')) {
                $columnsToAdd[] = "ADD COLUMN status ENUM('bozza', 'in_approvazione', 'approvato', 'rifiutato') DEFAULT 'in_approvazione' AFTER is_public";
                $allColumnsExist = false;
            }
            if (!columnExists($pdo, 'files', 'approved_by')) {
                $columnsToAdd[] = "ADD COLUMN approved_by INT UNSIGNED NULL AFTER status";
                $allColumnsExist = false;
            }
            if (!columnExists($pdo, 'files', 'approved_at')) {
                $columnsToAdd[] = "ADD COLUMN approved_at TIMESTAMP NULL AFTER approved_by";
                $allColumnsExist = false;
            }
            if (!columnExists($pdo, 'files', 'rejection_reason')) {
                $columnsToAdd[] = "ADD COLUMN rejection_reason TEXT NULL AFTER approved_at";
                $allColumnsExist = false;
            }

            if (!empty($columnsToAdd)) {
                $alterQuery = "ALTER TABLE files " . implode(", ", $columnsToAdd);
                $pdo->exec($alterQuery);
                output("Added " . count($columnsToAdd) . " new columns to files table", 'info');
            } else if ($allColumnsExist) {
                output("All approval columns already exist", 'info');
            }

            // Add index if not exists
            try {
                $pdo->exec("ALTER TABLE files ADD INDEX idx_file_status (status)");
                output("Added index on status column", 'info');
            } catch (Exception $e) {
                // Index might already exist
            }

            // Add foreign key if not exists
            if (!constraintExists($pdo, 'files', 'fk_file_approved_by')) {
                try {
                    $pdo->exec("ALTER TABLE files ADD CONSTRAINT fk_file_approved_by
                               FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL");
                    output("Added foreign key constraint", 'info');
                } catch (Exception $e) {
                    // Constraint might already exist
                }
            }

            // Set existing files to approved if status column exists
            if (columnExists($pdo, 'files', 'status')) {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE files SET status = 'approvato' WHERE status IS NULL OR status = ''");
                $stmt->execute();
                if ($stmt->rowCount() > 0) {
                    output("Updated {$stmt->rowCount()} existing files to approved status", 'info');
                }
                $pdo->commit();
            }

            markMigrationCompleted($pdo, $migrationName);
            output("✓ File approval fields configured successfully", 'success');
            $completedSteps[] = $migrationName;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            output("✗ Failed to add approval fields: " . $e->getMessage(), 'error');
            $failedSteps[] = $migrationName;
        }
    }

    // ============================================
    // Step 3: Create user_tenant_access table
    // ============================================
    $migrationName = 'create_user_tenant_access_table';
    output("\n[Step 3] Creating user_tenant_access table...", 'info');

    if (isMigrationCompleted($pdo, $migrationName)) {
        output("✓ Table user_tenant_access already created - skipping", 'warning');
        $completedSteps[] = $migrationName;
    } else if (tableExists($pdo, 'user_tenant_access')) {
        // Table exists but not marked in migration history
        markMigrationCompleted($pdo, $migrationName);
        output("✓ Table user_tenant_access already exists - marking as completed", 'success');
        $completedSteps[] = $migrationName;
    } else {
        try {
            $pdo->exec("CREATE TABLE user_tenant_access (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                tenant_id INT UNSIGNED NOT NULL,
                granted_by INT UNSIGNED NULL,
                granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
                UNIQUE KEY uk_user_tenant (user_id, tenant_id),
                INDEX idx_access_user (user_id),
                INDEX idx_access_tenant (tenant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            markMigrationCompleted($pdo, $migrationName);
            output("✓ Table user_tenant_access created successfully", 'success');
            $completedSteps[] = $migrationName;
        } catch (Exception $e) {
            output("✗ Failed to create user_tenant_access table: " . $e->getMessage(), 'error');
            $failedSteps[] = $migrationName;
        }
    }

    // ============================================
    // Step 4: Create document_approvals table
    // ============================================
    $migrationName = 'create_document_approvals_table';
    output("\n[Step 4] Creating document_approvals table...", 'info');

    if (isMigrationCompleted($pdo, $migrationName)) {
        output("✓ Table document_approvals already created - skipping", 'warning');
        $completedSteps[] = $migrationName;
    } else if (tableExists($pdo, 'document_approvals')) {
        // Table exists but not marked in migration history
        markMigrationCompleted($pdo, $migrationName);
        output("✓ Table document_approvals already exists - marking as completed", 'success');
        $completedSteps[] = $migrationName;
    } else {
        try {
            $pdo->exec("CREATE TABLE document_approvals (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT UNSIGNED NOT NULL,
                file_id INT UNSIGNED NOT NULL,
                requested_by INT UNSIGNED NOT NULL,
                requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                reviewed_by INT UNSIGNED NULL,
                reviewed_at TIMESTAMP NULL,
                action ENUM('approvato', 'rifiutato', 'in_attesa') DEFAULT 'in_attesa',
                comments TEXT NULL,
                version_at_approval INT UNSIGNED NULL,
                metadata JSON NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
                FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_approval_tenant_status (tenant_id, action),
                INDEX idx_approval_file (file_id),
                INDEX idx_approval_reviewer (reviewed_by),
                INDEX idx_approval_requested (requested_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            markMigrationCompleted($pdo, $migrationName);
            output("✓ Table document_approvals created successfully", 'success');
            $completedSteps[] = $migrationName;
        } catch (Exception $e) {
            output("✗ Failed to create document_approvals table: " . $e->getMessage(), 'error');
            $failedSteps[] = $migrationName;
        }
    }

    // ============================================
    // Step 5: Create approval_notifications table
    // ============================================
    $migrationName = 'create_approval_notifications_table';
    output("\n[Step 5] Creating approval_notifications table...", 'info');

    if (isMigrationCompleted($pdo, $migrationName)) {
        output("✓ Table approval_notifications already created - skipping", 'warning');
        $completedSteps[] = $migrationName;
    } else if (tableExists($pdo, 'approval_notifications')) {
        // Table exists but not marked in migration history
        markMigrationCompleted($pdo, $migrationName);
        output("✓ Table approval_notifications already exists - marking as completed", 'success');
        $completedSteps[] = $migrationName;
    } else {
        try {
            $pdo->exec("CREATE TABLE approval_notifications (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT UNSIGNED NOT NULL,
                approval_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                notification_type ENUM('richiesta', 'approvato', 'rifiutato', 'promemoria') DEFAULT 'richiesta',
                is_read BOOLEAN DEFAULT FALSE,
                read_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                FOREIGN KEY (approval_id) REFERENCES document_approvals(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_notif_user_unread (user_id, is_read),
                INDEX idx_notif_approval (approval_id),
                INDEX idx_notif_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            markMigrationCompleted($pdo, $migrationName);
            output("✓ Table approval_notifications created successfully", 'success');
            $completedSteps[] = $migrationName;
        } catch (Exception $e) {
            output("✗ Failed to create approval_notifications table: " . $e->getMessage(), 'error');
            $failedSteps[] = $migrationName;
        }
    }

    // ============================================
    // Step 6: Populate user_tenant_access
    // ============================================
    $migrationName = 'populate_user_tenant_access';
    output("\n[Step 6] Setting up multi-tenant access for admin users...", 'info');

    if (isMigrationCompleted($pdo, $migrationName)) {
        output("✓ Multi-tenant access already configured - skipping", 'warning');
        $completedSteps[] = $migrationName;
    } else {
        try {
            // Check if table exists before populating
            if (!tableExists($pdo, 'user_tenant_access')) {
                output("Table user_tenant_access does not exist, skipping population", 'warning');
                $failedSteps[] = $migrationName;
            } else {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO user_tenant_access (user_id, tenant_id)
                    SELECT DISTINCT u.id, u.tenant_id
                    FROM users u
                    WHERE u.role IN ('admin', 'super_admin')
                    AND u.deleted_at IS NULL
                ");
                $stmt->execute();
                $rowCount = $stmt->rowCount();

                if ($rowCount > 0) {
                    output("Multi-tenant access configured for {$rowCount} users", 'info');
                } else {
                    output("No new admin users to configure (may already be configured)", 'info');
                }

                // Also grant super admins access to all active tenants
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO user_tenant_access (user_id, tenant_id)
                    SELECT u.id, t.id
                    FROM users u
                    CROSS JOIN tenants t
                    WHERE u.role = 'super_admin'
                    AND u.deleted_at IS NULL
                    AND t.status = 'active'
                ");
                $stmt->execute();
                if ($stmt->rowCount() > 0) {
                    output("Granted super admins access to all tenants ({$stmt->rowCount()} entries)", 'info');
                }

                $pdo->commit();
                markMigrationCompleted($pdo, $migrationName);
                output("✓ Multi-tenant access setup completed", 'success');
                $completedSteps[] = $migrationName;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            output("✗ Failed to setup multi-tenant access: " . $e->getMessage(), 'error');
            $failedSteps[] = $migrationName;
        }
    }

    // ============================================
    // Step 7: Create super admin user if needed
    // ============================================
    $migrationName = 'create_super_admin_user';
    output("\n[Step 7] Checking for super admin user...", 'info');

    if (isMigrationCompleted($pdo, $migrationName)) {
        output("✓ Super admin check already completed - skipping", 'warning');
        $completedSteps[] = $migrationName;
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'");
            $superAdminCount = $stmt->fetchColumn();

            if ($superAdminCount == 0) {
                output("Creating default super admin user...", 'warning');

                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        tenant_id, email, password_hash, first_name, last_name,
                        role, status, email_verified_at, created_at
                    ) VALUES (
                        1, 'superadmin@collaboranexio.com',
                        :password_hash,
                        'Super', 'Admin', 'super_admin', 'active', NOW(), NOW()
                    )
                ");

                // Default password: Admin123!
                $passwordHash = password_hash('Admin123!', PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt->execute([':password_hash' => $passwordHash]);

                $superAdminId = $pdo->lastInsertId();

                // Grant access to all tenants
                if (tableExists($pdo, 'user_tenant_access')) {
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO user_tenant_access (user_id, tenant_id)
                        SELECT :user_id, id FROM tenants WHERE status = 'active'
                    ");
                    $stmt->execute([':user_id' => $superAdminId]);
                }

                output("Super admin created: superadmin@collaboranexio.com", 'success');
                output("⚠️ DEFAULT PASSWORD: Admin123! - CHANGE IMMEDIATELY!", 'warning');
            } else {
                output("Super admin user already exists ({$superAdminCount} found)", 'info');
            }

            $pdo->commit();
            markMigrationCompleted($pdo, $migrationName);
            output("✓ Super admin check completed", 'success');
            $completedSteps[] = $migrationName;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            output("✗ Failed in super admin setup: " . $e->getMessage(), 'error');
            $failedSteps[] = $migrationName;
        }
    }

    // ============================================
    // Step 8: Update test user roles
    // ============================================
    $migrationName = 'update_test_user_roles';
    output("\n[Step 8] Updating test user roles...", 'info');

    if (isMigrationCompleted($pdo, $migrationName)) {
        output("✓ Test user roles already updated - skipping", 'warning');
        $completedSteps[] = $migrationName;
    } else {
        try {
            $pdo->beginTransaction();

            // Make user with ID 2 a manager if exists
            $stmt = $pdo->prepare("UPDATE users SET role = 'manager' WHERE id = 2 AND role = 'user'");
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                output("User ID 2 promoted to manager role", 'info');
            }

            $pdo->commit();
            markMigrationCompleted($pdo, $migrationName);
            output("✓ Test user roles updated", 'success');
            $completedSteps[] = $migrationName;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            output("✗ Failed to update test user roles: " . $e->getMessage(), 'error');
            $failedSteps[] = $migrationName;
        }
    }

    // ============================================
    // Migration Summary
    // ============================================
    output("\n" . str_repeat("=", 60), 'info');

    if (empty($failedSteps)) {
        output("✅ MIGRATION COMPLETED SUCCESSFULLY!", 'success');
    } else {
        output("⚠️ MIGRATION COMPLETED WITH ISSUES", 'warning');
    }

    output(str_repeat("=", 60), 'info');

    output("\nMigration Summary:", 'info');
    output("• Completed steps: " . count($completedSteps), 'success');
    output("• Failed steps: " . count($failedSteps), empty($failedSteps) ? 'success' : 'error');

    if (!empty($failedSteps)) {
        output("\nFailed Steps:", 'error');
        foreach ($failedSteps as $step) {
            output("  ✗ {$step}", 'error');
        }
        output("\nPlease review the errors and run the migration again.", 'warning');
        output("The migration is idempotent - it's safe to run multiple times.", 'info');
    } else {
        output("\nNew Features Enabled:", 'info');
        output("✓ Document approval workflow", 'success');
        output("✓ Multi-tenant user access", 'success');
        output("✓ Role hierarchy (user → manager → admin → super_admin)", 'success');
        output("✓ Tenant switching for admin/super_admin", 'success');

        output("\nRole Permissions:", 'info');
        output("• User: Basic access, view own files, NO approval rights", 'info');
        output("• Manager: Can approve/reject documents in their tenant", 'info');
        output("• Admin: Manager rights + access to multiple tenants", 'info');
        output("• Super Admin: Full system control, all tenants", 'info');

        // Check migration history
        output("\nMigration History:", 'info');
        $stmt = $pdo->query("SELECT migration_name, executed_at FROM migration_history ORDER BY executed_at");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            output("• {$row['migration_name']} - {$row['executed_at']}", 'info');
        }
    }

} catch (Exception $e) {
    output("\nCRITICAL ERROR: " . $e->getMessage(), 'error');
    output("Stack trace: " . $e->getTraceAsString(), 'error');

    if (isset($pdo) && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
            output("Transaction rolled back", 'error');
        } catch (Exception $rollbackError) {
            output("Failed to rollback: " . $rollbackError->getMessage(), 'error');
        }
    }
}

// Ensure all transactions are closed
if (isset($pdo) && $pdo->inTransaction()) {
    $pdo->rollBack();
}

// HTML footer for browser
if (PHP_SAPI !== 'cli') {
    echo "</pre>
    </div>
</body>
</html>";
}