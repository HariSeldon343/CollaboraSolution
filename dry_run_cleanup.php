<?php
/**
 * Dry Run User Cleanup
 *
 * Simulates the cleanup process without actually deleting anything.
 * Shows exactly what would be deleted in each phase.
 */

require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "=== DRY RUN: USER CLEANUP SIMULATION ===\n\n";

    // Get users that would be deleted
    $sql = "SELECT id, email, name, tenant_id, deleted_at
            FROM users
            WHERE deleted_at IS NOT NULL
            AND deleted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";

    $stmt = $pdo->query($sql);
    $usersToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($usersToDelete)) {
        echo "No users eligible for cleanup (must be soft-deleted for > 7 days).\n";
        exit(0);
    }

    echo "Users that would be permanently deleted: " . count($usersToDelete) . "\n\n";

    $userIds = array_column($usersToDelete, 'id');
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    foreach ($usersToDelete as $user) {
        echo "User ID {$user['id']}: {$user['email']} ({$user['name']})\n";
    }

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "PHASE 1: DELETE RECORDS WITH RESTRICT CONSTRAINTS\n";
    echo str_repeat("=", 60) . "\n\n";

    $phase1Queries = [
        "chat_channels (owner_id)" => "SELECT COUNT(*) FROM chat_channels WHERE owner_id IN ($placeholders)",
        "file_versions (uploaded_by)" => "SELECT COUNT(*) FROM file_versions WHERE uploaded_by IN ($placeholders)",
        "folders (owner_id)" => "SELECT COUNT(*) FROM folders WHERE owner_id IN ($placeholders)",
        "projects (owner_id)" => "SELECT COUNT(*) FROM projects WHERE owner_id IN ($placeholders)"
    ];

    $totalPhase1 = 0;
    foreach ($phase1Queries as $label => $query) {
        $stmt = $pdo->prepare($query);
        $stmt->execute($userIds);
        $count = $stmt->fetchColumn();
        if ($count > 0) {
            echo "DELETE FROM {$label}: {$count} record(s)\n";
            $totalPhase1 += $count;
        }
    }

    $phase1Updates = [
        "project_members (added_by → NULL)" => "SELECT COUNT(*) FROM project_members WHERE added_by IN ($placeholders)",
        "tasks (created_by → NULL)" => "SELECT COUNT(*) FROM tasks WHERE created_by IN ($placeholders)",
        "task_assignments (assigned_by → NULL)" => "SELECT COUNT(*) FROM task_assignments WHERE assigned_by IN ($placeholders)"
    ];

    foreach ($phase1Updates as $label => $query) {
        $stmt = $pdo->prepare($query);
        $stmt->execute($userIds);
        $count = $stmt->fetchColumn();
        if ($count > 0) {
            echo "UPDATE {$label}: {$count} record(s)\n";
        }
    }

    echo "\nPhase 1 Total Deletions: {$totalPhase1}\n";

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "PHASE 2: DELETE RECORDS WITH CASCADE CONSTRAINTS\n";
    echo str_repeat("=", 60) . "\n\n";

    $phase2Queries = [
        "project_members (user_id)" => "SELECT COUNT(*) FROM project_members WHERE user_id IN ($placeholders)",
        "task_assignments (user_id)" => "SELECT COUNT(*) FROM task_assignments WHERE user_id IN ($placeholders)",
        "task_comments (user_id)" => "SELECT COUNT(*) FROM task_comments WHERE user_id IN ($placeholders)",
        "chat_channel_members (user_id)" => "SELECT COUNT(*) FROM chat_channel_members WHERE user_id IN ($placeholders)",
        "chat_messages (user_id)" => "SELECT COUNT(*) FROM chat_messages WHERE user_id IN ($placeholders)",
        "chat_message_reads (user_id)" => "SELECT COUNT(*) FROM chat_message_reads WHERE user_id IN ($placeholders)",
        "file_shares (shared_by OR shared_with)" => "SELECT COUNT(*) FROM file_shares WHERE shared_by IN ($placeholders) OR shared_with IN ($placeholders)",
        "calendar_events (organizer_id)" => "SELECT COUNT(*) FROM calendar_events WHERE organizer_id IN ($placeholders)",
        "calendar_shares (user_id)" => "SELECT COUNT(*) FROM calendar_shares WHERE user_id IN ($placeholders)",
        "document_approvals (requested_by)" => "SELECT COUNT(*) FROM document_approvals WHERE requested_by IN ($placeholders)",
        "approval_notifications (user_id)" => "SELECT COUNT(*) FROM approval_notifications WHERE user_id IN ($placeholders)",
        "password_expiry_notifications (user_id)" => "SELECT COUNT(*) FROM password_expiry_notifications WHERE user_id IN ($placeholders)",
        "user_permissions (user_id)" => "SELECT COUNT(*) FROM user_permissions WHERE user_id IN ($placeholders)",
        "user_tenant_access (user_id)" => "SELECT COUNT(*) FROM user_tenant_access WHERE user_id IN ($placeholders)"
    ];

    $totalPhase2 = 0;
    foreach ($phase2Queries as $label => $query) {
        // Handle special case for file_shares with two parameters
        if (strpos($label, 'file_shares') !== false) {
            $stmt = $pdo->prepare($query);
            $stmt->execute(array_merge($userIds, $userIds));
        } else {
            $stmt = $pdo->prepare($query);
            $stmt->execute($userIds);
        }
        $count = $stmt->fetchColumn();
        if ($count > 0) {
            echo "DELETE FROM {$label}: {$count} record(s)\n";
            $totalPhase2 += $count;
        }
    }

    echo "\nPhase 2 Total Deletions: {$totalPhase2}\n";

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "PHASE 3: UPDATE RECORDS WITH SET NULL CONSTRAINTS\n";
    echo str_repeat("=", 60) . "\n\n";

    $phase3Queries = [
        "document_approvals (reviewed_by → NULL)" => "SELECT COUNT(*) FROM document_approvals WHERE reviewed_by IN ($placeholders)",
        "user_permissions (granted_by → NULL)" => "SELECT COUNT(*) FROM user_permissions WHERE granted_by IN ($placeholders)",
        "user_tenant_access (granted_by → NULL)" => "SELECT COUNT(*) FROM user_tenant_access WHERE granted_by IN ($placeholders)",
        "files (uploaded_by → NULL)" => "SELECT COUNT(*) FROM files WHERE uploaded_by IN ($placeholders)",
        "tasks (assigned_to → NULL)" => "SELECT COUNT(*) FROM tasks WHERE assigned_to IN ($placeholders)",
        "audit_logs (user_id → NULL)" => "SELECT COUNT(*) FROM audit_logs WHERE user_id IN ($placeholders)"
    ];

    foreach ($phase3Queries as $label => $query) {
        $stmt = $pdo->prepare($query);
        $stmt->execute($userIds);
        $count = $stmt->fetchColumn();
        if ($count > 0) {
            echo "UPDATE {$label}: {$count} record(s)\n";
        }
    }

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "PHASE 4: DELETE USER RECORDS\n";
    echo str_repeat("=", 60) . "\n\n";

    echo "DELETE FROM users: " . count($usersToDelete) . " record(s)\n";

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "SUMMARY\n";
    echo str_repeat("=", 60) . "\n\n";

    $totalRecords = $totalPhase1 + $totalPhase2 + count($usersToDelete);
    echo "Total records that would be deleted: {$totalRecords}\n";
    echo "Total users that would be removed: " . count($usersToDelete) . "\n\n";

    echo "This is a DRY RUN - no data has been modified.\n";
    echo "To execute the actual cleanup, use the API endpoint:\n";
    echo "POST /api/users/cleanup_deleted.php\n\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
