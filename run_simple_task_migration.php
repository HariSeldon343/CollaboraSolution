<?php
/**
 * Simplified Task Management Schema Migration
 * Creates only essential tables without stored procedures
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "========================================\n";
echo "Simple Task Management Migration\n";
echo "========================================\n\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Create task_history table (was missing due to syntax error)
    echo "Creating task_history table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            task_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            action VARCHAR(50) NOT NULL,
            field_name VARCHAR(100) NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            CONSTRAINT fk_task_history_tenant FOREIGN KEY (tenant_id)
                REFERENCES tenants(id) ON DELETE CASCADE,
            CONSTRAINT fk_task_history_task FOREIGN KEY (task_id)
                REFERENCES tasks(id) ON DELETE CASCADE,
            CONSTRAINT fk_task_history_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE RESTRICT,
            INDEX idx_task_history_tenant (tenant_id, created_at),
            INDEX idx_task_history_task (task_id, created_at),
            INDEX idx_task_history_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Audit trail for task changes - NO soft delete'
    ");
    echo "✓ task_history table created\n\n";

    // Verify all tables exist
    echo "Verifying tables...\n";
    $tables = ['tasks', 'task_assignments', 'task_comments', 'task_history'];
    $allExist = true;

    foreach ($tables as $table) {
        $result = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
        if ($result) {
            echo "  ✓ Table '$table' exists\n";
        } else {
            echo "  ✗ Table '$table' NOT FOUND\n";
            $allExist = false;
        }
    }

    if (!$allExist) {
        echo "\n⚠ Some tables are missing. Please check the errors above.\n";
        exit(1);
    }

    echo "\n✅ All task management tables are ready!\n\n";

    // Insert demo data
    echo "Checking for demo data...\n";
    $existingTasks = $pdo->query("SELECT COUNT(*) as count FROM tasks")->fetch();

    if ($existingTasks['count'] == 0) {
        echo "Inserting demo tasks...\n";

        // Get a demo user
        $demoUser = $pdo->query("
            SELECT id, tenant_id FROM users
            WHERE deleted_at IS NULL
            ORDER BY id LIMIT 1
        ")->fetch();

        if ($demoUser) {
            $userId = $demoUser['id'];
            $tenantId = $demoUser['tenant_id'];

            // Insert demo tasks
            $pdo->exec("
                INSERT INTO tasks (tenant_id, title, description, status, priority, created_by, assigned_to)
                VALUES
                ($tenantId, 'Setup Project Environment', 'Install all necessary dependencies and tools', 'done', 'high', $userId, $userId),
                ($tenantId, 'Design Database Schema', 'Create comprehensive schema for task management', 'done', 'high', $userId, $userId),
                ($tenantId, 'Implement API Endpoints', 'Build REST API for task operations', 'in_progress', 'high', $userId, $userId),
                ($tenantId, 'Create Frontend Interface', 'Build user interface for task management', 'todo', 'medium', $userId, $userId),
                ($tenantId, 'Write Tests', 'Create comprehensive test suite', 'todo', 'medium', $userId, $userId)
            ");

            $taskCount = $pdo->query("SELECT COUNT(*) as count FROM tasks")->fetch()['count'];
            echo "✓ Created $taskCount demo tasks\n";
        } else {
            echo "  ⚠ No demo user found, skipping demo data\n";
        }
    } else {
        echo "  ✓ Demo data already exists ({$existingTasks['count']} tasks)\n";
    }

    echo "\n========================================\n";
    echo "Migration completed successfully!\n";
    echo "========================================\n";

} catch (Exception $e) {
    echo "\n========================================\n";
    echo "FATAL ERROR\n";
    echo "========================================\n";
    echo $e->getMessage() . "\n";
    exit(1);
}
