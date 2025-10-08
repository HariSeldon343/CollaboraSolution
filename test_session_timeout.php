<?php
/**
 * Test Session Timeout Configuration
 * Verifica le impostazioni del timeout della sessione
 */

// Initialize session with proper configuration
require_once __DIR__ . '/includes/session_init.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Session Timeout - CollaboraNexio</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
        }

        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .subtitle {
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .info-grid {
            display: grid;
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-card {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 20px;
            border-radius: 8px;
        }

        .info-label {
            font-weight: 600;
            color: #495057;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .info-value {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
        }

        .info-description {
            margin-top: 8px;
            font-size: 13px;
            color: #6c757d;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 8px;
        }

        .status-success {
            background: #d4edda;
            color: #155724;
        }

        .status-warning {
            background: #fff3cd;
            color: #856404;
        }

        .session-info {
            background: #e7f3ff;
            border-left: 4px solid #0066cc;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .session-info h3 {
            color: #0066cc;
            margin-bottom: 15px;
            font-size: 16px;
        }

        .session-detail {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #cce5ff;
        }

        .session-detail:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: #495057;
            font-weight: 500;
        }

        .detail-value {
            color: #212529;
            font-family: 'Courier New', monospace;
        }

        .countdown {
            text-align: center;
            padding: 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            margin: 30px 0;
        }

        .countdown-label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 10px;
        }

        .countdown-value {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .countdown-description {
            font-size: 13px;
            opacity: 0.8;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        .btn {
            flex: 1;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            text-decoration: none;
            display: block;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-info {
            background: #d1ecf1;
            border-left: 4px solid #17a2b8;
            color: #0c5460;
        }

        .alert-warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
        }

        .alert strong {
            display: block;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Test Session Timeout</h1>
        <p class="subtitle">Verifica configurazione timeout sessione - CollaboraNexio</p>

        <?php if (isset($_SESSION['user_id'])): ?>
            <div class="alert alert-info">
                <strong>Utente autenticato</strong>
                Sei attualmente loggato. La sessione scadra automaticamente dopo 10 minuti di inattivita.
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <strong>Non autenticato</strong>
                Non sei attualmente loggato. Accedi per testare il timeout della sessione.
            </div>
        <?php endif; ?>

        <div class="info-grid">
            <div class="info-card">
                <div class="info-label">Timeout Inattivita</div>
                <div class="info-value">10 minuti</div>
                <div class="info-description">
                    La sessione scadra dopo 10 minuti di inattivita
                </div>
                <span class="status-badge status-success">Configurato</span>
            </div>

            <div class="info-card">
                <div class="info-label">Cookie Lifetime</div>
                <div class="info-value"><?php echo ini_get('session.cookie_lifetime'); ?></div>
                <div class="info-description">
                    <?php if (ini_get('session.cookie_lifetime') == '0'): ?>
                        Scade alla chiusura del browser (valore corretto)
                    <?php else: ?>
                        Valore non standard: <?php echo ini_get('session.cookie_lifetime'); ?> secondi
                    <?php endif; ?>
                </div>
                <span class="status-badge <?php echo (ini_get('session.cookie_lifetime') == '0') ? 'status-success' : 'status-warning'; ?>">
                    <?php echo (ini_get('session.cookie_lifetime') == '0') ? 'OK' : 'Attenzione'; ?>
                </span>
            </div>

            <div class="info-card">
                <div class="info-label">GC Max Lifetime</div>
                <div class="info-value"><?php echo ini_get('session.gc_maxlifetime'); ?>s</div>
                <div class="info-description">
                    Durata massima dei dati di sessione sul server
                </div>
                <span class="status-badge <?php echo (ini_get('session.gc_maxlifetime') == '600') ? 'status-success' : 'status-warning'; ?>">
                    <?php echo (ini_get('session.gc_maxlifetime') == '600') ? 'OK (10 min)' : 'Valore: ' . ini_get('session.gc_maxlifetime') . 's'; ?>
                </span>
            </div>
        </div>

        <?php if (isset($_SESSION['user_id'])): ?>
            <div class="session-info">
                <h3>Informazioni Sessione Attiva</h3>

                <div class="session-detail">
                    <span class="detail-label">Session ID:</span>
                    <span class="detail-value"><?php echo substr(session_id(), 0, 20); ?>...</span>
                </div>

                <div class="session-detail">
                    <span class="detail-label">User ID:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($_SESSION['user_id'] ?? 'N/A'); ?></span>
                </div>

                <div class="session-detail">
                    <span class="detail-label">User Email:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($_SESSION['user_email'] ?? 'N/A'); ?></span>
                </div>

                <div class="session-detail">
                    <span class="detail-label">Role:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($_SESSION['role'] ?? 'N/A'); ?></span>
                </div>

                <div class="session-detail">
                    <span class="detail-label">Last Activity:</span>
                    <span class="detail-value">
                        <?php
                        if (isset($_SESSION['last_activity'])) {
                            echo date('Y-m-d H:i:s', $_SESSION['last_activity']);
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </span>
                </div>

                <div class="session-detail">
                    <span class="detail-label">Tempo Trascorso:</span>
                    <span class="detail-value">
                        <?php
                        if (isset($_SESSION['last_activity'])) {
                            $elapsed = time() - $_SESSION['last_activity'];
                            echo $elapsed . ' secondi';
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </span>
                </div>
            </div>

            <div class="countdown">
                <div class="countdown-label">Tempo rimanente prima del timeout</div>
                <div class="countdown-value" id="timeoutCountdown">
                    <?php
                    if (isset($_SESSION['last_activity'])) {
                        $elapsed = time() - $_SESSION['last_activity'];
                        $remaining = 600 - $elapsed;
                        echo max(0, $remaining) . 's';
                    } else {
                        echo '600s';
                    }
                    ?>
                </div>
                <div class="countdown-description">
                    La sessione scadra automaticamente e verrai reindirizzato a index.php
                </div>
            </div>

            <script>
                // Countdown timer
                let remaining = <?php echo isset($_SESSION['last_activity']) ? max(0, 600 - (time() - $_SESSION['last_activity'])) : 600; ?>;

                function updateCountdown() {
                    if (remaining > 0) {
                        remaining--;
                        const minutes = Math.floor(remaining / 60);
                        const seconds = remaining % 60;
                        document.getElementById('timeoutCountdown').textContent =
                            minutes + 'm ' + seconds + 's';
                    } else {
                        document.getElementById('timeoutCountdown').textContent = 'SCADUTO';
                        document.getElementById('timeoutCountdown').style.color = '#ff4444';
                        setTimeout(() => {
                            window.location.href = 'index.php?timeout=1';
                        }, 2000);
                    }
                }

                setInterval(updateCountdown, 1000);
            </script>
        <?php else: ?>
            <div class="alert alert-info">
                <strong>Come testare il timeout:</strong>
                <ol style="margin-top: 10px; padding-left: 20px;">
                    <li>Accedi all'applicazione da <a href="index.php" style="color: #0066cc;">index.php</a></li>
                    <li>Torna su questa pagina</li>
                    <li>Attendi 10 minuti senza fare nulla</li>
                    <li>Verrai automaticamente reindirizzato alla pagina di login con un messaggio di timeout</li>
                </ol>
            </div>
        <?php endif; ?>

        <div class="action-buttons">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="dashboard.php" class="btn btn-primary">Vai alla Dashboard</a>
                <a href="logout.php" class="btn btn-secondary">Logout</a>
            <?php else: ?>
                <a href="index.php" class="btn btn-primary">Vai al Login</a>
            <?php endif; ?>
        </div>

        <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; font-size: 13px; color: #6c757d;">
            <strong style="display: block; margin-bottom: 10px; color: #495057;">Configurazione PHP Session:</strong>
            <table style="width: 100%; font-family: 'Courier New', monospace; font-size: 12px;">
                <tr>
                    <td style="padding: 5px;">session.cookie_lifetime:</td>
                    <td style="padding: 5px;"><strong><?php echo ini_get('session.cookie_lifetime'); ?></strong></td>
                </tr>
                <tr>
                    <td style="padding: 5px;">session.gc_maxlifetime:</td>
                    <td style="padding: 5px;"><strong><?php echo ini_get('session.gc_maxlifetime'); ?></strong></td>
                </tr>
                <tr>
                    <td style="padding: 5px;">session.cookie_httponly:</td>
                    <td style="padding: 5px;"><strong><?php echo ini_get('session.cookie_httponly'); ?></strong></td>
                </tr>
                <tr>
                    <td style="padding: 5px;">session.cookie_secure:</td>
                    <td style="padding: 5px;"><strong><?php echo ini_get('session.cookie_secure'); ?></strong></td>
                </tr>
                <tr>
                    <td style="padding: 5px;">session.cookie_samesite:</td>
                    <td style="padding: 5px;"><strong><?php echo ini_get('session.cookie_samesite'); ?></strong></td>
                </tr>
                <tr>
                    <td style="padding: 5px;">session.name:</td>
                    <td style="padding: 5px;"><strong><?php echo session_name(); ?></strong></td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
