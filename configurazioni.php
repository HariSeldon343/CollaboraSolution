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

// Require active tenant access (super_admins bypass this check)
require_once __DIR__ . '/includes/tenant_access_check.php';
requireTenantAccess($currentUser['id'], $currentUser['role']);

// Only super_admin can access this page
if ($currentUser['role'] !== 'super_admin') {
    header('Location: dashboard.php');
    exit;
}

// Enforce Page Visibility access rules (configurazioni.php -> Visibilità Pagine)
require_once __DIR__ . '/includes/page_access_check.php';
checkPageAccess('configurazioni');

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
    'from_name' => $emailConfig['fromName'] ?? 'Nexio',
    'reply_to' => $emailConfig['replyTo'] ?? 'info@fortibyte.it'
]);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Configurazioni - Nexio (v' . time() . ')';
    $pageMeta = [
        '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">',
        '<meta http-equiv="Pragma" content="no-cache">',
        '<meta http-equiv="Expires" content="0">',
    ];
    require __DIR__ . '/includes/layout_head.php';
?>

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
<?php require __DIR__ . '/includes/layout_start.php'; ?>
            <div class="header">
                <h1 class="page-title">Configurazioni Sistema</h1>
                <div class="flex items-center gap-4">
                    <span class="text-sm text-muted"><?php echo htmlspecialchars($currentUser['name']); ?></span>
                </div>
            </div>

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
                        <button type="button" class="tab-btn active" data-tab="general" onclick="switchTab('general')">Generale</button>
                        <button type="button" class="tab-btn" data-tab="security" onclick="switchTab('security')">Sicurezza</button>
                        <button type="button" class="tab-btn" data-tab="email" onclick="switchTab('email')">Email</button>
                        <button type="button" class="tab-btn" data-tab="backup" onclick="switchTab('backup')">Backup</button>
                        <button type="button" class="tab-btn" data-tab="integrations" onclick="switchTab('integrations')">Integrazioni</button>
                        <button type="button" class="tab-btn" data-tab="appearance" onclick="switchTab('appearance')">Aspetto</button>
                        <button type="button" class="tab-btn" data-tab="visibility" onclick="switchTab('visibility')">Visibilita Pagine</button>
                    </div>

                    <!-- General Settings Tab -->
                    <div id="general-tab" class="tab-content active">
                        <div class="config-section">
                            <h3 class="section-title">Impostazioni Generali</h3>
                            <p class="section-description">Configura le impostazioni base della piattaforma</p>

                            <form class="config-form" id="configGeneralForm">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label required">Nome Piattaforma</label>
                                        <input type="text" class="form-control" id="cfg_platform_name" value="Nexio">
                                        <span class="form-help">Il nome visualizzato in tutta la piattaforma</span>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">URL Base</label>
                                        <input type="text" class="form-control" id="cfg_platform_base_url" value="https://collaboranexio.com">
                                        <span class="form-help">L'URL principale del sito</span>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Fuso Orario</label>
                                        <select class="form-control" id="cfg_platform_timezone">
                                            <option value="Europe/Rome">Europe/Rome (UTC+01:00)</option>
                                            <option value="Europe/London">Europe/London (UTC+00:00)</option>
                                            <option value="America/New_York">America/New_York (UTC-05:00)</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Lingua Predefinita</label>
                                        <select class="form-control" id="cfg_platform_language">
                                            <option value="it">Italiano</option>
                                            <option value="en">English</option>
                                            <option value="es">Español</option>
                                            <option value="fr">Français</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="toggle-group">
                                    <div class="toggle-info">
                                        <div class="toggle-label">Modalità Manutenzione</div>
                                        <div class="toggle-description">Mostra un messaggio di manutenzione agli utenti non amministratori</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="cfg_platform_maintenance_mode">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-group">
                                    <div class="toggle-info">
                                        <div class="toggle-label">Registrazione Utenti</div>
                                        <div class="toggle-description">Permetti ai nuovi utenti di registrarsi autonomamente</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="cfg_platform_allow_registration" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="config-actions">
                                    <button type="button" class="btn btn--secondary" id="cfg_general_reset_btn">Annulla</button>
                                    <button type="button" class="btn btn--primary" id="cfg_general_save_btn">Salva Modifiche</button>
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

                            <form class="config-form" id="configSecurityForm">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Durata Sessione (minuti)</label>
                                        <input type="number" class="form-control" id="cfg_security_session_minutes" value="60" min="1" step="1">
                                        <span class="form-help">Tempo di inattività prima del logout automatico</span>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Tentativi Login Massimi</label>
                                        <input type="number" class="form-control" id="cfg_security_max_login_attempts" value="5" min="1" step="1">
                                        <span class="form-help">Numero di tentativi prima del blocco account</span>
                                    </div>
                                </div>

                                <div class="toggle-group">
                                    <div class="toggle-info">
                                        <div class="toggle-label">Autenticazione a Due Fattori</div>
                                        <div class="toggle-description">Richiedi 2FA per tutti gli utenti</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="cfg_security_require_2fa" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-group">
                                    <div class="toggle-info">
                                        <div class="toggle-label">Crittografia Dati</div>
                                        <div class="toggle-description">Cripta tutti i dati sensibili nel database</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="cfg_security_encrypt_data" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-group">
                                    <div class="toggle-info">
                                        <div class="toggle-label">Log Audit Completo</div>
                                        <div class="toggle-description">Registra tutte le azioni degli utenti</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="cfg_security_full_audit_log" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="config-actions">
                                    <button type="button" class="btn btn--secondary" id="cfg_security_reset_btn">Annulla</button>
                                    <button type="button" class="btn btn--primary" id="cfg_security_save_btn">Salva Modifiche</button>
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
                                        <input type="text" class="form-control" id="from_name" value="<?php echo htmlspecialchars($emailConfig['fromName'] ?? 'Nexio'); ?>">
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
                                    <input type="checkbox" id="cfg_backup_enabled" checked>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Orario Backup</label>
                                    <input type="time" class="form-control" id="cfg_backup_time" value="03:00">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Retention (giorni)</label>
                                    <input type="number" class="form-control" id="cfg_backup_retention_days" value="30" min="1" step="1">
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
                                <button type="button" class="btn btn--secondary" id="cfg_backup_save_btn">Salva impostazioni backup</button>
                                <button type="button" class="btn btn--primary" id="cfg_backup_manual_btn">Esegui Backup Manuale</button>
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
                                    <button type="button" class="btn btn--sm btn--secondary">Configura</button>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="cfg_integration_google_calendar" checked>
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
                                    <button type="button" class="btn btn--sm btn--secondary">Configura</button>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="cfg_integration_onlyoffice" checked>
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
                                    <button type="button" class="btn btn--sm btn--secondary">Configura</button>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="cfg_integration_jitsi">
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
                                    <button type="button" class="btn btn--sm btn--secondary">Configura</button>
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="cfg_integration_slack">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div style="margin-top: var(--space-6)">
                                <button type="button" class="btn btn--secondary" id="cfg_integrations_save_btn">Salva integrazioni</button>
                                <button type="button" class="btn btn--primary">Aggiungi Integrazione</button>
                            </div>
                        </div>
                    </div>

                    <!-- Appearance Tab -->
                    <div id="appearance-tab" class="tab-content">
                        <div class="config-section">
                            <h3 class="section-title">Personalizzazione Aspetto</h3>
                            <p class="section-description">Personalizza i colori e il tema della piattaforma</p>

                            <form class="config-form" id="configAppearanceForm">
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
                                                <input type="text" class="form-control" id="cfg_theme_primary_color" value="#3b82f6" style="width: 100px">
                                            </div>
                                        </div>
                                        <div class="color-input-wrapper">
                                            <div class="color-preview" style="background: #8b5cf6"></div>
                                            <div>
                                                <div style="font-weight: 500">Colore Secondario</div>
                                                <input type="text" class="form-control" id="cfg_theme_secondary_color" value="#8b5cf6" style="width: 100px">
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
                                        <input type="checkbox" id="cfg_theme_force_dark_mode">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">CSS Personalizzato</label>
                                    <textarea class="form-control" id="cfg_theme_custom_css" rows="10" placeholder="/* Inserisci CSS personalizzato qui */"></textarea>
                                    <span class="form-help">Aggiungi stili CSS personalizzati per modificare l'aspetto</span>
                                </div>

                                <div class="config-actions">
                                    <button type="button" class="btn btn--secondary" id="cfg_appearance_preview_btn">Anteprima</button>
                                    <button type="button" class="btn btn--primary" id="cfg_appearance_save_btn">Salva Modifiche</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Page Visibility Tab -->
                    <div id="visibility-tab" class="tab-content">
                        <div class="config-section">
                            <h3 class="section-title">Visibilita Pagine per Ruolo</h3>
                            <p class="section-description">
                                Configura quali pagine sono visibili per ogni ruolo utente.
                                Le modifiche si applicano globalmente a tutti i tenant.
                                Il ruolo Super Admin vede sempre tutte le pagine.
                            </p>

                            <div class="alert-box info">
                                <i class="icon icon--info"></i>
                                <div>
                                    <strong>Nota:</strong> Queste impostazioni controllano la visibilita della sidebar.
                                    Le pagine nascoste non saranno accessibili agli utenti del ruolo specificato.
                                </div>
                            </div>

                            <div id="visibility-loading" style="text-align: center; padding: var(--space-8);">
                                <p>Caricamento impostazioni...</p>
                            </div>

                            <div id="visibility-content" style="display: none;">
                                <div class="visibility-matrix" style="overflow-x: auto;">
                                    <table class="visibility-table" style="width: 100%; border-collapse: collapse;">
                                        <thead>
                                            <tr>
                                                <th style="text-align: left; padding: var(--space-3); border-bottom: 2px solid var(--color-gray-200);">Pagina</th>
                                                <th style="text-align: center; padding: var(--space-3); border-bottom: 2px solid var(--color-gray-200); width: 100px;">Admin</th>
                                                <th style="text-align: center; padding: var(--space-3); border-bottom: 2px solid var(--color-gray-200); width: 100px;">Manager</th>
                                                <th style="text-align: center; padding: var(--space-3); border-bottom: 2px solid var(--color-gray-200); width: 100px;">User</th>
                                            </tr>
                                        </thead>
                                        <tbody id="visibility-tbody">
                                            <!-- Dynamic content loaded by JavaScript -->
                                        </tbody>
                                    </table>
                                </div>

                                <div class="config-actions" style="margin-top: var(--space-6);">
                                    <button type="button" class="btn btn--secondary" id="resetVisibilityBtn">
                                        Ripristina Default
                                    </button>
                                    <button type="button" class="btn btn--primary" id="saveVisibilityBtn">
                                        Salva Modifiche
                                    </button>
                                </div>
                            </div>

                            <div id="visibility-error" style="display: none;" class="alert-box warning">
                                <i class="icon icon--alert-triangle"></i>
                                <div id="visibility-error-message">Errore durante il caricamento delle impostazioni.</div>
                            </div>
                        </div>
                    </div>
                </div>
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

            // Add active class to the button that matches this tab
            const btn = document.querySelector(`.tab-btn[data-tab="${tabName}"]`);
            if (btn) btn.classList.add('active');

            // Load visibility settings when visibility tab is selected
            if (tabName === 'visibility') {
                loadPageVisibilitySettings();
            }
        }

        // Ensure tab buttons always work even if inline onclick is blocked/overridden
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.config-tabs .tab-btn[data-tab]').forEach((btn) => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    switchTab(this.getAttribute('data-tab'));
                });
            });
        });

        // ========================================
        // Page Visibility Management - GLOBAL SCOPE
        // ========================================

        // Cache for visibility settings (global)
        let visibilitySettings = [];
        let visibilityLoaded = false;

        // Load visibility settings when tab is opened
        async function loadPageVisibilitySettings() {
            if (visibilityLoaded) return;

            const loadingEl = document.getElementById('visibility-loading');
            const contentEl = document.getElementById('visibility-content');
            const errorEl = document.getElementById('visibility-error');

            loadingEl.style.display = 'block';
            contentEl.style.display = 'none';
            errorEl.style.display = 'none';

            try {
                const timestamp = new Date().getTime();
                const response = await fetch('/CollaboraNexio/api/system/page_visibility.php?action=get&_t=' + timestamp, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-Token': '<?php echo $csrfToken; ?>'
                    }
                });

                const data = await response.json();

                if (data.success && data.data && data.data.settings) {
                    visibilitySettings = data.data.settings;
                    renderVisibilityTable(visibilitySettings);
                    visibilityLoaded = true;
                    loadingEl.style.display = 'none';
                    contentEl.style.display = 'block';
                } else {
                    throw new Error(data.error || 'Risposta non valida dal server');
                }
            } catch (error) {
                console.error('Error loading visibility settings:', error);
                loadingEl.style.display = 'none';
                errorEl.style.display = 'flex';
                document.getElementById('visibility-error-message').textContent =
                    'Errore durante il caricamento: ' + error.message;
            }
        }

        // Render the visibility table
        function renderVisibilityTable(settings) {
            const tbody = document.getElementById('visibility-tbody');
            tbody.innerHTML = '';

            settings.forEach((page, index) => {
                const row = document.createElement('tr');
                row.style.borderBottom = '1px solid var(--color-gray-100)';

                // Page name cell
                const nameCell = document.createElement('td');
                nameCell.style.padding = 'var(--space-3)';
                nameCell.style.fontWeight = 'var(--font-medium)';
                nameCell.textContent = page.displayName || page.name;
                row.appendChild(nameCell);

                // Role cells (admin, manager, user)
                ['admin', 'manager', 'user'].forEach(role => {
                    const cell = document.createElement('td');
                    cell.style.textAlign = 'center';
                    cell.style.padding = 'var(--space-3)';

                    const toggle = document.createElement('label');
                    toggle.className = 'toggle-switch';

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.checked = page.roles && page.roles[role] !== false;
                    checkbox.dataset.page = page.name;
                    checkbox.dataset.role = role;
                    checkbox.addEventListener('change', function() {
                        updateVisibilitySetting(this.dataset.page, this.dataset.role, this.checked);
                    });

                    const slider = document.createElement('span');
                    slider.className = 'toggle-slider';

                    toggle.appendChild(checkbox);
                    toggle.appendChild(slider);
                    cell.appendChild(toggle);
                    row.appendChild(cell);
                });

                tbody.appendChild(row);
            });
        }

        // Update local visibility setting
        function updateVisibilitySetting(pageName, role, isVisible) {
            const page = visibilitySettings.find(p => p.name === pageName);
            if (page) {
                if (!page.roles) page.roles = {};
                page.roles[role] = isVisible;
            }
        }

        // Save visibility settings
        async function savePageVisibilitySettings() {
            const saveBtn = document.getElementById('saveVisibilityBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = 'Salvataggio...';

            try {
                const timestamp = new Date().getTime();
                const response = await fetch('/CollaboraNexio/api/system/page_visibility.php?action=save&_t=' + timestamp, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': '<?php echo $csrfToken; ?>'
                    },
                    body: JSON.stringify({
                        settings: visibilitySettings
                    })
                });

                const data = await response.json();

                if (data.success) {
                    const msg = data.message || 'Impostazioni salvate con successo!';
                    alert(msg);
                } else {
                    throw new Error(data.error || 'Salvataggio fallito');
                }
            } catch (error) {
                console.error('Error saving visibility settings:', error);
                alert('Errore durante il salvataggio: ' + error.message);
            } finally {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Salva Modifiche';
            }
        }

        // Reset visibility settings to default
        async function resetPageVisibilitySettings() {
            if (!confirm('Sei sicuro di voler ripristinare tutte le impostazioni ai valori predefiniti?\nTutte le pagine saranno visibili per tutti i ruoli.')) {
                return;
            }

            const resetBtn = document.getElementById('resetVisibilityBtn');
            resetBtn.disabled = true;
            resetBtn.textContent = 'Ripristino...';

            try {
                const timestamp = new Date().getTime();
                const response = await fetch('/CollaboraNexio/api/system/page_visibility.php?action=reset&_t=' + timestamp, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': '<?php echo $csrfToken; ?>'
                    }
                });

                const data = await response.json();

                if (data.success) {
                    // Reload settings after reset
                    visibilityLoaded = false;
                    await loadPageVisibilitySettings();
                    alert(data.message || 'Impostazioni ripristinate con successo!');
                } else {
                    throw new Error(data.error || 'Ripristino fallito');
                }
            } catch (error) {
                console.error('Error resetting visibility settings:', error);
                alert('Errore durante il ripristino: ' + error.message);
            } finally {
                resetBtn.disabled = false;
                resetBtn.textContent = 'Ripristina Default';
            }
        }

        // Email configuration functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent default submits (these tabs use AJAX save)
            ['configGeneralForm', 'configSecurityForm', 'configAppearanceForm'].forEach((id) => {
                document.getElementById(id)?.addEventListener('submit', (e) => e.preventDefault());
            });

            // Load system settings and populate all tabs
            loadSystemSettingsAndPopulate().catch((e) => console.warn('[Config] load settings failed:', e));

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

            // ========================================
            // Page Visibility - Event Listeners
            // ========================================
            const saveVisibilityBtn = document.getElementById('saveVisibilityBtn');
            const resetVisibilityBtn = document.getElementById('resetVisibilityBtn');

            if (saveVisibilityBtn) {
                saveVisibilityBtn.addEventListener('click', savePageVisibilitySettings);
            }

            if (resetVisibilityBtn) {
                resetVisibilityBtn.addEventListener('click', resetPageVisibilitySettings);
            }

            // General settings
            document.getElementById('cfg_general_reset_btn')?.addEventListener('click', () => window.location.reload());
            document.getElementById('cfg_general_save_btn')?.addEventListener('click', saveGeneralSettings);

            // Security settings
            document.getElementById('cfg_security_reset_btn')?.addEventListener('click', () => window.location.reload());
            document.getElementById('cfg_security_save_btn')?.addEventListener('click', saveSecuritySettings);

            // Backup
            document.getElementById('cfg_backup_save_btn')?.addEventListener('click', saveBackupSettings);
            document.getElementById('cfg_backup_manual_btn')?.addEventListener('click', () => {
                alert('Backup manuale: funzione non ancora automatizzata via web.\n\nSuggerimento: esegui un backup server-side (cron/mysqldump). Questa pagina salva solo le impostazioni.');
            });

            // Integrations
            document.getElementById('cfg_integrations_save_btn')?.addEventListener('click', saveIntegrationsSettings);

            // Appearance
            document.getElementById('cfg_appearance_preview_btn')?.addEventListener('click', () => {
                alert('Anteprima: in arrivo. Per ora puoi salvare i valori nel database.');
            });
            document.getElementById('cfg_appearance_save_btn')?.addEventListener('click', saveAppearanceSettings);
        });

        // ----------------------------
        // System settings helpers
        // ----------------------------
        async function apiSystemGet() {
            const ts = Date.now();
            const res = await fetch('/CollaboraNexio/api/system/config.php?action=get&_t=' + ts, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': '<?php echo $csrfToken; ?>' }
            });
            const data = await res.json();
            if (!data || data.success !== true) throw new Error(data?.error || 'Impossibile caricare impostazioni');
            return data.data || {};
        }

        async function apiSystemSave(settings) {
            const ts = Date.now();
            const res = await fetch('/CollaboraNexio/api/system/config.php?action=save&_t=' + ts, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?php echo $csrfToken; ?>'
                },
                body: JSON.stringify({ settings })
            });
            const data = await res.json().catch(() => null);
            if (!data || data.success !== true) throw new Error(data?.error || 'Salvataggio fallito');
            return data;
        }

        function setInputValue(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            if (el.type === 'checkbox') el.checked = !!value;
            else el.value = (value ?? '').toString();
        }

        async function loadSystemSettingsAndPopulate() {
            const s = await apiSystemGet();

            // General
            setInputValue('cfg_platform_name', s.platform_name ?? 'Nexio');
            setInputValue('cfg_platform_base_url', s.platform_base_url ?? 'https://collaboranexio.com');
            setInputValue('cfg_platform_timezone', s.platform_timezone ?? 'Europe/Rome');
            setInputValue('cfg_platform_language', s.platform_language ?? 'it');
            setInputValue('cfg_platform_maintenance_mode', s.platform_maintenance_mode ?? false);
            setInputValue('cfg_platform_allow_registration', s.platform_allow_registration ?? true);

            // Security
            setInputValue('cfg_security_session_minutes', s.security_session_minutes ?? 60);
            setInputValue('cfg_security_max_login_attempts', s.security_max_login_attempts ?? 5);
            setInputValue('cfg_security_require_2fa', s.security_require_2fa ?? false);
            setInputValue('cfg_security_encrypt_data', s.security_encrypt_data ?? true);
            setInputValue('cfg_security_full_audit_log', s.security_full_audit_log ?? true);

            // Backup
            setInputValue('cfg_backup_enabled', s.backup_enabled ?? true);
            setInputValue('cfg_backup_time', s.backup_time ?? '03:00');
            setInputValue('cfg_backup_retention_days', s.backup_retention_days ?? 30);

            // Integrations
            setInputValue('cfg_integration_google_calendar', s.integration_google_calendar ?? true);
            setInputValue('cfg_integration_onlyoffice', s.integration_onlyoffice ?? true);
            setInputValue('cfg_integration_jitsi', s.integration_jitsi ?? false);
            setInputValue('cfg_integration_slack', s.integration_slack ?? false);

            // Appearance
            setInputValue('cfg_theme_primary_color', s.theme_primary_color ?? '#3b82f6');
            setInputValue('cfg_theme_secondary_color', s.theme_secondary_color ?? '#8b5cf6');
            setInputValue('cfg_theme_force_dark_mode', s.theme_force_dark_mode ?? false);
            setInputValue('cfg_theme_custom_css', s.theme_custom_css ?? '');
        }

        async function saveGeneralSettings() {
            try {
                const settings = {
                    platform_name: document.getElementById('cfg_platform_name')?.value || 'Nexio',
                    platform_base_url: document.getElementById('cfg_platform_base_url')?.value || '',
                    platform_timezone: document.getElementById('cfg_platform_timezone')?.value || 'Europe/Rome',
                    platform_language: document.getElementById('cfg_platform_language')?.value || 'it',
                    platform_maintenance_mode: !!document.getElementById('cfg_platform_maintenance_mode')?.checked,
                    platform_allow_registration: !!document.getElementById('cfg_platform_allow_registration')?.checked,
                };
                await apiSystemSave(settings);
                alert('Impostazioni generali salvate.');
            } catch (e) {
                console.error(e);
                alert('Errore salvataggio: ' + (e.message || e));
            }
        }

        async function saveSecuritySettings() {
            try {
                const minutes = parseInt(document.getElementById('cfg_security_session_minutes')?.value || '60', 10) || 60;
                const settings = {
                    security_session_minutes: minutes,
                    security_max_login_attempts: parseInt(document.getElementById('cfg_security_max_login_attempts')?.value || '5', 10) || 5,
                    security_require_2fa: !!document.getElementById('cfg_security_require_2fa')?.checked,
                    security_encrypt_data: !!document.getElementById('cfg_security_encrypt_data')?.checked,
                    security_full_audit_log: !!document.getElementById('cfg_security_full_audit_log')?.checked,
                };
                await apiSystemSave(settings);
                alert('Impostazioni sicurezza salvate.\n\nNota: la durata sessione si applica subito alla sessione corrente.');
            } catch (e) {
                console.error(e);
                alert('Errore salvataggio: ' + (e.message || e));
            }
        }

        async function saveBackupSettings() {
            try {
                const settings = {
                    backup_enabled: !!document.getElementById('cfg_backup_enabled')?.checked,
                    backup_time: document.getElementById('cfg_backup_time')?.value || '03:00',
                    backup_retention_days: parseInt(document.getElementById('cfg_backup_retention_days')?.value || '30', 10) || 30,
                };
                await apiSystemSave(settings);
                alert('Impostazioni backup salvate.\n\nNota: l’esecuzione automatica richiede un job server-side (cron).');
            } catch (e) {
                console.error(e);
                alert('Errore salvataggio: ' + (e.message || e));
            }
        }

        async function saveIntegrationsSettings() {
            try {
                const settings = {
                    integration_google_calendar: !!document.getElementById('cfg_integration_google_calendar')?.checked,
                    integration_onlyoffice: !!document.getElementById('cfg_integration_onlyoffice')?.checked,
                    integration_jitsi: !!document.getElementById('cfg_integration_jitsi')?.checked,
                    integration_slack: !!document.getElementById('cfg_integration_slack')?.checked,
                };
                await apiSystemSave(settings);
                alert('Impostazioni integrazioni salvate.');
            } catch (e) {
                console.error(e);
                alert('Errore salvataggio: ' + (e.message || e));
            }
        }

        async function saveAppearanceSettings() {
            try {
                const settings = {
                    theme_primary_color: (document.getElementById('cfg_theme_primary_color')?.value || '').trim(),
                    theme_secondary_color: (document.getElementById('cfg_theme_secondary_color')?.value || '').trim(),
                    theme_force_dark_mode: !!document.getElementById('cfg_theme_force_dark_mode')?.checked,
                    theme_custom_css: document.getElementById('cfg_theme_custom_css')?.value || '',
                };
                await apiSystemSave(settings);
                alert('Impostazioni aspetto salvate.\n\nNota: l’applicazione globale del tema richiede l’iniezione dei valori in layout (lo abilitiamo nel prossimo step del check).');
            } catch (e) {
                console.error(e);
                alert('Errore salvataggio: ' + (e.message || e));
            }
        }
    </script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>