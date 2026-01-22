<?php
// GET: export checklist instance as XLSX (no external libs) (tenant 28 tool)
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

verifyApiCsrfToken(true);
requireApiRole('admin');

function cnx_xlsx_xml_escape(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function cnx_xlsx_col(int $n): string {
    // 1 => A, 26 => Z, 27 => AA ...
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

/**
 * Build an XLSX (single-sheet) as bytes.
 *
 * @param array<int,array<int,string>> $rows
 */
function cnx_build_checklist_xlsx(array $rows): string {
    if (!class_exists('ZipArchive')) {
        throw new Exception('ZipArchive non disponibile');
    }
    $zip = new ZipArchive();
    $tempFile = tempnam(sys_get_temp_dir(), 'chk_xlsx_');
    if ($tempFile === false) throw new Exception('Impossibile creare file temporaneo');
    @unlink($tempFile); // avoid PHP 8.2 deprecation warnings

    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Impossibile creare il file XLSX');
    }

    // _rels/.rels
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

    // [Content_Types].xml
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>');

    // xl/_rels/workbook.xml.rels
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');

    // xl/workbook.xml
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Checklist" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>');

    // xl/styles.xml (minimal)
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

    // xl/worksheets/sheet1.xml
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
    $storage = cnx_checklists_check_storage($db);
    if (empty($storage['ok'])) {
        api_error(
            'Checklist non disponibile (schema non aggiornato)',
            503,
            ['storage_available' => false, 'missing' => $storage['missing'] ?? [], 'migration' => $storage['migration'] ?? CNX_CHECKLISTS_MIGRATION_HINT]
        );
    }

    $planId = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
    if ($planId <= 0) api_error('plan_id obbligatorio', 400);

    $templateKeyIn = isset($_GET['template_key'])
        ? (string)$_GET['template_key']
        : (isset($_GET['template_code']) ? (string)$_GET['template_code'] : 'SGQ_ISO9001_BANDO');
    $templateKey = cnx_checklists_norm_template_key($templateKeyIn);
    if ($templateKey === '') $templateKey = 'SGQ_ISO9001_BANDO';

    // Load plan + access check
    $planHasDeletedAt = cnx_checklists_has_col($db, 'consulting_plans', 'deleted_at');
    $planWhere = "id = ?" . ($planHasDeletedAt ? " AND deleted_at IS NULL" : "");
    $plan = $db->fetchOne("SELECT * FROM consulting_plans WHERE {$planWhere} LIMIT 1", [$planId]);
    if (!$plan) api_error('Piano non trovato', 404);
    $clientTenantId = (int)($plan['client_tenant_id'] ?? 0);
    if ($clientTenantId <= 0) api_error('Piano non valido (client_tenant_id mancante)', 400);
    if (!cnx_consulting_is_client_allowed($db, $userInfo, $clientTenantId)) api_error('Accesso negato al tenant cliente', 403);

    $chk = $db->fetchOne(
        "SELECT *
         FROM consulting_project_checklists
         WHERE tenant_id = ?
           AND plan_id = ?
           AND template_key = ?
         ORDER BY id DESC
         LIMIT 1",
        [CNX_VENDOR_TENANT_ID, $planId, $templateKey]
    );
    if (!$chk || empty($chk['id'])) {
        api_error('Checklist non trovata (crea prima la checklist)', 404, ['can_create' => true]);
    }
    $checklistId = (int)$chk['id'];

    $items = $db->fetchAll(
        "SELECT *
         FROM consulting_project_checklist_items
         WHERE checklist_id = ?
         ORDER BY sort_order ASC, id ASC",
        [$checklistId]
    ) ?: [];

    // Section titles (from template JSON)
    $sectionTitleByKey = [];
    $tpl = cnx_checklists_load_template($templateKey);
    if (!empty($tpl['ok']) && is_array($tpl['template'] ?? null)) {
        $sections = is_array($tpl['template']['sections'] ?? null) ? $tpl['template']['sections'] : [];
        foreach ($sections as $s) {
            if (!is_array($s)) continue;
            $k = trim((string)($s['section_key'] ?? ''));
            $t = trim((string)($s['title'] ?? ''));
            if ($k !== '' && $t !== '') $sectionTitleByKey[$k] = $t;
        }
    }

    $objective = '';
    if (cnx_checklists_has_col($db, 'consulting_project_checklists', 'objective_text')) {
        $objective = trim((string)($chk['objective_text'] ?? ''));
    }

    // Build export rows
    $rows = [];
    $rows[] = ['Checklist', trim((string)($chk['title'] ?? 'Checklist')), 'Template', $templateKey];
    $rows[] = ['Piano', trim((string)($plan['title'] ?? '')), 'Cliente', trim((string)($plan['client_name'] ?? ''))];
    $rows[] = ['Obiettivo', $objective];
    $rows[] = []; // spacer
    $rows[] = ['Fase', 'Voce', 'Obbligatorio', 'Stato', 'Note/Testo', 'Evidenze', 'Aggiornato il'];

    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $phaseKey = (string)($it['section_key'] ?? '');
        $phase = $sectionTitleByKey[$phaseKey] ?? $phaseKey;
        $title = (string)($it['title'] ?? '');
        $required = !empty($it['required']) ? 'SI' : 'NO';
        $status = (string)($it['status'] ?? '');
        $note = (string)($it['answer_text'] ?? '');

        $evText = '';
        $evRaw = trim((string)($it['evidence_json'] ?? ''));
        if ($evRaw !== '') {
            $ev = json_decode($evRaw, true);
            if (is_array($ev)) {
                $parts = [];
                foreach ($ev as $e) {
                    if (!is_array($e)) continue;
                    $fid = (int)($e['file_id'] ?? 0);
                    if ($fid <= 0) continue;
                    $nm = trim((string)($e['name'] ?? ''));
                    $parts[] = $fid . ($nm !== '' ? (' — ' . $nm) : '');
                    if (count($parts) >= 10) break;
                }
                $evText = implode('; ', $parts);
            }
        }

        $updatedAt = (string)($it['updated_at'] ?? '');
        $rows[] = [$phase, $title, $required, $status, $note, $evText, $updatedAt];
    }

    $xlsx = cnx_build_checklist_xlsx($rows);
    $safeTpl = preg_replace('/[^A-Z0-9_]+/', '_', strtoupper($templateKey)) ?: 'CHECKLIST';
    $fn = "checklist_plan_{$planId}_{$safeTpl}.xlsx";

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $xlsx;
    exit;
} catch (Throwable $e) {
    $errId = 'chk_xlsx_' . substr(bin2hex(random_bytes(6)), 0, 12);
    error_log("[CONSULTING_CHECKLIST_EXPORT_XLSX][{$errId}] " . $e->getMessage());
    api_error('Errore export checklist', 500, ['error_id' => $errId]);
}

