<?php
/**
 * Close Editor Session API Endpoint
 *
 * Chiude una sessione di editing del documento
 * Chiamato quando l'utente chiude manualmente l'editor
 *
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// Include centralized API authentication
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/document_editor_helper.php';

// Initialize API environment
initializeApiEnvironment();

// Verify authentication
verifyApiAuthentication();

// Verify CSRF token
verifyApiCsrfToken();

// Get current user info
$userInfo = getApiUserInfo();
$user_id = $userInfo['user_id'];
$tenant_id = $userInfo['tenant_id'];

// Get database connection
$db = Database::getInstance();

// Get input data
$input = json_decode(file_get_contents('php://input'), true);

// Extract parameters
$session_token = $input['session_token'] ?? '';
$file_id = isset($input['file_id']) ? (int)$input['file_id'] : 0;
$changes_saved = $input['changes_saved'] ?? false;
$force_close = $input['force_close'] ?? false;

// Validate input
if (empty($session_token) && $file_id <= 0) {
    apiError('Token sessione o ID file richiesto', 400);
}

try {
    // Find the session to close
    if (!empty($session_token)) {
        // Find by session token
        $session = $db->fetchOne(
            "SELECT * FROM document_editor_sessions
             WHERE session_token = ?
             AND user_id = ?
             AND tenant_id = ?
             AND closed_at IS NULL",
            [$session_token, $user_id, $tenant_id]
        );
    } else {
        // Find by file_id and user_id
        $session = $db->fetchOne(
            "SELECT * FROM document_editor_sessions
             WHERE file_id = ?
             AND user_id = ?
             AND tenant_id = ?
             AND closed_at IS NULL
             ORDER BY opened_at DESC
             LIMIT 1",
            [$file_id, $user_id, $tenant_id]
        );
    }

    if (!$session) {
        // Session might already be closed
        apiSuccess(['already_closed' => true], 'Nessuna sessione attiva trovata');
        return;
    }

    // Check if user has permission to close this session
    if ($session['user_id'] != $user_id && !$force_close) {
        // Check if user is admin/manager
        if (!hasApiRole('manager')) {
            apiError('Non autorizzato a chiudere questa sessione', 403);
        }
    }

    // Close the session
    $closed = closeEditorSession($session['session_token'], $changes_saved);

    if (!$closed) {
        throw new Exception('Impossibile chiudere la sessione');
    }

    // Get file information for audit
    $file = $db->fetchOne(
        "SELECT name FROM files WHERE id = ? AND deleted_at IS NULL",
        [$session['file_id']]
    );

    // Calculate session duration
    $opened_at = new DateTime($session['opened_at']);
    $closed_at = new DateTime();
    $duration = $closed_at->diff($opened_at);

    $sessionInfo = [
        'session_id' => $session['id'],
        'file_id' => $session['file_id'],
        'file_name' => $file['name'] ?? 'Unknown',
        'duration' => [
            'hours' => $duration->h,
            'minutes' => $duration->i,
            'seconds' => $duration->s,
            'total_seconds' => ($duration->h * 3600) + ($duration->i * 60) + $duration->s
        ],
        'changes_saved' => $changes_saved,
        'closed_at' => $closed_at->format('Y-m-d H:i:s')
    ];

    // Log audit
    logDocumentAudit('editor_session_closed', $session['file_id'], $user_id, [
        'session_id' => $session['id'],
        'duration_seconds' => $sessionInfo['duration']['total_seconds'],
        'changes_saved' => $changes_saved,
        'forced' => $force_close
    ]);

    // Check for other active sessions on the same file
    $remainingSessions = $db->fetchAll(
        "SELECT ses.*, u.name as user_name
         FROM document_editor_sessions ses
         JOIN users u ON ses.user_id = u.id
         WHERE ses.file_id = ?
         AND ses.closed_at IS NULL
         AND ses.id != ?",
        [$session['file_id'], $session['id']]
    );

    // Build response
    $response = [
        'closed' => true,
        'session_info' => $sessionInfo,
        'active_sessions_remaining' => count($remainingSessions),
        'active_users' => array_map(function($s) {
            return [
                'user_id' => $s['user_id'],
                'user_name' => $s['user_name'],
                'opened_at' => $s['opened_at']
            ];
        }, $remainingSessions)
    ];

    // Send notification if this was the last session
    if (count($remainingSessions) === 0 && $changes_saved) {
        // Trigger any post-processing needed when all users have finished editing
        // For example, update file status, trigger approval workflow, etc.

        // Update file status if needed
        if ($changes_saved) {
            $db->update(
                'files',
                [
                    'status' => 'in_approvazione',
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                ['id' => $session['file_id']]
            );

            $response['file_status_updated'] = true;
        }
    }

    // Send success response
    apiSuccess($response, 'Sessione chiusa con successo');

} catch (Exception $e) {
    logApiError('Close Editor Session', $e);
    apiError(
        'Errore nella chiusura della sessione',
        500,
        DEBUG_MODE ? ['debug' => $e->getMessage()] : null
    );
}