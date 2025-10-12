<?php
/**
 * Get Editor Configuration API Endpoint
 *
 * Ottiene la configurazione completa dell'editor per un file
 * Utilizzato per inizializzare l'editor OnlyOffice nel frontend
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

// CSRF not required for GET requests, but we verify it if present
verifyApiCsrfToken(false);

// Get current user info
$userInfo = getApiUserInfo();
$user_id = $userInfo['user_id'];
$tenant_id = $userInfo['tenant_id'];
$user_role = $userInfo['role'];
$user_name = $userInfo['user_name'];
$user_email = $userInfo['user_email'];

// Get database connection
$db = Database::getInstance();

// Get parameters
$file_id = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;

// Validate input
if ($file_id <= 0) {
    apiError('ID file non valido', 400);
}

try {
    // Get file information with tenant isolation
    $file = $db->fetchOne(
        "SELECT f.*,
                u.name as uploaded_by_name,
                u.email as uploaded_by_email,
                t.name as tenant_name,
                fol.name as folder_name
         FROM files f
         LEFT JOIN users u ON f.uploaded_by = u.id
         LEFT JOIN tenants t ON f.tenant_id = t.id
         LEFT JOIN folders fol ON f.folder_id = fol.id
         WHERE f.id = ?
         AND f.tenant_id = ?
         AND f.deleted_at IS NULL",
        [$file_id, $tenant_id]
    );

    if (!$file) {
        apiError('File non trovato o accesso negato', 404);
    }

    // Get file extension and check if supported
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $isEditable = isFileEditableInOnlyOffice($extension);
    $isViewOnly = isFileViewOnlyInOnlyOffice($extension);

    if (!$isEditable && !$isViewOnly) {
        apiError('Formato file non supportato per l\'editor', 400);
    }

    // Check permissions
    $permissions = checkFileEditPermissions($file_id, $user_id, $user_role);

    // Determine document type
    $documentType = getOnlyOfficeDocumentType($extension);

    // Check for active sessions
    $activeSessions = getActiveSessionsForFile($file_id);
    $hasActiveEditors = count($activeSessions) > 0;
    $isCurrentUserEditing = false;

    foreach ($activeSessions as $session) {
        if ($session['user_id'] == $user_id) {
            $isCurrentUserEditing = true;
            break;
        }
    }

    // Get file versions count
    $versionCount = $db->count('file_versions', ['file_id' => $file_id]);

    // Build editor configuration
    $editorConfig = [
        'width' => '100%',
        'height' => '100%',
        'documentType' => $documentType,
        'type' => ($permissions['edit'] && !$isViewOnly) ? 'desktop' : 'embedded',

        // Events configuration
        'events' => [
            'onAppReady' => 'onAppReady',
            'onDocumentReady' => 'onDocumentReady',
            'onDocumentStateChange' => 'onDocumentStateChange',
            'onError' => 'onError',
            'onWarning' => 'onWarning',
            'onInfo' => 'onInfo',
            'onRequestEditRights' => 'onRequestEditRights',
            'onRequestHistory' => 'onRequestHistory',
            'onRequestHistoryData' => 'onRequestHistoryData',
            'onRequestRestore' => 'onRequestRestore',
            'onRequestSaveAs' => 'onRequestSaveAs',
            'onRequestRename' => 'onRequestRename',
            'onMetaChange' => 'onMetaChange',
            'onDownloadAs' => 'onDownloadAs',
            'onCollaborativeChanges' => 'onCollaborativeChanges'
        ],

        // File information
        'file' => [
            'id' => $file_id,
            'name' => $file['name'],
            'extension' => $extension,
            'size' => $file['file_size'],
            'mime_type' => $file['mime_type'],
            'created_at' => $file['created_at'],
            'updated_at' => $file['updated_at'],
            'version' => $versionCount + 1,
            'folder' => $file['folder_name'] ?? 'Root',
            'tenant' => $file['tenant_name'],
            'uploaded_by' => $file['uploaded_by_name'] ?? 'Unknown',
            'status' => $file['status']
        ],

        // User information
        'user' => [
            'id' => $user_id,
            'name' => $user_name,
            'email' => $user_email,
            'role' => $user_role
        ],

        // Permissions
        'permissions' => $permissions,

        // Editor features
        'features' => [
            'collaboration' => ONLYOFFICE_ENABLE_COLLABORATION && $hasActiveEditors,
            'comments' => ONLYOFFICE_ENABLE_COMMENTS && $permissions['comment'],
            'review' => ONLYOFFICE_ENABLE_REVIEW && $permissions['review'],
            'chat' => ONLYOFFICE_ENABLE_CHAT && $hasActiveEditors,
            'autosave' => $permissions['edit'],
            'forcesave' => $permissions['edit'],
            'download' => $permissions['download'],
            'print' => $permissions['print']
        ],

        // Active sessions info
        'sessions' => [
            'has_active' => $hasActiveEditors,
            'is_user_editing' => $isCurrentUserEditing,
            'active_count' => count($activeSessions),
            'active_users' => array_map(function($session) {
                return [
                    'id' => $session['user_id'],
                    'name' => $session['user_name'],
                    'opened_at' => $session['opened_at']
                ];
            }, $activeSessions)
        ],

        // UI Customization
        'ui' => [
            'logo' => BASE_URL . '/assets/images/logo-nexio.webp',
            'company_name' => 'CollaboraNexio',
            'lang' => ONLYOFFICE_LANG,
            'region' => ONLYOFFICE_REGION,
            'theme' => 'light',
            'unit' => 'cm',
            'zoom' => 100,
            'compact_header' => false,
            'compact_toolbar' => false,
            'hide_rulers' => false,
            'hide_notes' => false,
            'toolbar_no_tabs' => false,
            'show_review_changes' => $permissions['review'] ?? false
        ],

        // API URLs
        'api' => [
            'server_url' => ONLYOFFICE_SERVER_URL,
            'api_url' => ONLYOFFICE_API_URL,
            'open_url' => BASE_URL . '/api/documents/open_document.php',
            'close_url' => BASE_URL . '/api/documents/close_session.php',
            'callback_url' => ONLYOFFICE_CALLBACK_URL,
            'download_url' => ONLYOFFICE_DOWNLOAD_URL
        ],

        // Additional metadata
        'metadata' => [
            'can_edit' => $permissions['edit'] && !$isViewOnly,
            'is_view_only' => $isViewOnly,
            'is_editable_format' => $isEditable,
            'document_type' => $documentType,
            'max_file_size' => ONLYOFFICE_MAX_FILE_SIZE,
            'session_timeout' => ONLYOFFICE_SESSION_TIMEOUT,
            'idle_timeout' => ONLYOFFICE_IDLE_TIMEOUT,
            'jwt_enabled' => ONLYOFFICE_JWT_ENABLED
        ]
    ];

    // Add version history if available
    if ($versionCount > 0) {
        $versions = $db->fetchAll(
            "SELECT v.*, u.name as created_by_name
             FROM file_versions v
             LEFT JOIN users u ON v.created_by = u.id
             WHERE v.file_id = ?
             ORDER BY v.created_at DESC
             LIMIT 10",
            [$file_id]
        );

        $editorConfig['history'] = [
            'enabled' => true,
            'version_count' => $versionCount,
            'recent_versions' => array_map(function($v) {
                return [
                    'version' => $v['version_number'],
                    'created_at' => $v['created_at'],
                    'created_by' => $v['created_by_name'] ?? 'Unknown',
                    'size' => $v['size_bytes'],
                    'changes' => json_decode($v['changes_description'], true)
                ];
            }, $versions)
        ];
    }

    // Add warning messages if needed
    $warnings = [];

    if ($file['status'] === 'approvato' && $user_role === 'user') {
        $warnings[] = 'Questo file è approvato. Modalità solo lettura.';
    }

    if ($hasActiveEditors && !$isCurrentUserEditing) {
        $activeUserNames = array_column(
            array_filter($activeSessions, fn($s) => $s['user_id'] != $user_id),
            'user_name'
        );
        if (count($activeUserNames) > 0) {
            $warnings[] = 'Utenti attualmente in modifica: ' . implode(', ', $activeUserNames);
        }
    }

    if (!empty($warnings)) {
        $editorConfig['warnings'] = $warnings;
    }

    // Log audit
    logDocumentAudit('editor_config_requested', $file_id, $user_id, [
        'document_type' => $documentType,
        'can_edit' => $editorConfig['metadata']['can_edit']
    ]);

    // Send response
    apiSuccess($editorConfig, 'Configurazione editor ottenuta con successo');

} catch (Exception $e) {
    logApiError('Get Editor Config', $e);
    apiError(
        'Errore nel recupero della configurazione',
        500,
        DEBUG_MODE ? ['debug' => $e->getMessage()] : null
    );
}