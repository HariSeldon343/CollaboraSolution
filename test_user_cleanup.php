<?php
/**
 * Test Script: User Cleanup Verification
 *
 * This script helps verify that the user cleanup process handles
 * all foreign key constraints properly.
 */

require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "=== USER CLEANUP TEST UTILITY ===\n\n";

    // Get all soft-deleted users
    $sql = "SELECT id, email, name, tenant_id, deleted_at,
            DATEDIFF(NOW(), deleted_at) as days_since_deletion
            FROM users
            WHERE deleted_at IS NOT NULL
            ORDER BY deleted_at ASC";

    $stmt = $pdo->query($sql);
    $deletedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($deletedUsers)) {
        echo "No soft-deleted users found.\n";
        exit(0);
    }

    echo "Found " . count($deletedUsers) . " soft-deleted user(s):\n\n";

    foreach ($deletedUsers as $user) {
        echo "User ID: {$user['id']}\n";
        echo "  Email: {$user['email']}\n";
        echo "  Name: {$user['name']}\n";
        echo "  Tenant: {$user['tenant_id']}\n";
        echo "  Deleted: {$user['deleted_at']} ({$user['days_since_deletion']} days ago)\n";

        // Check for related records in tables with RESTRICT constraints
        $constraints = [
            'chat_channels' => 'owner_id',
            'file_versions' => 'uploaded_by',
            'folders' => 'owner_id',
            'projects' => 'owner_id',
            'project_members (added_by)' => 'added_by',
            'tasks (created_by)' => 'created_by',
            'task_assignments (assigned_by)' => 'assigned_by'
        ];

        echo "  Related records with RESTRICT constraints:\n";

        foreach ($constraints as $tableName => $columnName) {
            // Extract actual table name (in case of alias like "table (column)")
            $actualTable = explode(' ', $tableName)[0];
            $actualColumn = str_replace(['(', ')'], '', explode(' ', $columnName)[0]);

            $checkSql = "SELECT COUNT(*) as count FROM `{$actualTable}` WHERE `{$actualColumn}` = ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$user['id']]);
            $count = $checkStmt->fetchColumn();

            if ($count > 0) {
                echo "    - {$tableName}: {$count} record(s)\n";
            }
        }

        // Check for related records in tables with CASCADE
        $cascadeTables = [
            'approval_notifications' => 'user_id',
            'calendar_events' => 'organizer_id',
            'calendar_shares' => 'user_id',
            'chat_channel_members' => 'user_id',
            'chat_messages' => 'user_id',
            'chat_message_reads' => 'user_id',
            'document_approvals' => 'requested_by',
            'file_shares (shared_by)' => 'shared_by',
            'file_shares (shared_with)' => 'shared_with',
            'password_expiry_notifications' => 'user_id',
            'project_members' => 'user_id',
            'task_assignments' => 'user_id',
            'task_comments' => 'user_id',
            'user_permissions' => 'user_id',
            'user_tenant_access' => 'user_id'
        ];

        echo "  Related records with CASCADE:\n";

        foreach ($cascadeTables as $tableName => $columnName) {
            $actualTable = explode(' ', $tableName)[0];
            $actualColumn = str_replace(['(', ')'], '', explode(' ', $columnName)[0]);

            $checkSql = "SELECT COUNT(*) as count FROM `{$actualTable}` WHERE `{$actualColumn}` = ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$user['id']]);
            $count = $checkStmt->fetchColumn();

            if ($count > 0) {
                echo "    - {$tableName}: {$count} record(s)\n";
            }
        }

        echo "\n";
    }

    // Summary
    echo "=== CLEANUP ELIGIBILITY ===\n\n";

    $eligibleCount = 0;
    foreach ($deletedUsers as $user) {
        if ($user['days_since_deletion'] >= 7) {
            $eligibleCount++;
        }
    }

    echo "Users eligible for cleanup (deleted > 7 days): {$eligibleCount}\n";
    echo "Users not yet eligible: " . (count($deletedUsers) - $eligibleCount) . "\n\n";

    if ($eligibleCount > 0) {
        echo "To permanently delete eligible users, call the API endpoint:\n";
        echo "POST /api/users/cleanup_deleted.php\n";
        echo "(Requires admin or super_admin authentication)\n";
    }

    echo "\n=== TEST COMPLETE ===\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
