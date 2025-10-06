<?php
declare(strict_types=1);

/**
 * API Endpoint: Cleanup Deleted Users
 * Permanently removes users who have been soft-deleted for more than 7 days
 * Requires admin or super_admin role
 */

// Usa il sistema di autenticazione centralizzato
require_once '../../includes/api_auth.php';
require_once '../../config.php';
require_once '../../includes/db.php';
require_once '../../includes/audit_logger.php';

// Inizializza l'ambiente API
initializeApiEnvironment();

try {
    // Verifica autenticazione
    verifyApiAuthentication();

    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        apiError('Metodo non consentito', 405);
    }

    // Ottieni informazioni utente
    $userInfo = getApiUserInfo();
    $currentUserId = $userInfo['user_id'];
    $currentUserRole = $userInfo['role'];
    $currentTenantId = $userInfo['tenant_id'];

    // Verifica permessi - solo admin e super_admin possono eseguire la pulizia
    if (!hasApiRole('admin')) {
        ob_clean();
        apiError('Permessi insufficienti - solo Admin e Super Admin possono eseguire questa operazione', 403);
    }

    // Verifica CSRF token
    verifyApiCsrfToken(true);

    // Get database instance
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Inizia transazione per garantire atomicità
    $conn->beginTransaction();

    // Query per contare gli utenti che verranno eliminati
    $countQuery = "SELECT COUNT(*) as total
                   FROM users
                   WHERE deleted_at IS NOT NULL
                   AND deleted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";

    // Super admin può eliminare da tutti i tenant, admin solo dal proprio
    if ($currentUserRole !== 'super_admin') {
        $countQuery .= " AND tenant_id = :tenant_id";
    }

    $countStmt = $conn->prepare($countQuery);
    if ($currentUserRole !== 'super_admin') {
        $countStmt->bindParam(':tenant_id', $currentTenantId, PDO::PARAM_INT);
    }
    $countStmt->execute();
    $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
    $totalToDelete = (int)$countResult['total'];

    // Se non ci sono utenti da eliminare, restituisci subito
    if ($totalToDelete === 0) {
        $conn->rollBack();
        apiSuccess([
            'deleted_count' => 0,
            'message' => 'Nessun utente soft-deleted da più di 7 giorni trovato'
        ], 'Nessuna operazione necessaria');
    }

    // Ottieni i dettagli degli utenti che verranno eliminati per il log
    $detailsQuery = "SELECT id, email, name, tenant_id, deleted_at
                     FROM users
                     WHERE deleted_at IS NOT NULL
                     AND deleted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";

    if ($currentUserRole !== 'super_admin') {
        $detailsQuery .= " AND tenant_id = :tenant_id";
    }

    $detailsStmt = $conn->prepare($detailsQuery);
    if ($currentUserRole !== 'super_admin') {
        $detailsStmt->bindParam(':tenant_id', $currentTenantId, PDO::PARAM_INT);
    }
    $detailsStmt->execute();
    $deletedUsers = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Estrai gli ID degli utenti da eliminare
    $userIdsToDelete = array_column($deletedUsers, 'id');

    if (empty($userIdsToDelete)) {
        $conn->rollBack();
        apiSuccess([
            'deleted_count' => 0,
            'message' => 'Nessun utente trovato da eliminare'
        ], 'Nessuna operazione necessaria');
    }

    // Crea placeholder per la query IN
    $placeholders = implode(',', array_fill(0, count($userIdsToDelete), '?'));

    // ============================================
    // FASE 1: Elimina record da tabelle con RESTRICT constraint
    // Queste DEVONO essere eliminate prima della tabella users
    // ============================================

    // 1. Elimina canali chat dove l'utente è owner
    // Nota: questo eliminerà anche i messaggi e membri del canale tramite CASCADE
    $stmt = $conn->prepare("DELETE FROM chat_channels WHERE owner_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);
    $deletedChatChannels = $stmt->rowCount();

    // 2. Elimina versioni file dove l'utente è uploader
    $stmt = $conn->prepare("DELETE FROM file_versions WHERE uploaded_by IN ($placeholders)");
    $stmt->execute($userIdsToDelete);
    $deletedFileVersions = $stmt->rowCount();

    // 3. Elimina cartelle dove l'utente è owner
    $stmt = $conn->prepare("DELETE FROM folders WHERE owner_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);
    $deletedFolders = $stmt->rowCount();

    // 4. Elimina progetti dove l'utente è owner
    $stmt = $conn->prepare("DELETE FROM projects WHERE owner_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);
    $deletedProjects = $stmt->rowCount();

    // 5. Aggiorna project_members - imposta added_by a NULL per i record creati dall'utente
    $stmt = $conn->prepare("UPDATE project_members SET added_by = NULL WHERE added_by IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 6. Aggiorna tasks - imposta created_by a NULL per i task creati dall'utente
    $stmt = $conn->prepare("UPDATE tasks SET created_by = NULL WHERE created_by IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 7. Aggiorna task_assignments - imposta assigned_by a NULL
    $stmt = $conn->prepare("UPDATE task_assignments SET assigned_by = NULL WHERE assigned_by IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // ============================================
    // FASE 2: Elimina record da tabelle con CASCADE
    // Questi verranno eliminati automaticamente, ma lo facciamo esplicitamente per chiarezza
    // ============================================

    // 8. Elimina membri di progetti
    $stmt = $conn->prepare("DELETE FROM project_members WHERE user_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 9. Elimina assegnazioni task
    $stmt = $conn->prepare("DELETE FROM task_assignments WHERE user_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 10. Elimina commenti task
    $stmt = $conn->prepare("DELETE FROM task_comments WHERE user_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 11. Elimina membri canali chat
    $stmt = $conn->prepare("DELETE FROM chat_channel_members WHERE user_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 12. Elimina messaggi chat
    $stmt = $conn->prepare("DELETE FROM chat_messages WHERE user_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 13. Elimina letture messaggi
    $stmt = $conn->prepare("DELETE FROM chat_message_reads WHERE user_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 14. Elimina condivisioni file (come mittente e destinatario)
    $stmt = $conn->prepare("DELETE FROM file_shares WHERE shared_by IN ($placeholders) OR shared_with IN ($placeholders)");
    $stmt->execute(array_merge($userIdsToDelete, $userIdsToDelete));

    // 15. Elimina eventi calendario
    $stmt = $conn->prepare("DELETE FROM calendar_events WHERE organizer_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 16. Elimina condivisioni calendario
    $stmt = $conn->prepare("DELETE FROM calendar_shares WHERE user_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 17. Elimina approvazioni documenti
    $stmt = $conn->prepare("DELETE FROM document_approvals WHERE requested_by IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 18. Aggiorna reviewed_by a NULL per approvazioni revisionate dall'utente
    $stmt = $conn->prepare("UPDATE document_approvals SET reviewed_by = NULL WHERE reviewed_by IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 19. Elimina notifiche approvazioni
    $stmt = $conn->prepare("DELETE FROM approval_notifications WHERE user_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 20. Elimina notifiche scadenza password
    $stmt = $conn->prepare("DELETE FROM password_expiry_notifications WHERE user_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 21. Elimina permessi utente
    $stmt = $conn->prepare("DELETE FROM user_permissions WHERE user_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 22. Aggiorna granted_by a NULL per permessi concessi dall'utente
    $stmt = $conn->prepare("UPDATE user_permissions SET granted_by = NULL WHERE granted_by IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 23. Elimina accessi tenant
    $stmt = $conn->prepare("DELETE FROM user_tenant_access WHERE user_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 24. Aggiorna granted_by a NULL per accessi tenant concessi dall'utente
    $stmt = $conn->prepare("UPDATE user_tenant_access SET granted_by = NULL WHERE granted_by IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // ============================================
    // FASE 3: Aggiorna tabelle con SET NULL
    // ============================================

    // 25. Aggiorna uploaded_by a NULL per file caricati dall'utente
    $stmt = $conn->prepare("UPDATE files SET uploaded_by = NULL WHERE uploaded_by IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 26. Aggiorna assigned_to a NULL per task assegnati all'utente
    $stmt = $conn->prepare("UPDATE tasks SET assigned_to = NULL WHERE assigned_to IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // 27. Aggiorna user_id a NULL negli audit logs (già SET NULL, ma esplicito)
    $stmt = $conn->prepare("UPDATE audit_logs SET user_id = NULL WHERE user_id IN ($placeholders)");
    $stmt->execute($userIdsToDelete);

    // ============================================
    // FASE 4: Elimina finalmente l'utente dalla tabella users
    // ============================================

    $deleteQuery = "DELETE FROM users WHERE id IN ($placeholders)";
    $deleteStmt = $conn->prepare($deleteQuery);

    if (!$deleteStmt->execute($userIdsToDelete)) {
        $conn->rollBack();
        apiError('Errore durante l\'eliminazione permanente degli utenti', 500);
    }

    $actualDeleted = $deleteStmt->rowCount();

    // Commit della transazione
    $conn->commit();

    // Log dell'operazione di cleanup nell'audit log
    try {
        $auditLogger = new AuditLogger();

        // Prepara i dettagli per il log
        $deletedUsersList = array_map(function($user) {
            return [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'tenant_id' => $user['tenant_id'],
                'deleted_at' => $user['deleted_at']
            ];
        }, $deletedUsers);

        $auditLogger->log([
            'user_id' => $currentUserId,
            'tenant_id' => $currentTenantId,
            'action' => 'cleanup_deleted_users',
            'entity_type' => AuditLogger::ENTITY_USER,
            'entity_id' => null,
            'old_values' => null,
            'new_values' => [
                'deleted_count' => $actualDeleted,
                'deleted_users' => $deletedUsersList,
                'cleanup_date' => date('Y-m-d H:i:s'),
                'performed_by_role' => $currentUserRole
            ],
            'description' => "Cleanup permanente di $actualDeleted utenti soft-deleted da più di 7 giorni",
            'severity' => AuditLogger::SEVERITY_WARNING,
            'status' => AuditLogger::STATUS_SUCCESS
        ]);
    } catch (Exception $logError) {
        // Log dell'errore ma non bloccare la risposta
        error_log("Errore durante il logging dell'audit: " . $logError->getMessage());
    }

    // Log per debug
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log("Cleanup completed: $actualDeleted users permanently deleted by user ID $currentUserId");
    }

    // Success response
    apiSuccess([
        'deleted_count' => $actualDeleted,
        'deleted_users' => $deletedUsersList,
        'cleanup_date' => date('Y-m-d H:i:s'),
        'cleanup_summary' => [
            'chat_channels_deleted' => $deletedChatChannels ?? 0,
            'file_versions_deleted' => $deletedFileVersions ?? 0,
            'folders_deleted' => $deletedFolders ?? 0,
            'projects_deleted' => $deletedProjects ?? 0
        ]
    ], "Eliminati permanentemente $actualDeleted utenti soft-deleted da più di 7 giorni e tutti i dati correlati");

} catch (PDOException $e) {
    // Rollback in caso di errore
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }

    logApiError('cleanup_deleted.php', $e);

    // Log dettagliato dell'errore per il debug
    error_log("Cleanup deleted users PDO error: " . $e->getMessage());
    error_log("Error code: " . $e->getCode());
    error_log("SQL State: " . ($e->errorInfo[0] ?? 'N/A'));

    // Messaggio di errore più dettagliato in modalità debug
    $errorMessage = 'Errore database durante la pulizia';
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        $errorMessage .= ': ' . $e->getMessage();
    }

    apiError($errorMessage, 500);
} catch (Exception $e) {
    // Rollback in caso di errore
    if (isset($conn) && $conn && $conn->inTransaction()) {
        $conn->rollBack();
    }

    logApiError('cleanup_deleted.php', $e);

    // Log dettagliato dell'errore
    error_log("Cleanup deleted users general error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    $errorMessage = 'Errore durante la pulizia degli utenti eliminati';
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        $errorMessage .= ': ' . $e->getMessage();
    }

    apiError($errorMessage, 500);
}
?>
