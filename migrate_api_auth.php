<?php
/**
 * Script di migrazione per aggiornare tutti gli endpoint API
 * per utilizzare il sistema centralizzato di autenticazione
 *
 * NOTA: Questo script fornisce un report delle API che necessitano aggiornamento
 * senza modificare automaticamente i file per sicurezza
 */

require_once __DIR__ . '/config.php';

// Set content type
header('Content-Type: text/html; charset=utf-8');

// Find all PHP files in api directory
$apiDir = __DIR__ . '/api';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($apiDir)
);

$phpFiles = [];
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $phpFiles[] = $file->getPathname();
    }
}

// Patterns to check for old authentication methods
$oldPatterns = [
    'session_status() === PHP_SESSION_NONE',
    'if (session_status()',
    'session_start()',
    '$_SESSION[\'user_role\']',
    '$_SESSION[\'csrf_token\']',
    'http_response_code(401)',
    'http_response_code(403)',
    'die(json_encode'
];

// Files that should use new authentication
$needsUpdate = [];
$alreadyUpdated = [];

foreach ($phpFiles as $file) {
    $content = file_get_contents($file);
    $relativePath = str_replace($apiDir, '', $file);

    // Check if already using new system
    if (strpos($content, 'require_once') !== false && strpos($content, 'api_auth.php') !== false) {
        $alreadyUpdated[] = $relativePath;
        continue;
    }

    // Check if it's an API endpoint that needs authentication
    $hasOldPatterns = false;
    foreach ($oldPatterns as $pattern) {
        if (strpos($content, $pattern) !== false) {
            $hasOldPatterns = true;
            break;
        }
    }

    if ($hasOldPatterns) {
        $needsUpdate[] = $relativePath;
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Authentication Migration Report - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        h2 {
            color: #495057;
            margin-top: 30px;
        }
        .summary {
            background: #e7f3ff;
            border: 1px solid #b6d4fe;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .file-list {
            list-style: none;
            padding: 0;
        }
        .file-list li {
            padding: 8px 12px;
            margin: 5px 0;
            background: #f8f9fa;
            border-left: 4px solid #dee2e6;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .needs-update {
            border-left-color: #ffc107;
            background: #fff3cd;
        }
        .updated {
            border-left-color: #28a745;
            background: #d4edda;
        }
        .code-example {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            overflow-x: auto;
        }
        .code-example pre {
            margin: 0;
            font-size: 13px;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
        .badge.warning {
            background: #ffc107;
            color: #000;
        }
        .badge.success {
            background: #28a745;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 API Authentication Migration Report</h1>

        <div class="summary">
            <h3>📈 Riepilogo</h3>
            <p><strong>Totale file PHP nella directory API:</strong> <?php echo count($phpFiles); ?></p>
            <p><strong>✅ Già aggiornati:</strong> <?php echo count($alreadyUpdated); ?> file</p>
            <p><strong>⚠️ Necessitano aggiornamento:</strong> <?php echo count($needsUpdate); ?> file</p>
        </div>

        <h2>✅ File già aggiornati <span class="badge success"><?php echo count($alreadyUpdated); ?></span></h2>
        <?php if (empty($alreadyUpdated)): ?>
            <p>Nessun file ancora aggiornato.</p>
        <?php else: ?>
            <ul class="file-list">
                <?php foreach ($alreadyUpdated as $file): ?>
                    <li class="updated"><?php echo htmlspecialchars($file); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h2>⚠️ File che necessitano aggiornamento <span class="badge warning"><?php echo count($needsUpdate); ?></span></h2>
        <?php if (empty($needsUpdate)): ?>
            <p>Tutti i file sono già aggiornati! 🎉</p>
        <?php else: ?>
            <ul class="file-list">
                <?php foreach ($needsUpdate as $file): ?>
                    <li class="needs-update"><?php echo htmlspecialchars($file); ?></li>
                <?php endforeach; ?>
            </ul>

            <h2>📝 Esempio di migrazione</h2>
            <p>Per aggiornare un file API al nuovo sistema di autenticazione centralizzato:</p>

            <div class="code-example">
                <h4>Prima (vecchio sistema):</h4>
                <pre><?php echo htmlspecialchars('<?php
// Suppress all PHP warnings/notices from being output
error_reporting(E_ALL);
ini_set(\'display_errors\', \'0\');
ob_start();

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header(\'Content-Type: application/json; charset=utf-8\');

// Authentication check
if (!isset($_SESSION[\'user_id\'])) {
    http_response_code(401);
    die(json_encode([\'error\' => \'Non autorizzato\']));
}

// CSRF validation
$csrfToken = $_SERVER[\'HTTP_X_CSRF_TOKEN\'] ?? \'\';
if ($csrfToken !== $_SESSION[\'csrf_token\']) {
    http_response_code(403);
    die(json_encode([\'error\' => \'Token CSRF non valido\']));
}
?>'); ?></pre>
            </div>

            <div class="code-example">
                <h4>Dopo (nuovo sistema centralizzato):</h4>
                <pre><?php echo htmlspecialchars('<?php
// Include centralized API authentication
require_once \'../../includes/api_auth.php\';

// Initialize API environment (session, headers, error handling)
initializeApiEnvironment();

// Include required files
require_once \'../../config.php\';
require_once \'../../includes/db.php\';

// Verify authentication
verifyApiAuthentication();

// Get current user info
$userInfo = getApiUserInfo();
$currentUserId = $userInfo[\'user_id\'];
$currentUserRole = $userInfo[\'role\'];
$tenant_id = $userInfo[\'tenant_id\'];

// Verify CSRF token (checks headers, GET, POST automatically)
verifyApiCsrfToken();

// Check role permissions if needed
if (!hasApiRole(\'manager\')) {
    apiError(\'Accesso negato - Ruolo insufficiente\', 403);
}
?>'); ?></pre>
            </div>

            <h2>🔧 Vantaggi del nuovo sistema</h2>
            <ul>
                <li>✅ <strong>Sessioni centralizzate:</strong> Usa <code>session_init.php</code> con configurazione consistente</li>
                <li>✅ <strong>CSRF flessibile:</strong> Accetta token da header, GET, POST, e body JSON</li>
                <li>✅ <strong>Retrocompatibilità:</strong> Supporta sia <code>$_SESSION['role']</code> che <code>$_SESSION['user_role']</code></li>
                <li>✅ <strong>Error handling migliorato:</strong> Funzioni <code>apiSuccess()</code> e <code>apiError()</code> per risposte consistenti</li>
                <li>✅ <strong>Controllo ruoli semplificato:</strong> <code>hasApiRole()</code> e <code>requireApiRole()</code> con gerarchia</li>
                <li>✅ <strong>Compatibilità multi-ambiente:</strong> Funziona su localhost e Cloudflare</li>
            </ul>
        <?php endif; ?>

        <h2>🚀 Prossimi passi</h2>
        <ol>
            <li>Aggiorna manualmente ogni file nella lista "Necessitano aggiornamento"</li>
            <li>Testa ogni endpoint usando <a href="test_api_auth.php">test_api_auth.php</a></li>
            <li>Verifica che CSRF e autenticazione funzionino correttamente</li>
            <li>Monitora i log per eventuali errori dopo il deploy</li>
        </ol>
    </div>
</body>
</html>