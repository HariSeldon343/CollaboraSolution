<?php
/**
 * Ticket Create API Endpoint
 * POST /api/tickets/create.php
 *
 * Creates a new support ticket with automatic ticket number generation
 * Sends email notification to super_admin users
 *
 * FEATURE: Supports file attachment upload (max 5MB)
 * Accepts both JSON and multipart/form-data
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';

// Initialize API environment
initializeApiEnvironment();

// IMMEDIATELY verify authentication (CRITICAL - BUG-011 compliance)
verifyApiAuthentication();

// Verify CSRF token for POST request
verifyApiCsrfToken();

// Get user context
$userInfo = getApiUserInfo();
$db = Database::getInstance();

try {
    // FEATURE: Support both JSON and multipart/form-data
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isMultipart = stripos($contentType, 'multipart/form-data') !== false;

    if ($isMultipart) {
        // FormData from JavaScript (file upload)
        $data = [
            'subject' => $_POST['subject'] ?? '',
            'description' => $_POST['description'] ?? '',
            'category' => $_POST['category'] ?? '',
            'urgency' => $_POST['urgency'] ?? ''
        ];
    } else {
        // JSON data
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            api_error('Invalid JSON data', 400);
        }
    }

    // Validate required fields
    if (empty($data['subject'])) {
        api_error('Il soggetto e obbligatorio', 400);
    }

    if (empty($data['description'])) {
        api_error('La descrizione e obbligatoria', 400);
    }

    if (empty($data['category'])) {
        api_error('La categoria e obbligatoria', 400);
    }

    if (empty($data['urgency'])) {
        api_error('L\'urgenza e obbligatoria', 400);
    }

    // Sanitize and validate input
    $subject = trim($data['subject']);
    if (strlen($subject) > 500) {
        api_error('Il soggetto non puo superare 500 caratteri', 400);
    }

    $description = trim($data['description']);
    $category = $data['category'];
    $urgency = $data['urgency'];
    $attachments = !empty($data['attachments']) ? json_encode($data['attachments']) : null;

    // Validate category (include 'other' which is valid in the ENUM)
    $validCategories = ['technical', 'billing', 'feature_request', 'bug_report', 'general', 'other'];
    if (!in_array($category, $validCategories)) {
        api_error('Categoria non valida', 400);
    }

    // Validate urgency
    // BUG-145b FIX: Database ENUM uses 'medium' not 'normal' - align with DB schema
    $validUrgencies = ['low', 'medium', 'high', 'critical'];
    if (!in_array($urgency, $validUrgencies)) {
        api_error('Urgenza non valida', 400);
    }

    // ========================================
    // FEATURE: Handle file attachment upload
    // ========================================
    $attachmentId = null;
    $attachmentInfo = null;

    if ($isMultipart && isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['attachment'];

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'Il file supera la dimensione massima consentita dal server',
                UPLOAD_ERR_FORM_SIZE => 'Il file supera la dimensione massima consentita',
                UPLOAD_ERR_PARTIAL => 'Il file e stato caricato solo parzialmente',
                UPLOAD_ERR_NO_TMP_DIR => 'Cartella temporanea mancante',
                UPLOAD_ERR_CANT_WRITE => 'Impossibile scrivere il file su disco',
                UPLOAD_ERR_EXTENSION => 'Upload bloccato da un\'estensione PHP'
            ];
            $errorMsg = $uploadErrors[$file['error']] ?? 'Errore sconosciuto durante l\'upload';
            api_error($errorMsg, 400);
        }

        // Validate file
        $originalName = $file['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $tmpPath = $file['tmp_name'];
        $fileSize = $file['size'];

        // Validate file size (max 5MB)
        $maxFileSize = 5 * 1024 * 1024;
        if ($fileSize > $maxFileSize) {
            api_error('Il file supera la dimensione massima di 5MB', 400);
        }

        // Validate file exists and has content (BUG-136 pattern)
        if (!file_exists($tmpPath)) {
            api_error('File temporaneo non trovato', 400);
        }
        $actualTmpSize = filesize($tmpPath);
        if ($actualTmpSize === 0) {
            api_error('Il file caricato e vuoto', 400);
        }

        // Validate file extension
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt'];
        if (!in_array($extension, $allowedExtensions)) {
            api_error('Tipo di file non consentito. Formati accettati: JPG, PNG, GIF, PDF, DOC, DOCX, TXT', 400);
        }

        // Get MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);

        // Validate MIME type matches extension
        $validMimeTypes = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'txt' => ['text/plain', 'text/html', 'application/octet-stream']
        ];

        if (!isset($validMimeTypes[$extension]) || !in_array($mimeType, $validMimeTypes[$extension])) {
            // Allow some flexibility for txt files
            if ($extension !== 'txt') {
                error_log("[TICKET_ATTACHMENT] MIME type mismatch: $mimeType for extension $extension");
                api_error('Il tipo di file non corrisponde all\'estensione', 400);
            }
        }

        // Create upload directory for tenant tickets
        $uploadBaseDir = dirname(dirname(__DIR__)) . '/uploads/tickets/' . $userInfo['tenant_id'];
        if (!is_dir($uploadBaseDir)) {
            if (!mkdir($uploadBaseDir, 0755, true)) {
                error_log('[TICKET_ATTACHMENT] Failed to create upload directory: ' . $uploadBaseDir);
                api_error('Impossibile creare la cartella di upload', 500);
            }
        }

        // Generate safe filename
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        if (strlen($safeName) > 100) {
            $safeName = substr($safeName, 0, 100);
        }
        $uniqueSuffix = '_' . uniqid();
        $storedName = $safeName . $uniqueSuffix . '.' . $extension;
        $destinationPath = $uploadBaseDir . '/' . $storedName;
        $relativePath = 'uploads/tickets/' . $userInfo['tenant_id'] . '/' . $storedName;

        // Move uploaded file
        if (!move_uploaded_file($tmpPath, $destinationPath)) {
            error_log('[TICKET_ATTACHMENT] Failed to move uploaded file to: ' . $destinationPath);
            api_error('Errore nel salvataggio del file', 500);
        }

        // Verify saved file (BUG-136 pattern)
        $savedFileSize = filesize($destinationPath);
        if ($savedFileSize === 0) {
            unlink($destinationPath);
            api_error('Il file salvato e vuoto', 500);
        }

        // Store attachment info for later database insertion
        $attachmentInfo = [
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'file_path' => $relativePath,
            'file_size' => $savedFileSize,
            'mime_type' => $mimeType,
            'file_extension' => $extension
        ];

        error_log("[TICKET_ATTACHMENT] File uploaded successfully: $originalName -> $storedName ($savedFileSize bytes)");
    }

    // Start transaction
    $db->beginTransaction();

    try {
        // Generate unique ticket number: TICK-YYYY-NNNN
        // BUG-146b FIX: Search MAX globally (not per-tenant) because ticket_number has GLOBAL unique constraint
        // BUG-147c FIX: Remove deleted_at filter - UNIQUE constraint is GLOBAL (includes soft-deleted)
        $year = date('Y');
        $lastTicketSql = "SELECT MAX(CAST(SUBSTRING(ticket_number, 11) AS UNSIGNED)) as last_num
                          FROM tickets
                          WHERE ticket_number LIKE ?";
        $lastTicketResult = $db->fetchOne($lastTicketSql, ["TICK-$year-%"]);
        $lastNum = $lastTicketResult['last_num'] ?? 0;
        $newNum = str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
        $ticketNumber = "TICK-$year-$newNum";

        // Insert ticket
        $ticketId = $db->insert('tickets', [
            'tenant_id' => $userInfo['tenant_id'],
            'ticket_number' => $ticketNumber,
            'subject' => $subject,
            'description' => $description,
            'category' => $category,
            'urgency' => $urgency,
            'status' => 'open',
            'created_by' => $userInfo['user_id'],
            'attachments' => $attachments,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if (!$ticketId) {
            throw new Exception('Errore durante la creazione del ticket');
        }

        // FEATURE: Insert attachment record if file was uploaded
        if ($attachmentInfo) {
            $attachmentId = $db->insert('ticket_attachments', [
                'tenant_id' => $userInfo['tenant_id'],
                'ticket_id' => $ticketId,
                'original_name' => $attachmentInfo['original_name'],
                'stored_name' => $attachmentInfo['stored_name'],
                'file_path' => $attachmentInfo['file_path'],
                'file_size' => $attachmentInfo['file_size'],
                'mime_type' => $attachmentInfo['mime_type'],
                'file_extension' => $attachmentInfo['file_extension'],
                'uploaded_by' => $userInfo['user_id'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            if (!$attachmentId) {
                throw new Exception('Errore durante il salvataggio dell\'allegato');
            }

            error_log("[TICKET_ATTACHMENT] Attachment record created: ID=$attachmentId for ticket ID=$ticketId");
        }

        // Log to ticket history
        $historyData = [
            'ticket_number' => $ticketNumber,
            'subject' => $subject,
            'category' => $category,
            'urgency' => $urgency
        ];
        if ($attachmentInfo) {
            $historyData['attachment'] = $attachmentInfo['original_name'];
        }

        $db->insert('ticket_history', [
            'tenant_id' => $userInfo['tenant_id'],
            'ticket_id' => $ticketId,
            'user_id' => $userInfo['user_id'],
            'action' => 'created',
            'field_name' => null,
            'old_value' => null,
            'new_value' => json_encode($historyData),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Commit transaction
        $db->commit();

        // BUG-047: Audit log ticket creation (non-blocking)
        try {
            require_once __DIR__ . '/../../includes/audit_helper.php';
            AuditLogger::logCreate(
                $userInfo['user_id'],
                $userInfo['tenant_id'],
                'ticket',
                $ticketId,
                "Creato ticket #$ticketNumber: $subject" . ($attachmentInfo ? " con allegato" : ""),
                [
                    'ticket_number' => $ticketNumber,
                    'subject' => $subject,
                    'category' => $category,
                    'urgency' => $urgency,
                    'status' => 'open',
                    'has_attachment' => $attachmentInfo ? true : false
                ]
            );
        } catch (Exception $auditEx) {
            error_log('[TICKET_CREATE] Audit log failed: ' . $auditEx->getMessage());
        }

        // Fetch the created ticket with related data
        $ticket = $db->fetchOne(
            "SELECT
                t.*,
                u_creator.name as created_by_name,
                u_creator.email as created_by_email
            FROM tickets t
            LEFT JOIN users u_creator ON t.created_by = u_creator.id
            WHERE t.id = ?",
            [$ticketId]
        );

        // FEATURE: Add attachment data to response
        if ($attachmentId) {
            $attachment = $db->fetchOne(
                "SELECT id, original_name, stored_name, file_path, file_size, mime_type, file_extension, created_at
                 FROM ticket_attachments
                 WHERE id = ? AND deleted_at IS NULL",
                [$attachmentId]
            );
            $ticket['attachment'] = $attachment;
        }

        // ========================================
        // EMAIL NOTIFICATIONS (NON-BLOCKING)
        // ========================================
        try {
            require_once __DIR__ . '/../../includes/ticket_notification_helper.php';
            $notifier = new TicketNotification();

            // Notify super admins about new ticket
            $notifier->sendTicketCreatedNotification($ticketId);

            // Send confirmation email to ticket creator
            $notifier->sendTicketCreatedConfirmation($ticketId);

        } catch (Exception $e) {
            // Log error but don't fail the request
            error_log("Ticket notification error (create): " . $e->getMessage());
        }

        api_success([
            'ticket' => $ticket,
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber,
            'attachment_id' => $attachmentId
        ], 'Ticket creato con successo');

    } catch (Exception $e) {
        $db->rollback();

        // Cleanup uploaded file if transaction failed
        if ($attachmentInfo && isset($destinationPath) && file_exists($destinationPath)) {
            unlink($destinationPath);
            error_log('[TICKET_ATTACHMENT] Cleaned up uploaded file after transaction failure');
        }

        throw $e;
    }

} catch (Exception $e) {
    error_log("Ticket create error: " . $e->getMessage());
    api_error('Errore nella creazione del ticket: ' . $e->getMessage(), 500);
}
