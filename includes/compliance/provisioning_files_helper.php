<?php
/**
 * Compliance provisioning helpers (Leva 2)
 *
 * Creates folders/documents in a target tenant in an idempotent way.
 * IMPORTANT: No ISO standard text content. Documents are minimal placeholders.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../file_helper.php';

/**
 * Idempotent: ensure a folder exists under a parent folder for a tenant.
 *
 * @return array{folder_id:int,created:bool}
 */
function cnx_ensure_folder(Database $db, int $tenantId, int $parentFolderId, string $name, int $uploadedBy): array {
    $tenantId = (int)$tenantId;
    $parentFolderId = (int)$parentFolderId;
    $uploadedBy = (int)$uploadedBy;
    $name = trim($name);
    if ($tenantId <= 0 || $parentFolderId <= 0 || $uploadedBy <= 0 || $name === '') {
        throw new Exception('Invalid folder params');
    }

    // Reuse if exists (same tenant, same parent, same name)
    $existing = $db->fetchOne(
        "SELECT id
         FROM files
         WHERE tenant_id = ?
           AND is_folder = 1
           AND deleted_at IS NULL
           AND folder_id = ?
           AND name = ?
         LIMIT 1",
        [$tenantId, $parentFolderId, $name]
    );
    if ($existing && !empty($existing['id'])) {
        return ['folder_id' => (int)$existing['id'], 'created' => false];
    }

    // Compute a best-effort file_path based on breadcrumb (avoid requiring api/files_tenant.php)
    $pdo = $db->getConnection();
    $breadcrumbNames = [];
    $current = $parentFolderId;
    while ($current) {
        $row = $db->fetchOne(
            "SELECT id, name, folder_id
             FROM files
             WHERE id = ? AND is_folder = 1 AND deleted_at IS NULL
             LIMIT 1",
            [$current]
        );
        if (!$row) break;
        array_unshift($breadcrumbNames, (string)$row['name']);
        $current = (int)($row['folder_id'] ?? 0);
    }
    $fullPath = '/' . trim(implode('/', $breadcrumbNames), '/') . '/' . $name;

    $pdo->beginTransaction();
    try {
        $db->insert('files', [
            'name' => $name,
            'folder_id' => $parentFolderId,
            'tenant_id' => $tenantId,
            'uploaded_by' => $uploadedBy,
            'is_folder' => 1,
            'file_path' => $fullPath,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $folderId = (int)$pdo->lastInsertId();
        $pdo->commit();
        return ['folder_id' => $folderId, 'created' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Create a minimal placeholder document in a target tenant and folder.
 * Idempotent: if a same-name file exists in that folder, it is reused.
 *
 * @return array{file_id:int,created:bool}
 */
function cnx_ensure_placeholder_document(Database $db, int $tenantId, ?int $folderId, int $uploadedBy, string $type, string $name): array {
    $tenantId = (int)$tenantId;
    $uploadedBy = (int)$uploadedBy;
    $folderId = ($folderId !== null && $folderId > 0) ? (int)$folderId : null;
    $type = strtolower(trim($type));
    $name = trim($name);

    if ($tenantId <= 0 || $uploadedBy <= 0 || $name === '') {
        throw new Exception('Invalid document params');
    }

    $supportedTypes = ['docx', 'xlsx', 'pptx', 'txt'];
    if (!in_array($type, $supportedTypes, true)) {
        $type = 'docx';
    }

    // Sanitize + enforce extension
    $name = preg_replace('/[^a-zA-Z0-9\s\-_\.]/', '', $name) ?: $name;
    if (strlen($name) > 160) $name = substr($name, 0, 160);
    if (!str_ends_with(strtolower($name), '.' . $type)) {
        $name .= '.' . $type;
    }

    // Reuse existing file (STRICT idempotence) - but repair legacy blank placeholders (best-effort)
    $existing = $db->fetchOne(
        "SELECT id, file_path, name
         FROM files
         WHERE tenant_id = ?
           AND deleted_at IS NULL
           AND is_folder = 0
           AND name = ?
           AND folder_id <=> ?
         LIMIT 1",
        [$tenantId, $name, $folderId]
    );
    if ($existing && !empty($existing['id'])) {
        // Best-effort repair:
        // Older versions created truly blank DOCX/XLSX placeholders. If the file is still blank, overwrite it
        // with the current minimal placeholder that includes visible text + {{placeholders}}.
        try {
            $fid = (int)$existing['id'];
            $rel = (string)($existing['file_path'] ?? '');
            if ($rel !== '' && in_array($type, ['docx', 'xlsx'], true)) {
                $abs = rtrim(FileHelper::getTenantUploadPath($tenantId), '/\\') . '/' . ltrim($rel, '/\\');
                if (is_file($abs) && is_writable($abs)) {
                    $shouldRepair = false;
                    if ($type === 'docx') {
                        $z = new ZipArchive();
                        if ($z->open($abs) === true) {
                            $xml = (string)($z->getFromName('word/document.xml') ?: '');
                            $z->close();
                            if ($xml !== '' && strpos($xml, '{{') === false) {
                                // extract visible text in <w:t>
                                $hasText = false;
                                if (preg_match_all('/<w:t[^>]*>(.*?)<\\/w:t>/si', $xml, $m)) {
                                    foreach (($m[1] ?? []) as $chunk) {
                                        $plain = trim(html_entity_decode((string)$chunk, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        if ($plain !== '') { $hasText = true; break; }
                                    }
                                }
                                if (!$hasText) $shouldRepair = true;
                            }
                        }
                    } else {
                        // XLSX: if there is no placeholder marker anywhere, treat as blank
                        $z = new ZipArchive();
                        if ($z->open($abs) === true) {
                            $found = false;
                            for ($i = 1; $i <= 10; $i++) {
                                $sheet = "xl/worksheets/sheet{$i}.xml";
                                $sx = $z->getFromName($sheet);
                                if ($sx !== false && strpos((string)$sx, '{{') !== false) { $found = true; break; }
                            }
                            $shared = $z->getFromName('xl/sharedStrings.xml');
                            if (!$found && $shared !== false && strpos((string)$shared, '{{') !== false) $found = true;
                            $z->close();
                            if (!$found) $shouldRepair = true;
                        }
                    }

                    if ($shouldRepair) {
                        $bytes = ($type === 'docx') ? cnx_create_minimal_docx() : cnx_create_minimal_xlsx();
                        @file_put_contents($abs, $bytes);
                        // Update file_size best-effort
                        try {
                            $size = @filesize($abs);
                            if (is_int($size)) {
                                $db->query("UPDATE files SET file_size = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ? LIMIT 1", [$size, $fid, $tenantId]);
                            }
                        } catch (Throwable $_) {}
                    }
                }
            }
        } catch (Throwable $_) {
            // ignore (non-blocking)
        }
        return ['file_id' => (int)$existing['id'], 'created' => false];
    }

    // Create minimal file bytes (NO ISO text)
    $content = '';
    if ($type === 'docx') {
        $content = cnx_create_minimal_docx();
    } elseif ($type === 'xlsx') {
        $content = cnx_create_minimal_xlsx();
    } elseif ($type === 'pptx') {
        $content = cnx_create_minimal_pptx();
    } else {
        $content = ""; // empty .txt placeholder
    }

    $uploadPath = FileHelper::getTenantUploadPath($tenantId);
    if (!is_dir($uploadPath)) {
        throw new Exception('Directory upload tenant non disponibile: ' . $uploadPath);
    }
    if (!is_writable($uploadPath)) {
        throw new Exception('Directory upload tenant non scrivibile: ' . $uploadPath);
    }
    $safeName = FileHelper::generateSafeFilename($name, $uploadPath);
    $fullPath = rtrim($uploadPath, '/\\') . '/' . $safeName;
    $bytes = @file_put_contents($fullPath, $content);
    if ($bytes === false) {
        $last = error_get_last();
        $err = is_array($last) ? (string)($last['message'] ?? '') : '';
        $err = trim($err);
        throw new Exception('Impossibile creare il file placeholder: ' . $fullPath . ($err !== '' ? (' — ' . $err) : ''));
    }

    $mimeType = FileHelper::getMimeType($fullPath);
    $fileSize = filesize($fullPath);
    $editorFormat = FileHelper::getEditorFormat($mimeType);
    $relativePath = $safeName;

    $pdo = $db->getConnection();
    $pdo->beginTransaction();
    try {
        $fileId = $db->insert('files', [
            'tenant_id' => $tenantId,
            'name' => $name,
            'file_path' => $relativePath,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'file_type' => $type,
            'folder_id' => $folderId,
            'uploaded_by' => $uploadedBy,
            'is_folder' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Best-effort: create workflow rows if tables exist (avoid 500 on drift)
        try {
            $hasWorkflow = $db->fetchOne("SHOW TABLES LIKE 'document_workflow'");
            $hasHistory = $db->fetchOne("SHOW TABLES LIKE 'document_workflow_history'");
            if ($hasWorkflow) {
                $existingWorkflow = $db->fetchOne(
                    "SELECT id
                     FROM document_workflow
                     WHERE tenant_id = ?
                       AND file_id = ?
                       AND (deleted_at IS NULL OR deleted_at = '')
                     LIMIT 1",
                    [$tenantId, $fileId]
                );
                if ($existingWorkflow === false) {
                    $workflowId = $db->insert('document_workflow', [
                        'tenant_id' => $tenantId,
                        'file_id' => $fileId,
                        'current_state' => 'bozza',
                        'created_by_user_id' => $uploadedBy,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                } else {
                    $workflowId = (int)$existingWorkflow['id'];
                }

                if ($hasHistory) {
                    $hasAny = $db->fetchOne(
                        "SELECT 1 AS ok
                         FROM document_workflow_history
                         WHERE tenant_id = ?
                           AND workflow_id = ?
                           AND file_id = ?
                         LIMIT 1",
                        [$tenantId, $workflowId, $fileId]
                    );
                    if ($hasAny === false) {
                        $db->insert('document_workflow_history', [
                            'tenant_id' => $tenantId,
                            'workflow_id' => $workflowId,
                            'file_id' => $fileId,
                            'from_state' => null,
                            'to_state' => 'bozza',
                            'transition_type' => 'create',
                            'performed_by_user_id' => $uploadedBy,
                            'user_role_at_time' => 'creator',
                            'comment' => 'Documento placeholder creato (compliance provisioning)',
                            'metadata' => json_encode(['source' => 'compliance_provisioning'], JSON_UNESCAPED_SLASHES),
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            // non-blocking
        }

        $pdo->commit();
        return ['file_id' => (int)$fileId, 'created' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (file_exists($fullPath)) @unlink($fullPath);
        throw $e;
    }
}

/**
 * Copy an existing file from a source tenant into a target tenant folder.
 *
 * Idempotent:
 * - If a same-name file exists in that folder, it is reused.
 * - If copy succeeds, inserts a new `files` row and creates minimal workflow rows (best-effort) WITHOUT emails.
 *
 * @return array{file_id:int,created:bool}
 */
function cnx_copy_file_between_tenants(
    Database $db,
    int $sourceTenantId,
    int $sourceFileId,
    int $targetTenantId,
    ?int $targetFolderId,
    int $uploadedBy,
    string $targetName
): array {
    $sourceTenantId = (int)$sourceTenantId;
    $sourceFileId = (int)$sourceFileId;
    $targetTenantId = (int)$targetTenantId;
    $uploadedBy = (int)$uploadedBy;
    $targetFolderId = ($targetFolderId !== null && $targetFolderId > 0) ? (int)$targetFolderId : null;
    $targetName = trim((string)$targetName);

    if ($sourceTenantId <= 0 || $sourceFileId <= 0 || $targetTenantId <= 0 || $uploadedBy <= 0 || $targetName === '') {
        throw new Exception('Invalid copy params');
    }

    // Reuse existing file (STRICT idempotence by name+folder)
    $existing = $db->fetchOne(
        "SELECT id
         FROM files
         WHERE tenant_id = ?
           AND deleted_at IS NULL
           AND is_folder = 0
           AND name = ?
           AND folder_id <=> ?
         LIMIT 1",
        [$targetTenantId, $targetName, $targetFolderId]
    );
    if ($existing && !empty($existing['id'])) {
        return ['file_id' => (int)$existing['id'], 'created' => false];
    }

    // Load source file row
    $src = $db->fetchOne(
        "SELECT id, name, file_path, file_type, mime_type, file_size
         FROM files
         WHERE id = ?
           AND tenant_id = ?
           AND deleted_at IS NULL
           AND is_folder = 0
         LIMIT 1",
        [$sourceFileId, $sourceTenantId]
    );
    if (!$src) {
        throw new Exception('File sorgente non trovato o non accessibile');
    }
    $srcRel = (string)($src['file_path'] ?? '');
    if ($srcRel === '') {
        throw new Exception('File sorgente non valido (file_path vuoto)');
    }

    // Physical copy
    $srcPath = rtrim(FileHelper::getTenantUploadPath($sourceTenantId), '/\\') . '/' . ltrim($srcRel, '/\\');
    if (!is_file($srcPath)) {
        throw new Exception('File fisico sorgente non trovato: ' . $srcPath);
    }

    $targetUploadPath = FileHelper::getTenantUploadPath($targetTenantId);
    if (!is_dir($targetUploadPath)) {
        throw new Exception('Directory upload tenant non disponibile: ' . $targetUploadPath);
    }
    if (!is_writable($targetUploadPath)) {
        throw new Exception('Directory upload tenant non scrivibile: ' . $targetUploadPath);
    }

    $safeName = FileHelper::generateSafeFilename($targetName, $targetUploadPath);
    $dstPath = rtrim($targetUploadPath, '/\\') . '/' . $safeName;
    if (@copy($srcPath, $dstPath) !== true) {
        $last = error_get_last();
        $err = is_array($last) ? (string)($last['message'] ?? '') : '';
        $err = trim($err);
        throw new Exception('Impossibile copiare file modello: ' . $dstPath . ($err !== '' ? (' — ' . $err) : ''));
    }

    $mimeType = FileHelper::getMimeType($dstPath);
    $fileSize = filesize($dstPath);
    $fileType = strtolower(pathinfo($targetName, PATHINFO_EXTENSION));
    if ($fileType === '') {
        $fileType = strtolower((string)($src['file_type'] ?? ''));
    }
    if ($fileType === '') {
        $fileType = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    }

    $pdo = $db->getConnection();
    $pdo->beginTransaction();
    try {
        $fileId = $db->insert('files', [
            'tenant_id' => $targetTenantId,
            'name' => $targetName,
            'file_path' => $safeName, // stored_name relative to uploads/<tenant>/
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'file_type' => $fileType,
            'folder_id' => $targetFolderId,
            'uploaded_by' => $uploadedBy,
            'is_folder' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Best-effort workflow rows (NO EMAILS)
        try {
            $hasWorkflow = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'document_workflow'
                 LIMIT 1"
            );
            $hasHistory = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'document_workflow_history'
                 LIMIT 1"
            );
            if ($hasWorkflow) {
                $existingWorkflow = $db->fetchOne(
                    "SELECT id
                     FROM document_workflow
                     WHERE tenant_id = ?
                       AND file_id = ?
                       AND (deleted_at IS NULL OR deleted_at = '')
                     LIMIT 1",
                    [$targetTenantId, $fileId]
                );
                if ($existingWorkflow === false) {
                    $workflowId = $db->insert('document_workflow', [
                        'tenant_id' => $targetTenantId,
                        'file_id' => $fileId,
                        'current_state' => 'bozza',
                        'created_by_user_id' => $uploadedBy,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                } else {
                    $workflowId = (int)$existingWorkflow['id'];
                }

                if ($hasHistory) {
                    $hasAny = $db->fetchOne(
                        "SELECT 1 AS ok
                         FROM document_workflow_history
                         WHERE tenant_id = ?
                           AND workflow_id = ?
                           AND file_id = ?
                         LIMIT 1",
                        [$targetTenantId, $workflowId, $fileId]
                    );
                    if ($hasAny === false) {
                        $db->insert('document_workflow_history', [
                            'tenant_id' => $targetTenantId,
                            'workflow_id' => $workflowId,
                            'file_id' => $fileId,
                            'from_state' => null,
                            'to_state' => 'bozza',
                            'transition_type' => 'create',
                            'performed_by_user_id' => $uploadedBy,
                            'user_role_at_time' => 'creator',
                            'comment' => 'Documento copiato da modello master (compliance provisioning)',
                            'metadata' => json_encode(['source' => 'compliance_provisioning', 'copy_source' => true], JSON_UNESCAPED_SLASHES),
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            // non-blocking
        }

        $pdo->commit();
        return ['file_id' => (int)$fileId, 'created' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (file_exists($dstPath)) @unlink($dstPath);
        throw $e;
    }
}

// Minimal Office files (copied from api/files/create_document.php, kept generic)
function cnx_create_minimal_docx(): string {
    // NOTE: Previously this produced a "blank" DOCX. That breaks the wizard because there are no placeholders to replace.
    // This new placeholder is still generic (NO ISO/UNI text) but contains a minimal structure + common {{keys}}.
    return cnx_create_docx_from_paragraphs([
        'Dati organizzazione',
        'Ragione sociale',
        '{{company_name}}',
        'Scopo del sistema',
        '{{scope}}',
        'Sedi / siti (1 per riga):',
        '{{sites}}',
        'Prodotti/Servizi (1 per riga):',
        '{{products_services}}',
        'Processi principali (1 per riga):',
        '{{processes}}',
        'Ruoli / responsabilità (1 per riga):',
        '{{roles}}',
        'Note:',
        '{{notes}}',
        '',
        'Contenuto documento:',
        '{{body}}',
    ]);
}

function cnx_create_minimal_xlsx(): string {
    // Minimal XLSX with a simple key/value table to keep placeholders visible and editable.
    return cnx_create_xlsx_from_rows([
        ['Campo', 'Valore'],
        ['Titolo documento', '{{doc_title}}'],
        ['Data', '{{doc_date}}'],
        ['Versione', '{{doc_version}}'],
        ['Ragione sociale', '{{company_name}}'],
        ['Scopo del sistema', '{{scope}}'],
        ['Sedi / siti', '{{sites}}'],
        ['Prodotti/Servizi', '{{products_services}}'],
        ['Processi principali', '{{processes}}'],
        ['Ruoli / responsabilità', '{{roles}}'],
        ['Note', '{{notes}}'],
        ['Contenuto documento (bozza)', '{{body}}'],
    ], 'Dati');
}

/**
 * Escape text for XML nodes (OOXML).
 */
function cnx_ooxml_escape(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

/**
 * Build a very small DOCX with simple paragraphs.
 * Note: This is intentionally minimal and contains NO ISO/UNI text.
 *
 * @param array<int,string> $paragraphs Plain text; may include {{placeholders}}
 */
function cnx_create_docx_from_paragraphs(array $paragraphs): string {
    $zip = new ZipArchive();
    $tempFile = tempnam(sys_get_temp_dir(), 'docx');
    if ($tempFile === false) throw new Exception('tempnam failed');
    @unlink($tempFile);
    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Impossibile creare il file DOCX');
    }

    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>');

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>');

    $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>');

    $body = '';
    foreach ($paragraphs as $p) {
        $t = cnx_ooxml_escape((string)$p);
        // Preserve newlines inside a paragraph
        $t = str_replace("\r\n", "\n", $t);
        $parts = explode("\n", $t);
        $runs = [];
        foreach ($parts as $idx => $part) {
            $runs[] = '<w:t xml:space="preserve">' . $part . '</w:t>';
            if ($idx < count($parts) - 1) $runs[] = '<w:br/>';
        }
        $body .= '<w:p><w:r>' . implode('', $runs) . '</w:r></w:p>';
    }

    // NOTE: OnlyOffice can render DOCX as "blank" if section properties are missing.
    // Add a minimal <w:sectPr/> at the end of the body to improve compatibility.
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>' . $body . '<w:sectPr/></w:body>
</w:document>');

    $zip->close();
    $content = file_get_contents($tempFile);
    @unlink($tempFile);
    if ($content === false) throw new Exception('Impossibile leggere docx temporaneo');
    return $content;
}

/**
 * Build a small XLSX with one sheet filled with inline strings.
 * Note: intentionally minimal; placeholders are plain text and will be replaced by doc_template_engine.
 *
 * @param array<int,array<int,string>> $rows 2D table (row-major)
 * @param string $sheetName
 */
function cnx_create_xlsx_from_rows(array $rows, string $sheetName = 'Registro'): string {
    $zip = new ZipArchive();
    $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($tempFile === false) throw new Exception('tempnam failed');
    @unlink($tempFile);
    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Impossibile creare il file XLSX');
    }

    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>');

    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');

    $sn = cnx_ooxml_escape($sheetName !== '' ? $sheetName : 'Registro');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="' . $sn . '" sheetId="1" r:id="rId1"/></sheets>
</workbook>');

    // Build sheet1.xml with inline strings
    $sheetData = '';
    $rIdx = 1;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $cells = '';
        $cIdx = 0;
        foreach ($row as $cellText) {
            $cIdx++;
            // Column name (A, B, C...) up to AZ (good enough for our registers)
            $n = $cIdx;
            $col = '';
            while ($n > 0) {
                $n0 = ($n - 1) % 26;
                $col = chr(65 + $n0) . $col;
                $n = intdiv($n - 1, 26);
            }
            $addr = $col . $rIdx;
            $txt = cnx_ooxml_escape((string)$cellText);
            $cells .= '<c r="' . $addr . '" t="inlineStr"><is><t xml:space="preserve">' . $txt . '</t></is></c>';
        }
        $sheetData .= '<row r="' . $rIdx . '">' . $cells . '</row>';
        $rIdx++;
    }

    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>' . $sheetData . '</sheetData>
</worksheet>');

    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="1"><font/></fonts>
  <fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>
  <borders count="1"><border/></borders>
  <cellStyleXfs count="1"><xf/></cellStyleXfs>
  <cellXfs count="1"><xf/></cellXfs>
</styleSheet>');

    $zip->close();
    $content = file_get_contents($tempFile);
    @unlink($tempFile);
    if ($content === false) throw new Exception('Impossibile leggere xlsx temporaneo');
    return $content;
}

/**
 * Ensure a specific office file exists with provided bytes. Idempotent by (tenant, folder, name).
 *
 * @return array{file_id:int,created:bool}
 */
function cnx_ensure_document_from_content(Database $db, int $tenantId, ?int $folderId, int $uploadedBy, string $type, string $name, string $contentBytes): array {
    $tenantId = (int)$tenantId;
    $uploadedBy = (int)$uploadedBy;
    $folderId = ($folderId !== null && $folderId > 0) ? (int)$folderId : null;
    $type = strtolower(trim($type));
    $name = trim($name);
    if ($tenantId <= 0 || $uploadedBy <= 0 || $name === '') throw new Exception('Invalid document params');

    $supportedTypes = ['docx', 'xlsx', 'pptx', 'txt'];
    if (!in_array($type, $supportedTypes, true)) $type = 'docx';
    if (!str_ends_with(strtolower($name), '.' . $type)) $name .= '.' . $type;

    $existing = $db->fetchOne(
        "SELECT id
         FROM files
         WHERE tenant_id = ?
           AND deleted_at IS NULL
           AND is_folder = 0
           AND name = ?
           AND folder_id <=> ?
         LIMIT 1",
        [$tenantId, $name, $folderId]
    );
    if ($existing && !empty($existing['id'])) {
        return ['file_id' => (int)$existing['id'], 'created' => false];
    }

    $uploadPath = FileHelper::getTenantUploadPath($tenantId);
    if (!is_dir($uploadPath)) throw new Exception('Directory upload tenant non disponibile: ' . $uploadPath);
    if (!is_writable($uploadPath)) throw new Exception('Directory upload tenant non scrivibile: ' . $uploadPath);

    $safeName = FileHelper::generateSafeFilename($name, $uploadPath);
    $fullPath = rtrim($uploadPath, '/\\') . '/' . $safeName;
    $bytes = @file_put_contents($fullPath, $contentBytes);
    if ($bytes === false) {
        $last = error_get_last();
        $err = is_array($last) ? (string)($last['message'] ?? '') : '';
        $err = trim($err);
        throw new Exception('Impossibile creare il file master: ' . $fullPath . ($err !== '' ? (' — ' . $err) : ''));
    }

    $mimeType = FileHelper::getMimeType($fullPath);
    $fileSize = filesize($fullPath);
    $relativePath = $safeName;

    $pdo = $db->getConnection();
    $pdo->beginTransaction();
    try {
        $fileId = $db->insert('files', [
            'tenant_id' => $tenantId,
            'name' => $name,
            'file_path' => $relativePath,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'file_type' => $type,
            'folder_id' => $folderId,
            'uploaded_by' => $uploadedBy,
            'is_folder' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $pdo->commit();
        return ['file_id' => (int)$fileId, 'created' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (file_exists($fullPath)) @unlink($fullPath);
        throw $e;
    }
}

function cnx_create_minimal_pptx(): string {
    $zip = new ZipArchive();
    $tempFile = tempnam(sys_get_temp_dir(), 'pptx');
    if ($tempFile === false) throw new Exception('tempnam failed');
    @unlink($tempFile);
    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Impossibile creare il file PPTX');
    }
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>
</Relationships>');
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>
  <Override PartName="/ppt/slides/slide1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>
  <Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>
  <Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>
</Types>');
    $zip->addFromString('ppt/_rels/presentation.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
</Relationships>');
    $zip->addFromString('ppt/presentation.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>
  <p:sldIdLst><p:sldId id="256" r:id="rId2"/></p:sldIdLst>
  <p:sldSz cx="9144000" cy="6858000"/>
  <p:notesSz cx="6858000" cy="9144000"/>
</p:presentation>');
    $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"></p:sldMaster>');
    $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sldLayout xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"></p:sldLayout>');
    $zip->addFromString('ppt/slides/slide1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"></p:sld>');
    $zip->close();
    $content = file_get_contents($tempFile);
    @unlink($tempFile);
    if ($content === false) throw new Exception('Impossibile leggere pptx temporaneo');
    return $content;
}

