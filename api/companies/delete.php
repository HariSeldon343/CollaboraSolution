<?php
// Include centralized API authentication
require_once '../../includes/api_auth.php';
require_once '../../config.php';
require_once '../../includes/db.php';

// Initialize API environment
initializeApiEnvironment();

// Verify authentication
verifyApiAuthentication();

// Normalize session data for backward compatibility
normalizeSessionData();

try {
    // Initialize response
    $response = ['success' => false, 'message' => '', 'data' => null];

    // Get current user details using centralized function
    $userInfo = getApiUserInfo();
    $currentUserId = $userInfo['user_id'];
    $userRole = $userInfo['role'];
    $isSuperAdmin = ($userRole === 'super_admin');
    $currentTenantId = $userInfo['tenant_id'];

    // Debug logging
    error_log('Delete API - User ID: ' . $currentUserId . ', Role: ' . $userRole . ', Is Super Admin: ' . ($isSuperAdmin ? 'yes' : 'no'));

    // Get input data FIRST - FormData sends as POST parameters
    $input = $_POST;

    // If no POST data, try JSON body
    if (empty($input)) {
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $input = $jsonInput;
        }
    }

    // Make CSRF token available to $_POST for the verification function
    // This ensures getCsrfTokenFromRequest() can find it
    if (!empty($input['csrf_token']) && empty($_POST['csrf_token'])) {
        $_POST['csrf_token'] = $input['csrf_token'];
    }

    // Verify CSRF token using centralized function
    verifyApiCsrfToken(true);

    // Require super admin role
    requireApiRole('super_admin');

    // Validate required fields
    $companyId = intval($input['id'] ?? $input['company_id'] ?? 0);
    if ($companyId <= 0) {
        apiError('ID azienda non valido', 400);
    }

    // Prevent deletion of company ID 1 (default/system company)
    if ($companyId === 1) {
        apiError('Non è possibile eliminare l\'azienda di sistema', 400);
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

                apiSuccess(
                    ['deleted_company_id' => $companyId],
                    $result['message']
                );
            } else {
                apiError($result['message'], 400);
            }

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
            apiError('Azienda non trovata', 404);
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

            // Return success response using centralized function
            apiSuccess([
                'deleted_company_id' => $companyId,
                'folders_reassigned' => $foldersReassigned ?? 0,
                'files_reassigned' => $filesReassigned ?? 0,
                'users_updated' => $usersUpdated ?? 0,
                'admins_deleted' => $adminsDeleted ?? 0
            ], 'Azienda eliminata con successo');
        } else {
            $conn->rollBack();
            apiError('Errore nell\'eliminazione dell\'azienda', 500);
        }

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    // Log the actual error for debugging
    logApiError('Companies Delete PDO', $e);

    // Return user-friendly error
    apiError('Errore database', 500);

} catch (Exception $e) {
    // Log the error
    logApiError('Companies Delete', $e);

    // Return generic error
    apiError('Errore del server', 500);
}
?>