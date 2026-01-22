<?php
/**
 * Build Nexio onboarding PDF (self-contained, no external libraries).
 *
 * Usage (from project root):
 *   php scripts/build_docs_pdf.php
 *
 * Output:
 *   docs/Nexio_Guida_Iniziale.pdf
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$mdPath = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'Nexio_Guida_Iniziale.md';
$logoPng = $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'logo.png';
$outPdf = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'Nexio_Guida_Iniziale.pdf';

if (!is_file($mdPath)) {
    fwrite(STDERR, "Missing source: {$mdPath}\n");
    exit(1);
}
if (!is_file($logoPng)) {
    fwrite(STDERR, "Missing logo: {$logoPng}\n");
    exit(1);
}

// ----------------------------
// Helpers
// ----------------------------
function pdf_escape(string $s): string {
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace('(', '\\(', $s);
    $s = str_replace(')', '\\)', $s);
    $s = str_replace("\r", '', $s);
    return $s;
}

/**
 * Parse markdown into styled elements (very small subset).
 *
 * @return array<int, array{type:string,text:string,level?:int}>
 */
function md_to_elements(string $md): array {
    $lines = preg_split("/\\r?\\n/", $md) ?: [];
    $els = [];

    foreach ($lines as $line) {
        $raw = rtrim((string)$line);
        $t = trim($raw);
        if ($t === '') {
            $els[] = ['type' => 'blank', 'text' => ''];
            continue;
        }

        // Headings (# .. ######)
        if (preg_match('/^(#{1,6})\\s+(.+)$/', $t, $m)) {
            $level = strlen((string)$m[1]);
            $text = (string)$m[2];
            $text = str_replace('`', '', $text);
            $els[] = ['type' => 'heading', 'text' => $text, 'level' => $level];
            continue;
        }

        // Bullets
        if (preg_match('/^[-*]\\s+(.+)$/', $t, $m)) {
            $text = (string)$m[1];
            $text = str_replace('`', '', $text);
            $els[] = ['type' => 'bullet', 'text' => $text];
            continue;
        }

        // Normal paragraph line
        $t = str_replace('`', '', $t);
        $els[] = ['type' => 'text', 'text' => $t];
    }

    // Collapse excessive blank lines
    $clean = [];
    $blankRun = 0;
    foreach ($els as $el) {
        if (($el['type'] ?? '') === 'blank') {
            $blankRun++;
            if ($blankRun <= 1) $clean[] = $el;
            continue;
        }
        $blankRun = 0;
        $clean[] = $el;
    }
    return $clean;
}

/**
 * Wrap a single line of text into multiple lines (simple char heuristic).
 *
 * @return array<int,string>
 */
function wrap_text(string $text, int $maxChars): array {
    $t = trim($text);
    if ($t === '') return [''];

    $words = preg_split('/\\s+/', $t) ?: [];
    $out = [];
    $cur = '';
    foreach ($words as $w) {
        if ($w === '') continue;
        $candidate = ($cur === '') ? $w : ($cur . ' ' . $w);
        if (mb_strlen($candidate) > $maxChars) {
            if ($cur !== '') $out[] = $cur;
            $cur = $w;
        } else {
            $cur = $candidate;
        }
    }
    if ($cur !== '') $out[] = $cur;
    return $out ?: [''];
}

// ----------------------------
// Load content
// ----------------------------
$md = file_get_contents($mdPath);
$elements = md_to_elements((string)$md);

// ----------------------------
// Prepare logo JPEG bytes (PDF DCTDecode)
// ----------------------------
if (!function_exists('imagecreatefrompng') || !function_exists('imagejpeg')) {
    fwrite(STDERR, "PHP GD extension required (imagecreatefrompng/imagejpeg).\n");
    exit(1);
}

$im = @imagecreatefrompng($logoPng);
if (!$im) {
    fwrite(STDERR, "Unable to read logo PNG.\n");
    exit(1);
}
imagesavealpha($im, false);
imagealphablending($im, true);

ob_start();
imagejpeg($im, null, 85);
$jpgBytes = (string)ob_get_clean();
$wPx = imagesx($im);
$hPx = imagesy($im);
imagedestroy($im);

// ----------------------------
// Build a minimal PDF (A4)
// ----------------------------
$objects = [];
$offsets = [];

// PDF header
$pdf = "%PDF-1.4\n";

// Font objects
$fontObjNum = 3; // Helvetica
$fontBoldObjNum = 4; // Helvetica-Bold
$objects[$fontObjNum] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
$objects[$fontBoldObjNum] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

// Image XObject
$imgObjNum = 5;
$imgDict = "<< /Type /XObject /Subtype /Image /Width {$wPx} /Height {$hPx} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpgBytes) . " >>";
$objects[$imgObjNum] = $imgDict . "\nstream\n" . $jpgBytes . "\nendstream";

// Page geometry
$pageW = 595.28;  // A4 width in points
$pageH = 841.89;  // A4 height in points
$margin = 54;     // ~19mm

// Build multi-page content streams
$logoTargetW = 160.0;
$logoTargetH = ($hPx > 0) ? ($logoTargetW * ($hPx / $wPx)) : 40.0;
$logoX = $margin;
$logoY = $pageH - $margin - $logoTargetH;

$streams = []; // array<string>
$pageIndex = 0;

// Cursor starts below logo on first page; otherwise at top margin
$cursorY = $logoY - 26;
$startYFirst = $cursorY;
$startYOther = $pageH - $margin - 10;

$content = [];
$content[] = "q";
$content[] = sprintf("%.2f 0 0 %.2f %.2f %.2f cm", $logoTargetW, $logoTargetH, $logoX, $logoY);
$content[] = "/Im1 Do";
$content[] = "Q";
$content[] = "BT";

// Render helpers
$setFont = function(array &$c, bool $bold, int $size) use ($fontObjNum, $fontBoldObjNum) {
    $f = $bold ? 'F2' : 'F1';
    $c[] = "/{$f} {$size} Tf";
};

$newPage = function() use (&$streams, &$content, &$pageIndex, &$cursorY, $startYOther) {
    $content[] = "ET";
    $streams[] = implode("\n", $content) . "\n";
    $pageIndex++;
    $content = ["BT"];
    $cursorY = $startYOther;
};

$spaceAfterHeading = 6.0;

foreach ($elements as $el) {
    $type = (string)($el['type'] ?? 'text');

    if ($type === 'blank') {
        $cursorY -= 10;
        if ($cursorY < $margin + 20) $newPage();
        continue;
    }

    if ($type === 'heading') {
        $lvl = (int)($el['level'] ?? 2);
        $text = (string)($el['text'] ?? '');

        $size = 14;
        $maxChars = 78;
        $gap = 16;
        if ($lvl <= 1) { $size = 18; $maxChars = 62; $gap = 22; }
        elseif ($lvl === 2) { $size = 14; $maxChars = 76; $gap = 18; }
        else { $size = 12; $maxChars = 84; $gap = 16; }

        $lines = wrap_text($text, $maxChars);
        $setFont($content, true, $size);
        foreach ($lines as $ln) {
            if ($cursorY < $margin + 30) $newPage();
            $content[] = sprintf("1 0 0 1 %.2f %.2f Tm", $margin, $cursorY);
            $content[] = "(" . pdf_escape($ln) . ") Tj";
            $cursorY -= $gap;
        }
        $cursorY -= $spaceAfterHeading;
        continue;
    }

    if ($type === 'bullet') {
        $text = (string)($el['text'] ?? '');
        $lines = wrap_text($text, 88);
        $setFont($content, false, 11);

        $first = true;
        foreach ($lines as $ln) {
            if ($cursorY < $margin + 20) $newPage();
            $x = $margin + ($first ? 0 : 12);
            $prefix = $first ? "• " : "";
            $content[] = sprintf("1 0 0 1 %.2f %.2f Tm", $x, $cursorY);
            $content[] = "(" . pdf_escape($prefix . $ln) . ") Tj";
            $cursorY -= 14;
            $first = false;
        }
        continue;
    }

    // Normal text
    $text = (string)($el['text'] ?? '');
    $lines = wrap_text($text, 92);
    $setFont($content, false, 11);
    foreach ($lines as $ln) {
        if ($cursorY < $margin + 20) $newPage();
        $content[] = sprintf("1 0 0 1 %.2f %.2f Tm", $margin, $cursorY);
        $content[] = "(" . pdf_escape($ln) . ") Tj";
        $cursorY -= 14;
    }
}

// Close last page stream
$content[] = "ET";
$streams[] = implode("\n", $content) . "\n";

$pageCount = count($streams);

// Object numbering:
// 1 Catalog, 2 Pages, 3 Font, 4 FontBold, 5 Image, then pages and content streams
$catalogObjNum = 1;
$pagesObjNum = 2;

$firstPageObjNum = 6;
$firstContentObjNum = $firstPageObjNum + $pageCount;

$kids = [];
for ($i = 0; $i < $pageCount; $i++) {
    $kids[] = ($firstPageObjNum + $i) . " 0 R";
}

// Pages object
$objects[$pagesObjNum] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count {$pageCount} >>";
// Catalog object
$objects[$catalogObjNum] = "<< /Type /Catalog /Pages {$pagesObjNum} 0 R >>";

// Content streams + page objects
for ($i = 0; $i < $pageCount; $i++) {
    $pageObjNum = $firstPageObjNum + $i;
    $contentObjNum = $firstContentObjNum + $i;

    $stream = $streams[$i];
    $objects[$contentObjNum] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";

    $objects[$pageObjNum] = "<< /Type /Page /Parent {$pagesObjNum} 0 R /MediaBox [0 0 {$pageW} {$pageH}] /Resources << /Font << /F1 {$fontObjNum} 0 R /F2 {$fontBoldObjNum} 0 R >> /XObject << /Im1 {$imgObjNum} 0 R >> >> /Contents {$contentObjNum} 0 R >>";
}

// Write objects in order
$maxObj = max(array_keys($objects));
for ($i = 1; $i <= $maxObj; $i++) {
    $offsets[$i] = strlen($pdf);
    $body = $objects[$i] ?? "";
    $pdf .= "{$i} 0 obj\n{$body}\nendobj\n";
}

// xref
$xrefPos = strlen($pdf);
$pdf .= "xref\n";
$pdf .= "0 " . ($maxObj + 1) . "\n";
$pdf .= "0000000000 65535 f \n";
for ($i = 1; $i <= $maxObj; $i++) {
    $off = $offsets[$i] ?? 0;
    $pdf .= str_pad((string)$off, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
}

// trailer
$pdf .= "trailer\n";
$pdf .= "<< /Size " . ($maxObj + 1) . " /Root {$catalogObjNum} 0 R >>\n";
$pdf .= "startxref\n{$xrefPos}\n%%EOF";

// Write output
if (file_put_contents($outPdf, $pdf) === false) {
    fwrite(STDERR, "Failed to write: {$outPdf}\n");
    exit(1);
}

fwrite(STDOUT, "OK: {$outPdf}\n");


