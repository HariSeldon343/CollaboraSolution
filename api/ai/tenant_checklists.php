<?php
/**
 * Tenant Checklists API (multi-tenant onboarding)
 */
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/ai/tenant_checklists.php';

cnx_ai_require_csrf_for_write();

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));
$action = strtolower(trim($action));
if ($action === '') api_error('action richiesto', 400);

$tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : (isset($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : cnx_ai_resolve_target_tenant_id($userInfo));
if ($tenantId <= 0) api_error('tenant_id richiesto', 400);
if (!cnx_ai_user_has_access_to_tenant($db, $userInfo, $tenantId)) api_error('Accesso negato', 403);

$storage = cnx_ai_check_tables($db, [
    'tenant_checklists',
    'tenant_checklist_items',
    'tenant_checklist_item_values',
], 'database/migrations/67_tenant_ai_onboarding.sql');
if (!$storage['ok']) {
    api_success([
        'storage_available' => false,
        'missing' => $storage['missing'] ?? [],
        'migration' => $storage['migration'] ?? null,
    ]);
}

function cnx_xlsx_xml_escape(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function cnx_xlsx_col(int $n): string {
    $n = max(1, $n);
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

function cnx_xlsx_cell_inline_str(string $ref, string $value): string {
    return '<c r="' . $ref . '" t="inlineStr"><is><t>' . cnx_xlsx_xml_escape($value) . '</t></is></c>';
}

function cnx_build_simple_xlsx(array $rows): string {
    if (!class_exists('ZipArchive')) throw new Exception('ZipArchive non disponibile');
    $zip = new ZipArchive();
    $tempFile = tempnam(sys_get_temp_dir(), 'chk_xlsx_');
    if ($tempFile === false) throw new Exception('Impossibile creare file temporaneo');
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
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Checklist" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="1"><font/></fonts>
  <fills count="2">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
  </fills>
  <borders count="1"><border/></borders>
  <cellStyleXfs count="1"><xf/></cellStyleXfs>
  <cellXfs count="1"><xf/></cellXfs>
</styleSheet>');

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n";
    $xml .= '<sheetData>' . "\n";
    $rIdx = 1;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $xml .= '<row r="' . $rIdx . '">' . "\n";
        $cIdx = 1;
        foreach ($r as $cell) {
            $ref = cnx_xlsx_col($cIdx) . $rIdx;
            $xml .= cnx_xlsx_cell_inline_str($ref, (string)$cell) . "\n";
            $cIdx++;
        }
        $xml .= '</row>' . "\n";
        $rIdx++;
    }
    $xml .= '</sheetData>' . "\n";
    $xml .= '</worksheet>' . "\n";
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    $zip->close();

    $content = file_get_contents($tempFile);
    @unlink($tempFile);
    if (!is_string($content) || $content === '') throw new Exception('XLSX vuoto');
    return $content;
}

try {
    if ($action === 'templates_list') {
        api_success(['templates' => cnx_tenant_checklists_list_templates()]);
    }

    $payload = json_decode(cnx_get_raw_request_body(), true) ?: [];

    if ($action === 'get_or_create') {
        $templateKey = (string)($payload['template_key'] ?? '');
        $scopeType = (string)($payload['scope_type'] ?? 'company');
        $scopeId = isset($payload['scope_id']) ? (int)$payload['scope_id'] : null;
        $res = cnx_tenant_checklists_get_or_create($db, $tenantId, $scopeType, $scopeId, $templateKey);
        api_success($res);
    }

    if ($action === 'get') {
        $checklistId = isset($_GET['checklist_id']) ? (int)$_GET['checklist_id'] : (int)($payload['checklist_id'] ?? 0);
        if ($checklistId <= 0) api_error('checklist_id richiesto', 400);
        $chk = $db->fetchOne(
            "SELECT *
             FROM tenant_checklists
             WHERE id = ? AND tenant_id = ?
             LIMIT 1",
            [$checklistId, $tenantId]
        );
        if (!$chk) api_error('Checklist non trovata', 404);
        $items = $db->fetchAll(
            "SELECT i.*, v.status, v.answer_text, v.evidences_json
             FROM tenant_checklist_items i
             LEFT JOIN tenant_checklist_item_values v
               ON v.tenant_id = i.tenant_id AND v.checklist_id = i.checklist_id AND v.item_key = i.item_key
             WHERE i.checklist_id = ? AND i.tenant_id = ?
             ORDER BY i.section_key ASC, i.id ASC",
            [$checklistId, $tenantId]
        ) ?: [];
        api_success(['checklist' => $chk, 'items' => $items]);
    }

    if ($action === 'update_item') {
        $checklistId = (int)($payload['checklist_id'] ?? 0);
        $itemKey = trim((string)($payload['item_key'] ?? ''));
        if ($checklistId <= 0 || $itemKey === '') api_error('checklist_id e item_key richiesti', 400);
        $status = strtolower(trim((string)($payload['status'] ?? '')));
        $allowed = ['missing','present','to_review','done','not_applicable'];
        if (!in_array($status, $allowed, true)) $status = 'missing';
        $answerText = array_key_exists('answer_text', $payload) ? (string)($payload['answer_text'] ?? '') : null;
        $evidences = $payload['evidences_json'] ?? null;
        $evidencesJson = $evidences !== null ? json_encode($evidences, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $stmt = $db->getConnection()->prepare(
            "INSERT INTO tenant_checklist_item_values
                (tenant_id, checklist_id, item_key, status, answer_text, evidences_json, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                answer_text = VALUES(answer_text),
                evidences_json = VALUES(evidences_json),
                updated_at = NOW()"
        );
        $stmt->execute([
            $tenantId,
            $checklistId,
            $itemKey,
            $status !== '' ? $status : 'missing',
            $answerText,
            $evidencesJson,
        ]);
        api_success(['updated' => true]);
    }

    if ($action === 'export_xlsx') {
        $checklistId = isset($_GET['checklist_id']) ? (int)$_GET['checklist_id'] : 0;
        if ($checklistId <= 0) api_error('checklist_id richiesto', 400);
        $chk = $db->fetchOne(
            "SELECT * FROM tenant_checklists WHERE id = ? AND tenant_id = ? LIMIT 1",
            [$checklistId, $tenantId]
        );
        if (!$chk) api_error('Checklist non trovata', 404);
        $items = $db->fetchAll(
            "SELECT i.section_key, i.title, i.description, i.required_flag, v.status, v.answer_text, v.evidences_json
             FROM tenant_checklist_items i
             LEFT JOIN tenant_checklist_item_values v
               ON v.tenant_id = i.tenant_id AND v.checklist_id = i.checklist_id AND v.item_key = i.item_key
             WHERE i.checklist_id = ? AND i.tenant_id = ?
             ORDER BY i.section_key ASC, i.id ASC",
            [$checklistId, $tenantId]
        ) ?: [];
        $rows = [];
        $rows[] = ['Checklist', (string)($chk['template_key'] ?? ''), 'Scope', (string)($chk['scope_type'] ?? '')];
        $rows[] = [];
        $rows[] = ['Sezione', 'Voce', 'Obbligatorio', 'Stato', 'Note/Testo', 'Evidenze'];
        foreach ($items as $it) {
            $ev = '';
            if (!empty($it['evidences_json'])) {
                $dec = json_decode((string)$it['evidences_json'], true);
                if (is_array($dec)) {
                    $ev = implode(', ', array_map(static fn($x) => '#' . (int)($x['file_id'] ?? 0), $dec));
                }
            }
            $rows[] = [
                (string)($it['section_key'] ?? ''),
                (string)($it['title'] ?? ''),
                !empty($it['required_flag']) ? 'SI' : 'NO',
                (string)($it['status'] ?? 'missing'),
                (string)($it['answer_text'] ?? ''),
                $ev,
            ];
        }
        $bin = cnx_build_simple_xlsx($rows);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="tenant_checklist.xlsx"');
        echo $bin;
        exit;
    }

    api_error('Azione non valida', 400);
} catch (Throwable $e) {
    $errId = 'chk_tenant_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[TENANT_CHECKLIST][{$errId}] " . $e->getMessage());
    api_error('Errore checklist', 500, ['error_id' => $errId]);
}

