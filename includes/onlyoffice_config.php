<?php
/**
 * OnlyOffice Document Server Configuration
 *
 * Configurazione centralizzata per l'integrazione con OnlyOffice
 * Document Server Community Edition
 *
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// Ensure BASE_URL is defined before using it
if (!defined('BASE_URL')) {
    throw new RuntimeException('BASE_URL constant must be defined before loading onlyoffice_config.php. Please include config.php first.');
}

// OnlyOffice Server Configuration
define('ONLYOFFICE_SERVER_URL', getenv('ONLYOFFICE_SERVER_URL') ?: 'http://localhost:8083');
define('ONLYOFFICE_API_URL', ONLYOFFICE_SERVER_URL . '/web-apps/apps/api/documents/api.js');

// JWT Authentication Settings
define('ONLYOFFICE_JWT_SECRET', getenv('ONLYOFFICE_JWT_SECRET') ?: '16211f3e8588521503a1265ef24f6bda02b064c6b0ed5a1922d0f36929a613af');
define('ONLYOFFICE_JWT_HEADER', 'Authorization');
define('ONLYOFFICE_JWT_ENABLED', true);

// Document Server Endpoints
define('ONLYOFFICE_DOWNLOAD_URL', BASE_URL . '/api/documents/download_for_editor.php');
// Use host.docker.internal for callback so Docker can reach XAMPP on Windows
define('ONLYOFFICE_CALLBACK_URL', 'http://host.docker.internal:8888/CollaboraNexio/api/documents/save_document.php');

// Editor Configuration
define('ONLYOFFICE_LANG', 'it'); // Italian language
define('ONLYOFFICE_REGION', 'it-IT');

// File Size Limits
define('ONLYOFFICE_MAX_FILE_SIZE', 100 * 1024 * 1024); // 100MB max file size for editing

// Session Configuration
define('ONLYOFFICE_SESSION_TIMEOUT', 3600); // 1 hour session timeout
define('ONLYOFFICE_IDLE_TIMEOUT', 1800); // 30 minutes idle timeout

// Collaboration Settings
define('ONLYOFFICE_ENABLE_COLLABORATION', true);
define('ONLYOFFICE_ENABLE_COMMENTS', true);
define('ONLYOFFICE_ENABLE_REVIEW', true);
define('ONLYOFFICE_ENABLE_CHAT', false); // Disabled by default

// Document Types Configuration
$ONLYOFFICE_DOCUMENT_TYPES = [
    'word' => [
        'extensions' => ['doc', 'docx', 'docm', 'dot', 'dotx', 'dotm', 'odt', 'fodt', 'ott', 'rtf', 'txt', 'html', 'htm', 'mht', 'pdf', 'djvu', 'fb2', 'epub', 'xps'],
        'mime_types' => [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-word.document.macroEnabled.12',
            'application/vnd.oasis.opendocument.text',
            'text/plain',
            'text/rtf',
            'text/html',
            'application/pdf'
        ],
        'type' => 'text'
    ],
    'cell' => [
        'extensions' => ['xls', 'xlsx', 'xlsm', 'xlt', 'xltx', 'xltm', 'ods', 'fods', 'ots', 'csv'],
        'mime_types' => [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel.sheet.macroEnabled.12',
            'application/vnd.oasis.opendocument.spreadsheet',
            'text/csv'
        ],
        'type' => 'spreadsheet'
    ],
    'slide' => [
        'extensions' => ['pps', 'ppsx', 'ppsm', 'ppt', 'pptx', 'pptm', 'pot', 'potx', 'potm', 'odp', 'fodp', 'otp'],
        'mime_types' => [
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
            'application/vnd.oasis.opendocument.presentation'
        ],
        'type' => 'presentation'
    ]
];

// Editable File Extensions
$ONLYOFFICE_EDITABLE_EXTENSIONS = [
    'docx', 'xlsx', 'pptx', 'txt', 'csv',
    'odt', 'ods', 'odp', 'doc', 'xls', 'ppt',
    'docm', 'xlsm', 'pptm', 'dotx', 'xltx', 'potx',
    'dotm', 'xltm', 'potm', 'fodt', 'fods', 'fodp',
    'rtf'
];

// View-only File Extensions
$ONLYOFFICE_VIEWONLY_EXTENSIONS = [
    'pdf', 'djvu', 'xps', 'epub', 'fb2'
];

// Customization Settings
$ONLYOFFICE_CUSTOMIZATION = [
    'customer' => [
        'address' => 'Italia',
        'info' => 'CollaboraNexio - Sistema di gestione documentale',
        'logo' => BASE_URL . '/assets/images/logo-nexio.webp',
        'mail' => 'support@nexiosolution.it',
        'name' => 'CollaboraNexio',
        'www' => 'https://app.nexiosolution.it'
    ],
    'feedback' => [
        'visible' => false
    ],
    'forcesave' => true,
    'goback' => [
        'url' => BASE_URL . '/files.php'
    ],
    'logo' => [
        'image' => BASE_URL . '/assets/images/logo-nexio.webp',
        'imageEmbedded' => BASE_URL . '/assets/images/logo-nexio.webp',
        'url' => BASE_URL
    ]
];

// Permission Mappings (role -> OnlyOffice permissions)
$ONLYOFFICE_PERMISSIONS_MAP = [
    'super_admin' => [
        'comment' => true,
        'download' => true,
        'edit' => true,
        'fillForms' => true,
        'modifyContentControl' => true,
        'modifyFilter' => true,
        'print' => true,
        'review' => true
    ],
    'admin' => [
        'comment' => true,
        'download' => true,
        'edit' => true,
        'fillForms' => true,
        'modifyContentControl' => true,
        'modifyFilter' => true,
        'print' => true,
        'review' => true
    ],
    'manager' => [
        'comment' => true,
        'download' => true,
        'edit' => true,
        'fillForms' => true,
        'modifyContentControl' => false,
        'modifyFilter' => true,
        'print' => true,
        'review' => true
    ],
    'user' => [
        'comment' => true,
        'download' => true,
        'edit' => false,
        'fillForms' => false,
        'modifyContentControl' => false,
        'modifyFilter' => false,
        'print' => true,
        'review' => false
    ]
];

// OnlyOffice Callback Status Codes
$ONLYOFFICE_STATUS_CODES = [
    0 => 'No document with the key identifier could be found',
    1 => 'Document is being edited',
    2 => 'Document is ready for saving',
    3 => 'Document saving error has occurred',
    4 => 'Document is closed with no changes',
    6 => 'Document is being edited, but the current document state is saved',
    7 => 'Error has occurred while force saving the document'
];

/**
 * Ottiene il tipo di documento OnlyOffice basato sull'estensione
 */
function getOnlyOfficeDocumentType(string $extension): string {
    global $ONLYOFFICE_DOCUMENT_TYPES;

    $extension = strtolower($extension);

    foreach ($ONLYOFFICE_DOCUMENT_TYPES as $type => $config) {
        if (in_array($extension, $config['extensions'])) {
            return $type;
        }
    }

    return 'word'; // Default to word processor
}

/**
 * Verifica se un file è editabile in OnlyOffice
 */
function isFileEditableInOnlyOffice(string $extension): bool {
    global $ONLYOFFICE_EDITABLE_EXTENSIONS;
    return in_array(strtolower($extension), $ONLYOFFICE_EDITABLE_EXTENSIONS);
}

/**
 * Verifica se un file è solo visualizzabile in OnlyOffice
 */
function isFileViewOnlyInOnlyOffice(string $extension): bool {
    global $ONLYOFFICE_VIEWONLY_EXTENSIONS;
    return in_array(strtolower($extension), $ONLYOFFICE_VIEWONLY_EXTENSIONS);
}

/**
 * Ottiene i permessi OnlyOffice per un ruolo utente
 */
function getOnlyOfficePermissions(string $userRole, bool $isOwner = false): array {
    global $ONLYOFFICE_PERMISSIONS_MAP;

    // Se non editabile per il ruolo, forza view-only
    if ($userRole === 'user' && !$isOwner) {
        return [
            'comment' => true,
            'download' => true,
            'edit' => false,
            'fillForms' => false,
            'modifyContentControl' => false,
            'modifyFilter' => false,
            'print' => true,
            'review' => false
        ];
    }

    return $ONLYOFFICE_PERMISSIONS_MAP[$userRole] ?? $ONLYOFFICE_PERMISSIONS_MAP['user'];
}

/**
 * Genera una chiave unica per il documento
 * La chiave deve cambiare quando il documento viene modificato
 */
function generateDocumentKey(int $fileId, string $fileHash, int $version = 1): string {
    // Combinazione di file ID, hash del contenuto e versione
    return sprintf(
        'file_%d_v%d_%s',
        $fileId,
        $version,
        substr($fileHash, 0, 12)
    );
}

/**
 * Ottiene le impostazioni di customizzazione per l'editor
 * Removes deprecated parameters to prevent OnlyOffice warnings
 */
function getOnlyOfficeCustomization(array $additionalSettings = []): array {
    global $ONLYOFFICE_CUSTOMIZATION;

    $config = array_merge_recursive($ONLYOFFICE_CUSTOMIZATION, $additionalSettings);

    // Remove deprecated parameters that cause OnlyOffice errors
    // 'chat' is now in editorConfig.coEditing, not customization
    unset($config['chat']);
    // 'showReviewChanges' is deprecated - use 'review' section instead
    unset($config['showReviewChanges']);

    return $config;
}