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
// CRITICAL: OnlyOffice Document Server runs on port 8083 (not 8888!)
// Internal URL: used by PHP backend to reach the OnlyOffice container (healthcheck, server-side proxying)
define('ONLYOFFICE_INTERNAL_URL', getenv('ONLYOFFICE_SERVER_URL') ?: 'http://localhost:8083');
define('ONLYOFFICE_INTERNAL_API_URL', ONLYOFFICE_INTERNAL_URL . '/web-apps/apps/api/documents/api.js');

// Public URL: used by browsers to load the OnlyOffice web app + api.js.
// IMPORTANT: api.js must be served from a URL that preserves the expected "/web-apps/apps/api/documents/api.js" path,
// otherwise OnlyOffice will attempt to load editor assets from wrong paths (causing 404 pages).
//
// Default behavior:
// - production: use same-origin reverse proxy at BASE_URL/onlyoffice
// - development: fallback to internal URL (localhost:8083), unless overridden
$onlyOfficePublicBase = getenv('ONLYOFFICE_PUBLIC_URL');
if (empty($onlyOfficePublicBase)) {
    if (defined('PRODUCTION_MODE') && PRODUCTION_MODE) {
        $onlyOfficePublicBase = BASE_URL . '/onlyoffice';
    } else {
        $onlyOfficePublicBase = ONLYOFFICE_INTERNAL_URL;
    }
}
define('ONLYOFFICE_PUBLIC_URL', rtrim($onlyOfficePublicBase, '/'));
define('ONLYOFFICE_PUBLIC_API_URL', ONLYOFFICE_PUBLIC_URL . '/web-apps/apps/api/documents/api.js');

// Backwards compatible aliases (keep existing constant names used across codebase)
define('ONLYOFFICE_SERVER_URL', ONLYOFFICE_INTERNAL_URL);
define('ONLYOFFICE_API_URL', ONLYOFFICE_INTERNAL_API_URL);

// JWT Authentication Settings
define('ONLYOFFICE_JWT_SECRET', getenv('ONLYOFFICE_JWT_SECRET') ?: '16211f3e8588521503a1265ef24f6bda02b064c6b0ed5a1922d0f36929a613af');
define('ONLYOFFICE_JWT_HEADER', 'Authorization');
define('ONLYOFFICE_JWT_ENABLED', true);

// Document Server Endpoints
// ONLYOFFICE_DOWNLOAD_URL - Must be reachable from Docker container
//
// BUG-135 FIX v2: Docker Location Detection (not request-based)
// =============================================================
// PROBLEM SCENARIO: Cloudflare Tunnel on Local Machine
// - User accesses: https://app.nexiosolution.it (via Cloudflare tunnel)
// - Server: Windows XAMPP localhost:8888 (SAME machine)
// - OnlyOffice Docker: localhost:8083 (SAME machine)
// - Cloudflare tunnel terminates on localhost:8888
//
// ISSUE with v1 (request-based detection):
// - HTTP_HOST = "app.nexiosolution.it" (looks like production)
// - isLocalRequest = FALSE (wrong!)
// - DOWNLOAD_URL = https://app.nexiosolution.it/... (Docker can't reach this!)
// - OnlyOffice Docker tries to download from public URL but fails
//
// SOLUTION v2: Detect WHERE Docker container runs, not WHERE request comes from
// The key insight is: if OnlyOffice Docker is LOCAL, it needs LOCAL URLs.
// We detect this by checking if the Docker container can be reached on localhost.
//
// Decision Matrix:
// | Server Location | Docker Location | Download URL |
// |-----------------|-----------------|--------------|
// | Local (XAMPP)   | Local           | host.docker.internal:8888 |
// | Remote (VPS)    | Remote          | Public BASE_URL |
// | Remote (VPS)    | Local (N/A)     | Public BASE_URL |

// Step 1: Check for explicit ENV overrides (highest priority)
$downloadOverride = getenv('ONLYOFFICE_DOWNLOAD_URL');
$callbackOverride = getenv('ONLYOFFICE_CALLBACK_URL');

// Step 2: Detect if OnlyOffice Docker is LOCAL
// We check this by seeing if ONLYOFFICE_INTERNAL_URL points to localhost
// If the PHP process can reach localhost:8083, Docker is local.
$onlyOfficeHost = parse_url(ONLYOFFICE_INTERNAL_URL, PHP_URL_HOST) ?? 'localhost';
$onlyOfficePort = parse_url(ONLYOFFICE_INTERNAL_URL, PHP_URL_PORT) ?? 8083;

$isDockerLocal = (
    $onlyOfficeHost === 'localhost' ||
    $onlyOfficeHost === '127.0.0.1' ||
    $onlyOfficeHost === '::1'
);

// Step 3: Also detect if THIS SERVER is local (Windows/WSL/Mac development)
// This is needed to determine the correct host.docker.internal address
$isServerLocal = false;
$dockerHost = getenv('ONLYOFFICE_DEV_HOST');

if (empty($dockerHost)) {
    // Auto-detect based on OS where PHP runs
    $isWindows = (PHP_OS_FAMILY === 'Windows' || stripos(PHP_OS, 'WIN') === 0);
    $isWSL = (stripos(php_uname(), 'microsoft') !== false || file_exists('/proc/sys/fs/binfmt_misc/WSLInterop'));
    $isMac = (PHP_OS_FAMILY === 'Darwin');

    if ($isWindows || $isWSL || $isMac) {
        $isServerLocal = true;
        // Docker Desktop (Windows/Mac/WSL) uses special DNS name
        $dockerHost = 'host.docker.internal';
    } else {
        // Linux server: could be local dev or production
        // Check if we're likely a dev machine by looking for typical markers
        $hostname = gethostname() ?: '';
        $isServerLocal = (
            stripos($hostname, 'desktop') !== false ||
            stripos($hostname, 'laptop') !== false ||
            stripos($hostname, 'dev') !== false ||
            file_exists('/home/' . get_current_user() . '/.local') // typical user desktop
        );

        if ($isServerLocal) {
            // Try to detect LAN IP for local Linux dev
            try {
                $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
                if ($sock) {
                    @socket_connect($sock, '8.8.8.8', 53);
                    @socket_getsockname($sock, $addr);
                    if (!empty($addr) && $addr !== '127.0.0.1') {
                        $dockerHost = $addr;
                    }
                    @socket_close($sock);
                }
            } catch (Throwable $e) {
                // ignore
            }

            if (empty($dockerHost)) {
                $ips = @gethostbynamel(gethostname()) ?: [];
                foreach ($ips as $ip) {
                    if ($ip !== '127.0.0.1') { $dockerHost = $ip; break; }
                }
            }

            if (empty($dockerHost)) {
                $dockerHost = '172.17.0.1'; // Docker bridge network fallback
            }
        }
    }
}

// Step 4: Determine if we need local Docker URLs
// Use local URLs when BOTH Docker AND server are local
$useLocalDockerUrls = $isDockerLocal && $isServerLocal && !empty($dockerHost);

// Step 5: Build the URLs based on Docker location
if (!empty($downloadOverride)) {
    // ENV override takes absolute priority
    define('ONLYOFFICE_DOWNLOAD_URL', $downloadOverride);
} elseif ($useLocalDockerUrls) {
    // BUG-135 v2: Docker is local - ALWAYS use internal address regardless of HTTP_HOST
    // This ensures OnlyOffice Docker can reach Apache even when user accesses via Cloudflare tunnel
    define('ONLYOFFICE_DOWNLOAD_URL', 'http://' . $dockerHost . ':8888/CollaboraNexio/api/documents/download_for_editor.php');
} else {
    // Docker is remote OR server is remote: Use public BASE_URL
    define('ONLYOFFICE_DOWNLOAD_URL', BASE_URL . '/api/documents/download_for_editor.php');
}

if (!empty($callbackOverride)) {
    // ENV override takes absolute priority
    define('ONLYOFFICE_CALLBACK_URL', $callbackOverride);
} elseif ($useLocalDockerUrls) {
    // BUG-135 v2: Docker is local - ALWAYS use internal address
    define('ONLYOFFICE_CALLBACK_URL', 'http://' . $dockerHost . ':8888/CollaboraNexio/api/documents/save_document.php');
} else {
    // Docker is remote OR server is remote: Use public BASE_URL
    define('ONLYOFFICE_CALLBACK_URL', BASE_URL . '/api/documents/save_document.php');
}

// Debug logging
$requestHost = $_SERVER['HTTP_HOST'] ?? 'CLI';
$shouldLog = !defined('PRODUCTION_MODE') || !PRODUCTION_MODE || (defined('DEBUG_MODE') && DEBUG_MODE);
if ($shouldLog) {
    error_log('[OnlyOffice Config] BUG-135 v2 Detection:');
    error_log('[OnlyOffice Config]   Request Host: ' . $requestHost);
    error_log('[OnlyOffice Config]   OnlyOffice URL: ' . ONLYOFFICE_INTERNAL_URL);
    error_log('[OnlyOffice Config]   Is Docker Local: ' . ($isDockerLocal ? 'YES' : 'NO'));
    error_log('[OnlyOffice Config]   Is Server Local: ' . ($isServerLocal ? 'YES' : 'NO'));
    error_log('[OnlyOffice Config]   Docker Host: ' . ($dockerHost ?? 'N/A'));
    error_log('[OnlyOffice Config]   Use Local URLs: ' . ($useLocalDockerUrls ? 'YES' : 'NO'));
    error_log('[OnlyOffice Config]   Download URL: ' . ONLYOFFICE_DOWNLOAD_URL);
    error_log('[OnlyOffice Config]   Callback URL: ' . ONLYOFFICE_CALLBACK_URL);
}

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
        'info' => 'Nexio - Sistema di gestione documentale',
        'logo' => BASE_URL . '/assets/images/logo-nexio.webp',
        'mail' => 'support@nexiosolution.it',
        'name' => 'Nexio',
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