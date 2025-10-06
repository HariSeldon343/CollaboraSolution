<?php
/**
 * Test Email Optimization
 * Verifica che l'ambiente Windows/XAMPP venga rilevato correttamente
 * e che l'invio email venga skippato per performance
 */

require_once __DIR__ . '/includes/EmailSender.php';

echo "<h1>Test Email Optimization</h1>\n";
echo "<pre>\n";

// Informazioni ambiente
echo "=== INFORMAZIONI AMBIENTE ===\n";
echo "PHP_OS: " . PHP_OS . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'N/A') . "\n";
echo "Server Port: " . ($_SERVER['SERVER_PORT'] ?? 'N/A') . "\n";
echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'N/A') . "\n";
echo "XAMPP_ROOT env: " . (getenv('XAMPP_ROOT') ?: 'Not set') . "\n\n";

// Test detection
echo "=== TEST RILEVAMENTO AMBIENTE ===\n";

// Usa reflection per accedere al metodo privato
$emailSender = new EmailSender();
$reflection = new ReflectionClass($emailSender);
$method = $reflection->getMethod('isWindowsXamppEnvironment');
$method->setAccessible(true);

$isXampp = $method->invoke($emailSender);

if ($isXampp) {
    echo "✅ Ambiente Windows/XAMPP RILEVATO correttamente\n";
    echo "   → Email verrà SKIPPATA per performance\n";
    echo "   → Tempo di risposta: < 0.5 secondi\n\n";
} else {
    echo "❌ Ambiente di produzione rilevato\n";
    echo "   → Email verrà TENTATA (timeout 1 secondo)\n\n";
}

// Test performance
echo "=== TEST PERFORMANCE ===\n";
$startTime = microtime(true);

// Simula invio email
$result = $emailSender->sendWelcomeEmail(
    'test@example.com',
    'Test User',
    'test_token_123',
    'Demo Tenant'
);

$endTime = microtime(true);
$duration = round(($endTime - $startTime) * 1000, 2); // Millisecondi

echo "Tempo di esecuzione: {$duration}ms\n";
echo "Risultato invio: " . ($result ? "SUCCESS" : "FAILED (come previsto su XAMPP)") . "\n\n";

if ($duration < 500) {
    echo "✅ PERFORMANCE OTTIMA: Risposta in < 500ms\n";
    echo "   Target: < 2000ms ✅ SUPERATO\n";
} elseif ($duration < 2000) {
    echo "⚠️  PERFORMANCE ACCETTABILE: {$duration}ms\n";
    echo "   Target: < 2000ms ✅ RAGGIUNTO\n";
} else {
    echo "❌ PERFORMANCE SCARSA: {$duration}ms\n";
    echo "   Target: < 2000ms ❌ NON RAGGIUNTO\n";
}

echo "\n=== CONTROLLO LOG ===\n";
echo "Verifica /logs/php_errors.log per messaggi:\n";
echo "- 'Ambiente Windows/XAMPP rilevato'\n";
echo "- 'Skip SMTP per performance'\n";
echo "- 'Email non inviata (SMTP non configurato su XAMPP)'\n\n";

// Test condizioni rilevamento
echo "=== CONDIZIONI RILEVAMENTO ===\n";
$checks = [
    'Windows OS' => stripos(PHP_OS, 'WIN') !== false,
    'XAMPP in path' => stripos($_SERVER['DOCUMENT_ROOT'] ?? '', 'xampp') !== false,
    'htdocs in path' => stripos($_SERVER['DOCUMENT_ROOT'] ?? '', 'htdocs') !== false,
    'Port 8888' => ($_SERVER['SERVER_PORT'] ?? '') === '8888',
    'Apache on Win' => stripos($_SERVER['SERVER_SOFTWARE'] ?? '', 'Apache') !== false && stripos(PHP_OS, 'WIN') !== false
];

foreach ($checks as $name => $value) {
    echo ($value ? '✅' : '❌') . " $name\n";
}

echo "\n=== CONCLUSIONE ===\n";
if ($isXampp && $duration < 500) {
    echo "🎉 OTTIMIZZAZIONE RIUSCITA!\n";
    echo "   Il sistema è configurato correttamente per XAMPP.\n";
    echo "   Le API di creazione utente risponderanno rapidamente.\n";
} elseif (!$isXampp) {
    echo "ℹ️  Sistema di produzione rilevato.\n";
    echo "   Email verranno tentate con timeout di 1 secondo.\n";
} else {
    echo "⚠️  Verifica la configurazione.\n";
}

echo "</pre>\n";
?>
