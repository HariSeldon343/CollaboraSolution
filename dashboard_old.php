<?php
session_start();
// Authentication check - redirect to login if not authenticated
require_once __DIR__ . '/includes/auth_simple.php';
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

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Dashboard - CollaboraNexio</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
    <!-- Mobile menu overlay -->
    <div class="mobile-overlay" id="mobileOverlay"></div>

    <!-- Main container -->
    <div class="dashboard-container">

        <!-- Left Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <svg class="logo-icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M24 4L4 14V34L24 44L44 34V14L24 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M24 44V24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M44 14L24 24L4 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M14 19L24 24L34 19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="logo-text">CollaboraNexio</span>
                </div>
                <button class="sidebar-toggle desktop-only" id="sidebarToggle" aria-label="Toggle sidebar">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M11 7L7 10L11 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>

            <nav class="sidebar-nav">
                <ul class="nav-list">
                    <li class="nav-item active">
                        <a href="dashboard.php" class="nav-link">
                            <svg class="nav-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="files.php" class="nav-link">
                            <svg class="nav-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span class="nav-text">File</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="chat.php" class="nav-link">
                            <svg class="nav-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span class="nav-text">Chat</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="calendar.php" class="nav-link">
                            <svg class="nav-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span class="nav-text">Calendario</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="tasks.php" class="nav-link">
                            <svg class="nav-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z" fill="currentColor"/>
                                <path fill-rule="evenodd" d="M4 5a2 2 0 012-2 1 1 0 000 2H4v10h12V5h-2a1 1 0 100-2 2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 011-1h.01a1 1 0 110 2H8a1 1 0 01-1-1zm0 3a1 1 0 011-1h.01a1 1 0 110 2H8a1 1 0 01-1-1zm0 3a1 1 0 011-1h.01a1 1 0 110 2H8a1 1 0 01-1-1zm3-6a1 1 0 011-1h3a1 1 0 110 2h-3a1 1 0 01-1-1zm0 3a1 1 0 011-1h3a1 1 0 110 2h-3a1 1 0 01-1-1zm0 3a1 1 0 011-1h3a1 1 0 110 2h-3a1 1 0 01-1-1z" clip-rule="evenodd" fill="currentColor"/>
                            </svg>
                            <span class="nav-text">Attività</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <svg class="nav-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M8 12h.01M12 12h.01M16 12h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span class="nav-text">Altro</span>
                        </a>
                    </li>
                </ul>
            </nav>

            <div class="sidebar-footer">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($currentUser['name'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($currentUser['name']); ?></div>
                        <div class="user-role"><?php echo htmlspecialchars($currentUser['role']); ?></div>
                    </div>
                </div>
                <button class="btn-logout" id="logoutBtn" aria-label="Logout">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M11 16l4-4-4-4m4 4H3m10-8h4a2 2 0 012 2v8a2 2 0 01-2 2h-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            <!-- Top Header -->
            <header class="header">
                <div class="header-left">
                    <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3 7h14M3 12h14M3 17h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <nav class="breadcrumb" aria-label="Breadcrumb">
                        <ol class="breadcrumb-list">
                            <li class="breadcrumb-item">
                                <a href="#" class="breadcrumb-link">Home</a>
                            </li>
                            <li class="breadcrumb-separator">/</li>
                            <li class="breadcrumb-item active">Dashboard</li>
                        </ol>
                    </nav>
                </div>

                <div class="header-right">
                    <div class="tenant-display">
                        <svg class="tenant-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span class="tenant-name"><?php echo htmlspecialchars($currentUser['tenant_name']); ?></span>
                        <?php if (isset($currentUser['is_multi_tenant']) && $currentUser['is_multi_tenant']): ?>
                        <button class="tenant-switch" id="tenantSwitch" aria-label="Switch tenant">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <?php endif; ?>
                    </div>

                    <button class="header-btn notifications-btn" id="notificationsBtn" aria-label="Notifications">
                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="currentColor"/>
                        </svg>
                        <span class="notification-badge">3</span>
                    </button>

                    <div class="user-dropdown" id="userDropdown">
                        <button class="user-dropdown-trigger" id="userDropdownTrigger" aria-label="User menu">
                            <div class="user-avatar small">
                                <?php echo strtoupper(substr($currentUser['name'], 0, 1)); ?>
                            </div>
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <div class="dropdown-menu" id="userDropdownMenu">
                            <div class="dropdown-header">
                                <div class="dropdown-user-name"><?php echo htmlspecialchars($currentUser['name']); ?></div>
                                <div class="dropdown-user-email"><?php echo htmlspecialchars($currentUser['email']); ?></div>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a href="#" class="dropdown-item">
                                <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" fill="currentColor"/>
                                </svg>
                                Profilo
                            </a>
                            <a href="#" class="dropdown-item">
                                <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" fill="currentColor"/>
                                </svg>
                                Impostazioni
                            </a>
                            <div class="dropdown-divider"></div>
                            <button class="dropdown-item logout-item" id="dropdownLogoutBtn">
                                <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M11 16l4-4-4-4m4 4H3m10-8h4a2 2 0 012 2v8a2 2 0 01-2 2h-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Logout
                            </button>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title">Dashboard</h1>
                    <p class="page-description">Benvenuto nel tuo spazio di lavoro</p>
                </div>

                <!-- Dashboard Grid -->
                <div class="dashboard-grid">
                    <!-- Stats Cards -->
                    <div class="stat-card">
                        <div class="stat-icon stat-icon--primary">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-label">Progetti Attivi</div>
                            <div class="stat-value">12</div>
                            <div class="stat-change positive">+2.5% dal mese scorso</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon stat-icon--success">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-label">Attività Completate</div>
                            <div class="stat-value">48</div>
                            <div class="stat-change positive">+12% questa settimana</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon stat-icon--warning">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-label">In Scadenza</div>
                            <div class="stat-value">7</div>
                            <div class="stat-change negative">3 urgenti</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon stat-icon--info">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-label">Membri del Team</div>
                            <div class="stat-value">24</div>
                            <div class="stat-change">6 online ora</div>
                        </div>
                    </div>
                </div>

                <!-- Content Panels -->
                <div class="content-panels">
                    <div class="panel">
                        <div class="panel-header">
                            <h2 class="panel-title">Attività Recenti</h2>
                            <button class="panel-action">Vedi tutte</button>
                        </div>
                        <div class="panel-body">
                            <div class="activity-list">
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z" fill="currentColor"/>
                                            <path fill-rule="evenodd" d="M4 5a2 2 0 012-2 1 1 0 000 2H4v10h12V5h-2a1 1 0 100-2 2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V5z" clip-rule="evenodd" fill="currentColor"/>
                                        </svg>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">Nuovo progetto creato</div>
                                        <div class="activity-description">Progetto Marketing Q1 2024</div>
                                    </div>
                                    <div class="activity-time">2 ore fa</div>
                                </div>
                                <div class="activity-item">
                                    <div class="activity-icon success">
                                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">Attività completata</div>
                                        <div class="activity-description">Revisione documento contrattuale</div>
                                    </div>
                                    <div class="activity-time">5 ore fa</div>
                                </div>
                                <div class="activity-item">
                                    <div class="activity-icon warning">
                                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">Scadenza imminente</div>
                                        <div class="activity-description">Report mensile - scade domani</div>
                                    </div>
                                    <div class="activity-time">Ieri</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header">
                            <h2 class="panel-title">Progetti in Evidenza</h2>
                            <button class="panel-action">Gestisci</button>
                        </div>
                        <div class="panel-body">
                            <div class="project-list">
                                <div class="project-item">
                                    <div class="project-header">
                                        <div class="project-title">Website Redesign</div>
                                        <span class="project-badge in-progress">In corso</span>
                                    </div>
                                    <div class="project-progress">
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: 65%;"></div>
                                        </div>
                                        <span class="progress-text">65%</span>
                                    </div>
                                </div>
                                <div class="project-item">
                                    <div class="project-header">
                                        <div class="project-title">App Mobile</div>
                                        <span class="project-badge planning">Pianificazione</span>
                                    </div>
                                    <div class="project-progress">
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: 20%;"></div>
                                        </div>
                                        <span class="progress-text">20%</span>
                                    </div>
                                </div>
                                <div class="project-item">
                                    <div class="project-header">
                                        <div class="project-title">API Integration</div>
                                        <span class="project-badge completed">Completato</span>
                                    </div>
                                    <div class="project-progress">
                                        <div class="progress-bar">
                                            <div class="progress-fill completed" style="width: 100%;"></div>
                                        </div>
                                        <span class="progress-text">100%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Right Info Panel (Optional/Hideable) -->
        <aside class="info-panel" id="infoPanel">
            <div class="info-panel-header">
                <h3 class="info-panel-title">Informazioni</h3>
                <button class="info-panel-close" id="infoPanelClose" aria-label="Close panel">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
            <div class="info-panel-body">
                <div class="info-section">
                    <h4 class="info-section-title">Prossimi Eventi</h4>
                    <ul class="event-list">
                        <li class="event-item">
                            <div class="event-time">10:00</div>
                            <div class="event-title">Meeting Team</div>
                        </li>
                        <li class="event-item">
                            <div class="event-time">14:30</div>
                            <div class="event-title">Review Progetto</div>
                        </li>
                        <li class="event-item">
                            <div class="event-time">16:00</div>
                            <div class="event-title">Call Cliente</div>
                        </li>
                    </ul>
                </div>

                <div class="info-section">
                    <h4 class="info-section-title">Team Online</h4>
                    <div class="online-users">
                        <div class="online-user">
                            <div class="user-avatar tiny online">MR</div>
                            <span>Mario Rossi</span>
                        </div>
                        <div class="online-user">
                            <div class="user-avatar tiny online">LB</div>
                            <span>Laura Bianchi</span>
                        </div>
                        <div class="online-user">
                            <div class="user-avatar tiny away">GV</div>
                            <span>Giuseppe Verdi</span>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <!-- Toast container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Hidden CSRF token for AJAX requests -->
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">

    <script src="assets/js/dashboard.js"></script>
    <script>
        // Basic navigation and logout functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Handle logout button click
            const logoutBtn = document.getElementById('logoutBtn');
            const dropdownLogoutBtn = document.getElementById('dropdownLogoutBtn');

            function handleLogout() {
                if (confirm('Sei sicuro di voler uscire?')) {
                    fetch('auth_api.php?action=logout', {
                        method: 'GET',
                        credentials: 'same-origin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            window.location.href = 'index.php';
                        }
                    })
                    .catch(error => {
                        console.error('Logout error:', error);
                        // Fallback: redirect anyway
                        window.location.href = 'index.php';
                    });
                }
            }

            if (logoutBtn) {
                logoutBtn.addEventListener('click', handleLogout);
            }

            if (dropdownLogoutBtn) {
                dropdownLogoutBtn.addEventListener('click', handleLogout);
            }

            // Handle mobile menu toggle
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const sidebar = document.getElementById('sidebar');
            const mobileOverlay = document.getElementById('mobileOverlay');

            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('active');
                    mobileOverlay.classList.toggle('active');
                });
            }

            if (mobileOverlay) {
                mobileOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    mobileOverlay.classList.remove('active');
                });
            }

            // Handle sidebar toggle for desktop
            const sidebarToggle = document.getElementById('sidebarToggle');
            const dashboardContainer = document.querySelector('.dashboard-container');

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    dashboardContainer.classList.toggle('sidebar-collapsed');
                });
            }

            // Handle user dropdown
            const userDropdownTrigger = document.getElementById('userDropdownTrigger');
            const userDropdownMenu = document.getElementById('userDropdownMenu');

            if (userDropdownTrigger && userDropdownMenu) {
                userDropdownTrigger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userDropdownMenu.classList.toggle('show');
                });

                // Close dropdown when clicking outside
                document.addEventListener('click', function(e) {
                    if (!userDropdownMenu.contains(e.target) && !userDropdownTrigger.contains(e.target)) {
                        userDropdownMenu.classList.remove('show');
                    }
                });
            }

            // Highlight current page in navigation
            const currentPath = window.location.pathname;
            const navLinks = document.querySelectorAll('.nav-link');

            navLinks.forEach(link => {
                const href = link.getAttribute('href');
                const linkPath = href ? href.replace(/^.*\//, '') : '';
                const pagePath = currentPath.replace(/^.*\//, '');

                if (linkPath === pagePath) {
                    link.parentElement.classList.add('active');
                } else {
                    link.parentElement.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>