<?php
declare(strict_types=1);

/**
 * Compute cosine similarity between two vectors.
 */
function cnx_cosine_similarity(array $a, array $b): float {
    $len = min(count($a), count($b));
    if ($len <= 0) return 0.0;
    $dot = 0.0;
    $na = 0.0;
    $nb = 0.0;
    for ($i = 0; $i < $len; $i++) {
        $va = (float)$a[$i];
        $vb = (float)$b[$i];
        $dot += $va * $vb;
        $na += $va * $va;
        $nb += $vb * $vb;
    }
    if ($na <= 0.0 || $nb <= 0.0) return 0.0;
    return $dot / (sqrt($na) * sqrt($nb));
}
