<?php
/**
 * Ticket Attachment Download API Endpoint
 * GET /api/tickets/download_attachment.php?id={attachment_id}
 *
 * Securely downloads a ticket attachment
 * Validates user has access to the ticket's tenant
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';

// Initialize API environment
initializeApiEnvironment();

// IMMEDIATELY verify authentication (CRITICAL - BUG-011 compliance)
verifyApiAuthentication();

// Get user context
$userInfo = getApiUserInfo();
$db = Database::getInstance();

try {
    // Get attachment ID from query string
    $attachmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($attachmentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID allegato non valido']);
        exit;
    }

    // Fetch attachment with ticket info for tenant validation
    $attachment = $db->fetchOne(
        "SELECT ta.*, t.tenant_id as ticket_tenant_id
         FROM ticket_attachments ta
         INNER JOIN tickets t ON ta.ticket_id = t.id
         WHERE ta.id = ?
           AND ta.deleted_at IS NULL
           AND t.deleted_at IS NULL",
        [$attachmentId]
    );

    if (!$attachment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Allegato non trovato']);
        exit;
    }

    // Multi-tenant security check: user must have access to this tenant
    // super_admin bypasses tenant isolation
    $isSuperAdmin = ($userInfo['role'] === 'super_admin');
    if (!$isSuperAdmin && (int)$attachment['tenant_id'] !== (int)$userInfo['tenant_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Accesso non autorizzato a questo allegato']);
        exit;
    }

    // Build full file path
    $basePath = dirname(dirname(__DIR__));
    $filePath = $basePath . '/' . $attachment['file_path'];

    // Validate file exists
    if (!file_exists($filePath)) {
        error_log("[TICKET_DOWNLOAD] File not found: $filePath (attachment ID: $attachmentId)");
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'File fisico non trovato sul server']);
        exit;
    }

    // Validate file is readable
    if (!is_readable($filePath)) {
        error_log("[TICKET_DOWNLOAD] File not readable: $filePath (attachment ID: $attachmentId)");
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Impossibile leggere il file']);
        exit;
    }

    // Get actual file size
    $actualSize = filesize($filePath);
    if ($actualSize === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Il file e vuoto']);
        exit;
    }

    // Set headers for file download
    header('Content-Type: ' . $attachment['mime_type']);
    header('Content-Disposition: attachment; filename="' . rawurlencode($attachment['original_name']) . '"');
    header('Content-Length: ' . $actualSize);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Security headers
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    // Clear any output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Send file
    readfile($filePath);

    // Log download (non-blocking)
    try {
        require_once __DIR__ . '/../../includes/audit_helper.php';
        AuditLogger::log(
            $userInfo['user_id'],
            $userInfo['tenant_id'],
            'download',
            'ticket_attachment',
            $attachmentId,
            "Downloaded ticket attachment: " . $attachment['original_name'],
            null,
            ['file_name' => $attachment['original_name'], 'ticket_id' => $attachment['ticket_id']]
        );
    } catch (Exception $auditEx) {
        error_log('[TICKET_DOWNLOAD] Audit log failed: ' . $auditEx->getMessage());
    }

    exit;

} catch (Exception $e) {
    error_log("Ticket attachment download error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore durante il download dell\'allegato']);
    exit;
}
