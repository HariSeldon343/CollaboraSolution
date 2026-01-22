<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/compliance/doc_template_engine.php';

$src = __DIR__ . '/Manuale_IMS_test.docx';
if (!is_file($src)) {
    fwrite(STDERR, "Missing test file: {$src}\n");
    exit(2);
}

$warnings = [];
$repl = [
    'doc_title' => 'TEST_TITLE',
    'doc_date' => '11/01/2026',
    'doc_version' => '9.9',
    'doc_code' => 'IMS-001',
    'company_name' => 'ACME SPA',
    'scope' => 'SCOPO TEST',
];
$zip = new ZipArchive();
$zipOk = $zip->open($src) === true;
$docXml = $zipOk ? $zip->getFromName('word/document.xml') : false;
$hdrXml = $zipOk ? $zip->getFromName('word/header1.xml') : false;
if ($zipOk) { $zip->close(); }

$diag = [
    'zip_open' => $zipOk,
    'doc_len' => is_string($docXml) ? strlen($docXml) : null,
    'hdr_len' => is_string($hdrXml) ? strlen($hdrXml) : null,
    'doc_has_doc_title' => is_string($docXml) ? (strpos($docXml, 'doc_title') !== false) : null,
    'doc_has_literal' => is_string($docXml) ? (strpos($docXml, '{{doc_title}}') !== false) : null,
    'hdr_has_literal' => is_string($hdrXml) ? (strpos($hdrXml, '{{doc_title}}') !== false) : null,
];
if (is_string($docXml) && ($p = strpos($docXml, 'doc_title')) !== false) {
    $start = max(0, $p - 20);
    $diag['doc_snippet'] = substr($docXml, $start, 80);
    $diag['doc_snippet_hex'] = bin2hex(substr($docXml, $start, 40));
}
if (is_string($hdrXml) && ($p2 = strpos($hdrXml, 'doc_title')) !== false) {
    $start2 = max(0, $p2 - 20);
    $diag['hdr_snippet'] = substr($hdrXml, $start2, 80);
    $diag['hdr_snippet_hex'] = bin2hex(substr($hdrXml, $start2, 40));
}

// Pure in-memory replacement sanity check
if (is_string($docXml)) {
    $res = cnx_replace_placeholders_in_xml($docXml, $repl, true);
    $diag['mem_doc_has_test_title'] = (strpos((string)($res['xml'] ?? ''), 'TEST_TITLE') !== false);
    $diag['mem_doc_found_keys'] = array_keys((array)($res['found_keys'] ?? []));
}
if (is_string($hdrXml)) {
    $resH = cnx_replace_placeholders_in_xml($hdrXml, $repl, true);
    $diag['mem_hdr_has_test_title'] = (strpos((string)($resH['xml'] ?? ''), 'TEST_TITLE') !== false);
    $diag['mem_hdr_found_keys'] = array_keys((array)($resH['found_keys'] ?? []));
}

$ok = cnx_apply_placeholders_to_docx($src, $repl, $warnings, ['header_enabled' => true]);

echo json_encode([
    'ok' => (bool)$ok,
    'diag' => $diag,
    'warnings' => $warnings,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

