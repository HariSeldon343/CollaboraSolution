<?php
// Suppress all PHP warnings/notices from being output
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// Start output buffering to catch any unexpected output
ob_start();

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON headers immediately
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    // Include required files
    require_once '../../config.php';
    require_once '../../includes/db.php';

    // Initialize response
    $response = ['success' => false, 'message' => '', 'data' => null];

    // Check authentication
    if (!isset($_SESSION['user_id'])) {
        ob_clean();
        http_response_code(401);
        die(json_encode(['error' => 'Non autorizzato', 'success' => false]));
    }

    // Get current user details from session
    $currentUserId = $_SESSION['user_id'];
    $userRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'user';
    $isSuperAdmin = ($userRole === 'super_admin');
    $currentTenantId = $_SESSION['tenant_id'] ?? null;

    // Check if user is super admin
    if (!$isSuperAdmin) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Solo i Super Admin possono eliminare le aziende', 'success' => false]));
    }

    // Verify CSRF token from headers
    $headers = getallheaders();
    $csrfToken = $headers['X-CSRF-Token'] ?? '';
    if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido', 'success' => false]));
    }

    // Get input data (support both POST and JSON)
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $input = $_POST;
    }

    // Validate required fields
    $companyId = intval($input['id'] ?? $input['company_id'] ?? 0);
    if ($companyId <= 0) {
        ob_clean();
        http_response_code(400);
        die(json_encode(['error' => 'ID azienda non valido', 'success' => false]));
    }

    // Prevent deletion of company ID 1 (default/system company)
    if ($companyId === 1) {
        $response['message'] = 'Non è possibile eliminare l\'azienda di sistema';
        http_response_code(400);
        echo json_encode($response);
        exit;
    }

    // Get database instance
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Begin transaction
    $conn->beginTransaction();

    try {
        // Check if company exists
        $checkStmt = $conn->prepare("SELECT id, name,
                                     IFNULL(code, '') as code,
                                     IFNULL(status, 'active') as status,
                                     IFNULL(plan_type, 'basic') as plan_type
                                     FROM tenants WHERE id = :id");
        $checkStmt->bindParam(':id', $companyId, PDO::PARAM_INT);
        $checkStmt->execute();
        $company = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            $conn->rollBack();
            ob_clean();
            $response['message'] = 'Azienda non trovata';
            http_response_code(404);
            echo json_encode($response);
            exit;
        }

        // Check if company has associated users
        $userCheckStmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE tenant_id = :tenant_id");
        $userCheckStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
        $userCheckStmt->execute();
        $userCount = $userCheckStmt->fetch(PDO::FETCH_ASSOC)['count'];

        if ($userCount > 0) {
            // Option 1: Prevent deletion if there are users
            // Uncomment this block if you want to prevent deletion
            /*
            $db->rollBack();
            $response['message'] = 'Non è possibile eliminare un\'azienda con utenti associati. Elimina prima tutti gli utenti.';
            http_response_code(400);
            echo json_encode($response);
            exit;
            */

            // Option 2: Delete all associated users (cascade delete)
            // This is the current implementation
            $deleteUsersStmt = $conn->prepare("DELETE FROM users WHERE tenant_id = :tenant_id");
            $deleteUsersStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
            $deleteUsersStmt->execute();
        }

        // Delete associated data from other tables (if any)
        // Use try-catch for each table in case it doesn't exist
        try {
            // Delete files
            $deleteFilesStmt = $conn->prepare("DELETE FROM files WHERE tenant_id = :tenant_id");
            $deleteFilesStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
            $deleteFilesStmt->execute();
        } catch (PDOException $e) {
            // Table might not exist, continue
        }

        try {
            // Delete folders
            $deleteFoldersStmt = $conn->prepare("DELETE FROM folders WHERE tenant_id = :tenant_id");
            $deleteFoldersStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
            $deleteFoldersStmt->execute();
        } catch (PDOException $e) {
            // Table might not exist, continue
        }

        try {
            // Delete tasks
            $deleteTasksStmt = $conn->prepare("DELETE FROM tasks WHERE tenant_id = :tenant_id");
            $deleteTasksStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
            $deleteTasksStmt->execute();
        } catch (PDOException $e) {
            // Table might not exist, continue
        }

        try {
            // Delete calendars
            $deleteCalendarsStmt = $conn->prepare("DELETE FROM calendars WHERE tenant_id = :tenant_id");
            $deleteCalendarsStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
            $deleteCalendarsStmt->execute();
        } catch (PDOException $e) {
            // Table might not exist, continue
        }

        try {
            // Delete events
            $deleteEventsStmt = $conn->prepare("DELETE FROM events WHERE tenant_id = :tenant_id");
            $deleteEventsStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
            $deleteEventsStmt->execute();
        } catch (PDOException $e) {
            // Table might not exist, continue
        }

        try {
            // Delete audit logs for this company
            $deleteLogsStmt = $conn->prepare("DELETE FROM audit_logs WHERE tenant_id = :tenant_id");
            $deleteLogsStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
            $deleteLogsStmt->execute();
        } catch (PDOException $e) {
            // Table might not exist, continue
        }

        // Finally, delete the company
        $deleteStmt = $conn->prepare("DELETE FROM tenants WHERE id = :id");
        $deleteStmt->bindParam(':id', $companyId, PDO::PARAM_INT);

        if ($deleteStmt->execute()) {
            // Log the deletion (using current user's tenant_id for the log)
            try {
                $logQuery = "INSERT INTO audit_logs (user_id, tenant_id, action, entity_type, entity_id, description, ip_address, created_at)
                             VALUES (:user_id, :tenant_id, 'delete', 'company', :entity_id, :description, :ip, NOW())";

                $logStmt = $conn->prepare($logQuery);
                $logStmt->bindParam(':user_id', $currentUserId);
                $logStmt->bindParam(':tenant_id', $currentTenantId);
                $logStmt->bindParam(':entity_id', $companyId);
                $description = "Eliminata azienda: {$company['name']} ({$company['code']})";
                if ($userCount > 0) {
                    $description .= " e {$userCount} utenti associati";
                }
                $logStmt->bindParam(':description', $description);
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $logStmt->bindParam(':ip', $ipAddress);
                $logStmt->execute();
            } catch (PDOException $logError) {
                // Log error but don't fail the main operation
                error_log('Audit log error: ' . $logError->getMessage());
            }

            // Commit transaction
            $conn->commit();

            // Clean output buffer
            ob_clean();

            // Return success response
            $response['success'] = true;
            $response['message'] = 'Azienda eliminata con successo';
            $response['data'] = [
                'deleted_company_id' => $companyId,
                'deleted_users' => $userCount
            ];
            echo json_encode($response);
            exit();
        } else {
            $conn->rollBack();
            ob_clean();
            http_response_code(500);
            echo json_encode(['error' => 'Errore nell\'eliminazione dell\'azienda', 'success' => false]);
            exit();
        }

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log('Companies Delete PDO Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return user-friendly error
    http_response_code(500);
    echo json_encode(['error' => 'Errore database', 'success' => false]);
    exit();

} catch (Exception $e) {
    // Log the error
    error_log('Companies Delete Error: ' . $e->getMessage());

    // Clean any output buffer
    ob_clean();

    // Return generic error
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'success' => false]);
    exit();
}
?>