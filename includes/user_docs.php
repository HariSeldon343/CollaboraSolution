<?php
/**
 * User documentation PDFs helper.
 *
 * Provides a safe allowlist of PDFs that can be attached to emails:
 * - All *.pdf files under /docs (non-recursive)
 *
 * Rationale: user requested "onboarding PDF + other PDFs".
 * We keep it extensible by allowing admins to drop additional PDFs in /docs.
 */

declare(strict_types=1);

/**
 * Return a list of attachable PDF files (absolute paths) found in /docs.
 *
 * @return array<int, array{path:string,name:string,size:int}>
 */
function cnx_list_user_doc_pdfs(): array
{
    $root = dirname(__DIR__);
    $docsDir = realpath($root . DIRECTORY_SEPARATOR . 'docs');
    if (!$docsDir || !is_dir($docsDir)) {
        return [];
    }

    $files = glob($docsDir . DIRECTORY_SEPARATOR . '*.pdf') ?: [];
    $out = [];

    foreach ($files as $f) {
        $real = realpath($f);
        if (!$real || !is_file($real)) continue;
        if (stripos($real, $docsDir . DIRECTORY_SEPARATOR) !== 0) continue;

        $size = (int)@filesize($real);
        if ($size <= 0) continue;

        // Safety: avoid huge attachments (default 8MB)
        if ($size > 8 * 1024 * 1024) continue;

        $out[] = [
            'path' => $real,
            'name' => basename($real),
            'size' => $size
        ];
    }

    // Stable order
    usort($out, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));

    return $out;
}

/**
 * Filter attachable PDFs by an allowlisted set of basenames.
 *
 * @param array<int,string> $names
 * @return array<int, array{path:string,name:string,size:int}>
 */
function cnx_filter_user_doc_pdfs_by_name(array $names): array
{
    $wanted = array_values(array_unique(array_map('strval', $names)));
    $wanted = array_filter($wanted, fn($n) => $n !== '' && strpos($n, '..') === false && strpos($n, '/') === false && strpos($n, '\\') === false);
    if (empty($wanted)) {
        return [];
    }

    $all = cnx_list_user_doc_pdfs();
    $map = [];
    foreach ($all as $f) {
        $map[(string)$f['name']] = $f;
    }

    $out = [];
    foreach ($wanted as $n) {
        if (isset($map[$n])) $out[] = $map[$n];
    }
    return $out;
}


