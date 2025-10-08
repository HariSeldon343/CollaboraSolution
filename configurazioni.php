<?php
// Initialize session with proper configuration
require_once __DIR__ . '/includes/session_init.php';
// Authentication check - redirect to login if not authenticated
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/company_filter.php';
$auth = new Auth();

if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}

// Get current user data
$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    header('Location: index.php');
    exit;
}

// Only super_admin can access this page
if ($currentUser['role'] !== 'super_admin') {
    header('Location: dashboard.php');
    exit;
}

// Initialize company filter
$companyFilter = new CompanyFilter($currentUser);

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();

// Load email configuration from database
require_once __DIR__ . '/includes/email_config.php';
$emailConfig = getEmailConfigFromDatabase();

// Prepare email config for JavaScript
$emailConfigJson = json_encode([
    'smtp_host' => $emailConfig['smtpHost'] ?? 'mail.infomaniak.com',
    'smtp_port' => $emailConfig['smtpPort'] ?? 465,
    'smtp_username' => $emailConfig['smtpUsername'] ?? 'info@fortibyte.it',
    'from_email' => $emailConfig['fromEmail'] ?? 'info@fortibyte.it',
    'from_name' => $emailConfig['fromName'] ?? 'CollaboraNexio',
    'reply_to' => $emailConfig['replyTo'] ?? 'info@fortibyte.it'
]);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Configurazioni - CollaboraNexio (v<?php echo time(); ?>)</title>

    <!-- Main CSS -->
    <link rel="stylesheet" href="assets/css/styles.css">
    <!-- Sidebar Responsive Optimization CSS -->
    <link rel="stylesheet" href="assets/css/sidebar-responsive.css">

    <style>
        .config-container {
            padding: var(--space-6);
            max-width: 1200px;
        }

        .config-header {
            margin-bottom: var(--space-8);
        }

        .config-tabs {
            display: flex;
            gap: var(--space-2);
            border-bottom: 2px solid var(--color-gray-200);
            margin-bottom: var(--space-8);
        }

        .tab-btn {
            padding: var(--space-3) var(--space-6);
            background: transparent;
            border: none;
            color: var(--color-gray-600);
            font-weight: var(--font-medium);
            cursor: pointer;
            position: relative;
            transition: color var(--transition-fast);
        }

        .tab-btn:hover {
            color: var(--color-gray-900);
        }

        .tab-btn.active {
            color: var(--color-primary);
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--color-primary);
        }

        .config-section {
            background: var(--color-white);
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--space-6);
        }

        .section-title {
            font-size: var(--text-xl);
            font-weight: var(--font-semibold);
            color: var(--color-gray-900);
            margin-bottom: var(--space-4);
        }

        .section-description {
            color: var(--color-gray-600);
            font-size: var(--text-sm);
            margin-bottom: var(--space-6);
        }

        .config-form {
            display: flex;
            flex-direction: column;
            gap: var(--space-6);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-6);
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: var(--space-2);
        }

        .form-label {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-700);
        }

        .form-label.required::after {
            content: ' *';
            color: var(--color-error);
        }

        .form-help {
            font-size: var(--text-xs);
            color: var(--color-gray-500);
            margin-top: var(--space-1);
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 24px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--color-gray-300);
            transition: background var(--transition-fast);
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background: white;
            transition: transform var(--transition-fast);
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background: var(--color-primary);
        }

        input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }

        .toggle-group {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: var(--space-4);
            background: var(--color-gray-50);
            border-radius: var(--radius-md);
        }

        .toggle-info {
            flex: 1;
        }

        .toggle-label {
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
            margin-bottom: var(--space-1);
        }

        .toggle-description {
            font-size: var(--text-sm);
            color: var(--color-gray-600);
        }

        .config-actions {
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
            padding-top: var(--space-6);
            border-top: 1px solid var(--color-gray-200);
        }

        .alert-box {
            padding: var(--space-4);
            border-radius: var(--radius-md);
            margin-bottom: var(--space-4);
            display: flex;
            align-items: start;
            gap: var(--space-3);
        }

        .alert-box.warning {
            background: var(--color-warning-50);
            border: 1px solid var(--color-warning-200);
            color: var(--color-warning-700);
        }

        .alert-box.info {
            background: var(--color-primary-50);
            border: 1px solid var(--color-primary-200);
            color: var(--color-primary-700);
        }

        .color-picker-group {
            display: flex;
            gap: var(--space-4);
            flex-wrap: wrap;
        }

        .color-input-wrapper {
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }

        .color-preview {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-md);
            border: 2px solid var(--color-gray-300);
            cursor: pointer;
        }

        .backup-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--space-4);
            background: var(--color-gray-50);
            border-radius: var(--radius-md);
            margin-bottom: var(--space-3);
        }

        .backup-info {
            display: flex;
            flex-direction: column;
            gap: var(--space-1);
        }

        .backup-name {
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
        }

        .backup-details {
            font-size: var(--text-sm);
            color: var(--color-gray-600);
        }

        .backup-actions {
            display: flex;
            gap: var(--space-2);
        }

        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: var(--space-2);
        }

        .status-indicator.active {
            background: var(--color-success);
        }

        .status-indicator.inactive {
            background: var(--color-gray-400);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo-container">
                    <span class="logo">CN</span>
                    <span class="logo-text">CollaboraNexio</span>
                </div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <a href="dashboard.php" class="nav-item"><i class="icon icon--home"></i> Dashboard</a>
                    <a href="files.php" class="nav-item"><i class="icon icon--folder"></i> File Manager</a>
                    <a href="calendar.php" class="nav-item"><i class="icon icon--calendar"></i> Calendario</a>
                    <a href="tasks.php" class="nav-item"><i class="icon icon--check"></i> Task</a>
                    <a href="ticket.php" class="nav-item"><i class="icon icon--ticket"></i> Ticket</a>
                    <a href="conformita.php" class="nav-item"><i class="icon icon--shield"></i> Conformità</a>
                    <a href="ai.php" class="nav-item"><i class="icon icon--cpu"></i> AI</a>
                </div>

                <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'super_admin'): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Gestione</div>
                    <a href="aziende.php" class="nav-item"><i class="icon icon--building"></i> Aziende</a>
                </div>
                <?php endif; ?>

                <?php if ($currentUser['role'] === 'super_admin'): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Amministrazione</div>
                    <a href="utenti.php" class="nav-item"><i class="icon icon--users"></i> Utenti</a>
                    <a href="audit_log.php" class="nav-item"><i class="icon icon--chart"></i> Audit Log</a>
                    <a href="configurazioni.php" class="nav-item active"><i class="icon icon--settings"></i> Configurazioni</a>
                </div>
                <?php endif; ?>

                <div class="nav-section">
                    <div class="nav-section-title">Account</div>
                    <a href="profilo.php" class="nav-item"><i class="icon icon--user"></i> Il Mio Profilo</a>
                    <a href="logout.php" class="nav-item"><i class="icon icon--logout"></i> Esci</a>
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <header class="top-bar">
                <div class="top-bar-left">
                    <h1>Configurazioni Sistema</h1>
                </div>
                <div class="top-bar-right">
                    <div class="user-menu">
                        <button class="user-menu-trigger">
                            <span class="user-avatar"><?php echo strtoupper(substr($currentUser['name'], 0, 2)); ?></span>
                            <span class="user-name"><?php echo htmlspecialchars($currentUser['name']); ?></span>
                            <i class="icon icon--chevron-down"></i>
                        </button>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <div class="page-content">
                <div class="config-container">
                    <!-- Header -->
                    <div class="config-header">
                        <h2>Impostazioni della Piattaforma</h2>
                        <p style="color: var(--color-gray-600); margin-top: var(--space-2)">
                            Configura le impostazioni generali del sistema e personalizza l'esperienza utente.
                        </p>
                    </div>

                    <!-- Tabs -->
                    <div class="config-tabs">
                        <button class="tab-btn active" onclick="switchTab('general')">Generale</button>
                        <button class="tab-btn" onclick="switchTab('security')">Sicurezza</button>
                        <button class="tab-btn" onclick="switchTab('email')">Email</button>
                        <button class="tab-btn" onclick="switchTab('backup')">Backup</button>
                        <button class="tab-btn" onclick="switchTab('integrations')">Integrazioni</button>
                        <button class="tab-btn" onclick="switchTab('appearance')">Aspetto</button>
                    </div>

                    <!-- General Settings Tab -->
                    <div id="general-tab" class="tab-content active">
                        <div class="config-section">
                            <h3 class="section-title">Impostazioni Generali</h3>
                            <p class="section-description">Configura le impostazioni base della piattaforma</p>

                            <form class="config-form">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label required">Nome Piattaforma</label>
                                        <input type="text" class="form-control" value="CollaboraNexio">
                                        <span class="form-help">Il nome visualizzato in tutta la piattaforma</span>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">URL Base</label>
                                        <input type="text" class="form-control" value="https://collaboranexio.com">
                                        <span class="form-help">L'URL principale del sito</span>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Fuso Orario</label>
                                        <select class="form-control">
                                            <option>Europe/Rome (UTC+01:00)</option>
                                            <option>Europe/London (UTC+00:00)</option>
                                            <option>America/New_York (UTC-05:00)</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Lingua Predefinita</label>
                                        <select class="form-control">
                                            <option>Italiano</option>
                                            <option>English</option>
                                            <option>Español</option>
                                            <option>Français</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="toggle-group">
                                    <div class="toggle-info">
                                        <div class="toggle-label">Modalità Manutenzione</div>
                                        <div class="toggle-description">Mostra un messaggio di manutenzione agli utenti non amministratori</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-group">
                                    <div class="toggle-info">
                                        <div class="toggle-label">Registrazione Utenti</div>
                                        <div class="toggle-description">Permetti ai nuovi utenti di registrarsi autonomamente</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="config-actions">
                                    <button type="button" class="btn btn--secondary">Annulla</button>
                                    <button type="submit" class="btn btn--primary">Salva Modifiche</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Security Tab -->
                    <div id="security-tab" class="tab-content">
                        <div class="config-section">
                            <h3 class="section-title">Impostazioni di Sicurezza</h3>
                            <p class="section-description">Configura le opzioni di sicurezza e autenticazione</p>

                            <div class="alert-box warning">
                                <i class="icon icon--alert-triangle"></i>
                                <div>
                                    <strong>Attenzione:</strong> Modificare queste impostazioni potrebbe influenzare l'accesso degli utenti al sistema.
                                </div>
                            </div>

                            <form class="config-form">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Durata Sessione (minuti)</label>
                                        <input type="number" class="form-control" value="60">
                                        <span class="form-help">Tempo di inattività prima del logout automatico</span>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Tentativi Login Massimi</label>
                                        <input type="number" class="form-control" value="5">
                                        <span class="form-help">Numero di tentativi prima del blocco account</span>
                                    </div>
                                </div>

                                <div class="toggle-group">
                                    <div class="toggle-info">
                                        <div class="toggle-label">Autenticazione a Due Fattori</div>
                                        <div class="toggle-description">Richiedi 2FA per tutti gli utenti</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-group">
                                    <div class="toggle-info">
                                        <div class="toggle-label">Crittografia Dati</div>
                                        <div class="toggle-description">Cripta tutti i dati sensibili nel database</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-group">
                                    <div class="toggle-info">
                                        <div class="toggle-label">Log Audit Completo</div>
                                        <div class="toggle-description">Registra tutte le azioni degli utenti</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="config-actions">
                                    <button type="button" class="btn btn--secondary">Annulla</button>
                                    <button type="submit" class="btn btn--primary">Salva Modifiche</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Email Tab -->
                    <div id="email-tab" class="tab-content">
                        <div class="config-section">
                            <h3 class="section-title">Configurazione Email</h3>
                            <p class="section-description">Imposta i parametri per l'invio delle email</p>

                            <form class="config-form">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label required">Server SMTP</label>
                                        <input type="text" class="form-control" id="smtp_host" value="<?php echo htmlspecialchars($emailConfig['smtpHost'] ?? 'mail.infomaniak.com'); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label required">Porta SMTP</label>
                                        <input type="text" class="form-control" id="smtp_port" value="<?php echo htmlspecialchars((string)($emailConfig['smtpPort'] ?? 465)); ?>">
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label required">Email Mittente</label>
                                        <input type="email" class="form-control" id="from_email" value="<?php echo htmlspecialchars($emailConfig['fromEmail'] ?? 'info@fortibyte.it'); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Nome Mittente</label>
                                        <input type="text" class="form-control" id="from_name" value="<?php echo htmlspecialchars($emailConfig['fromName'] ?? 'CollaboraNexio'); ?>">
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Username SMTP</label>
                                        <input type="text" class="form-control" id="smtp_username" value="<?php echo htmlspecialchars($emailConfig['smtpUsername'] ?? 'info@fortibyte.it'); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Password SMTP</label>
                                        <input type="password" class="form-control" id="smtp_password" placeholder="••••••••" data-has-value="<?php echo !empty($emailConfig['smtpPassword']) ? '1' : '0'; ?>">
                                        <small class="form-text text-muted">Lascia vuoto per mantenere la password esistente</small>
                                    </div>
                                </div>

                                <div class="toggle-group">
                                    <div class="toggle-info">
                                        <div class="toggle-label">Usa TLS/SSL</div>
                                        <div class="toggle-description">Abilita la crittografia per le comunicazioni email (porta 465 richiede SSL)</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="use_tls" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div style="display: flex; gap: var(--space-3); margin-top: var(--space-4)">
                                    <button type="button" class="btn btn--secondary" id="testEmailBtn">Test Connessione</button>
                                </div>

                                <div class="config-actions">
                                    <button type="button" class="btn btn--secondary" onclick="window.location.reload()">Annulla</button>
                                    <button type="button" class="btn btn--primary" id="saveEmailConfigBtn">Salva Modifiche</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Backup Tab -->
                    <div id="backup-tab" class="tab-content">
                        <div class="config-section">
                            <h3 class="section-title">Gestione Backup</h3>
                            <p class="section-description">Configura e gestisci i backup del sistema</p>

                            <div class="alert-box info">
                                <i class="icon icon--info"></i>
                                <div>
                                    Ultimo backup completato: 07/10/2024 03:00 AM
                                </div>
                            </div>

                            <div class="toggle-group">
                                <div class="toggle-info">
                                    <div class="toggle-label">Backup Automatico</div>
                                    <div class="toggle-description">Esegui backup automatici giornalieri</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" checked>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Orario Backup</label>
                                    <input type="time" class="form-control" value="03:00">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Retention (giorni)</label>
                                    <input type="number" class="form-control" value="30">
                                </div>
                            </div>

                            <h4 style="margin-top: var(--space-6); margin-bottom: var(--space-4)">Backup Recenti</h4>

                            <div class="backup-item">
                                <div class="backup-info">
                                    <span class="backup-name">backup_20241007_0300.sql</span>
                                    <span class="backup-details">Database completo • 245 MB • 07/10/2024 03:00</span>
                                </div>
                                <div class="backup-actions">
                                    <button class="btn btn--sm btn--secondary">Download</button>
                                    <button class="btn btn--sm btn--secondary">Ripristina</button>
                                </div>
                            </div>

                            <div class="backup-item">
                                <div class="backup-info">
                                    <span class="backup-name">backup_20241006_0300.sql</span>
                                    <span class="backup-details">Database completo • 242 MB • 06/10/2024 03:00</span>
                                </div>
                                <div class="backup-actions">
                                    <button class="btn btn--sm btn--secondary">Download</button>
                                    <button class="btn btn--sm btn--secondary">Ripristina</button>
                                </div>
                            </div>

                            <div style="margin-top: var(--space-6)">
                                <button class="btn btn--primary">Esegui Backup Manuale</button>
                            </div>
                        </div>
                    </div>

                    <!-- Integrations Tab -->
                    <div id="integrations-tab" class="tab-content">
                        <div class="config-section">
                            <h3 class="section-title">Integrazioni API</h3>
                            <p class="section-description">Gestisci le integrazioni con servizi esterni</p>

                            <div class="backup-item">
                                <div class="backup-info">
                                    <span class="backup-name">
                                        <span class="status-indicator active"></span>Google Calendar
                                    </span>
                                    <span class="backup-details">Sincronizzazione eventi calendario</span>
                                </div>
                                <div class="backup-actions">
                                    <button class="btn btn--sm btn--secondary">Configura</button>
                                    <label class="toggle-switch">
                                        <input type="checkbox" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="backup-item">
                                <div class="backup-info">
                                    <span class="backup-name">
                                        <span class="status-indicator active"></span>OnlyOffice
                                    </span>
                                    <span class="backup-details">Editor documenti online</span>
                                </div>
                                <div class="backup-actions">
                                    <button class="btn btn--sm btn--secondary">Configura</button>
                                    <label class="toggle-switch">
                                        <input type="checkbox" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="backup-item">
                                <div class="backup-info">
                                    <span class="backup-name">
                                        <span class="status-indicator inactive"></span>Jitsi Meet
                                    </span>
                                    <span class="backup-details">Videochiamate integrate</span>
                                </div>
                                <div class="backup-actions">
                                    <button class="btn btn--sm btn--secondary">Configura</button>
                                    <label class="toggle-switch">
                                        <input type="checkbox">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="backup-item">
                                <div class="backup-info">
                                    <span class="backup-name">
                                        <span class="status-indicator inactive"></span>Slack
                                    </span>
                                    <span class="backup-details">Notifiche e messaggistica</span>
                                </div>
                                <div class="backup-actions">
                                    <button class="btn btn--sm btn--secondary">Configura</button>
                                    <label class="toggle-switch">
                                        <input type="checkbox">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div style="margin-top: var(--space-6)">
                                <button class="btn btn--primary">Aggiungi Integrazione</button>
                            </div>
                        </div>
                    </div>

                    <!-- Appearance Tab -->
                    <div id="appearance-tab" class="tab-content">
                        <div class="config-section">
                            <h3 class="section-title">Personalizzazione Aspetto</h3>
                            <p class="section-description">Personalizza i colori e il tema della piattaforma</p>

                            <form class="config-form">
                                <div class="form-group">
                                    <label class="form-label">Logo Aziendale</label>
                                    <div style="display: flex; align-items: center; gap: var(--space-4)">
                                        <div style="width: 100px; height: 100px; background: var(--color-gray-100); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center">
                                            <span style="color: var(--color-gray-400)">Logo</span>
                                        </div>
                                        <div>
                                            <button type="button" class="btn btn--secondary">Carica Logo</button>
                                            <p class="form-help">PNG o JPG, max 2MB, dimensioni consigliate 200x200px</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Colori Tema</label>
                                    <div class="color-picker-group">
                                        <div class="color-input-wrapper">
                                            <div class="color-preview" style="background: #3b82f6"></div>
                                            <div>
                                                <div style="font-weight: 500">Colore Primario</div>
                                                <input type="text" class="form-control" value="#3b82f6" style="width: 100px">
                                            </div>
                                        </div>
                                        <div class="color-input-wrapper">
                                            <div class="color-preview" style="background: #8b5cf6"></div>
                                            <div>
                                                <div style="font-weight: 500">Colore Secondario</div>
                                                <input type="text" class="form-control" value="#8b5cf6" style="width: 100px">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="toggle-group">
                                    <div class="toggle-info">
                                        <div class="toggle-label">Tema Scuro</div>
                                        <div class="toggle-description">Abilita il tema scuro per tutti gli utenti</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">CSS Personalizzato</label>
                                    <textarea class="form-control" rows="10" placeholder="/* Inserisci CSS personalizzato qui */"></textarea>
                                    <span class="form-help">Aggiungi stili CSS personalizzati per modificare l'aspetto</span>
                                </div>

                                <div class="config-actions">
                                    <button type="button" class="btn btn--secondary">Anteprima</button>
                                    <button type="submit" class="btn btn--primary">Salva Modifiche</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="assets/js/app.js"></script>
    <script>
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');

            // Add active class to clicked button
            event.target.classList.add('active');
        }

        // Email configuration functionality
        document.addEventListener('DOMContentLoaded', function() {
            const testEmailBtn = document.getElementById('testEmailBtn');
            const saveEmailConfigBtn = document.getElementById('saveEmailConfigBtn');

            // Test email connection
            if (testEmailBtn) {
                testEmailBtn.addEventListener('click', async function() {
                    const email = prompt('Inserisci l\'email a cui inviare il test:');

                    if (!email) {
                        return;
                    }

                    // Validate email
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(email)) {
                        alert('Email non valida');
                        return;
                    }

                    // Get email configuration from form
                    const smtpHost = document.getElementById('smtp_host').value;
                    const smtpPort = document.getElementById('smtp_port').value;
                    const fromEmail = document.getElementById('from_email').value;
                    const fromName = document.getElementById('from_name').value;
                    const smtpUsername = document.getElementById('smtp_username').value;
                    const smtpPassword = document.getElementById('smtp_password').value;
                    const useTLS = document.getElementById('use_tls').checked;

                    testEmailBtn.disabled = true;
                    testEmailBtn.textContent = 'Invio in corso...';

                    try {
                        // Cache buster to force fresh request
                        const timestamp = new Date().getTime();
                        const response = await fetch('/CollaboraNexio/api/system/config.php?action=test_email&_t=' + timestamp, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': '<?php echo $csrfToken; ?>'
                            },
                            body: JSON.stringify({
                                to_email: email,
                                smtp_host: smtpHost,
                                smtp_port: parseInt(smtpPort),
                                from_email: fromEmail,
                                from_name: fromName,
                                smtp_username: smtpUsername,
                                smtp_password: smtpPassword,
                                use_tls: useTLS
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            alert('Email di test inviata con successo! Controlla la casella di posta.');
                        } else if (data.warning) {
                            // Development environment warning
                            alert('⚠️ ' + data.message + '\n\n' + (data.details || ''));
                        } else {
                            alert('Errore: ' + (data.error || data.message || 'Invio fallito') + '\n\n' + (data.details || ''));
                        }
                    } catch (error) {
                        console.error('Error testing email:', error);
                        alert('Errore di rete durante il test email');
                    } finally {
                        testEmailBtn.disabled = false;
                        testEmailBtn.textContent = 'Test Connessione';
                    }
                });
            }

            // Save email configuration
            if (saveEmailConfigBtn) {
                saveEmailConfigBtn.addEventListener('click', async function() {
                    if (!confirm('Salvare le configurazioni email?')) {
                        return;
                    }

                    // Get email configuration from form
                    const smtpHost = document.getElementById('smtp_host').value;
                    const smtpPort = document.getElementById('smtp_port').value;
                    const fromEmail = document.getElementById('from_email').value;
                    const fromName = document.getElementById('from_name').value;
                    const smtpUsername = document.getElementById('smtp_username').value;
                    const smtpPassword = document.getElementById('smtp_password').value;
                    const useTLS = document.getElementById('use_tls').checked;

                    // Validation
                    if (!smtpHost || !smtpPort || !fromEmail || !smtpUsername) {
                        alert('Compila tutti i campi obbligatori');
                        return;
                    }

                    saveEmailConfigBtn.disabled = true;
                    saveEmailConfigBtn.textContent = 'Salvataggio...';

                    try {
                        // Prepare settings object - use correct field names matching database schema
                        const settings = {
                            smtp_host: smtpHost,
                            smtp_port: parseInt(smtpPort),
                            from_email: fromEmail,
                            from_name: fromName,
                            smtp_username: smtpUsername,
                            reply_to: fromEmail  // Use same email for reply-to
                        };

                        // Only include password if it was changed (not empty)
                        if (smtpPassword && smtpPassword.trim() !== '') {
                            settings.smtp_password = smtpPassword;
                        }

                        // Cache buster to force fresh request
                        const timestamp = new Date().getTime();
                        const response = await fetch('/CollaboraNexio/api/system/config.php?action=save&_t=' + timestamp, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': '<?php echo $csrfToken; ?>'
                            },
                            body: JSON.stringify({
                                settings: settings
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            alert('Configurazioni salvate con successo!');
                        } else {
                            alert('Errore: ' + (data.error || 'Salvataggio fallito'));
                        }
                    } catch (error) {
                        console.error('Error saving config:', error);
                        alert('Errore di rete durante il salvataggio');
                    } finally {
                        saveEmailConfigBtn.disabled = false;
                        saveEmailConfigBtn.textContent = 'Salva Modifiche';
                    }
                });
            }
        });
    </script>
</body>
</html>