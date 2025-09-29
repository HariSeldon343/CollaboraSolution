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
    // Check both possible session keys for role
    $userRole = $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'user';
    $isSuperAdmin = ($userRole === 'super_admin');
    $currentTenantId = $_SESSION['tenant_id'] ?? null;

    // Debug logging
    error_log('Delete API - User ID: ' . $currentUserId . ', Role: ' . $userRole . ', Is Super Admin: ' . ($isSuperAdmin ? 'yes' : 'no'));

    // Check if user is super admin
    if (!$isSuperAdmin) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Solo i Super Admin possono eliminare le aziende', 'success' => false]));
    }

    // Get input data - FormData sends as POST parameters
    $input = $_POST;

    // If no POST data, try JSON body
    if (empty($input)) {
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $input = $jsonInput;
        }
    }

    // Verify CSRF token from POST data (FormData) or headers
    $csrfToken = $input['csrf_token'] ?? null;

    // If not in POST, check headers
    if (empty($csrfToken)) {
        $headers = getallheaders();
        $csrfToken = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
    }

    // Debug logging
    error_log('Delete API - CSRF Token received: ' . substr($csrfToken, 0, 10) . '...');
    error_log('Delete API - Session CSRF Token: ' . substr($_SESSION['csrf_token'] ?? '', 0, 10) . '...');

    if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF non valido', 'success' => false, 'debug' => 'CSRF mismatch']));
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

    // Check if stored procedure exists, use it if available
    $checkProcedure = $conn->prepare("SELECT COUNT(*) as count FROM information_schema.ROUTINES
                                      WHERE ROUTINE_SCHEMA = 'collaboranexio'
                                      AND ROUTINE_NAME = 'SafeDeleteCompany'");
    $checkProcedure->execute();
    $procedureExists = $checkProcedure->fetch(PDO::FETCH_ASSOC)['count'] > 0;

    if ($procedureExists) {
        // Use the new stored procedure for safe deletion
        try {
            $stmt = $conn->prepare("CALL SafeDeleteCompany(:company_id, :deleted_by, @success, @message)");
            $stmt->bindParam(':company_id', $companyId, PDO::PARAM_INT);
            $stmt->bindParam(':deleted_by', $currentUserId, PDO::PARAM_INT);
            $stmt->execute();

            // Get the output parameters
            $result = $conn->query("SELECT @success as success, @message as message")->fetch(PDO::FETCH_ASSOC);

            ob_clean();

            if ($result['success']) {
                // Log the deletion
                try {
                    $logQuery = "INSERT INTO audit_logs (user_id, tenant_id, action, entity_type, entity_id, description, ip_address, created_at)
                                 VALUES (:user_id, :tenant_id, 'delete', 'company', :entity_id, :description, :ip, NOW())";

                    $logStmt = $conn->prepare($logQuery);
                    $logStmt->bindParam(':user_id', $currentUserId);
                    $logStmt->bindParam(':tenant_id', $currentTenantId);
                    $logStmt->bindParam(':entity_id', $companyId);
                    $logStmt->bindParam(':description', $result['message']);
                    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $logStmt->bindParam(':ip', $ipAddress);
                    $logStmt->execute();
                } catch (PDOException $logError) {
                    error_log('Audit log error: ' . $logError->getMessage());
                }

                $response['success'] = true;
                $response['message'] = $result['message'];
                $response['data'] = ['deleted_company_id' => $companyId];
                echo json_encode($response);
            } else {
                http_response_code(400);
                echo json_encode(['error' => $result['message'], 'success' => false]);
            }
            exit();

        } catch (PDOException $e) {
            error_log('SafeDeleteCompany procedure error: ' . $e->getMessage());
            // Fall back to original method if procedure fails
        }
    }

    // Original deletion logic (fallback if stored procedure doesn't exist)
    // Begin transaction
    $conn->beginTransaction();

    try {
        // Check if company exists
        $checkStmt = $conn->prepare("SELECT id, name,
                                     IFNULL(denominazione, name) as denominazione,
                                     IFNULL(status, 'active') as status,
                                     settings
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

        // Handle folders - reassign to Super Admin (NULL tenant_id)
        try {
            // First check if nullable tenant_id is supported
            $checkColumn = $conn->prepare("SELECT IS_NULLABLE FROM information_schema.COLUMNS
                                          WHERE TABLE_SCHEMA = 'collaboranexio'
                                          AND TABLE_NAME = 'folders'
                                          AND COLUMN_NAME = 'tenant_id'");
            $checkColumn->execute();
            $isNullable = $checkColumn->fetch(PDO::FETCH_ASSOC)['IS_NULLABLE'] ?? 'NO';

            if ($isNullable === 'YES') {
                // Update folders to NULL tenant_id (reassign to Super Admin)
                $updateFoldersStmt = $conn->prepare("UPDATE folders
                                                     SET tenant_id = NULL,
                                                         original_tenant_id = :tenant_id
                                                     WHERE tenant_id = :tenant_id2");
                $updateFoldersStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
                $updateFoldersStmt->bindParam(':tenant_id2', $companyId, PDO::PARAM_INT);
                $updateFoldersStmt->execute();
                $foldersReassigned = $updateFoldersStmt->rowCount();

                // Update files to NULL tenant_id
                $updateFilesStmt = $conn->prepare("UPDATE files
                                                   SET tenant_id = NULL,
                                                       original_tenant_id = :tenant_id
                                                   WHERE tenant_id = :tenant_id2");
                $updateFilesStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
                $updateFilesStmt->bindParam(':tenant_id2', $companyId, PDO::PARAM_INT);
                $updateFilesStmt->execute();
                $filesReassigned = $updateFilesStmt->rowCount();
            } else {
                // Delete folders and files (old behavior)
                $deleteFoldersStmt = $conn->prepare("DELETE FROM folders WHERE tenant_id = :tenant_id");
                $deleteFoldersStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
                $deleteFoldersStmt->execute();

                $deleteFilesStmt = $conn->prepare("DELETE FROM files WHERE tenant_id = :tenant_id");
                $deleteFilesStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
                $deleteFilesStmt->execute();

                $foldersReassigned = 0;
                $filesReassigned = 0;
            }
        } catch (PDOException $e) {
            // If tables don't exist, continue
            $foldersReassigned = 0;
            $filesReassigned = 0;
        }

        // Handle users - update tenant_id to NULL for regular users, delete admins
        try {
            // Check if nullable tenant_id is supported for users
            $checkUserColumn = $conn->prepare("SELECT IS_NULLABLE FROM information_schema.COLUMNS
                                              WHERE TABLE_SCHEMA = 'collaboranexio'
                                              AND TABLE_NAME = 'users'
                                              AND COLUMN_NAME = 'tenant_id'");
            $checkUserColumn->execute();
            $userIsNullable = $checkUserColumn->fetch(PDO::FETCH_ASSOC)['IS_NULLABLE'] ?? 'NO';

            if ($userIsNullable === 'YES') {
                // Update regular users to NULL tenant_id
                $updateUsersStmt = $conn->prepare("UPDATE users
                                                   SET tenant_id = NULL,
                                                       original_tenant_id = :tenant_id
                                                   WHERE tenant_id = :tenant_id2
                                                   AND role IN ('user', 'manager')");
                $updateUsersStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
                $updateUsersStmt->bindParam(':tenant_id2', $companyId, PDO::PARAM_INT);
                $updateUsersStmt->execute();
                $usersUpdated = $updateUsersStmt->rowCount();

                // Delete admin users
                $deleteAdminsStmt = $conn->prepare("DELETE FROM users
                                                    WHERE tenant_id = :tenant_id
                                                    AND role IN ('admin', 'tenant_admin')");
                $deleteAdminsStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
                $deleteAdminsStmt->execute();
                $adminsDeleted = $deleteAdminsStmt->rowCount();
            } else {
                // Delete all users (old behavior)
                $deleteUsersStmt = $conn->prepare("DELETE FROM users WHERE tenant_id = :tenant_id");
                $deleteUsersStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
                $deleteUsersStmt->execute();
                $usersUpdated = 0;
                $adminsDeleted = $deleteUsersStmt->rowCount();
            }
        } catch (PDOException $e) {
            // If error, just delete all users
            $deleteUsersStmt = $conn->prepare("DELETE FROM users WHERE tenant_id = :tenant_id");
            $deleteUsersStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
            $deleteUsersStmt->execute();
            $usersUpdated = 0;
            $adminsDeleted = $deleteUsersStmt->rowCount();
        }

        // Delete other dependent data
        $tablesToClean = ['tasks', 'calendar_events', 'chat_messages', 'chat_channels',
                         'projects', 'notifications', 'audit_logs', 'user_tenant_access'];

        foreach ($tablesToClean as $table) {
            try {
                $deleteStmt = $conn->prepare("DELETE FROM $table WHERE tenant_id = :tenant_id");
                $deleteStmt->bindParam(':tenant_id', $companyId, PDO::PARAM_INT);
                $deleteStmt->execute();
            } catch (PDOException $e) {
                // Table might not exist, continue
            }
        }

        // Finally, delete the company
        $deleteStmt = $conn->prepare("DELETE FROM tenants WHERE id = :id");
        $deleteStmt->bindParam(':id', $companyId, PDO::PARAM_INT);

        if ($deleteStmt->execute()) {
            // Log the deletion
            try {
                $logQuery = "INSERT INTO audit_logs (user_id, tenant_id, action, entity_type, entity_id, description, ip_address, created_at)
                             VALUES (:user_id, :tenant_id, 'delete', 'company', :entity_id, :description, :ip, NOW())";

                $logStmt = $conn->prepare($logQuery);
                $logStmt->bindParam(':user_id', $currentUserId);
                $logStmt->bindParam(':tenant_id', $currentTenantId);
                $logStmt->bindParam(':entity_id', $companyId);

                $description = "Eliminata azienda: {$company['denominazione']}";
                if ($foldersReassigned > 0) {
                    $description .= ", {$foldersReassigned} cartelle riassegnate";
                }
                if ($filesReassigned > 0) {
                    $description .= ", {$filesReassigned} file riassegnati";
                }
                if ($usersUpdated > 0) {
                    $description .= ", {$usersUpdated} utenti aggiornati";
                }
                if ($adminsDeleted > 0) {
                    $description .= ", {$adminsDeleted} admin eliminati";
                }

                $logStmt->bindParam(':description', $description);
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $logStmt->bindParam(':ip', $ipAddress);
                $logStmt->execute();
            } catch (PDOException $logError) {
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
                'folders_reassigned' => $foldersReassigned ?? 0,
                'files_reassigned' => $filesReassigned ?? 0,
                'users_updated' => $usersUpdated ?? 0,
                'admins_deleted' => $adminsDeleted ?? 0
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