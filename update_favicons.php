<?php
/**
 * Script to update all main PHP pages with favicon include
 */

// List of main PHP files to update
$files = [
    'files.php',
    'utenti.php',
    'aziende.php',
    'calendar.php',
    'tasks.php',
    'progetti.php',
    'chat.php',
    'configurazioni.php',
    'conformita.php',
    'profilo.php',
    'ticket.php',
    'audit_log.php',
    'ai.php',
    'document_approvals.php',
    'system_check.php',
    'forgot_password.php',
    'set_password.php'
];

$updated = 0;
$skipped = 0;
$errors = [];

foreach ($files as $file) {
    $filepath = __DIR__ . '/' . $file;

    if (!file_exists($filepath)) {
        $errors[] = "File not found: $file";
        continue;
    }

    $content = file_get_contents($filepath);

    // Check if favicon is already included
    if (strpos($content, 'favicon.php') !== false || strpos($content, 'favicon.svg') !== false) {
        echo "⏩ Skipped $file (already has favicon)\n";
        $skipped++;
        continue;
    }

    // Look for <title> tag to insert favicon after it
    $pattern = '/(<title>.*?<\/title>)/i';

    if (preg_match($pattern, $content, $matches)) {
        $replacement = $matches[1] . "\n\n    <?php require_once __DIR__ . '/includes/favicon.php'; ?>";
        $newContent = preg_replace($pattern, $replacement, $content, 1);

        if ($newContent !== $content) {
            file_put_contents($filepath, $newContent);
            echo "✅ Updated $file\n";
            $updated++;
        } else {
            $errors[] = "Failed to update: $file";
        }
    } else {
        // Try alternative pattern for pages with different structure
        $pattern = '/(<head[^>]*>)/i';

        if (preg_match($pattern, $content, $matches)) {
            $replacement = $matches[1] . "\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <title>" . ucfirst(str_replace('.php', '', $file)) . " - CollaboraNexio</title>\n\n    <?php require_once __DIR__ . '/includes/favicon.php'; ?>";
            $newContent = preg_replace($pattern, $replacement, $content, 1);

            if ($newContent !== $content) {
                file_put_contents($filepath, $newContent);
                echo "✅ Updated $file (added title and favicon)\n";
                $updated++;
            } else {
                $errors[] = "Failed to update: $file";
            }
        } else {
            $errors[] = "Could not find insertion point in: $file";
        }
    }
}

echo "\n=====================================\n";
echo "Update Summary:\n";
echo "✅ Updated: $updated files\n";
echo "⏩ Skipped: $skipped files\n";
echo "❌ Errors: " . count($errors) . " files\n";

if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
}
?>