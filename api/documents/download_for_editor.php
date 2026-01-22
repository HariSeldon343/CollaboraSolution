<?php
/**
 * Download File for Editor - FIXED VERSION
 *
 * Secure endpoint per OnlyOffice per scaricare i file da editare
 * Fixed to properly handle JWT tokens from OnlyOffice
 *
 * @version 1.1.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// No standard session for OnlyOffice requests
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Include required files
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/document_editor_helper.php';
require_once __DIR__ . '/../../includes/file_access.php';

// CORS headers for OnlyOffice Document Server access
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Set headers for security
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

/**
 * Send error response and exit
 */
function sendError(string $message, int $code = 403): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);

    if (DEBUG_MODE) {
        error_log("[OnlyOffice Download] Error $code: $message");
    }

    exit();
}

try {
    /**
     * Create a minimal valid blank DOCX (OpenXML) so empty placeholders can be opened in OnlyOffice.
     * Uses ZipArchive to avoid shipping binary templates.
     */
    function createBlankDocxBytes(): string {
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZipArchive non disponibile per generare DOCX vuoto');
        }

        $tmpDir = sys_get_temp_dir();
        $tmpFile = tempnam($tmpDir, 'blank_docx_');
        if ($tmpFile === false) {
            throw new Exception('Impossibile creare file temporaneo');
        }
        // Ensure .docx extension for some Zip implementations
        $docxPath = $tmpFile . '.docx';
        @rename($tmpFile, $docxPath);

        $zip = new ZipArchive();
        if ($zip->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Impossibile creare archivio DOCX');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');

        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>'
            . '<w:p><w:r><w:t></w:t></w:r></w:p>'
            . '<w:sectPr/>'
            . '</w:body>'
            . '</w:document>');

        $zip->close();

        $bytes = @file_get_contents($docxPath);
        @unlink($docxPath);

        if ($bytes === false || $bytes === '') {
            throw new Exception('Impossibile leggere DOCX generato');
        }
        return $bytes;
    }

    /**
     * Best-effort: ensure word/document.xml contains a <w:sectPr/> inside <w:body>.
     * Some OnlyOffice configurations may render DOCX without section properties as blank.
     * We patch the file in place (minimal change) to improve compatibility.
     */
    function ensureDocxHasSectPrOnDisk(string $docxPath): bool {
        if (!class_exists('ZipArchive')) return false;
        if (!is_file($docxPath) || !is_writable($docxPath)) return false;

        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) return false;

        try {
            $xml = $zip->getFromName('word/document.xml');
            if ($xml === false || $xml === '') {
                $zip->close();
                return false;
            }
            if (strpos($xml, '<w:sectPr') !== false) {
                $zip->close();
                return false;
            }
            if (strpos($xml, '</w:body>') === false) {
                $zip->close();
                return false;
            }
            $patched = str_replace('</w:body>', '<w:sectPr/></w:body>', $xml);
            $zip->addFromString('word/document.xml', $patched);
            $zip->close();
            return true;
        } catch (Throwable $e) {
            try { $zip->close(); } catch (Throwable $_) {}
            return false;
        }
    }
    // ENHANCED LOGGING FOR DEBUGGING
    if (DEBUG_MODE) {
        error_log('=== OnlyOffice Download Request (FIXED) ===');
        error_log('Request Method: ' . $_SERVER['REQUEST_METHOD']);
        error_log('Request URI: ' . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
        error_log('Remote Address: ' . ($_SERVER['REMOTE_ADDR'] ?? 'N/A'));
        error_log('User Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'N/A'));
        error_log('JWT Enabled: ' . (ONLYOFFICE_JWT_ENABLED ? 'TRUE' : 'FALSE'));

        // Log all headers for debugging
        $headers = getallheaders();
        foreach ($headers as $name => $value) {
            if (stripos($name, 'authorization') !== false || stripos($name, 'token') !== false) {
                error_log("Header $name: " . substr($value, 0, 50) . '...');
            }
        }
    }

    /**
     * Best-effort: if a DOCX is truly empty (no text, no placeholders), inject a minimal bootstrap
     * content so the user does not see a "white page" and the placeholder engine has something to replace.
     * This is conservative: it ONLY triggers when the document has no visible text.
     */
    function ensureDocxHasBootstrapContentOnDisk(string $docxPath): bool {
        if (!class_exists('ZipArchive')) return false;
        if (!is_file($docxPath) || !is_writable($docxPath)) return false;

        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) return false;

        try {
            $xml = $zip->getFromName('word/document.xml');
            if ($xml === false || $xml === '') {
                $zip->close();
                return false;
            }

            // If there are placeholders already, do nothing.
            if (strpos($xml, '{{') !== false) {
                $zip->close();
                return false;
            }

            // Detect visible text inside <w:t>. If any non-whitespace exists, keep it.
            $hasText = false;
            if (preg_match_all('/<w:t[^>]*>(.*?)<\\/w:t>/si', $xml, $m)) {
                foreach (($m[1] ?? []) as $chunk) {
                    $plain = trim(html_entity_decode((string)$chunk, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    if ($plain !== '') { $hasText = true; break; }
                }
            }
            if ($hasText) {
                $zip->close();
                return false;
            }

            $escape = function (string $s): string {
                return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
            };
            $paragraphs = [
                'Documento placeholder (bozza)',
                '',
                'Titolo documento: {{doc_title}}',
                'Azienda: {{company_name}}',
                'Data: {{doc_date}}',
                'Versione: {{doc_version}}',
                '',
                'Scopo del sistema (sintesi):',
                '{{scope}}',
                '',
                'Contenuto documento (bozza):',
                '{{body}}',
                '',
                'Note:',
                '{{notes}}',
            ];

            $body = '';
            foreach ($paragraphs as $p) {
                $t = $escape((string)$p);
                $t = str_replace("\r\n", "\n", $t);
                $parts = explode("\n", $t);
                $runs = [];
                foreach ($parts as $idx => $part) {
                    $runs[] = '<w:t xml:space="preserve">' . $part . '</w:t>';
                    if ($idx < count($parts) - 1) $runs[] = '<w:br/>';
                }
                $body .= '<w:p><w:r>' . implode('', $runs) . '</w:r></w:p>';
            }

            $patched = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:body>' . $body . '<w:sectPr/></w:body>'
                . '</w:document>';

            $zip->addFromString('word/document.xml', $patched);
            $zip->close();
            return true;
        } catch (Throwable $e) {
            try { $zip->close(); } catch (Throwable $_) {}
            return false;
        }
    }

    // Get file_id parameter
    $file_id = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;

    if ($file_id <= 0) {
        if (DEBUG_MODE) {
            error_log('ERROR: Invalid file ID: ' . $file_id);
        }
        sendError('ID file non valido', 400);
    }

    // CRITICAL FIX: Handle JWT token from multiple sources
    $token = '';
    $tokenSource = 'NONE';

    // 1) Query parameter (backwards compatibility)
    if (!empty($_GET['token'])) {
        $token = $_GET['token'];
        $tokenSource = 'QUERY_PARAM';
    }

    // 2) Authorization header (OnlyOffice standard way)
    if (empty($token)) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // Fallback: some stacks don't populate $_SERVER['HTTP_AUTHORIZATION']
        // (e.g. certain Apache/FastCGI setups behind proxies). Use getallheaders().
        if (empty($authHeader) && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers[ONLYOFFICE_JWT_HEADER] ?? $headers['Authorization'] ?? $headers['authorization'] ?? '';
            if (!empty($authHeader)) {
                $tokenSource = 'AUTH_HEADER_FALLBACK';
            }
        }

        if (!empty($authHeader)) {
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                $token = $matches[1];
                if ($tokenSource === 'NONE') {
                    $tokenSource = 'AUTH_HEADER';
                }
            } else {
                // Some proxies/clients may send the raw JWT without "Bearer "
                $candidate = trim($authHeader);
                if (substr_count($candidate, '.') === 2) {
                    $token = $candidate;
                    if ($tokenSource === 'NONE') {
                        $tokenSource = 'AUTH_HEADER_RAW';
                    }
                }
            }
        }
    }

    // 3) Token in POST body (OnlyOffice sometimes sends it this way)
    if (empty($token) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $data = json_decode($input, true);
            if (isset($data['token']) && is_string($data['token']) && $data['token'] !== '') {
                $token = $data['token'];
                $tokenSource = 'POST_BODY';
            }
        }
    }

    if (DEBUG_MODE) {
        error_log('File ID: ' . $file_id);
        error_log('Token source: ' . $tokenSource);
        error_log('Token present: ' . (!empty($token) ? 'YES' : 'NO'));
        if (!empty($token)) {
            error_log('Token length: ' . strlen($token));
            error_log('Token preview: ' . substr($token, 0, 50) . '...');
        }
    }

    // CRITICAL: Allow access without token in development mode
    if (ONLYOFFICE_JWT_ENABLED && empty($token)) {
        // In development, allow tokenless access if from local/docker
        $isLocalRequest = false;
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        // Check if request is from Docker or localhost
        if (in_array($remoteAddr, ['127.0.0.1', '::1', 'host.docker.internal']) ||
            strpos($remoteAddr, '172.') === 0 || // Docker network
            strpos($remoteAddr, '192.168.') === 0) { // Local network
            $isLocalRequest = true;
        }

        if (!$isLocalRequest || PRODUCTION_MODE) {
            if (DEBUG_MODE) {
                error_log('ERROR: Token missing and not a local request');
            }
            sendError('Token mancante', 401);
        } else {
            if (DEBUG_MODE) {
                error_log('WARNING: Allowing tokenless access from local/docker in development mode');
            }
        }
    }

    // Verify JWT token if provided
    $payload = [];
    if (!empty($token) && ONLYOFFICE_JWT_ENABLED) {
        $payload = verifyOnlyOfficeJWT($token);
        if ($payload === false) {
            error_log('Invalid JWT token for file download: ' . $file_id);
            error_log('Token was: ' . substr($token, 0, 100) . '...');
            sendError('Token non valido', 403);
        }

        if (DEBUG_MODE) {
            error_log('JWT payload verified successfully');
            error_log('Payload: ' . json_encode($payload));
        }
    }

    // Extract data from token or use defaults
    $token_file_id = $payload['file_id'] ?? $file_id;
    $token_user_id = $payload['user_id'] ?? 0;
    $token_tenant_id = $payload['tenant_id'] ?? 0;
    $session_token = $payload['session_token'] ?? '';
    $permissions = $payload['permissions'] ?? ['download' => true]; // Default allow download

    // Verify file ID matches if token provided
    if (!empty($token) && ONLYOFFICE_JWT_ENABLED && $token_file_id !== $file_id) {
        error_log(sprintf(
            'File ID mismatch: requested=%d, token=%d',
            $file_id,
            $token_file_id
        ));
        sendError('Token non valido per questo file', 403);
    }

    // Check download permission
    if (!empty($token) && ONLYOFFICE_JWT_ENABLED &&
        (!isset($permissions['download']) || !$permissions['download'])) {
        error_log("User $token_user_id does not have download permission for file $file_id");
        sendError('Permessi insufficienti', 403);
    }

    // Get database connection
    $db = Database::getInstance();

    // Get file information
    if (!empty($token) && ONLYOFFICE_JWT_ENABLED && $token_tenant_id > 0) {
        // With JWT, enforce tenant isolation
        $file = $db->fetchOne(
            "SELECT f.* FROM files f WHERE f.id = ? AND f.tenant_id = ? AND f.deleted_at IS NULL",
            [$file_id, $token_tenant_id]
        );
    } else {
        // Without JWT or in dev mode, allow file access by ID only
        $file = $db->fetchOne(
            "SELECT f.* FROM files f WHERE f.id = ? AND f.deleted_at IS NULL",
            [$file_id]
        );
    }

    if (!$file) {
        error_log("File not found or access denied: file_id=$file_id, tenant_id=$token_tenant_id");
        sendError('File non trovato', 404);
    }

    // Enforce assignment-aware access for the user embedded in the JWT (OnlyOffice user)
    if ($token_user_id > 0) {
        $tokenUser = $db->fetchOne(
            "SELECT id, role
             FROM users
             WHERE id = ?
               AND deleted_at IS NULL
             LIMIT 1",
            [$token_user_id]
        );

        $tokenUserRole = $tokenUser && !empty($tokenUser['role']) ? (string)$tokenUser['role'] : 'user';

        $access = hasFileOrFolderAccess($db, (int)$file_id, (int)$token_user_id, $tokenUserRole, (int)$file['tenant_id']);
        if (!$access['has_access']) {
            if (DEBUG_MODE) {
                error_log('[OnlyOffice Download] Access denied by assignment policy: ' . json_encode($access));
            }
            sendError('Accesso negato', 403);
        }
    }

    // Build physical file path (with legacy fallbacks)
    $filePath = UPLOAD_PATH . '/' . $file['tenant_id'] . '/' . $file['file_path'];

    if (!file_exists($filePath)) {
        $tried = [$filePath];
        // Fallback 1: legacy stored with full path or subfolder - try basename
        $basename = basename($file['file_path']);
        $candidate1 = UPLOAD_PATH . '/' . $file['tenant_id'] . '/' . $basename;
        if (file_exists($candidate1)) {
            $filePath = $candidate1;
        } else {
            $tried[] = $candidate1;
            // Fallback 2: legacy path relative to project root
            $projectRoot = dirname(__DIR__, 2);
            $candidate2 = rtrim($projectRoot, '/') . '/' . ltrim($file['file_path'], '/');
            if (file_exists($candidate2)) {
                $filePath = $candidate2;
            } else {
                $tried[] = $candidate2;
                error_log('Physical file not found, tried: ' . implode(' | ', $tried));
                sendError('File fisico non trovato', 404);
            }
        }
    }

    // Verify file is readable
    if (!is_readable($filePath)) {
        error_log("File not readable: $filePath");
        sendError('File non leggibile', 500);
    }

    // Update session activity if session token provided
    if (!empty($session_token)) {
        updateSessionActivity($session_token);
    }

    // Get file info
    $fileSize = filesize($filePath);
    $mimeType = $file['mime_type'] ?? 'application/octet-stream';
    $fileName = $file['name'];

    // Best-effort repair for DOCX rendering issues (avoid "blank pages" in editor)
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext === 'docx' && $fileSize > 0 && $fileSize < (50 * 1024 * 1024)) {
        $patched = ensureDocxHasSectPrOnDisk($filePath);
        // Only bootstrap truly empty docs for Compliance deliverables (avoid surprising users for normal blank docs).
        $allowBootstrap = false;
        try {
            $hasCompliance = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'compliance_artifacts'
                 LIMIT 1"
            );
            if ($hasCompliance) {
                $row = $db->fetchOne("SELECT 1 AS ok FROM compliance_artifacts WHERE file_id = ? LIMIT 1", [$file_id]);
                $allowBootstrap = (bool)$row;
            }
        } catch (Throwable $_) {
            $allowBootstrap = false;
        }

        $bootstrapped = $allowBootstrap ? ensureDocxHasBootstrapContentOnDisk($filePath) : false;
        $patched = $patched || $bootstrapped;
        if ($patched) {
            clearstatcache(true, $filePath);
            $fileSize = filesize($filePath);
            if (DEBUG_MODE) {
                error_log('[OnlyOffice Download] Patched DOCX in place for file_id=' . $file_id . ' (sectPr/bootstrap)');
            }
        }
    }

    // BUG-135 v4: Empty files are allowed - we serve a valid blank document when possible
    if ($fileSize === 0) {
        error_log(sprintf(
            '[BUG-135 v4] Empty file detected during download: id=%d, name=%s, path=%s',
            $file_id,
            $fileName,
            $filePath
        ));

        if ($ext === 'docx') {
            $blank = createBlankDocxBytes();
            $mimeType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            $fileSize = strlen($blank);

            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . $fileSize);
            header('Content-Disposition: attachment; filename="' . addslashes($fileName) . '"');
            header('Cache-Control: private, max-age=3600');
            header('Pragma: private');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
            header('X-Empty-File: true');

            if (ob_get_level()) {
                ob_end_clean();
            }

            echo $blank;
            exit();
        }

        // For plain text-like formats, an empty body is still a valid document
        if (in_array($ext, ['txt', 'csv'], true)) {
            header('Content-Type: ' . ($ext === 'csv' ? 'text/csv; charset=utf-8' : 'text/plain; charset=utf-8'));
            header('Content-Length: 0');
            header('Content-Disposition: attachment; filename="' . addslashes($fileName) . '"');
            header('Cache-Control: private, max-age=3600');
            header('Pragma: private');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
            header('X-Empty-File: true');
            if (ob_get_level()) {
                ob_end_clean();
            }
            exit();
        }

        // Unknown binary formats: still fail clearly
        sendError('File vuoto - impossibile aprire questo formato', 400);
    }

    // Log the successful download
    if (DEBUG_MODE) {
        error_log(sprintf(
            'OnlyOffice downloading file SUCCESS: id=%d, name=%s, size=%d, tenant=%d',
            $file_id,
            $fileName,
            $fileSize,
            $file['tenant_id']
        ));
    }

    // Audit log (only if we have user context)
    if ($token_user_id > 0) {
        try {
            // Use values compatible with audit_logs CHECK constraints
            $db->insert('audit_logs', [
                'tenant_id' => (int)$file['tenant_id'],
                'user_id' => $token_user_id,
                'action' => 'download',
                'entity_type' => 'file',
                'entity_id' => $file_id,
                'description' => "Download file per editor: {$fileName}",
                'metadata' => json_encode([
                    'file_name' => $fileName,
                    'file_size' => $fileSize,
                    'token_source' => $tokenSource,
                    'context' => 'onlyoffice_download_for_editor'
                ]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'severity' => 'info',
                'status' => 'success',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            // Don't fail download if audit fails
            error_log('Failed to log download audit: ' . $e->getMessage());
        }
    }

    // Set headers for file download
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $fileSize);
    header('Content-Disposition: attachment; filename="' . addslashes($fileName) . '"');
    header('Cache-Control: private, max-age=3600');
    header('Pragma: private');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');

    // Prevent any output before file
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Handle range requests for large files
    $range = $_SERVER['HTTP_RANGE'] ?? '';

    if (!empty($range)) {
        // Parse range header
        if (preg_match('/bytes=(\d+)-(\d+)?/', $range, $matches)) {
            $start = (int)$matches[1];
            $end = isset($matches[2]) ? (int)$matches[2] : $fileSize - 1;

            if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
                http_response_code(416); // Range Not Satisfiable
                header('Content-Range: bytes */' . $fileSize);
                exit();
            }

            $length = $end - $start + 1;

            http_response_code(206); // Partial Content
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
            header('Content-Length: ' . $length);

            // Read and send partial content
            $fp = fopen($filePath, 'rb');
            fseek($fp, $start);

            $chunkSize = 8192;
            $sent = 0;

            while (!feof($fp) && $sent < $length && connection_status() === 0) {
                $buffer = fread($fp, min($chunkSize, $length - $sent));
                echo $buffer;
                flush();
                $sent += strlen($buffer);
            }

            fclose($fp);
        }
    } else {
        // Send entire file
        // For large files, read in chunks to avoid memory issues
        if ($fileSize > 10 * 1024 * 1024) { // > 10MB
            $fp = fopen($filePath, 'rb');

            $chunkSize = 8192;
            while (!feof($fp) && connection_status() === 0) {
                echo fread($fp, $chunkSize);
                flush();
            }

            fclose($fp);
        } else {
            // Small file, read all at once
            readfile($filePath);
        }
    }

    exit();

} catch (Exception $e) {
    error_log('Download for editor error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendError('Errore interno del server', 500);
}