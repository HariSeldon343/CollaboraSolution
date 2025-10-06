<?php
// PRIMA COSA: Includi session_init.php per configurare sessione correttamente
require_once __DIR__ . '/../../includes/session_init.php';

// POI: Headers (DOPO session_start di session_init.php)
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once '../../config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

// Authentication validation
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Non autorizzato']));
}

// Tenant isolation
$tenant_id = $_SESSION['tenant_id'] ?? null;
if (!$tenant_id) {
    http_response_code(400);
    die(json_encode(['error' => 'Tenant non specificato']));
}

// Input sanitization
$input = json_decode(file_get_contents('php://input'), true);
$file_id = filter_var($input['file_id'] ?? 0, FILTER_VALIDATE_INT);
$comments = filter_var($input['comments'] ?? '', FILTER_SANITIZE_STRING);

try {
    // Validate input
    if (!$file_id) {
        throw new Exception('ID file non valido');
    }

    // Get current user role
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'] ?? 'user';

    // Check permission - only manager, admin and super_admin can approve
    if (!in_array($user_role, ['manager', 'admin', 'super_admin'])) {
        throw new Exception('Non hai i permessi per approvare documenti');
    }

    // Get database connection
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Begin transaction
    $pdo->beginTransaction();

    // Check if file exists and belongs to accessible tenant
    // Schema: files table uses uploaded_by (not owner_id)
    $fileQuery = "SELECT f.*, u.first_name, u.last_name, u.email
                  FROM files f
                  LEFT JOIN users u ON f.uploaded_by = u.id
                  WHERE f.id = :file_id AND f.deleted_at IS NULL";

    // Add tenant check for non-super_admin users
    if ($user_role !== 'super_admin') {
        $fileQuery .= " AND f.tenant_id = :tenant_id";
    }

    $stmt = $pdo->prepare($fileQuery);
    $stmt->execute([
        ':file_id' => $file_id,
        ...($user_role !== 'super_admin' ? [':tenant_id' => $tenant_id] : [])
    ]);

    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        throw new Exception('File non trovato o non accessibile');
    }

    // Check if file is pending approval
    if ($file['status'] === 'approvato') {
        throw new Exception('Questo file è già stato approvato');
    }

    if ($file['status'] === 'rifiutato') {
        throw new Exception('Questo file è stato rifiutato. Richiedi una nuova approvazione');
    }

    // Update file status to approved
    $updateQuery = "UPDATE files
                    SET status = 'approvato',
                        approved_by = :approved_by,
                        approved_at = NOW(),
                        rejection_reason = NULL,
                        updated_at = NOW()
                    WHERE id = :file_id";

    $stmt = $pdo->prepare($updateQuery);
    $stmt->execute([
        ':approved_by' => $user_id,
        ':file_id' => $file_id
    ]);

    // Check if there's a pending approval request
    $approvalQuery = "SELECT id FROM document_approvals
                      WHERE file_id = :file_id
                      AND action = 'in_attesa'
                      ORDER BY requested_at DESC
                      LIMIT 1";

    $stmt = $pdo->prepare($approvalQuery);
    $stmt->execute([':file_id' => $file_id]);
    $approval = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($approval) {
        // Update approval record
        $updateApprovalQuery = "UPDATE document_approvals
                                SET reviewed_by = :reviewed_by,
                                    reviewed_at = NOW(),
                                    action = 'approvato',
                                    comments = :comments
                                WHERE id = :approval_id";

        $stmt = $pdo->prepare($updateApprovalQuery);
        $stmt->execute([
            ':reviewed_by' => $user_id,
            ':comments' => $comments,
            ':approval_id' => $approval['id']
        ]);

        // Create notification for requester
        $notifQuery = "INSERT INTO approval_notifications
                       (tenant_id, approval_id, user_id, notification_type)
                       SELECT :tenant_id, :approval_id, requested_by, 'approvato'
                       FROM document_approvals
                       WHERE id = :approval_id2";

        $stmt = $pdo->prepare($notifQuery);
        $stmt->execute([
            ':tenant_id' => $file['tenant_id'],
            ':approval_id' => $approval['id'],
            ':approval_id2' => $approval['id']
        ]);
    } else {
        // Create new approval record if none exists
        $createApprovalQuery = "INSERT INTO document_approvals
                                (tenant_id, file_id, requested_by, reviewed_by,
                                 reviewed_at, action, comments)
                                VALUES (:tenant_id, :file_id, :requested_by,
                                        :reviewed_by, NOW(), 'approvato', :comments)";

        // Schema: files table uses uploaded_by (not owner_id)
        $stmt = $pdo->prepare($createApprovalQuery);
        $stmt->execute([
            ':tenant_id' => $file['tenant_id'],
            ':file_id' => $file_id,
            ':requested_by' => $file['uploaded_by'],
            ':reviewed_by' => $user_id,
            ':comments' => $comments
        ]);
    }

    // Create general notification for file owner
    $ownerNotifQuery = "INSERT INTO notifications
                        (tenant_id, user_id, type, title, message, data)
                        VALUES (:tenant_id, :user_id, 'document_approved',
                                'Documento Approvato',
                                :message, :data)";

    $message = "Il tuo documento '{$file['name']}' è stato approvato.";
    $data = json_encode([
        'file_id' => $file_id,
        'file_name' => $file['name'],
        'approved_by' => $user_id,
        'comments' => $comments
    ]);

    // Schema: files table uses uploaded_by (not owner_id)
    $stmt = $pdo->prepare($ownerNotifQuery);
    $stmt->execute([
        ':tenant_id' => $file['tenant_id'],
        ':user_id' => $file['uploaded_by'],
        ':message' => $message,
        ':data' => $data
    ]);

    // Log the action
    $logQuery = "INSERT INTO audit_logs
                 (tenant_id, user_id, action, entity_type, entity_id, details, ip_address)
                 VALUES (:tenant_id, :user_id, 'approve', 'file', :entity_id, :details, :ip_address)";

    $details = json_encode([
        'file_name' => $file['name'],
        'file_owner' => $file['first_name'] . ' ' . $file['last_name'],
        'comments' => $comments
    ]);

    $stmt = $pdo->prepare($logQuery);
    $stmt->execute([
        ':tenant_id' => $file['tenant_id'],
        ':user_id' => $user_id,
        ':entity_id' => $file_id,
        ':details' => $details,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    // Commit transaction
    $pdo->commit();

    // Success response
    echo json_encode([
        'success' => true,
        'message' => 'Documento approvato con successo',
        'data' => [
            'file_id' => $file_id,
            'status' => 'approvato',
            'approved_at' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Document Approve Error: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => $e->getMessage()]));
}