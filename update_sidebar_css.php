<?php
/**
 * Script to update all PHP files with sidebar to include the new responsive CSS
 */

$files_to_update = [
    'ai.php',
    'audit_log.php',
    'aziende.php',
    'calendar.php',
    'chat.php',
    'conformita.php',
    'configurazioni.php',
    'files.php',
    'profilo.php',
    'tasks.php',
    'ticket.php',
    'utenti.php'
];

$css_line_to_add = '    <!-- Sidebar Responsive Optimization CSS -->' . PHP_EOL .
                   '    <link rel="stylesheet" href="assets/css/sidebar-responsive.css">';

$updated_count = 0;
$already_updated = 0;
$error_count = 0;

foreach ($files_to_update as $file) {
    $file_path = __DIR__ . '/' . $file;

    if (!file_exists($file_path)) {
        echo "❌ File not found: $file\n";
        $error_count++;
        continue;
    }

    $content = file_get_contents($file_path);

    // Check if already has the responsive CSS
    if (strpos($content, 'sidebar-responsive.css') !== false) {
        echo "✓ Already updated: $file\n";
        $already_updated++;
        continue;
    }

    // Find where to insert - after styles.css
    $pattern = '/(<link\s+rel="stylesheet"\s+href="assets\/css\/styles\.css"[^>]*>)/i';

    if (preg_match($pattern, $content)) {
        $new_content = preg_replace(
            $pattern,
            '$1' . PHP_EOL . $css_line_to_add,
            $content,
            1
        );

        if (file_put_contents($file_path, $new_content)) {
            echo "✅ Updated: $file\n";
            $updated_count++;
        } else {
            echo "❌ Failed to write: $file\n";
            $error_count++;
        }
    } else {
        echo "⚠️  Could not find styles.css link in: $file\n";
        $error_count++;
    }
}

echo "\n";
echo "========================================\n";
echo "Update Summary:\n";
echo "========================================\n";
echo "✅ Updated: $updated_count files\n";
echo "✓  Already updated: $already_updated files\n";
echo "❌ Errors: $error_count files\n";
echo "========================================\n";
echo "Total processed: " . count($files_to_update) . " files\n";
?>