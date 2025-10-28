<?php
/**
 * Create Document API Endpoint
 *
 * Crea nuovi documenti vuoti (DOCX, XLSX, PPTX, TXT)
 * utilizzando template minimali
 *
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// Include centralized API authentication
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/file_helper.php';
require_once __DIR__ . '/../../includes/db.php';

// Initialize API environment
initializeApiEnvironment();

// Verify authentication
verifyApiAuthentication();

// Verify CSRF token
verifyApiCsrfToken();

// Get current user info
$userInfo = getApiUserInfo();
$userId = $userInfo['user_id'];
$tenantId = $userInfo['tenant_id'];

// Get database connection
$db = Database::getInstance();

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
$type = $input['type'] ?? '';
$name = trim($input['name'] ?? '');
$folderId = isset($input['folder_id']) ? (int)$input['folder_id'] : null;

// Supported document types
$supportedTypes = ['docx', 'xlsx', 'pptx', 'txt'];

if (!in_array($type, $supportedTypes)) {
    apiError('Tipo di documento non supportato. Tipi validi: ' . implode(', ', $supportedTypes), 400);
}

if (empty($name)) {
    apiError('Il nome del documento è obbligatorio', 400);
}

// Sanitize document name
$name = preg_replace('/[^a-zA-Z0-9\s\-_\.]/', '', $name);
if (strlen($name) > 100) {
    $name = substr($name, 0, 100);
}

// Add extension if not present
if (!str_ends_with(strtolower($name), '.' . $type)) {
    $name .= '.' . $type;
}

try {
    // Validate folder access if specified
    if ($folderId !== null && $folderId > 0) {
        $folder = $db->fetchOne(
            "SELECT * FROM files
             WHERE id = ? AND tenant_id = ? AND is_folder = 1 AND deleted_at IS NULL",
            [$folderId, $tenantId]
        );

        if (!$folder) {
            apiError('Cartella non trovata o accesso negato', 404);
        }
    }

    // Check if file already exists
    $existingFile = $db->fetchOne(
        "SELECT id FROM files
         WHERE tenant_id = ? AND name = ? AND folder_id <=> ? AND deleted_at IS NULL",
        [$tenantId, $name, $folderId]
    );

    if ($existingFile) {
        // Add timestamp to make name unique
        $baseName = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $name = $baseName . '_' . date('YmdHis') . '.' . $extension;
    }

    // Create document based on type
    $result = createDocument($type, $name, $tenantId, $folderId, $userId, $db);

    if ($result['success']) {
        apiSuccess([
            'file' => $result['file']
        ], 'Documento creato con successo');
    } else {
        apiError($result['error'], 500);
    }

} catch (Exception $e) {
    logApiError('Create Document', $e);
    apiError(
        'Errore durante la creazione del documento',
        500,
        DEBUG_MODE ? ['debug' => $e->getMessage()] : null
    );
}

/**
 * Create a new document from template
 */
function createDocument(string $type, string $name, int $tenantId, ?int $folderId, int $userId, $db): array {
    try {
        // Get template path or create content
        $templateContent = getTemplateContent($type);

        // Create file path
        $uploadPath = FileHelper::getTenantUploadPath($tenantId);
        $safeName = FileHelper::generateSafeFilename($name, $uploadPath);
        $fullPath = $uploadPath . '/' . $safeName;

        // Save document
        if (!file_put_contents($fullPath, $templateContent)) {
            return [
                'success' => false,
                'error' => 'Impossibile creare il file'
            ];
        }

        // Get file info
        $mimeType = FileHelper::getMimeType($fullPath);
        $fileSize = filesize($fullPath);
        $fileHash = FileHelper::getFileHash($fullPath);
        $editorFormat = FileHelper::getEditorFormat($mimeType);

        // Create relative path for database (store only unique filename to match files schema)
        $relativePath = $safeName;

        // Insert file record in database
        $fileId = $db->insert('files', [
            'tenant_id' => $tenantId,
            'name' => $name,
            'file_path' => $relativePath,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'file_type' => $type,
            'folder_id' => $folderId,
            'uploaded_by' => $userId,
            'is_folder' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Note: document_editor table is not required for basic functionality
        // Editor sessions are tracked via document_editor_sessions when files are opened

        // Log audit (optional - if table exists)
        try {
            $db->insert('audit_logs', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'action' => 'document_created',
                'entity_type' => 'file',
                'entity_id' => $fileId,
                'description' => "Documento creato: {$name}.{$type}",
                'new_values' => json_encode([
                    'document_name' => $name,
                    'document_type' => $type,
                    'folder_id' => $folderId
                ]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'severity' => 'info',
                'status' => 'success',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $auditError) {
            // Audit logging is optional - don't fail if table doesn't exist
            error_log('Audit log failed: ' . $auditError->getMessage());
        }

        return [
            'success' => true,
            'file' => [
                'id' => $fileId,
                'name' => $name,
                'path' => '/' . $relativePath,
                'type' => $type,
                'size' => $fileSize,
                'mime_type' => $mimeType,
                'is_editable' => true,
                'editor_format' => $editorFormat,
                'icon' => FileHelper::getFileIcon($type),
                'formatted_size' => FileHelper::formatFileSize($fileSize),
                'created_at' => date('Y-m-d H:i:s')
            ]
        ];

    } catch (Exception $e) {
        // Clean up file if database operation failed
        if (isset($fullPath) && file_exists($fullPath)) {
            unlink($fullPath);
        }

        return [
            'success' => false,
            'error' => 'Errore durante la creazione: ' . $e->getMessage()
        ];
    }
}

/**
 * Get template content for document type
 */
function getTemplateContent(string $type): string {
    $templatesDir = dirname(dirname(__DIR__)) . '/templates';

    switch ($type) {
        case 'docx':
            return createMinimalDocx();

        case 'xlsx':
            return createMinimalXlsx();

        case 'pptx':
            return createMinimalPptx();

        case 'txt':
            return ''; // Empty text file

        default:
            throw new Exception('Tipo di documento non supportato: ' . $type);
    }
}

/**
 * Create minimal DOCX file
 */
function createMinimalDocx(): string {
    // Create a minimal DOCX structure
    $zip = new ZipArchive();
    $tempFile = tempnam(sys_get_temp_dir(), 'docx');
    unlink($tempFile);  // Delete empty file to avoid PHP 8.2 deprecation warning

    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Impossibile creare il file DOCX');
    }

    // Add required structure
    // _rels/.rels
    $relsContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
    $zip->addFromString('_rels/.rels', $relsContent);

    // [Content_Types].xml
    $contentTypesContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>';
    $zip->addFromString('[Content_Types].xml', $contentTypesContent);

    // word/_rels/document.xml.rels
    $wordRelsContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
</Relationships>';
    $zip->addFromString('word/_rels/document.xml.rels', $wordRelsContent);

    // word/document.xml
    $documentContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
        <w:p>
            <w:r>
                <w:t></w:t>
            </w:r>
        </w:p>
    </w:body>
</w:document>';
    $zip->addFromString('word/document.xml', $documentContent);

    $zip->close();

    $content = file_get_contents($tempFile);
    unlink($tempFile);

    return $content;
}

/**
 * Create minimal XLSX file
 */
function createMinimalXlsx(): string {
    $zip = new ZipArchive();
    $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
    unlink($tempFile);  // Delete empty file to avoid PHP 8.2 deprecation warning

    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Impossibile creare il file XLSX');
    }

    // _rels/.rels
    $relsContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    $zip->addFromString('_rels/.rels', $relsContent);

    // [Content_Types].xml
    $contentTypesContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';
    $zip->addFromString('[Content_Types].xml', $contentTypesContent);

    // xl/_rels/workbook.xml.rels
    $xlRelsContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $xlRelsContent);

    // xl/workbook.xml
    $workbookContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>';
    $zip->addFromString('xl/workbook.xml', $workbookContent);

    // xl/worksheets/sheet1.xml
    $sheet1Content = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData/>
</worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Content);

    // xl/styles.xml
    $stylesContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="1">
        <font/>
    </fonts>
    <fills count="2">
        <fill>
            <patternFill patternType="none"/>
        </fill>
        <fill>
            <patternFill patternType="gray125"/>
        </fill>
    </fills>
    <borders count="1">
        <border/>
    </borders>
    <cellStyleXfs count="1">
        <xf/>
    </cellStyleXfs>
    <cellXfs count="1">
        <xf/>
    </cellXfs>
</styleSheet>';
    $zip->addFromString('xl/styles.xml', $stylesContent);

    $zip->close();

    $content = file_get_contents($tempFile);
    unlink($tempFile);

    return $content;
}

/**
 * Create minimal PPTX file
 */
function createMinimalPptx(): string {
    $zip = new ZipArchive();
    $tempFile = tempnam(sys_get_temp_dir(), 'pptx');
    unlink($tempFile);  // Delete empty file to avoid PHP 8.2 deprecation warning

    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Impossibile creare il file PPTX');
    }

    // _rels/.rels
    $relsContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>
</Relationships>';
    $zip->addFromString('_rels/.rels', $relsContent);

    // [Content_Types].xml
    $contentTypesContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>
    <Override PartName="/ppt/slides/slide1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>
    <Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>
    <Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>
</Types>';
    $zip->addFromString('[Content_Types].xml', $contentTypesContent);

    // ppt/_rels/presentation.xml.rels
    $pptRelsContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
</Relationships>';
    $zip->addFromString('ppt/_rels/presentation.xml.rels', $pptRelsContent);

    // ppt/presentation.xml
    $presentationContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <p:sldMasterIdLst>
        <p:sldMasterId id="2147483648" r:id="rId1"/>
    </p:sldMasterIdLst>
    <p:sldIdLst>
        <p:sldId id="256" r:id="rId2"/>
    </p:sldIdLst>
    <p:sldSz cx="9144000" cy="6858000"/>
    <p:notesSz cx="6858000" cy="9144000"/>
</p:presentation>';
    $zip->addFromString('ppt/presentation.xml', $presentationContent);

    // ppt/slides/_rels/slide1.xml.rels
    $slide1RelsContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
</Relationships>';
    $zip->addFromString('ppt/slides/_rels/slide1.xml.rels', $slide1RelsContent);

    // ppt/slides/slide1.xml
    $slide1Content = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <p:cSld>
        <p:spTree>
            <p:nvGrpSpPr>
                <p:cNvPr id="1" name=""/>
                <p:cNvGrpSpPr/>
                <p:nvPr/>
            </p:nvGrpSpPr>
            <p:grpSpPr/>
        </p:spTree>
    </p:cSld>
</p:sld>';
    $zip->addFromString('ppt/slides/slide1.xml', $slide1Content);

    // ppt/slideLayouts/_rels/slideLayout1.xml.rels
    $slideLayout1RelsContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>
</Relationships>';
    $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $slideLayout1RelsContent);

    // ppt/slideLayouts/slideLayout1.xml
    $slideLayout1Content = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sldLayout xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
    <p:cSld>
        <p:spTree>
            <p:nvGrpSpPr>
                <p:cNvPr id="1" name=""/>
                <p:cNvGrpSpPr/>
                <p:nvPr/>
            </p:nvGrpSpPr>
            <p:grpSpPr/>
        </p:spTree>
    </p:cSld>
</p:sldLayout>';
    $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', $slideLayout1Content);

    // ppt/slideMasters/slideMaster1.xml
    $slideMaster1Content = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
    <p:cSld>
        <p:spTree>
            <p:nvGrpSpPr>
                <p:cNvPr id="1" name=""/>
                <p:cNvGrpSpPr/>
                <p:nvPr/>
            </p:nvGrpSpPr>
            <p:grpSpPr/>
        </p:spTree>
    </p:cSld>
    <p:sldLayoutIdLst>
        <p:sldLayoutId id="2147483649" r:id="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>
    </p:sldLayoutIdLst>
</p:sldMaster>';
    $zip->addFromString('ppt/slideMasters/slideMaster1.xml', $slideMaster1Content);

    // ppt/slideMasters/_rels/slideMaster1.xml.rels
    $slideMaster1RelsContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
</Relationships>';
    $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $slideMaster1RelsContent);

    $zip->close();

    $content = file_get_contents($tempFile);
    unlink($tempFile);

    return $content;
}