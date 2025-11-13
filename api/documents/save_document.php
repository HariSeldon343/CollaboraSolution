<?php
/**
 * Save Document Callback API Endpoint
 *
 * Callback endpoint chiamato da OnlyOffice per salvare le modifiche al documento
 * Gestisce i vari status codes di OnlyOffice (1-7)
 *
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// No session needed for OnlyOffice callbacks
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Include required files
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/document_editor_helper.php';

// CORS headers for OnlyOffice Document Server access
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Set JSON headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Get raw POST data
$rawData = file_get_contents('php://input');

// Log raw callback for debugging
if (DEBUG_MODE) {
    error_log('OnlyOffice Callback Raw Data: ' . $rawData);
}

// Parse JSON data
$data = json_decode($rawData, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die(json_encode(['error' => 1, 'message' => 'Invalid JSON']));
}

// Get JWT token from header if JWT is enabled
$token = null;
if (ONLYOFFICE_JWT_ENABLED) {
    $headers = getallheaders();
    $authHeader = $headers[ONLYOFFICE_JWT_HEADER] ?? $headers['Authorization'] ?? '';

    if (empty($authHeader)) {
        http_response_code(403);
        die(json_encode(['error' => 1, 'message' => 'Missing authorization token']));
    }

    // Verify JWT token
    $payload = verifyOnlyOfficeJWT($authHeader);
    if ($payload === false) {
        http_response_code(403);
        die(json_encode(['error' => 1, 'message' => 'Invalid authorization token']));
    }

    // Extract the actual callback data from the JWT payload
    if (isset($payload['payload'])) {
        $data = $payload['payload'];
    }
}

// Extract callback parameters
$key = $data['key'] ?? '';
$status = isset($data['status']) ? (int)$data['status'] : 0;
$users = $data['users'] ?? [];
$url = $data['url'] ?? '';
$changesUrl = $data['changesurl'] ?? '';
$history = $data['history'] ?? null;
$actions = $data['actions'] ?? [];
$forcesavetype = $data['forcesavetype'] ?? 0;

// Log callback details
if (DEBUG_MODE) {
    error_log(sprintf(
        'OnlyOffice Callback - Key: %s, Status: %d, Users: %s',
        $key,
        $status,
        implode(',', $users)
    ));
}

// Get database instance
$db = Database::getInstance();

try {
    // Get session information based on editor key
    $session = $db->fetchOne(
        "SELECT ses.*, f.id as file_id, f.tenant_id, f.name as file_name
         FROM document_editor_sessions ses
         JOIN files f ON ses.file_id = f.id
         WHERE ses.editor_key = ?
         AND ses.closed_at IS NULL
         ORDER BY ses.opened_at DESC
         LIMIT 1",
        [$key]
    );

    if (!$session) {
        // Try to find by document key pattern
        $session = $db->fetchOne(
            "SELECT ses.*, f.id as file_id, f.tenant_id, f.name as file_name
             FROM document_editor_sessions ses
             JOIN files f ON ses.file_id = f.id
             WHERE ses.editor_key LIKE ?
             AND ses.closed_at IS NULL
             ORDER BY ses.opened_at DESC
             LIMIT 1",
            [$key . '%']
        );
    }

    if (!$session) {
        error_log("No active session found for key: $key");
        // Return success to avoid OnlyOffice retry
        die(json_encode(['error' => 0]));
    }

    $file_id = $session['file_id'];
    $user_id = $session['user_id'];
    $tenant_id = $session['tenant_id'];

    // Handle different status codes
    switch ($status) {
        case 0:
            // No document with key found - should not happen
            error_log("OnlyOffice: Document not found for key: $key");
            break;

        case 1:
            // Document is being edited
            updateSessionActivity($session['session_token']);

            // Log active users
            if (!empty($users)) {
                logDocumentAudit('document_editing', $file_id, $user_id, [
                    'active_users' => $users,
                    'session_key' => $key
                ]);
            }
            break;

        case 2:
            // Document is ready for saving
            if (empty($url)) {
                error_log("OnlyOffice: No URL provided for saving document");
                http_response_code(400);
                die(json_encode(['error' => 1, 'message' => 'No URL provided']));
            }

            // Save the document
            $saved = saveFileVersion($file_id, $url, $user_id, [
                'users' => $users,
                'changes_url' => $changesUrl,
                'history' => $history,
                'actions' => $actions,
                'forcesave_type' => $forcesavetype
            ]);

            if ($saved) {
                // Update session as saved
                $db->update(
                    'document_editor_sessions',
                    ['changes_saved' => 1, 'last_activity' => date('Y-m-d H:i:s')],
                    ['id' => $session['id']]
                );

                // Log successful save
                logDocumentAudit('document_saved', $file_id, $user_id, [
                    'session_key' => $key,
                    'users' => $users,
                    'has_changes' => !empty($changesUrl)
                ]);

                error_log("Document saved successfully: File ID $file_id");
            } else {
                error_log("Failed to save document: File ID $file_id");
                http_response_code(500);
                die(json_encode(['error' => 1, 'message' => 'Failed to save document']));
            }
            break;

        case 3:
            // Document saving error
            error_log("OnlyOffice: Error saving document for key: $key");

            logDocumentAudit('document_save_error', $file_id, $user_id, [
                'session_key' => $key,
                'error' => 'OnlyOffice reported saving error'
            ]);
            break;

        case 4:
            // Document closed with no changes
            closeEditorSession($session['session_token'], false);

            logDocumentAudit('document_closed_no_changes', $file_id, $user_id, [
                'session_key' => $key
            ]);
            break;

        case 6:
            // Document being edited, but current state is saved (autosave/forcesave)
            if (!empty($url)) {
                // Save intermediate version
                $saved = saveFileVersion($file_id, $url, $user_id, [
                    'type' => 'autosave',
                    'users' => $users,
                    'forcesave_type' => $forcesavetype
                ]);

                if ($saved) {
                    updateSessionActivity($session['session_token']);

                    logDocumentAudit('document_autosaved', $file_id, $user_id, [
                        'session_key' => $key,
                        'forcesave_type' => $forcesavetype
                    ]);
                }
            }
            break;

        case 7:
            // Error during force save
            error_log("OnlyOffice: Force save error for key: $key");

            logDocumentAudit('document_forcesave_error', $file_id, $user_id, [
                'session_key' => $key,
                'error' => 'Force save failed'
            ]);
            break;

        default:
            error_log("OnlyOffice: Unknown status code: $status for key: $key");
            break;
    }

    // Handle specific actions
    if (!empty($actions)) {
        foreach ($actions as $action) {
            $actionType = $action['type'] ?? '';
            $actionUser = $action['userid'] ?? '';

            switch ($actionType) {
                case 0: // User disconnected
                    if ($actionUser == $user_id) {
                        closeEditorSession($session['session_token'], $status === 2);
                    }
                    break;

                case 1: // User connected
                    // Already handled in status 1
                    break;

                case 2: // User clicked forcesave button
                    logDocumentAudit('document_forcesave_requested', $file_id, $actionUser, [
                        'session_key' => $key
                    ]);
                    break;
            }
        }
    }

    // Return success response to OnlyOffice
    echo json_encode(['error' => 0]);

} catch (Exception $e) {
    error_log('OnlyOffice Callback Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());

    // Return error response
    http_response_code(500);
    echo json_encode([
        'error' => 1,
        'message' => DEBUG_MODE ? $e->getMessage() : 'Internal server error'
    ]);
}