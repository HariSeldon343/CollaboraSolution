<?php
declare(strict_types=1);

/**
 * Document Template Engine (DOCX/XLSX) — placeholder replacement.
 *
 * Replaces placeholders in the form {{key}} with values.
 * Uses ZipArchive and modifies relevant XML parts inside Office Open XML packages.
 *
 * Notes:
 * - No ISO/UNI text is generated here; only literal replacements.
 * - Best-effort: if a placeholder is not found, we add a warning (do not fail hard).
 * - Robustness: creates a backup before writing and restores on failure.
 */

/**
 * @param string $value
 */
function cnx_escape_xml_text(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Convert newlines for DOCX XML. Best-effort.
 * @return string
 */
function cnx_docx_newlines_to_wbr(string $escapedXmlText): string {
    // docx line breaks use <w:br/>
    return str_replace(["\r\n", "\n", "\r"], '<w:br/>', $escapedXmlText);
}

/**
 * Ensure DOCX has a <w:sectPr/> in word/document.xml body.
 * Some editors (including OnlyOffice in some configurations) may render documents without section properties as blank.
 */
function cnx_docx_ensure_sectpr_xml(string $xml, array &$warnings): string {
    if (strpos($xml, '<w:sectPr') !== false) return $xml;
    if (strpos($xml, '</w:body>') !== false) {
        $warnings[] = 'DOCX: aggiunto sectPr per compatibilità editor';
        return str_replace('</w:body>', '<w:sectPr/></w:body>', $xml);
    }
    return $xml;
}

/**
 * Best-effort: strip the placeholder intro block from the very beginning of the document body.
 *
 * We only remove when we see a strong signature in the first few paragraphs, to avoid false positives.
 */
function cnx_docx_strip_placeholder_intro_block(string $xml, array &$warnings): string {
    if (!class_exists('DOMDocument')) return $xml;
    if (strpos($xml, '<w:body') === false) return $xml;

    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return $xml;

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $paras = $xp->query('/w:document/w:body/w:p');
    if (!$paras || $paras->length === 0) return $xml;

    $getText = function(DOMNode $p) use ($xp): string {
        $ts = $xp->query('.//w:t', $p);
        if (!$ts || $ts->length === 0) return '';
        $buf = '';
        foreach ($ts as $t) {
            $buf .= (string)($t->nodeValue ?? '');
        }
        // keep simple normalization
        $buf = preg_replace('/\s+/u', ' ', $buf) ?? $buf;
        return trim($buf);
    };

    $norm = function(string $s): string {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = mb_strtolower($s, 'UTF-8');
        return trim($s);
    };

    // Detect meta signature in the first paragraphs (older placeholders often start with title/date/version lines)
    $scanN = min(20, $paras->length);
    $firstTexts = [];
    for ($i = 0; $i < $scanN; $i++) {
        /** @var DOMNode $p */
        $p = $paras->item($i);
        $firstTexts[] = $norm($getText($p));
    }

    $hasSignature = false;
    foreach ($firstTexts as $t) {
        if ($t === '') continue;
        if (strpos($t, 'documento placeholder') !== false) { $hasSignature = true; break; }
        if (strpos($t, 'nexio') !== false && strpos($t, 'placeholder') !== false) { $hasSignature = true; break; }
        if (strpos($t, 'titolo documento:') === 0) { $hasSignature = true; break; }
        if (strpos($t, 'titolo documento') === 0) { $hasSignature = true; break; }
        if (strpos($t, 'titolo:') === 0) { $hasSignature = true; break; }
        if (strpos($t, 'procedura:') === 0) { $hasSignature = true; break; }
        if (strpos($t, 'data:') === 0) { $hasSignature = true; break; }
        if (strpos($t, 'versione:') === 0) { $hasSignature = true; break; }
    }
    if (!$hasSignature) return $xml;

    // Remove leading paragraphs that are part of the intro (metadata + blanks).
    // We remove only at the beginning until we hit the first meaningful content heading.
    $maxRemove = min(30, $paras->length);
    $removed = 0;
    for ($i = 0; $i < $maxRemove; $i++) {
        /** @var DOMNode $p */
        $p = $paras->item(0);
        if (!$p) break;
        $t = $norm($getText($p));

        // Stop when we reach actual content (heuristics)
        if ($t !== '') {
            if (strpos($t, 'dati organizzazione') !== false) break;
            if (preg_match('/^sezione\s+\d+/u', $t)) break;
            if (preg_match('/^\d+\)\s*/u', $t)) break;
        }

        $isMeta = ($t === '')
            || (strpos($t, 'documento placeholder') !== false)
            || (strpos($t, 'titolo documento:') === 0)
            || (strpos($t, 'titolo documento') === 0)
            || (strpos($t, 'titolo:') === 0)
            || (strpos($t, 'procedura:') === 0)
            || (strpos($t, 'azienda:') === 0)
            || (strpos($t, 'ragione sociale:') === 0)
            || (strpos($t, 'data:') === 0)
            || (strpos($t, 'versione:') === 0)
            || (strpos($t, 'codice:') === 0);

        if (!$isMeta) break;

        $parent = $p->parentNode;
        if ($parent) {
            $parent->removeChild($p);
            $removed++;
        } else {
            break;
        }
    }

    if ($removed > 0) {
        $warnings[] = 'DOCX: rimossa intestazione ridondante nel corpo (placeholder)';
    }

    $out = $dom->saveXML($dom->documentElement);
    return is_string($out) && $out !== '' ? $out : $xml;
}

/**
 * Apply Heading1 paragraph style to any paragraphs whose plain-text matches target headings.
 *
 * @param array<int,string> $headingTexts
 */
function cnx_docx_apply_heading1_styles(string $xml, array $headingTexts, array &$warnings): string {
    if (empty($headingTexts)) return $xml;
    if (!class_exists('DOMDocument')) return $xml;

    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return $xml;

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $normalize = function(string $s): string {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s*:\s*$/u', '', $s) ?? $s;
        $s = mb_strtolower($s, 'UTF-8');
        return trim($s);
    };

    $targets = [];
    foreach ($headingTexts as $t) {
        $t = trim((string)$t);
        if ($t === '') continue;
        $targets[$normalize($t)] = true;
        // common variants
        $targets[$normalize($t . ':')] = true;
        $noParen = preg_replace('/\s*\([^)]*\)\s*$/u', '', $t);
        if (is_string($noParen) && trim($noParen) !== '') {
            $targets[$normalize($noParen)] = true;
            $targets[$normalize($noParen . ':')] = true;
        }
    }

    $paras = $xp->query('/w:document/w:body//w:p');
    if (!$paras || $paras->length === 0) return $xml;

    $applied = 0;
    foreach ($paras as $p) {
        /** @var DOMElement $p */
        $ts = $xp->query('.//w:t', $p);
        if (!$ts || $ts->length === 0) continue;
        $buf = '';
        foreach ($ts as $tNode) {
            $buf .= (string)($tNode->nodeValue ?? '');
        }
        $k = $normalize($buf);
        if ($k === '' || empty($targets[$k])) continue;

        $pPr = $xp->query('./w:pPr', $p)->item(0);
        if (!$pPr) {
            $pPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:pPr');
            // Insert pPr as first child
            if ($p->firstChild) $p->insertBefore($pPr, $p->firstChild);
            else $p->appendChild($pPr);
        }
        $pStyle = $xp->query('./w:pStyle', $pPr)->item(0);
        if (!$pStyle) {
            $pStyle = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:pStyle');
            $pPr->appendChild($pStyle);
        }
        /** @var DOMElement $pStyle */
        $pStyle->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:val', 'Heading1');
        $applied++;
    }

    if ($applied > 0) {
        $warnings[] = 'DOCX: applicato stile Titolo 1 su ' . $applied . ' titoli';
    }

    $out = $dom->saveXML($dom->documentElement);
    return is_string($out) && $out !== '' ? $out : $xml;
}

/**
 * Append a minimal "wizard" block with placeholders for missing keys.
 * This is used when the target document does not contain placeholders (or lacks some keys),
 * so that applying inputs still results in visible content.
 *
 * @param string[] $missingKeys
 */
function cnx_docx_append_missing_placeholders_block(string $xml, array $missingKeys, array &$warnings): string {
    $missingKeys = array_values(array_filter(array_map('strval', $missingKeys)));
    if (empty($missingKeys)) return $xml;

    // Ensure sectPr exists so we can safely insert before it.
    $xml = cnx_docx_ensure_sectpr_xml($xml, $warnings);

    $pos = strpos($xml, '<w:sectPr');
    if ($pos === false) {
        // Fallback: insert before </w:body>
        $pos = strpos($xml, '</w:body>');
        if ($pos === false) return $xml;
    }

    $warnings[] = 'DOCX: aggiunta appendice per placeholder mancanti (wizard)';

    // Simple paragraphs (no styling), keep placeholders intact ({{key}}) for immediate replacement.
    $block = '';
    $block .= '<w:p><w:r><w:t xml:space="preserve">' . cnx_escape_xml_text('---') . '</w:t></w:r></w:p>';
    $block .= '<w:p><w:r><w:t xml:space="preserve">' . cnx_escape_xml_text('Dati aggiunti automaticamente (wizard)') . '</w:t></w:r></w:p>';
    foreach ($missingKeys as $k) {
        $k = trim((string)$k);
        if ($k === '') continue;
        $line = $k . ': {{' . $k . '}}';
        $block .= '<w:p><w:r><w:t xml:space="preserve">' . cnx_escape_xml_text($line) . '</w:t></w:r></w:p>';
    }

    return substr($xml, 0, $pos) . $block . substr($xml, $pos);
}

/**
 * Append missing placeholders to a worksheet (inlineStr) as new rows at the end.
 *
 * @param string[] $missingKeys
 */
function cnx_xlsx_append_missing_placeholders_rows(string $xml, array $missingKeys, array &$warnings): string {
    $missingKeys = array_values(array_filter(array_map('strval', $missingKeys)));
    if (empty($missingKeys)) return $xml;

    if (strpos($xml, '<sheetData') === false || strpos($xml, '</sheetData>') === false) {
        return $xml;
    }

    // Find max existing row number
    $maxRow = 0;
    if (preg_match_all('/<row\\s+[^>]*r="(\\d+)"/i', $xml, $m)) {
        foreach ($m[1] as $n) {
            $v = (int)$n;
            if ($v > $maxRow) $maxRow = $v;
        }
    }
    $rIdx = max(1, $maxRow + 1);

    $rowsXml = '';
    foreach ($missingKeys as $k) {
        $k = trim((string)$k);
        if ($k === '') continue;

        // A = key label, B = placeholder value
        $addrA = 'A' . $rIdx;
        $addrB = 'B' . $rIdx;
        $txtA = cnx_escape_xml_text($k);
        $txtB = cnx_escape_xml_text('{{' . $k . '}}');
        $rowsXml .= '<row r="' . $rIdx . '">'
            . '<c r="' . $addrA . '" t="inlineStr"><is><t xml:space="preserve">' . $txtA . '</t></is></c>'
            . '<c r="' . $addrB . '" t="inlineStr"><is><t xml:space="preserve">' . $txtB . '</t></is></c>'
            . '</row>';
        $rIdx++;
    }

    if ($rowsXml === '') return $xml;
    $warnings[] = 'XLSX: aggiunte righe per placeholder mancanti (wizard)';

    // Insert before closing sheetData
    return str_replace('</sheetData>', $rowsXml . '</sheetData>', $xml);
}

/**
 * @return string|null backup path if created
 */
function cnx_backup_file_best_effort(string $absoluteFilePath, array &$warnings): ?string {
    $dir = dirname($absoluteFilePath);
    $base = basename($absoluteFilePath);
    $ts = date('Ymd_His');
    $bak = $dir . DIRECTORY_SEPARATOR . $base . '.bak_' . $ts;
    try {
        if (@copy($absoluteFilePath, $bak)) {
            return $bak;
        }
        $warnings[] = "Backup non creato (copy failed): {$bak}";
        return null;
    } catch (Throwable $e) {
        $warnings[] = "Backup non creato: " . $e->getMessage();
        return null;
    }
}

function cnx_restore_backup_best_effort(string $absoluteFilePath, ?string $backupPath, array &$warnings): void {
    if (!$backupPath) return;
    try {
        if (@is_file($backupPath) && @copy($backupPath, $absoluteFilePath)) {
            $warnings[] = "Ripristinato da backup: " . basename($backupPath);
            return;
        }
        $warnings[] = "Ripristino backup fallito: {$backupPath}";
    } catch (Throwable $e) {
        $warnings[] = "Ripristino backup fallito: " . $e->getMessage();
    }
}

/**
 * Replace placeholders in a single XML string.
 *
 * @param array<string,string> $replacements
 * @return array{xml:string,found_keys:array<string,bool>}
 */
function cnx_replace_placeholders_in_xml(string $xml, array $replacements, bool $docxMode): array {
    $found = [];
    $out = $xml;
    $missing = [];
    foreach ($replacements as $k => $vRaw) {
        $ph = '{{' . $k . '}}';
        if (strpos($out, $ph) === false) {
            $missing[$k] = $vRaw;
            continue;
        }
        $found[$k] = true;
        $escaped = cnx_escape_xml_text((string)$vRaw);
        if ($docxMode) {
            $escaped = cnx_docx_newlines_to_wbr($escaped);
        }
        $out = str_replace($ph, $escaped, $out);
    }

    // DOCX: placeholders are often split across multiple <w:t> nodes (Word/OnlyOffice behavior).
    // If we still have missing keys, attempt a fallback replacement that can span nodes.
    // Note: in DOCX the braces may be split by tags (e.g. "{" in one <w:t>, "{" in the next),
    // so checking for literal "{{" in raw XML is unreliable.
    if ($docxMode && !empty($missing) && strpos($out, '<w:t') !== false) {
        $GLOBALS['CNX_DOCX_PLACEHOLDER_DEBUG'] = $GLOBALS['CNX_DOCX_PLACEHOLDER_DEBUG'] ?? [];
        $GLOBALS['CNX_DOCX_PLACEHOLDER_DEBUG']['dom_available'] = class_exists('DOMDocument');
        try {
            $beforeCount = count($found);
            if (class_exists('DOMDocument')) {
                $GLOBALS['CNX_DOCX_PLACEHOLDER_DEBUG']['fallback_mode'] = 'dom';
                $out2 = cnx_docx_replace_placeholders_across_wt($out, $missing, $found);
                $out = $out2;
            }
            // If DOM is unavailable OR did not replace anything, try no-DOM fallback too.
            if (!class_exists('DOMDocument') || count($found) === $beforeCount) {
                $GLOBALS['CNX_DOCX_PLACEHOLDER_DEBUG']['fallback_mode'] = 'nodom';
                $out2 = cnx_docx_replace_placeholders_across_wt_nodom($out, $missing, $found);
                $out = $out2;
            }
        } catch (Throwable $e) {
            // best-effort: ignore and keep original output
            $GLOBALS['CNX_DOCX_PLACEHOLDER_DEBUG']['fallback_error'] = $e->getMessage();
        }
    }

    return ['xml' => $out, 'found_keys' => $found];
}

/**
 * No-DOM fallback: replace placeholders across multiple <w:t> nodes using regex extraction.
 * This is used on servers without ext-dom (DOMDocument unavailable).
 *
 * @param array<string,string> $replacements
 * @param array<string,bool>   $found In/out
 */
function cnx_docx_replace_placeholders_across_wt_nodom(string $xml, array $replacements, array &$found): string {
    // Extract all <w:t ...>...</w:t> nodes (best-effort). Keep wrappers to reassemble later.
    if (strpos($xml, '<w:t') === false) return $xml;
    if (!preg_match_all('/<w:t\\b([^>]*)>(.*?)<\\/w:t>/s', $xml, $m, PREG_OFFSET_CAPTURE)) {
        return $xml;
    }

    $count = count($m[0]);
    if ($count <= 0) return $xml;

    $nodes = [];
    $starts = [];
    $lens = [];
    $full = '';
    $off = 0;

    for ($i = 0; $i < $count; $i++) {
        $attrs = $m[1][$i][0] ?? '';
        $inner = $m[2][$i][0] ?? '';
        // decode entities for matching braces/keys; keep as text
        $text = html_entity_decode($inner, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $nodes[] = ['attrs' => $attrs, 'inner_raw' => $inner, 'text' => $text];
        $starts[] = $off;
        $l = strlen($text);
        $lens[] = $l;
        $full .= $text;
        $off += $l;
    }

    if ($full === '') return $xml;

    $normalizeKey = function(string $raw): string {
        $k = $raw;
        // remove whitespace + common invisible/bidi marks that can be injected by editors
        $k = preg_replace('/[\\s\\x{00A0}\\x{200B}-\\x{200F}\\x{202A}-\\x{202E}\\x{2060}\\x{2066}-\\x{2069}\\x{FEFF}]+/u', '', $k) ?? $k;
        $k = preg_replace('/[^A-Za-z0-9_]/', '', $k) ?? $k;
        return (string)$k;
    };

    $findNodeIndex = function(int $idx) use ($starts, $lens): int {
        $lo = 0;
        $hi = count($starts) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $s = $starts[$mid];
            $e = $s + $lens[$mid];
            if ($idx < $s) $hi = $mid - 1;
            elseif ($idx >= $e) $lo = $mid + 1;
            else return $mid;
        }
        return max(0, min(count($starts) - 1, $lo));
    };

    $pos = 0;
    $guard = 0;
    $tokenRe = '/\\{[\\s\\x{00A0}\\x{200B}-\\x{200F}\\x{202A}-\\x{202E}\\x{2060}\\x{2066}-\\x{2069}\\x{FEFF}]*\\{(.{0,200}?)\\}[\\s\\x{00A0}\\x{200B}-\\x{200F}\\x{202A}-\\x{202E}\\x{2060}\\x{2066}-\\x{2069}\\x{FEFF}]*\\}/u';
    while ($guard++ < 5000) {
        $mm = [];
        if (!preg_match($tokenRe, $full, $mm, PREG_OFFSET_CAPTURE, $pos)) {
            break;
        }
        $matchText = (string)($mm[0][0] ?? '');
        $start = (int)($mm[0][1] ?? -1);
        $rawKey = (string)($mm[1][0] ?? '');
        if ($start < 0 || $matchText === '') {
            break;
        }
        $key = $normalizeKey($rawKey);
        if ($key === '' || !array_key_exists($key, $replacements)) {
            $pos = $start + 1;
            continue;
        }

        $startIdx = $start;
        $endIdx = $start + strlen($matchText) - 1;
        $iStart = $findNodeIndex($startIdx);
        $iEnd = $findNodeIndex($endIdx);

        // For no-DOM mode we can safely inject escaped XML text (including <w:br/> if present),
        // because we will write raw inner XML back (not nodeValue).
        $replacementXml = cnx_docx_newlines_to_wbr(cnx_escape_xml_text((string)$replacements[$key]));

        $startText = $nodes[$iStart]['text'];
        $localStart = max(0, $startIdx - $starts[$iStart]);

        if ($iStart === $iEnd) {
            $before = substr($startText, 0, $localStart);
            $after = substr($startText, $localStart + ($endIdx - $startIdx + 1));
            $nodes[$iStart]['inner_raw'] = cnx_escape_xml_text($before) . $replacementXml . cnx_escape_xml_text($after);
            $nodes[$iStart]['text'] = $before . html_entity_decode($replacementXml, ENT_QUOTES | ENT_XML1, 'UTF-8') . $after;
        } else {
            $endText = $nodes[$iEnd]['text'];
            $localEnd = max(0, $endIdx - $starts[$iEnd]);
            $before = substr($startText, 0, $localStart);
            $after = substr($endText, $localEnd + 1);
            $nodes[$iStart]['inner_raw'] = cnx_escape_xml_text($before) . $replacementXml . cnx_escape_xml_text($after);
            $nodes[$iStart]['text'] = $before . html_entity_decode($replacementXml, ENT_QUOTES | ENT_XML1, 'UTF-8') . $after;
            for ($j = $iStart + 1; $j <= $iEnd; $j++) {
                $nodes[$j]['inner_raw'] = '';
                $nodes[$j]['text'] = '';
            }
        }

        $found[$key] = true;

        // recompute full/offsets
        $full = '';
        $off = 0;
        for ($i = 0; $i < $count; $i++) {
            $starts[$i] = $off;
            $lens[$i] = strlen($nodes[$i]['text']);
            $full .= $nodes[$i]['text'];
            $off += $lens[$i];
        }
        $pos = $start + max(1, strlen($replacementXml));
    }

    // Rebuild XML by replacing each <w:t...>...</w:t> occurrence in order
    $out = $xml;
    $cursor = 0;
    for ($i = 0; $i < $count; $i++) {
        $match = $m[0][$i][0];
        $at = strpos($out, $match, $cursor);
        if ($at === false) break;
        $before = substr($out, 0, $at);
        $after = substr($out, $at + strlen($match));
        $new = '<w:t' . ($nodes[$i]['attrs'] ?? '') . '>' . ($nodes[$i]['inner_raw'] ?? '') . '</w:t>';
        $out = $before . $new . $after;
        $cursor = $at + strlen($new);
    }

    return $out;
}

/**
 * DOCX helper: replace placeholders even when the token is split across multiple <w:t> nodes.
 *
 * Strategy:
 * - Parse XML with DOMDocument
 * - Collect all w:t elements in document order
 * - Build a concatenated plain-text string of their values and map offsets to nodes
 * - For each placeholder occurrence, rewrite the start node and blank intermediate nodes
 *
 * @param array<string,string> $replacements
 * @param array<string,bool>   $found In/out
 */
function cnx_docx_replace_placeholders_across_wt(string $xml, array $replacements, array &$found): string {
    if (!class_exists('DOMDocument')) return $xml;

    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return $xml;

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $nodes = $xp->query('//w:t');
    if (!$nodes || $nodes->length === 0) return $xml;

    $texts = [];
    $starts = [];
    $lens = [];
    $full = '';
    $offset = 0;

    /** @var DOMElement $n */
    foreach ($nodes as $n) {
        $val = (string)$n->nodeValue;
        $texts[] = $n;
        $starts[] = $offset;
        $len = strlen($val);
        $lens[] = $len;
        $full .= $val;
        $offset += $len;
    }

    if ($full === '') return $xml;

    // Helper: find node index by global offset (binary search)
    $findNodeIndex = function(int $idx) use ($starts, $lens): int {
        $lo = 0;
        $hi = count($starts) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $s = $starts[$mid];
            $e = $s + $lens[$mid];
            if ($idx < $s) {
                $hi = $mid - 1;
            } elseif ($idx >= $e) {
                $lo = $mid + 1;
            } else {
                return $mid;
            }
        }
        // If idx falls exactly at end-of-node, place it on previous node when possible
        return max(0, min(count($starts) - 1, $lo));
    };

    // Normalize a placeholder key extracted from {{ ... }} in the DOCX text.
    $normalizeKey = function(string $raw): string {
        $k = $raw;
        // remove whitespace + common invisible/bidi marks that can be injected by editors
        $k = preg_replace('/[\\s\\x{00A0}\\x{200B}-\\x{200F}\\x{202A}-\\x{202E}\\x{2060}\\x{2066}-\\x{2069}\\x{FEFF}]+/u', '', $k) ?? $k;
        // keep only safe key chars
        $k = preg_replace('/[^A-Za-z0-9_]/', '', $k) ?? $k;
        return (string)$k;
    };

    // Replace any {{...}} token we can resolve, even if whitespace/zero-width chars exist between braces.
    $pos = 0;
    $guard = 0;
    $tokenRe = '/\\{[\\s\\x{00A0}\\x{200B}-\\x{200F}\\x{202A}-\\x{202E}\\x{2060}\\x{2066}-\\x{2069}\\x{FEFF}]*\\{(.{0,200}?)\\}[\\s\\x{00A0}\\x{200B}-\\x{200F}\\x{202A}-\\x{202E}\\x{2060}\\x{2066}-\\x{2069}\\x{FEFF}]*\\}/u';
    while ($guard++ < 5000) {
        $mm = [];
        if (!preg_match($tokenRe, $full, $mm, PREG_OFFSET_CAPTURE, $pos)) {
            break;
        }
        $matchText = (string)($mm[0][0] ?? '');
        $start = (int)($mm[0][1] ?? -1);
        $rawKey = (string)($mm[1][0] ?? '');
        if ($start < 0 || $matchText === '') {
            break;
        }
        $key = $normalizeKey($rawKey);
        if ($key === '' || !array_key_exists($key, $replacements)) {
            $pos = $start + 1;
            continue;
        }

        $startIdx = $start;
        $endIdx = $start + strlen($matchText) - 1;
        $iStart = $findNodeIndex($startIdx);
        $iEnd = $findNodeIndex($endIdx);

        // DOM nodeValue can't contain XML elements; convert newlines to spaces for this fallback.
        $replacementText = (string)($replacements[$key] ?? '');
        $replacementText = str_replace(["\r\n", "\n", "\r"], ' ', $replacementText);

        /** @var DOMElement $nStart */
        $nStart = $texts[$iStart];
        $startVal = (string)$nStart->nodeValue;
        $localStart = max(0, $startIdx - $starts[$iStart]);

        if ($iStart === $iEnd) {
            $before = substr($startVal, 0, $localStart);
            $after = substr($startVal, $localStart + ($endIdx - $startIdx + 1));
            $nStart->nodeValue = $before . $replacementText . $after;
        } else {
            /** @var DOMElement $nEnd */
            $nEnd = $texts[$iEnd];
            $endVal = (string)$nEnd->nodeValue;
            $localEnd = max(0, $endIdx - $starts[$iEnd]);
            $before = substr($startVal, 0, $localStart);
            $after = substr($endVal, $localEnd + 1);
            $nStart->nodeValue = $before . $replacementText . $after;
            for ($j = $iStart + 1; $j <= $iEnd; $j++) {
                /** @var DOMElement $nn */
                $nn = $texts[$j];
                $nn->nodeValue = '';
            }
        }

        $found[$key] = true;

        // Recompute full text and offsets (doc sizes are small here; best-effort)
        $full = '';
        $offset = 0;
        foreach ($texts as $idx => $tn) {
            $v = (string)$tn->nodeValue;
            $starts[$idx] = $offset;
            $lens[$idx] = strlen($v);
            $full .= $v;
            $offset += $lens[$idx];
        }
        $pos = $start + max(1, strlen($replacementText));
    }

    // Save XML back (strip XML declaration to keep Zip parts consistent)
    $out = $dom->saveXML($dom->documentElement);
    return is_string($out) && $out !== '' ? $out : $xml;
}

/**
 * Apply placeholders to DOCX file.
 *
 * @param array<string,string> $replacements
 * @param array<string,mixed> $opts
 */
function cnx_apply_placeholders_to_docx(string $absoluteFilePath, array $replacements, array &$warnings, array $opts = []): bool {
    $warnings = $warnings ?? [];
    if (!is_file($absoluteFilePath)) {
        $warnings[] = "File non trovato: {$absoluteFilePath}";
        return false;
    }

    $backup = cnx_backup_file_best_effort($absoluteFilePath, $warnings);

    $zip = new ZipArchive();
    if ($zip->open($absoluteFilePath) !== true) {
        $warnings[] = "Impossibile aprire DOCX (ZipArchive): {$absoluteFilePath}";
        return false;
    }

    try {
        // Ensure a standard header exists (all pages) before placeholder replacement.
        // This allows us to manage tenant header consistently across templates and legacy docs.
        try {
            cnx_docx_ensure_standard_header($zip, $warnings, $opts);
        } catch (Throwable $e) {
            $warnings[] = 'DOCX: header non applicato: ' . $e->getMessage();
        }

        // Some ZipArchive builds are unreliable with locateName()/getFromName() (especially after addFromString()).
        // To be robust, we close+reopen so the central directory is stable, then enumerate entries and read via index.
        $zip->close();
        $zip = new ZipArchive();
        if ($zip->open($absoluteFilePath) !== true) {
            $warnings[] = "Impossibile riaprire DOCX (ZipArchive) dopo header: {$absoluteFilePath}";
            return false;
        }

        // Build a case-insensitive map of entry name -> index (use lowercase key).
        $nameToIndex = [];
        $indexToName = [];
        $n = (int)($zip->numFiles ?? 0);
        for ($i = 0; $i < $n; $i++) {
            $st = $zip->statIndex($i);
            $nm = is_array($st) ? (string)($st['name'] ?? '') : '';
            if ($nm === '') continue;
            $indexToName[$i] = $nm;
            $nameToIndex[strtolower($nm)] = $i;
        }

        // Discover target XML parts. Use case-insensitive matching and avoid locateName().
        $targets = []; // array<int,array{idx:int,name:string}>
        $docIdx = null;
        foreach ($indexToName as $idx => $nm) {
            $low = strtolower($nm);
            if ($low === 'word/document.xml') {
                $docIdx = (int)$idx;
                $targets[] = ['idx' => (int)$idx, 'name' => $nm];
                continue;
            }
            if (preg_match('/^word\\/(header\\d+\\.xml|footer\\d+\\.xml)$/i', $nm)) {
                $targets[] = ['idx' => (int)$idx, 'name' => $nm];
                continue;
            }
        }

        // Ensure we always process our standard header part if header is enabled (even if we couldn't match via regex above).
        if (!empty($opts['header_enabled'])) {
            $h1 = $nameToIndex['word/header1.xml'] ?? null;
            if ($h1 !== null) {
                $already = false;
                foreach ($targets as $t) {
                    if ((int)$t['idx'] === (int)$h1) { $already = true; break; }
                }
                if (!$already) {
                    $targets[] = ['idx' => (int)$h1, 'name' => (string)($indexToName[(int)$h1] ?? 'word/header1.xml')];
                }
            }
        }

        if ($docIdx === null) {
            $warnings[] = "DOCX senza word/document.xml (struttura inattesa)";
            $zip->close();
            return false;
        }

        $foundAll = [];
        $docXmlAfter = null;
        foreach ($targets as $t) {
            $path = (string)($t['name'] ?? '');
            $idx = (int)($t['idx'] ?? -1);
            if ($path === '' || $idx < 0) continue;

            // Prefer getFromIndex (more reliable than getFromName on some builds).
            $xml = $zip->getFromIndex($idx);
            if ($xml === false) continue;
            if (strtolower($path) === 'word/document.xml') {
                $xml = cnx_docx_ensure_sectpr_xml($xml, $warnings);
                if (!empty($opts['strip_intro_block'])) {
                    $xml = cnx_docx_strip_placeholder_intro_block($xml, $warnings);
                }
                if (!empty($opts['docx_heading1_texts']) && is_array($opts['docx_heading1_texts'])) {
                    $xml = cnx_docx_apply_heading1_styles($xml, $opts['docx_heading1_texts'], $warnings);
                }
            }
            $res = cnx_replace_placeholders_in_xml($xml, $replacements, true);
            foreach (($res['found_keys'] ?? []) as $k => $_v) { $foundAll[$k] = true; }
            if (strtolower($path) === 'word/document.xml') {
                $docXmlAfter = $res['xml'];
            }
            $zip->addFromString($path, $res['xml']);
        }

        // Bootstrap: if some non-empty replacements were not found anywhere, append a small block in document.xml
        // with placeholders and apply again (best-effort, non-destructive).
        $missingKeys = [];
        foreach ($replacements as $k => $v) {
            $sv = trim((string)$v);
            if ($sv === '') continue;
            if (!isset($foundAll[$k])) $missingKeys[] = (string)$k;
        }
        if (!empty($missingKeys) && is_string($docXmlAfter) && $docXmlAfter !== '') {
            $boot = cnx_docx_append_missing_placeholders_block($docXmlAfter, $missingKeys, $warnings);
            $res2 = cnx_replace_placeholders_in_xml($boot, $replacements, true);
            foreach (($res2['found_keys'] ?? []) as $k => $_v) { $foundAll[$k] = true; }
            // Write back using canonical name for document.xml (preserve original case/name if any)
            $zip->addFromString($indexToName[$docIdx] ?? 'word/document.xml', $res2['xml']);
        }

        $zip->close();

        // Warn once for missing placeholders (only if value is non-empty)
        foreach ($replacements as $k => $v) {
            $sv = trim((string)$v);
            if ($sv === '') continue;
            if (!isset($foundAll[$k])) {
                $warnings[] = "Placeholder non trovato nel documento: {{" . $k . "}}";
            }
        }

        return true;
    } catch (Throwable $e) {
        try { $zip->close(); } catch (Throwable $_) {}
        $warnings[] = "Errore applicazione placeholder DOCX: " . $e->getMessage();
        cnx_restore_backup_best_effort($absoluteFilePath, $backup, $warnings);
        return false;
    }
}

/**
 * Ensure a standard header is present and referenced in word/document.xml (default header).
 *
 * - Creates/updates word/header1.xml (and rels + media) if needed
 * - Ensures [Content_Types].xml includes header override (and image default)
 * - Ensures word/_rels/document.xml.rels includes header relationship
 * - Ensures word/document.xml has a w:headerReference (default) pointing to our header rel id
 *
 * @param array<string,mixed> $opts
 */
function cnx_docx_ensure_standard_header(ZipArchive $zip, array &$warnings, array $opts = []): void {
    $enabled = (bool)($opts['header_enabled'] ?? true);
    if (!$enabled) return;

    $relId = (string)($opts['header_rel_id'] ?? 'rIdCnxHeader1');
    if (trim($relId) === '') $relId = 'rIdCnxHeader1';

    $headerPath = 'word/header1.xml';
    $headerRelsPath = 'word/_rels/header1.xml.rels';
    $footerPath = 'word/footer1.xml';
    $docRelsPath = 'word/_rels/document.xml.rels';
    $contentTypesPath = '[Content_Types].xml';
    $stylesPath = 'word/styles.xml';

    // Optional logo embedding
    $logoBytes = $opts['logo_bytes'] ?? null;
    $logoExt = strtolower(trim((string)($opts['logo_ext'] ?? 'png')));
    if (!in_array($logoExt, ['png', 'jpg', 'jpeg'], true)) {
        $logoExt = 'png';
    }
    $logoTarget = "media/cnx_tenant_logo.{$logoExt}";
    $logoFullPath = "word/{$logoTarget}";
    $hasLogo = is_string($logoBytes) && $logoBytes !== '';

    // 1) Ensure header XML exists (with placeholders)
    if ($zip->locateName($headerPath) === false) {
        $warnings[] = 'DOCX: aggiunto header standard (header1.xml)';
    }
    $hdrXml = cnx_docx_build_header_xml($hasLogo ? 'rIdLogo' : '');
    $zip->addFromString($headerPath, $hdrXml);

    // 1.1) Ensure footer XML exists (page number bottom-right)
    if ($zip->locateName($footerPath) === false) {
        $warnings[] = 'DOCX: aggiunto footer standard (numero pagina)';
    }
    $zip->addFromString($footerPath, cnx_docx_build_footer_page_number_xml());

    // 2) Ensure header rels (only if logo is embedded)
    if ($hasLogo) {
        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../' . $logoTarget . '"/>'
            . '</Relationships>';
        $zip->addFromString($headerRelsPath, $relsXml);
        $zip->addFromString($logoFullPath, $logoBytes);
    } else {
        // If we don't have a logo, avoid stale rels that could reference missing media.
        if ($zip->locateName($headerRelsPath) !== false) {
            $zip->deleteName($headerRelsPath);
        }
        if ($zip->locateName($logoFullPath) !== false) {
            $zip->deleteName($logoFullPath);
        }
    }

    // 3) Ensure content types include header override and image defaults
    $ct = $zip->getFromName($contentTypesPath);
    if (is_string($ct) && $ct !== '') {
        if (strpos($ct, 'PartName="/word/header1.xml"') === false) {
            $ct = preg_replace(
                '/<\/Types>\s*$/',
                '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/></Types>',
                $ct
            ) ?: $ct;
        }
        if (strpos($ct, 'PartName="/word/footer1.xml"') === false) {
            $ct = preg_replace(
                '/<\/Types>\s*$/',
                '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>',
                $ct
            ) ?: $ct;
        }
        // Ensure styles override (so paragraph styles like Heading1 resolve consistently)
        if (strpos($ct, 'PartName="/word/styles.xml"') === false) {
            $ct = preg_replace(
                '/<\/Types>\s*$/',
                '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
                $ct
            ) ?: $ct;
        }
        if ($hasLogo) {
            if ($logoExt === 'png' && strpos($ct, 'Extension="png"') === false) {
                $ct = preg_replace(
                    '/<\/Types>\s*$/',
                    '<Default Extension="png" ContentType="image/png"/></Types>',
                    $ct
                ) ?: $ct;
            }
            if (in_array($logoExt, ['jpg', 'jpeg'], true) && strpos($ct, 'Extension="jpg"') === false && strpos($ct, 'Extension="jpeg"') === false) {
                $ct = preg_replace(
                    '/<\/Types>\s*$/',
                    '<Default Extension="jpg" ContentType="image/jpeg"/></Types>',
                    $ct
                ) ?: $ct;
            }
        }
        $zip->addFromString($contentTypesPath, $ct);
    }

    // 4) Ensure document.xml.rels has header relationship
    $rels = $zip->getFromName($docRelsPath);
    if (is_string($rels) && $rels !== '') {
        if (strpos($rels, 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header"') === false
            || strpos($rels, 'Target="header1.xml"') === false
            || strpos($rels, 'Id="' . $relId . '"') === false) {
            // Remove any existing relationship with our Id (safe) then append ours.
            $rels = preg_replace('/<Relationship\b[^>]*Id="' . preg_quote($relId, '/') . '"[^>]*\/>\s*/', '', $rels) ?: $rels;
            $insert = '<Relationship Id="' . $relId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>';
            $rels = preg_replace('/<\/Relationships>\s*$/', $insert . '</Relationships>', $rels) ?: $rels;
            $zip->addFromString($docRelsPath, $rels);
        }

        // Ensure styles relationship
        if (strpos($rels, 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"') === false) {
            $insert = '<Relationship Id="rIdCnxStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
            $rels2 = preg_replace('/<\/Relationships>\s*$/', $insert . '</Relationships>', $rels);
            if (is_string($rels2) && $rels2 !== '') {
                $rels = $rels2;
                $zip->addFromString($docRelsPath, $rels);
            }
        }

        // Ensure footer relationship
        if (strpos($rels, 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer"') === false) {
            $insert = '<Relationship Id="rIdCnxFooter1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>';
            $rels2 = preg_replace('/<\/Relationships>\s*$/', $insert . '</Relationships>', $rels);
            if (is_string($rels2) && $rels2 !== '') {
                $rels = $rels2;
                $zip->addFromString($docRelsPath, $rels);
            }
        }
    }

    // 4.1) Ensure a minimal styles.xml exists (Normal + Heading1)
    $stylesXml = $zip->getFromName($stylesPath);
    if (!is_string($stylesXml) || trim($stylesXml) === '') {
        $warnings[] = 'DOCX: aggiunto styles.xml (Heading1)';
        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '  <w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
            . '    <w:name w:val="Normal"/>'
            . '    <w:qFormat/>'
            . '    <w:rPr><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr>'
            . '  </w:style>'
            . '  <w:style w:type="paragraph" w:styleId="Heading1">'
            . '    <w:name w:val="heading 1"/>'
            . '    <w:basedOn w:val="Normal"/>'
            . '    <w:next w:val="Normal"/>'
            . '    <w:qFormat/>'
            . '    <w:pPr><w:keepNext/><w:keepLines/><w:outlineLvl w:val="0"/></w:pPr>'
            . '    <w:rPr><w:b/><w:bCs/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr>'
            . '  </w:style>'
            . '</w:styles>';
        $zip->addFromString($stylesPath, $stylesXml);
    }

    // 5) Ensure document.xml has default headerReference pointing to our rel id
    $docXml = $zip->getFromName('word/document.xml');
    if (is_string($docXml) && $docXml !== '') {
        $docXml = cnx_docx_ensure_sectpr_xml($docXml, $warnings);
        // Ensure relationships namespace exists for r:id usage (some minimal DOCX generators omit it)
        if (strpos($docXml, 'xmlns:r=') === false) {
            $docXml = preg_replace(
                '/<w:document\b([^>]*)>/',
                '<w:document$1 xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">',
                $docXml,
                1
            ) ?: $docXml;
        }
        // Expand self-closing sectPr preserving attributes
        $docXml = preg_replace('/<w:sectPr([^>]*)\/>/', '<w:sectPr$1></w:sectPr>', $docXml) ?: $docXml;

        if (strpos($docXml, 'w:headerReference') === false) {
            // Insert inside sectPr (before closing) preserving attributes and inner content
            $docXml = preg_replace(
                '/<w:sectPr\b([^>]*)>(.*?)<\/w:sectPr>/s',
                '<w:sectPr$1>$2<w:headerReference w:type="default" r:id="' . $relId . '"/></w:sectPr>',
                $docXml,
                1
            ) ?: $docXml;
        } else {
            // Replace default headerReference only (leave first/even if present)
            $docXml = preg_replace(
                '/<w:headerReference\b[^>]*w:type="default"[^>]*\/>/',
                '<w:headerReference w:type="default" r:id="' . $relId . '"/>',
                $docXml
            ) ?: $docXml;
        }
        // Ensure default footerReference for page numbers
        if (strpos($docXml, 'w:footerReference') === false) {
            $docXml = preg_replace(
                '/<w:sectPr\b([^>]*)>(.*?)<\/w:sectPr>/s',
                '<w:sectPr$1>$2<w:footerReference w:type="default" r:id="rIdCnxFooter1"/></w:sectPr>',
                $docXml,
                1
            ) ?: $docXml;
        } else {
            $docXml = preg_replace(
                '/<w:footerReference\b[^>]*w:type="default"[^>]*\/>/',
                '<w:footerReference w:type="default" r:id="rIdCnxFooter1"/>',
                $docXml
            ) ?: $docXml;
        }
        $zip->addFromString('word/document.xml', $docXml);
    }
}

/**
 * Build a standard header XML with placeholders.
 * If $logoRelId is non-empty, an image drawing referencing that relationship is inserted.
 */
function cnx_docx_build_header_xml(string $logoRelId = ''): string {
    $nsW = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $nsR = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $nsWp = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
    $nsA = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    $nsPic = 'http://schemas.openxmlformats.org/drawingml/2006/picture';

    $logoCell = '';
    if (trim($logoRelId) !== '') {
        // ~160x45 px
        $cx = 1524000;
        $cy = 428625;
        $logoCell = '
            <w:p>
              <w:r>
                <w:drawing>
                  <wp:inline distT="0" distB="0" distL="0" distR="0">
                    <wp:extent cx="' . $cx . '" cy="' . $cy . '"/>
                    <wp:docPr id="1" name="TenantLogo"/>
                    <a:graphic xmlns:a="' . $nsA . '">
                      <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                        <pic:pic xmlns:pic="' . $nsPic . '">
                          <pic:nvPicPr>
                            <pic:cNvPr id="0" name="tenant_logo"/>
                            <pic:cNvPicPr/>
                          </pic:nvPicPr>
                          <pic:blipFill>
                            <a:blip r:embed="' . htmlspecialchars($logoRelId, ENT_QUOTES) . '"/>
                            <a:stretch><a:fillRect/></a:stretch>
                          </pic:blipFill>
                          <pic:spPr>
                            <a:xfrm>
                              <a:off x="0" y="0"/>
                              <a:ext cx="' . $cx . '" cy="' . $cy . '"/>
                            </a:xfrm>
                            <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                          </pic:spPr>
                        </pic:pic>
                      </a:graphicData>
                    </a:graphic>
                  </wp:inline>
                </w:drawing>
              </w:r>
            </w:p>';
    } else {
        $logoCell = '<w:p><w:r><w:t></w:t></w:r></w:p>';
    }

    // 3-column layout (1 row):
    // Col1: Logo
    // Col2: Titolo + Codice
    // Col3: Revisione + Data
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:hdr xmlns:w="' . $nsW . '" xmlns:r="' . $nsR . '" xmlns:wp="' . $nsWp . '" xmlns:a="' . $nsA . '" xmlns:pic="' . $nsPic . '">'
        . '<w:tbl>'
        . '  <w:tblPr><w:tblW w:w="0" w:type="auto"/></w:tblPr>'
        . '  <w:tr>'
        . '    <w:tc><w:tcPr><w:tcW w:w="2500" w:type="dxa"/></w:tcPr>' . $logoCell . '</w:tc>'
        . '    <w:tc><w:tcPr><w:tcW w:w="5000" w:type="dxa"/></w:tcPr>'
        . '      <w:p><w:r><w:t>{{doc_title}}</w:t></w:r></w:p>'
        . '      <w:p><w:r><w:t>Codice: {{doc_code}}</w:t></w:r></w:p>'
        . '    </w:tc>'
        . '    <w:tc><w:tcPr><w:tcW w:w="3500" w:type="dxa"/></w:tcPr>'
        . '      <w:p><w:r><w:t>Revisione: {{doc_version}}</w:t></w:r></w:p>'
        . '      <w:p><w:r><w:t>Data: {{doc_date}}</w:t></w:r></w:p>'
        . '    </w:tc>'
        . '  </w:tr>'
        . '</w:tbl>'
        . '</w:hdr>';
}

/**
 * Build a standard footer XML with a right-aligned PAGE field.
 */
function cnx_docx_build_footer_page_number_xml(): string {
    $nsW = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    // fldSimple is the most compatible way across Word/OnlyOffice
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:ftr xmlns:w="' . $nsW . '">'
        . '  <w:p>'
        . '    <w:pPr><w:jc w:val="right"/></w:pPr>'
        . '    <w:fldSimple w:instr="PAGE \\* MERGEFORMAT">'
        . '      <w:r><w:t>1</w:t></w:r>'
        . '    </w:fldSimple>'
        . '  </w:p>'
        . '</w:ftr>';
}

/**
 * Apply placeholders to XLSX file.
 *
 * @param array<string,string> $replacements
 */
function cnx_apply_placeholders_to_xlsx(string $absoluteFilePath, array $replacements, array &$warnings): bool {
    $warnings = $warnings ?? [];
    if (!is_file($absoluteFilePath)) {
        $warnings[] = "File non trovato: {$absoluteFilePath}";
        return false;
    }

    $backup = cnx_backup_file_best_effort($absoluteFilePath, $warnings);

    $zip = new ZipArchive();
    if ($zip->open($absoluteFilePath) !== true) {
        $warnings[] = "Impossibile aprire XLSX (ZipArchive): {$absoluteFilePath}";
        return false;
    }

    try {
        $targets = [];
        $shared = 'xl/sharedStrings.xml';
        if ($zip->locateName($shared) !== false) $targets[] = $shared;
        for ($i = 1; $i <= 50; $i++) {
            $sheet = "xl/worksheets/sheet{$i}.xml";
            if ($zip->locateName($sheet) !== false) $targets[] = $sheet;
        }

        if (empty($targets)) {
            $warnings[] = "XLSX senza sharedStrings/worksheets (struttura inattesa)";
            $zip->close();
            return false;
        }

        $foundAll = [];
        $firstSheetPath = null;
        $firstSheetAfter = null;
        foreach ($targets as $path) {
            $xml = $zip->getFromName($path);
            if ($xml === false) continue;
            $res = cnx_replace_placeholders_in_xml($xml, $replacements, false);
            foreach (($res['found_keys'] ?? []) as $k => $_v) { $foundAll[$k] = true; }
            if ($firstSheetPath === null && str_starts_with($path, 'xl/worksheets/sheet')) {
                $firstSheetPath = $path;
                $firstSheetAfter = $res['xml'];
            }
            $zip->addFromString($path, $res['xml']);
        }

        // Bootstrap: if placeholders are missing, append rows into the first worksheet and apply again.
        $missingKeys = [];
        foreach ($replacements as $k => $v) {
            $sv = trim((string)$v);
            if ($sv === '') continue;
            if (!isset($foundAll[$k])) $missingKeys[] = (string)$k;
        }
        if (!empty($missingKeys) && is_string($firstSheetPath) && $firstSheetPath !== '' && is_string($firstSheetAfter) && $firstSheetAfter !== '') {
            $boot = cnx_xlsx_append_missing_placeholders_rows($firstSheetAfter, $missingKeys, $warnings);
            $res2 = cnx_replace_placeholders_in_xml($boot, $replacements, false);
            foreach (($res2['found_keys'] ?? []) as $k => $_v) { $foundAll[$k] = true; }
            $zip->addFromString($firstSheetPath, $res2['xml']);
        }

        $zip->close();

        foreach ($replacements as $k => $v) {
            $sv = trim((string)$v);
            if ($sv === '') continue;
            if (!isset($foundAll[$k])) {
                $warnings[] = "Placeholder non trovato nel documento: {{" . $k . "}}";
            }
        }

        return true;
    } catch (Throwable $e) {
        try { $zip->close(); } catch (Throwable $_) {}
        $warnings[] = "Errore applicazione placeholder XLSX: " . $e->getMessage();
        cnx_restore_backup_best_effort($absoluteFilePath, $backup, $warnings);
        return false;
    }
}

