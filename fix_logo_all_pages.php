<?php
/**
 * Script per correggere il logo in tutte le pagine PHP
 * Sostituisce il vecchio <span class="logo-icon">N</span> con il logo SVG corretto
 */

$files_to_update = [
    'tasks.php',
    'calendar.php',
    'utenti.php',
    'files.php',
    'dashboard.php',
    'audit_log.php',
    'ai.php',
    'profilo.php',
    'conformita.php',
    'ticket.php',
    'aziende.php',
    'chat.php'
];

$old_pattern = '<span class="logo-icon">N</span>';
$new_pattern = '<img src="/CollaboraNexio/assets/images/logo.png" alt="CollaboraNexio" class="logo-img">';

echo "=== FIX LOGO IN TUTTE LE PAGINE ===\n\n";

$updated_count = 0;
$error_count = 0;

foreach ($files_to_update as $file) {
    $filepath = __DIR__ . '/' . $file;

    echo "Processing: $file ... ";

    if (!file_exists($filepath)) {
        echo "SKIP (file non trovato)\n";
        continue;
    }

    // Leggi il contenuto del file
    $content = file_get_contents($filepath);

    if ($content === false) {
        echo "ERRORE (impossibile leggere)\n";
        $error_count++;
        continue;
    }

    // Controlla se contiene il pattern da sostituire
    if (strpos($content, $old_pattern) === false) {
        echo "SKIP (pattern non trovato)\n";
        continue;
    }

    // Sostituisci il pattern
    $new_content = str_replace($old_pattern, $new_pattern, $content);

    // Salva il file
    if (file_put_contents($filepath, $new_content) !== false) {
        echo "✓ AGGIORNATO\n";
        $updated_count++;
    } else {
        echo "✗ ERRORE nel salvataggio\n";
        $error_count++;
    }
}

echo "\n=== RISULTATO ===\n";
echo "File aggiornati: $updated_count\n";
echo "Errori: $error_count\n";
echo "File saltati: " . (count($files_to_update) - $updated_count - $error_count) . "\n";

echo "\nNOTE: Assicurati che il file logo.png esista in /assets/images/\n";

// Verifica esistenza logo
$logo_path = __DIR__ . '/assets/images/logo.png';
if (file_exists($logo_path)) {
    echo "✓ Logo SVG trovato: $logo_path\n";
    echo "  Dimensione: " . filesize($logo_path) . " bytes\n";
} else {
    echo "✗ ATTENZIONE: Logo SVG non trovato in $logo_path\n";
}
?>