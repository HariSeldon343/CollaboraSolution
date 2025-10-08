<?php
/**
 * Script di Verifica BASE_URL
 *
 * Verifica che la configurazione BASE_URL sia corretta per l'ambiente corrente
 * e che tutti i link generati dal sistema usino l'URL corretto.
 *
 * UTILIZZO:
 * - Sviluppo: http://localhost:8888/CollaboraNexio/verify_base_url.php
 * - Produzione: https://app.nexiosolution.it/CollaboraNexio/verify_base_url.php
 */

require_once 'config.php';

// Funzione helper per formattare output HTML
function formatStatus($isCorrect, $label, $value) {
    $color = $isCorrect ? '#28a745' : '#dc3545';
    $icon = $isCorrect ? '✓' : '✗';
    echo "<div style='padding: 10px; margin: 5px 0; background: " . ($isCorrect ? '#d4edda' : '#f8d7da') . "; border-left: 4px solid $color; border-radius: 4px;'>";
    echo "<strong>$icon $label:</strong> <code style='background: #fff; padding: 2px 6px; border-radius: 3px;'>$value</code>";
    echo "</div>";
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifica BASE_URL - CollaboraNexio</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f6fa;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .content {
            padding: 30px;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h2 {
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 10px;
            margin: 15px 0;
        }
        .info-label {
            font-weight: 600;
            color: #555;
        }
        .info-value {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 5px 10px;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
        }
        .test-links {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e0e0e0;
        }
        .test-links a {
            display: block;
            padding: 10px;
            margin: 5px 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #667eea;
            transition: all 0.2s;
        }
        .test-links a:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-production {
            background: #dc3545;
            color: white;
        }
        .badge-development {
            background: #28a745;
            color: white;
        }
        code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Verifica Configurazione BASE_URL</h1>
            <p>CollaboraNexio - Environment Detection System</p>
        </div>

        <div class="content">
            <!-- Sezione 1: Rilevamento Ambiente -->
            <div class="section">
                <h2>🌐 Rilevamento Ambiente</h2>
                <?php
                $currentHost = $_SERVER['HTTP_HOST'] ?? 'unknown';
                $isProduction = strpos($currentHost, 'nexiosolution.it') !== false;
                ?>

                <div class="info-grid">
                    <div class="info-label">HTTP_HOST:</div>
                    <div class="info-value"><?php echo htmlspecialchars($currentHost); ?></div>

                    <div class="info-label">Ambiente:</div>
                    <div class="info-value">
                        <?php if ($isProduction): ?>
                            <span class="badge badge-production">PRODUZIONE</span>
                        <?php else: ?>
                            <span class="badge badge-development">SVILUPPO</span>
                        <?php endif; ?>
                    </div>

                    <div class="info-label">PRODUCTION_MODE:</div>
                    <div class="info-value"><?php echo PRODUCTION_MODE ? 'TRUE' : 'FALSE'; ?></div>

                    <div class="info-label">DEBUG_MODE:</div>
                    <div class="info-value"><?php echo DEBUG_MODE ? 'TRUE' : 'FALSE'; ?></div>

                    <div class="info-label">ENVIRONMENT:</div>
                    <div class="info-value"><?php echo ENVIRONMENT; ?></div>
                </div>

                <?php
                // Verifica corrispondenza
                $environmentCorrect = ($isProduction && PRODUCTION_MODE) || (!$isProduction && !PRODUCTION_MODE);
                formatStatus($environmentCorrect, 'Auto-detect Ambiente', $environmentCorrect ? 'CORRETTO' : 'ERRORE');
                ?>
            </div>

            <!-- Sezione 2: BASE_URL Configuration -->
            <div class="section">
                <h2>🔗 Configurazione BASE_URL</h2>

                <div class="info-grid">
                    <div class="info-label">BASE_URL Attuale:</div>
                    <div class="info-value"><?php echo BASE_URL; ?></div>

                    <div class="info-label">URL Atteso (prod):</div>
                    <div class="info-value">https://app.nexiosolution.it/CollaboraNexio</div>

                    <div class="info-label">URL Atteso (dev):</div>
                    <div class="info-value">http://localhost:8888/CollaboraNexio</div>
                </div>

                <?php
                if ($isProduction) {
                    $expectedUrl = 'https://app.nexiosolution.it/CollaboraNexio';
                    $baseUrlCorrect = (BASE_URL === $expectedUrl);
                    formatStatus($baseUrlCorrect, 'BASE_URL Produzione', $baseUrlCorrect ? 'CORRETTO' : 'ERRORE - Atteso: ' . $expectedUrl);
                } else {
                    $expectedUrl = 'http://localhost:8888/CollaboraNexio';
                    $baseUrlCorrect = (BASE_URL === $expectedUrl);
                    formatStatus($baseUrlCorrect, 'BASE_URL Sviluppo', $baseUrlCorrect ? 'CORRETTO' : 'WARNING - Atteso: ' . $expectedUrl);
                }
                ?>
            </div>

            <!-- Sezione 3: Test Link Generati -->
            <div class="section">
                <h2>📧 Test Link Email</h2>
                <p style="color: #666; margin-bottom: 15px;">
                    Verifica che i link generati per le email usino il BASE_URL corretto:
                </p>

                <?php
                // Simula generazione link
                $testToken = 'test_token_12345';
                $resetLink = BASE_URL . '/set_password.php?token=' . urlencode($testToken);
                $loginLink = BASE_URL . '/index.php';
                $dashboardLink = BASE_URL . '/dashboard.php';
                ?>

                <div class="test-links">
                    <strong>Link Reset Password:</strong>
                    <a href="<?php echo $resetLink; ?>" target="_blank"><?php echo $resetLink; ?></a>

                    <strong style="display: block; margin-top: 15px;">Link Login:</strong>
                    <a href="<?php echo $loginLink; ?>" target="_blank"><?php echo $loginLink; ?></a>

                    <strong style="display: block; margin-top: 15px;">Link Dashboard:</strong>
                    <a href="<?php echo $dashboardLink; ?>" target="_blank"><?php echo $dashboardLink; ?></a>
                </div>
            </div>

            <!-- Sezione 4: Sessione & Sicurezza -->
            <div class="section">
                <h2>🔐 Configurazione Sessione</h2>

                <div class="info-grid">
                    <div class="info-label">SESSION_NAME:</div>
                    <div class="info-value"><?php echo SESSION_NAME; ?></div>

                    <div class="info-label">SESSION_SECURE:</div>
                    <div class="info-value"><?php echo SESSION_SECURE ? 'TRUE (HTTPS)' : 'FALSE (HTTP)'; ?></div>

                    <div class="info-label">SESSION_DOMAIN:</div>
                    <div class="info-value"><?php echo SESSION_DOMAIN ?: '(empty - localhost)'; ?></div>

                    <div class="info-label">SESSION_HTTPONLY:</div>
                    <div class="info-value"><?php echo SESSION_HTTPONLY ? 'TRUE' : 'FALSE'; ?></div>

                    <div class="info-label">SESSION_SAMESITE:</div>
                    <div class="info-value"><?php echo SESSION_SAMESITE; ?></div>
                </div>

                <?php
                if ($isProduction) {
                    $sessionCorrect = SESSION_SECURE && SESSION_DOMAIN === '.nexiosolution.it';
                    formatStatus($sessionCorrect, 'Configurazione Sessione Produzione', $sessionCorrect ? 'CORRETTA' : 'ERRORE');
                } else {
                    $sessionCorrect = !SESSION_SECURE && SESSION_DOMAIN === '';
                    formatStatus($sessionCorrect, 'Configurazione Sessione Sviluppo', $sessionCorrect ? 'CORRETTA' : 'WARNING');
                }
                ?>
            </div>

            <!-- Sezione 5: Riepilogo e Raccomandazioni -->
            <div class="section">
                <h2>📋 Riepilogo</h2>

                <?php
                $allCorrect = $environmentCorrect && $baseUrlCorrect && $sessionCorrect;

                if ($allCorrect):
                ?>
                    <div class="success">
                        <strong>✅ Tutto Corretto!</strong><br>
                        La configurazione BASE_URL è corretta per l'ambiente <?php echo $isProduction ? 'produzione' : 'sviluppo'; ?>.
                        Tutti i link generati useranno l'URL appropriato.
                    </div>
                <?php else: ?>
                    <div class="warning">
                        <strong>⚠️ Attenzione!</strong><br>
                        Alcuni parametri di configurazione potrebbero non essere corretti.
                        Verifica i dettagli sopra e controlla <code>config.php</code>.
                    </div>
                <?php endif; ?>

                <h3 style="margin-top: 20px; margin-bottom: 10px;">📝 Note Operative:</h3>
                <ul style="padding-left: 20px; color: #555;">
                    <li style="margin: 8px 0;">
                        L'auto-detect si basa su <code>$_SERVER['HTTP_HOST']</code>
                    </li>
                    <li style="margin: 8px 0;">
                        Se contiene <code>nexiosolution.it</code> → ambiente PRODUZIONE
                    </li>
                    <li style="margin: 8px 0;">
                        Altrimenti → ambiente SVILUPPO
                    </li>
                    <li style="margin: 8px 0;">
                        Le email usano sempre <code>BASE_URL</code> con fallback a localhost
                    </li>
                    <li style="margin: 8px 0;">
                        La sessione è condivisa tra dev e prod tramite <code>SESSION_NAME</code>
                    </li>
                </ul>
            </div>

            <!-- Sezione 6: Test Funzionali -->
            <div class="section">
                <h2>🧪 Test Funzionali</h2>
                <p style="color: #666; margin-bottom: 15px;">
                    Per testare completamente la configurazione:
                </p>

                <ol style="padding-left: 20px; color: #555;">
                    <li style="margin: 10px 0;">
                        <strong>Crea un nuovo utente</strong> da <code>/utenti.php</code>
                    </li>
                    <li style="margin: 10px 0;">
                        <strong>Verifica l'email ricevuta</strong> - il link deve usare BASE_URL corretto
                    </li>
                    <li style="margin: 10px 0;">
                        <strong>Clicca sul link</strong> - deve aprirsi su <?php echo $isProduction ? 'produzione' : 'localhost'; ?>
                    </li>
                    <li style="margin: 10px 0;">
                        <strong>Test reset password</strong> - verifica link in email
                    </li>
                </ol>

                <?php if (!$isProduction): ?>
                <div class="warning" style="margin-top: 20px;">
                    <strong>⚠️ Ambiente Sviluppo Rilevato</strong><br>
                    Stai testando in locale. Per testare in produzione, accedi a:
                    <br><code>https://app.nexiosolution.it/CollaboraNexio/verify_base_url.php</code>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="text-align: center; padding: 20px; color: #999; font-size: 12px;">
        CollaboraNexio v1.0 - Environment Detection System
    </div>
</body>
</html>
