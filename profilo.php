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

// Initialize company filter
$companyFilter = new CompanyFilter($currentUser);

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Il Mio Profilo - CollaboraNexio</title>

    <!-- Main CSS -->
    <link rel="stylesheet" href="assets/css/styles.css">
    <!-- Page specific CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css">

    <style>
        /* Logo image style */
        .logo-img {
            width: 32px;
            height: 32px;
            background: white;
            padding: 4px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .profile-container {
            padding: var(--space-6);
            max-width: 1200px;
            margin: 0 auto;
        }

        .profile-header {
            background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
            padding: var(--space-8);
            border-radius: var(--radius-lg);
            color: white;
            margin-bottom: var(--space-8);
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .profile-info {
            display: flex;
            align-items: center;
            gap: var(--space-6);
            position: relative;
            z-index: 1;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: bold;
            color: var(--color-primary);
            position: relative;
        }

        .avatar-upload {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 36px;
            height: 36px;
            background: var(--color-primary);
            border: 3px solid white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform var(--transition-fast);
        }

        .avatar-upload:hover {
            transform: scale(1.1);
        }

        .profile-details {
            flex: 1;
        }

        .profile-name {
            font-size: var(--text-2xl);
            font-weight: var(--font-bold);
            margin-bottom: var(--space-2);
        }

        .profile-role {
            display: inline-block;
            padding: var(--space-1) var(--space-3);
            background: rgba(255, 255, 255, 0.2);
            border-radius: var(--radius-full);
            font-size: var(--text-sm);
            margin-bottom: var(--space-2);
        }

        .profile-meta {
            display: flex;
            gap: var(--space-6);
            font-size: var(--text-sm);
            opacity: 0.9;
        }

        .profile-tabs {
            display: flex;
            gap: var(--space-2);
            margin-bottom: var(--space-6);
            background: var(--color-white);
            padding: var(--space-2);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
        }

        .profile-tab {
            padding: var(--space-3) var(--space-6);
            background: transparent;
            border: none;
            color: var(--color-gray-600);
            font-weight: var(--font-medium);
            cursor: pointer;
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
        }

        .profile-tab:hover {
            background: var(--color-gray-50);
            color: var(--color-gray-900);
        }

        .profile-tab.active {
            background: var(--color-primary);
            color: white;
        }

        .profile-section {
            background: var(--color-white);
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--space-6);
        }

        .section-title {
            font-size: var(--text-lg);
            font-weight: var(--font-semibold);
            color: var(--color-gray-900);
            margin-bottom: var(--space-4);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-6);
            margin-bottom: var(--space-6);
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

        .form-control:disabled {
            background: var(--color-gray-50);
            cursor: not-allowed;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: var(--space-4);
        }

        .activity-item {
            display: flex;
            gap: var(--space-4);
            padding: var(--space-4);
            background: var(--color-gray-50);
            border-radius: var(--radius-md);
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--color-primary-100);
            color: var(--color-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
            margin-bottom: var(--space-1);
        }

        .activity-time {
            font-size: var(--text-sm);
            color: var(--color-gray-500);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-6);
        }

        .stat-card {
            padding: var(--space-4);
            background: var(--color-gray-50);
            border-radius: var(--radius-md);
            text-align: center;
        }

        .stat-value {
            font-size: var(--text-2xl);
            font-weight: var(--font-bold);
            color: var(--color-primary);
            margin-bottom: var(--space-1);
        }

        .stat-label {
            font-size: var(--text-sm);
            color: var(--color-gray-600);
        }

        .password-section {
            background: var(--color-warning-50);
            padding: var(--space-4);
            border-radius: var(--radius-md);
            border: 1px solid var(--color-warning-200);
            margin-bottom: var(--space-6);
        }

        .notification-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: var(--space-4);
            background: var(--color-gray-50);
            border-radius: var(--radius-md);
            margin-bottom: var(--space-3);
        }

        .notification-info {
            display: flex;
            flex-direction: column;
            gap: var(--space-1);
        }

        .notification-title {
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
        }

        .notification-desc {
            font-size: var(--text-sm);
            color: var(--color-gray-600);
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

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .profile-info {
                flex-direction: column;
                text-align: center;
            }

            .profile-meta {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="main-layout">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="assets/images/logo.png" alt="CollaboraNexio" class="logo-img">
                    <span class="logo-text">NEXIO</span>
                </div>
                <div class="sidebar-subtitle">Semplifica, Connetti, Cresci Insieme</div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">AREA OPERATIVA</div>
                    <a href="dashboard.php" class="nav-item"><i class="icon icon--home"></i> Dashboard</a>
                    <a href="files.php" class="nav-item"><i class="icon icon--folder"></i> File Manager</a>
                    <a href="calendar.php" class="nav-item"><i class="icon icon--calendar"></i> Calendario</a>
                    <a href="tasks.php" class="nav-item"><i class="icon icon--check"></i> Task</a>
                    <a href="ticket.php" class="nav-item"><i class="icon icon--ticket"></i> Ticket</a>
                    <a href="conformita.php" class="nav-item"><i class="icon icon--shield"></i> Conformità</a>
                    <a href="ai.php" class="nav-item"><i class="icon icon--cpu"></i> AI</a>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">GESTIONE</div>
                    <a href="aziende.php" class="nav-item"><i class="icon icon--building"></i> Aziende</a>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">AMMINISTRAZIONE</div>
                    <a href="utenti.php" class="nav-item"><i class="icon icon--users"></i> Utenti</a>
                    <a href="audit_log.php" class="nav-item"><i class="icon icon--chart"></i> Audit Log</a>
                    <a href="configurazioni.php" class="nav-item"><i class="icon icon--settings"></i> Configurazioni</a>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">ACCOUNT</div>
                    <a href="profilo.php" class="nav-item active"><i class="icon icon--user"></i> Il Mio Profilo</a>
                    <a href="logout.php" class="nav-item"><i class="icon icon--logout"></i> Esci</a>
                </div>
            </nav>
            <div class="sidebar-footer">
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($currentUser['name'], 0, 2)); ?></div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($currentUser['name']); ?></div>
                        <div class="user-badge">SUPER ADMIN</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Bar -->
            <div class="header">
                <h1 class="page-title">Il Mio Profilo</h1>
                <div class="flex items-center gap-4">
                    <span class="text-sm text-muted">Benvenuto, <?php echo htmlspecialchars($currentUser['name']); ?></span>
                </div>
            </div>

            <!-- Page Content -->
            <div class="page-content">
                <div class="profile-container">
                    <!-- Profile Header -->
                    <div class="profile-header">
                        <div class="profile-info">
                            <div class="profile-avatar">
                                <?php echo strtoupper(substr($currentUser['name'], 0, 2)); ?>
                                <div class="avatar-upload">
                                    <i class="icon icon--camera" style="color: white"></i>
                                </div>
                            </div>
                            <div class="profile-details">
                                <h2 class="profile-name"><?php echo htmlspecialchars($currentUser['name']); ?></h2>
                                <span class="profile-role">
                                    <?php
                                    $roleDisplay = [
                                        'super_admin' => 'Super Amministratore',
                                        'admin' => 'Amministratore',
                                        'user' => 'Utente'
                                    ];
                                    echo $roleDisplay[$currentUser['role']] ?? 'Utente';
                                    ?>
                                </span>
                                <div class="profile-meta">
                                    <span><i class="icon icon--mail"></i> <?php echo htmlspecialchars($currentUser['email']); ?></span>
                                    <span><i class="icon icon--calendar"></i> Membro dal 01/01/2024</span>
                                    <span><i class="icon icon--building"></i> <?php echo htmlspecialchars($currentUser['company'] ?? 'Azienda Demo'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Tabs -->
                    <div class="profile-tabs">
                        <button class="profile-tab active" onclick="switchTab('info')">
                            <i class="icon icon--user"></i> Informazioni
                        </button>
                        <button class="profile-tab" onclick="switchTab('security')">
                            <i class="icon icon--lock"></i> Sicurezza
                        </button>
                        <button class="profile-tab" onclick="switchTab('notifications')">
                            <i class="icon icon--bell"></i> Notifiche
                        </button>
                        <button class="profile-tab" onclick="switchTab('activity')">
                            <i class="icon icon--activity"></i> Attività
                        </button>
                    </div>

                    <!-- Info Tab -->
                    <div id="info-tab" class="tab-content active">
                        <div class="profile-section">
                            <h3 class="section-title">Informazioni Personali</h3>
                            <form>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Nome</label>
                                        <input type="text" class="form-control" value="<?php echo explode(' ', $currentUser['name'])[0]; ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Cognome</label>
                                        <input type="text" class="form-control" value="<?php echo explode(' ', $currentUser['name'])[1] ?? ''; ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" value="<?php echo htmlspecialchars($currentUser['email']); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Telefono</label>
                                        <input type="tel" class="form-control" placeholder="+39 123 456 7890">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Posizione</label>
                                        <input type="text" class="form-control" placeholder="Manager">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Dipartimento</label>
                                        <select class="form-control">
                                            <option>IT</option>
                                            <option>Amministrazione</option>
                                            <option>Vendite</option>
                                            <option>Marketing</option>
                                            <option>Risorse Umane</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Bio</label>
                                    <textarea class="form-control" rows="4" placeholder="Scrivi qualcosa su di te..."></textarea>
                                </div>

                                <div style="display: flex; justify-content: flex-end; gap: var(--space-3)">
                                    <button type="button" class="btn btn--secondary">Annulla</button>
                                    <button type="submit" class="btn btn--primary">Salva Modifiche</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Security Tab -->
                    <div id="security-tab" class="tab-content">
                        <div class="profile-section">
                            <h3 class="section-title">Sicurezza Account</h3>

                            <div class="password-section">
                                <h4 style="margin-bottom: var(--space-2)">Modifica Password</h4>
                                <p style="color: var(--color-gray-600); font-size: var(--text-sm)">
                                    Assicurati di utilizzare una password sicura di almeno 8 caratteri
                                </p>
                            </div>

                            <form>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Password Attuale</label>
                                        <input type="password" class="form-control">
                                    </div>
                                    <div></div>
                                    <div class="form-group">
                                        <label class="form-label">Nuova Password</label>
                                        <input type="password" class="form-control">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Conferma Nuova Password</label>
                                        <input type="password" class="form-control">
                                    </div>
                                </div>

                                <div style="display: flex; justify-content: flex-end; gap: var(--space-3); margin-bottom: var(--space-8)">
                                    <button type="submit" class="btn btn--primary">Aggiorna Password</button>
                                </div>
                            </form>

                            <h3 class="section-title">Autenticazione a Due Fattori</h3>
                            <div class="notification-item">
                                <div class="notification-info">
                                    <div class="notification-title">Abilita 2FA</div>
                                    <div class="notification-desc">Aggiungi un ulteriore livello di sicurezza al tuo account</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <h3 class="section-title" style="margin-top: var(--space-8)">Sessioni Attive</h3>
                            <div class="activity-list">
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="icon icon--monitor"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">Windows 11 - Chrome</div>
                                        <div class="activity-time">192.168.1.105 • Sessione corrente</div>
                                    </div>
                                    <button class="btn btn--sm btn--secondary">Termina</button>
                                </div>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="icon icon--smartphone"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">iPhone 14 - Safari</div>
                                        <div class="activity-time">192.168.1.112 • Ultimo accesso: 2 ore fa</div>
                                    </div>
                                    <button class="btn btn--sm btn--secondary">Termina</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Notifications Tab -->
                    <div id="notifications-tab" class="tab-content">
                        <div class="profile-section">
                            <h3 class="section-title">Preferenze Notifiche</h3>

                            <h4 style="margin-bottom: var(--space-4); margin-top: var(--space-6)">Email</h4>
                            <div class="notification-item">
                                <div class="notification-info">
                                    <div class="notification-title">Nuovi Task</div>
                                    <div class="notification-desc">Ricevi email quando ti viene assegnato un nuovo task</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" checked>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <div class="notification-item">
                                <div class="notification-info">
                                    <div class="notification-title">Commenti</div>
                                    <div class="notification-desc">Notifiche per nuovi commenti sui tuoi task</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" checked>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <div class="notification-item">
                                <div class="notification-info">
                                    <div class="notification-title">Promemoria</div>
                                    <div class="notification-desc">Promemoria per scadenze e appuntamenti</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" checked>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <h4 style="margin-bottom: var(--space-4); margin-top: var(--space-6)">Push Notifications</h4>
                            <div class="notification-item">
                                <div class="notification-info">
                                    <div class="notification-title">Notifiche Desktop</div>
                                    <div class="notification-desc">Mostra notifiche sul desktop quando sei online</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <div class="notification-item">
                                <div class="notification-info">
                                    <div class="notification-title">Notifiche Mobile</div>
                                    <div class="notification-desc">Ricevi notifiche push sul tuo dispositivo mobile</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <div style="display: flex; justify-content: flex-end; gap: var(--space-3); margin-top: var(--space-6)">
                                <button class="btn btn--secondary">Ripristina Predefinite</button>
                                <button class="btn btn--primary">Salva Preferenze</button>
                            </div>
                        </div>
                    </div>

                    <!-- Activity Tab -->
                    <div id="activity-tab" class="tab-content">
                        <div class="profile-section">
                            <h3 class="section-title">Panoramica Attività</h3>

                            <div class="stats-grid">
                                <div class="stat-card">
                                    <div class="stat-value">42</div>
                                    <div class="stat-label">Task Completati</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-value">128</div>
                                    <div class="stat-label">File Caricati</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-value">15</div>
                                    <div class="stat-label">Progetti Attivi</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-value">89%</div>
                                    <div class="stat-label">Tasso Completamento</div>
                                </div>
                            </div>

                            <h3 class="section-title">Attività Recenti</h3>
                            <div class="activity-list">
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="icon icon--check"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">Completato task "Revisione Documento"</div>
                                        <div class="activity-time">10 minuti fa</div>
                                    </div>
                                </div>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="icon icon--upload"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">Caricato file "Report Q3 2024.pdf"</div>
                                        <div class="activity-time">2 ore fa</div>
                                    </div>
                                </div>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="icon icon--message"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">Commentato su "Progetto Marketing"</div>
                                        <div class="activity-time">5 ore fa</div>
                                    </div>
                                </div>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="icon icon--calendar"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">Creato evento "Meeting Team"</div>
                                        <div class="activity-time">Ieri alle 14:30</div>
                                    </div>
                                </div>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="icon icon--users"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">Aggiunto al team "Sviluppo Prodotto"</div>
                                        <div class="activity-time">3 giorni fa</div>
                                    </div>
                                </div>
                            </div>

                            <div style="text-align: center; margin-top: var(--space-6)">
                                <button class="btn btn--secondary">Carica Altre Attività</button>
                            </div>
                        </div>
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
            document.querySelectorAll('.profile-tab').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');

            // Add active class to clicked button
            event.target.closest('.profile-tab').classList.add('active');
        }

        // Initialize company filter if present
        <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'super_admin'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const companySelector = document.getElementById('company-filter');
            if (companySelector) {
                companySelector.addEventListener('change', function() {
                    // Handle company filter change
                    console.log('Company changed:', this.value);
                });
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>