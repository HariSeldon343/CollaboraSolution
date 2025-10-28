<?php
/**
 * Task Notification System Migration Runner
 *
 * Executes the task notification schema migration and verifies installation
 *
 * Usage: php run_task_notification_migration.php
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from command line\n");
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  TASK NOTIFICATION SYSTEM - MIGRATION RUNNER                  ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Load configuration
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = Database::getInstance();

echo "[1/4] Loading migration file...\n";

$migrationFile = __DIR__ . '/database/migrations/task_notifications_schema.sql';

if (!file_exists($migrationFile)) {
    die("ERROR: Migration file not found at: $migrationFile\n");
}

$sql = file_get_contents($migrationFile);

if (empty($sql)) {
    die("ERROR: Migration file is empty\n");
}

echo "      ✓ Migration file loaded (" . number_format(strlen($sql)) . " bytes)\n\n";

echo "[2/4] Executing migration...\n";

try {
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) &&
                   !preg_match('/^--/', $stmt) &&
                   !preg_match('/^\/\*/', $stmt);
        }
    );

    $successCount = 0;
    $errorCount = 0;

    foreach ($statements as $statement) {
        // Skip comments and SELECT verification queries during migration
        if (preg_match('/^\s*(SELECT|SHOW)/i', $statement)) {
            continue;
        }

        try {
            $db->query($statement);
            $successCount++;

            // Show what was executed
            if (preg_match('/CREATE TABLE.*?`(\w+)`/i', $statement, $matches)) {
                echo "      ✓ Created table: {$matches[1]}\n";
            } elseif (preg_match('/INSERT.*?INTO\s+(\w+)/i', $statement, $matches)) {
                echo "      ✓ Inserted default data into: {$matches[1]}\n";
            }
        } catch (Exception $e) {
            // Ignore "table already exists" errors
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "      ✗ Error: " . $e->getMessage() . "\n";
                $errorCount++;
            }
        }
    }

    echo "\n";
    echo "      Migration executed: $successCount statements succeeded";
    if ($errorCount > 0) {
        echo ", $errorCount errors";
    }
    echo "\n\n";

} catch (Exception $e) {
    die("ERROR executing migration: " . $e->getMessage() . "\n");
}

echo "[3/4] Verifying installation...\n";

try {
    // Check task_notifications table
    $taskNotificationsCheck = $db->fetchOne(
        "SELECT COUNT(*) as count
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = 'collaboranexio'
           AND TABLE_NAME = 'task_notifications'"
    );

    if ($taskNotificationsCheck['count'] == 1) {
        echo "      ✓ Table 'task_notifications' exists\n";

        // Count indexes
        $indexes = $db->fetchAll(
            "SELECT COUNT(DISTINCT INDEX_NAME) as count
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = 'collaboranexio'
               AND TABLE_NAME = 'task_notifications'"
        );
        echo "      ✓ Indexes: {$indexes[0]['count']}\n";
    } else {
        throw new Exception("Table 'task_notifications' not found!");
    }

    // Check user_notification_preferences table
    $preferencesCheck = $db->fetchOne(
        "SELECT COUNT(*) as count
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = 'collaboranexio'
           AND TABLE_NAME = 'user_notification_preferences'"
    );

    if ($preferencesCheck['count'] == 1) {
        echo "      ✓ Table 'user_notification_preferences' exists\n";

        // Count default preferences created
        $defaultPrefs = $db->fetchOne(
            "SELECT COUNT(*) as count
             FROM user_notification_preferences
             WHERE deleted_at IS NULL"
        );
        echo "      ✓ Default preferences created for {$defaultPrefs['count']} users\n";
    } else {
        throw new Exception("Table 'user_notification_preferences' not found!");
    }

    // Verify foreign keys
    $fkCheck = $db->fetchAll(
        "SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME
         FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = 'collaboranexio'
           AND TABLE_NAME IN ('task_notifications', 'user_notification_preferences')
           AND REFERENCED_TABLE_NAME IS NOT NULL"
    );

    echo "      ✓ Foreign keys: " . count($fkCheck) . " constraints\n";

} catch (Exception $e) {
    die("\nERROR during verification: " . $e->getMessage() . "\n");
}

echo "\n";
echo "[4/4] Testing notification system...\n";

try {
    // Test insert into task_notifications (will be rolled back)
    $db->beginTransaction();

    $testUser = $db->fetchOne("SELECT id, tenant_id FROM users WHERE deleted_at IS NULL LIMIT 1");
    $testTask = $db->fetchOne("SELECT id FROM tasks WHERE deleted_at IS NULL LIMIT 1");

    if ($testUser && $testTask) {
        $testId = $db->insert('task_notifications', [
            'tenant_id' => $testUser['tenant_id'],
            'task_id' => $testTask['id'],
            'user_id' => $testUser['id'],
            'notification_type' => 'task_created',
            'recipient_email' => 'test@example.com',
            'email_subject' => 'Test notification',
            'delivery_status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        if ($testId) {
            echo "      ✓ Test notification inserted (ID: $testId)\n";
        }

        // Test user preferences retrieval
        $prefs = $db->fetchOne(
            "SELECT * FROM user_notification_preferences
             WHERE user_id = ? AND deleted_at IS NULL",
            [$testUser['id']]
        );

        if ($prefs) {
            echo "      ✓ User preferences retrieved successfully\n";
            echo "        - notify_task_assigned: " . ($prefs['notify_task_assigned'] ? 'Yes' : 'No') . "\n";
            echo "        - notify_task_created: " . ($prefs['notify_task_created'] ? 'Yes' : 'No') . "\n";
        }
    }

    // Rollback test data
    $db->rollback();
    echo "      ✓ Test data rolled back\n";

} catch (Exception $e) {
    $db->rollback();
    echo "      ⚠ Warning during testing: " . $e->getMessage() . "\n";
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  ✓ MIGRATION COMPLETED SUCCESSFULLY                           ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo "NEXT STEPS:\n";
echo "1. Email templates created in: /includes/email_templates/tasks/\n";
echo "2. TaskNotification helper class: /includes/task_notification_helper.php\n";
echo "3. API integrations in: /api/tasks/*.php\n";
echo "4. Test with: php test_task_notifications.php\n";
echo "\n";

echo "To rollback this migration:\n";
echo "  php -f database/migrations/task_notifications_schema_rollback.sql\n";
echo "\n";
