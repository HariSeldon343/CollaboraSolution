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
    <title>Dashboard - CollaboraNexio</title>

    <?php require_once __DIR__ . '/includes/favicon.php'; ?>

    <!-- Main CSS -->
    <link rel="stylesheet" href="assets/css/styles.css">
    <!-- Page specific CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css">

    <style>
        /* Additional dashboard specific styles */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: var(--space-6);
            margin-bottom: var(--space-8);
        }

        .stat-card {
            background: var(--color-white);
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            transition: box-shadow var(--transition-fast);
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
        }

        .stat-label {
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            color: var(--color-gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: var(--space-2);
        }

        .stat-value {
            font-size: var(--text-3xl);
            font-weight: var(--font-bold);
            color: var(--color-gray-900);
            line-height: 1.2;
        }

        .stat-change {
            font-size: var(--text-sm);
            color: var(--color-gray-600);
            margin-top: var(--space-2);
        }

        .stat-change.positive {
            color: var(--color-success);
        }

        .stat-change.negative {
            color: var(--color-error);
        }

        /* Additional sidebar styles */
        .nav-section {
            margin-bottom: var(--space-6);
        }

        .nav-section-title {
            padding: var(--space-2) var(--space-4);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--color-sidebar-text-muted);
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            padding: var(--space-3) var(--space-4);
            color: var(--color-sidebar-text);
            text-decoration: none;
            transition: all var(--transition-fast);
            position: relative;
            font-size: var(--text-sm);
        }

        .nav-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .nav-item.active {
            background-color: rgba(255, 255, 255, 0.15);
        }

        .nav-item.active::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background-color: var(--color-sidebar-active);
        }

        .icon {
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-style: normal;
            color: var(--color-sidebar-text);
            position: relative;
        }

        /* Logo image style */
        .logo-img {
            width: 32px;
            height: 32px;
            background: white;
            padding: 4px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* White icon styles using CSS */
        .icon::before {
            content: '';
            display: block;
            width: 18px;
            height: 18px;
            background-color: currentColor;
            mask-size: contain;
            mask-repeat: no-repeat;
            mask-position: center;
            -webkit-mask-size: contain;
            -webkit-mask-repeat: no-repeat;
            -webkit-mask-position: center;
        }

        /* Individual icon masks */
        .icon--home::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'/%3E%3Cpolyline points='9 22 9 12 15 12 15 22'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'/%3E%3Cpolyline points='9 22 9 12 15 12 15 22'/%3E%3C/svg%3E");
        }

        .icon--folder::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z'/%3E%3C/svg%3E");
        }

        .icon--calendar::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Crect x='3' y='4' width='18' height='18' rx='2'/%3E%3Cline x1='16' y1='2' x2='16' y2='6'/%3E%3Cline x1='8' y1='2' x2='8' y2='6'/%3E%3Cline x1='3' y1='10' x2='21' y2='10'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Crect x='3' y='4' width='18' height='18' rx='2'/%3E%3Cline x1='16' y1='2' x2='16' y2='6'/%3E%3Cline x1='8' y1='2' x2='8' y2='6'/%3E%3Cline x1='3' y1='10' x2='21' y2='10'/%3E%3C/svg%3E");
        }

        .icon--check::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpolyline points='9 11 12 14 22 4'/%3E%3Cpath d='M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpolyline points='9 11 12 14 22 4'/%3E%3Cpath d='M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'/%3E%3C/svg%3E");
        }

        .icon--ticket::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z'/%3E%3Cpath d='M13 5v2'/%3E%3Cpath d='M13 17v2'/%3E%3Cpath d='M13 11v2'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z'/%3E%3Cpath d='M13 5v2'/%3E%3Cpath d='M13 17v2'/%3E%3Cpath d='M13 11v2'/%3E%3C/svg%3E");
        }

        .icon--shield::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10'/%3E%3Cpath d='m9 12 2 2 4-4'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10'/%3E%3Cpath d='m9 12 2 2 4-4'/%3E%3C/svg%3E");
        }

        .icon--cpu::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Crect x='4' y='4' width='16' height='16' rx='2'/%3E%3Crect x='9' y='9' width='6' height='6'/%3E%3Cline x1='9' y1='1' x2='9' y2='4'/%3E%3Cline x1='15' y1='1' x2='15' y2='4'/%3E%3Cline x1='9' y1='20' x2='9' y2='23'/%3E%3Cline x1='15' y1='20' x2='15' y2='23'/%3E%3Cline x1='20' y1='9' x2='23' y2='9'/%3E%3Cline x1='20' y1='14' x2='23' y2='14'/%3E%3Cline x1='1' y1='9' x2='4' y2='9'/%3E%3Cline x1='1' y1='14' x2='4' y2='14'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Crect x='4' y='4' width='16' height='16' rx='2'/%3E%3Crect x='9' y='9' width='6' height='6'/%3E%3Cline x1='9' y1='1' x2='9' y2='4'/%3E%3Cline x1='15' y1='1' x2='15' y2='4'/%3E%3Cline x1='9' y1='20' x2='9' y2='23'/%3E%3Cline x1='15' y1='20' x2='15' y2='23'/%3E%3Cline x1='20' y1='9' x2='23' y2='9'/%3E%3Cline x1='20' y1='14' x2='23' y2='14'/%3E%3Cline x1='1' y1='9' x2='4' y2='9'/%3E%3Cline x1='1' y1='14' x2='4' y2='14'/%3E%3C/svg%3E");
        }

        .icon--building::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z'/%3E%3Cpath d='M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2'/%3E%3Cpath d='M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2'/%3E%3Cpath d='M10 6h4'/%3E%3Cpath d='M10 10h4'/%3E%3Cpath d='M10 14h4'/%3E%3Cpath d='M10 18h4'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z'/%3E%3Cpath d='M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2'/%3E%3Cpath d='M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2'/%3E%3Cpath d='M10 6h4'/%3E%3Cpath d='M10 10h4'/%3E%3Cpath d='M10 14h4'/%3E%3Cpath d='M10 18h4'/%3E%3C/svg%3E");
        }

        .icon--users::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2'/%3E%3Ccircle cx='9' cy='7' r='4'/%3E%3Cpath d='M22 21v-2a4 4 0 0 0-3-3.87'/%3E%3Cpath d='M16 3.13a4 4 0 0 1 0 7.75'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2'/%3E%3Ccircle cx='9' cy='7' r='4'/%3E%3Cpath d='M22 21v-2a4 4 0 0 0-3-3.87'/%3E%3Cpath d='M16 3.13a4 4 0 0 1 0 7.75'/%3E%3C/svg%3E");
        }

        .icon--chart::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M3 3v18h18'/%3E%3Cpath d='m19 9-5 5-4-4-3 3'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M3 3v18h18'/%3E%3Cpath d='m19 9-5 5-4-4-3 3'/%3E%3C/svg%3E");
        }

        .icon--settings::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Ccircle cx='12' cy='12' r='3'/%3E%3Cpath d='M12 1v6m0 6v6m4.22-13.22l4.24 4.24M1.54 13.54l4.24 4.24M6.34 6.34L2.1 2.1m13.8 13.8l4.24 4.24'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Ccircle cx='12' cy='12' r='3'/%3E%3Cpath d='M12 1v6m0 6v6m4.22-13.22l4.24 4.24M1.54 13.54l4.24 4.24M6.34 6.34L2.1 2.1m13.8 13.8l4.24 4.24'/%3E%3C/svg%3E");
        }

        .icon--user::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2'/%3E%3Ccircle cx='12' cy='7' r='4'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2'/%3E%3Ccircle cx='12' cy='7' r='4'/%3E%3C/svg%3E");
        }

        .icon--logout::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4'/%3E%3Cpolyline points='16 17 21 12 16 7'/%3E%3Cline x1='21' y1='12' x2='9' y2='12'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2'%3E%3Cpath d='M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4'/%3E%3Cpolyline points='16 17 21 12 16 7'/%3E%3Cline x1='21' y1='12' x2='9' y2='12'/%3E%3C/svg%3E");
        }

        .logo-icon {
            font-size: var(--text-2xl);
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--color-primary);
            color: var(--color-white);
            border-radius: var(--radius-md);
            font-weight: var(--font-bold);
        }

        .logo-text {
            font-size: var(--text-xl);
            font-weight: var(--font-bold);
        }

        .sidebar-subtitle {
            font-size: var(--text-xs);
            color: var(--color-sidebar-text-muted);
            margin-top: var(--space-1);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            padding: var(--space-3);
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-lg);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--color-sidebar-active);
            color: var(--color-white);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
        }

        .user-details {
            flex: 1;
        }

        .user-name {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-sidebar-text);
        }

        .user-badge {
            font-size: 10px;
            color: var(--color-white);
            background: var(--color-primary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 4px;
            padding: 2px 6px;
            border-radius: var(--radius-sm);
            display: inline-block;
            font-weight: var(--font-semibold);
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
                    <a href="dashboard.php" class="nav-item active"><i class="icon icon--home"></i> Dashboard</a>
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
                    <a href="profilo.php" class="nav-item"><i class="icon icon--user"></i> Il Mio Profilo</a>
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
            <div class="header">
                <h1 class="page-title">Dashboard</h1>
                <div class="flex items-center gap-4">
                    <?php if ($companyFilter->canUseCompanyFilter()): ?>
                        <?php echo $companyFilter->renderDropdown(); ?>
                    <?php endif; ?>
                    <span class="text-sm text-muted">Benvenuto, <?php echo htmlspecialchars($currentUser['name']); ?></span>
                </div>
            </div>

            <div class="page-content">
                <!-- Stats Grid -->
                <div class="dashboard-grid">
                    <div class="stat-card">
                        <div class="stat-label">Progetti Attivi</div>
                        <div class="stat-value">12</div>
                        <div class="stat-change positive">+2.5% dal mese scorso</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Task Completati</div>
                        <div class="stat-value">48</div>
                        <div class="stat-change positive">+12% questa settimana</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">In Scadenza</div>
                        <div class="stat-value">7</div>
                        <div class="stat-change negative">3 urgenti</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Membri del Team</div>
                        <div class="stat-value">24</div>
                        <div class="stat-change">6 online ora</div>
                    </div>
                </div>

                <!-- Content Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Recent Activity -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Attività Recente</h2>
                        </div>
                        <div class="card-body">
                            <ul class="simple-list">
                                <li class="list-item">
                                    <div class="list-icon"></div>
                                    <div class="list-content">
                                        <div class="list-title">Nuovo progetto creato</div>
                                        <div class="list-description">Marketing Q1 2024</div>
                                    </div>
                                    <div class="list-time text-xs text-muted">2h fa</div>
                                </li>
                                <li class="list-item">
                                    <div class="list-icon bg-success"></div>
                                    <div class="list-content">
                                        <div class="list-title">Task completato</div>
                                        <div class="list-description">Revisione contratto</div>
                                    </div>
                                    <div class="list-time text-xs text-muted">5h fa</div>
                                </li>
                                <li class="list-item">
                                    <div class="list-icon bg-warning"></div>
                                    <div class="list-content">
                                        <div class="list-title">Scadenza imminente</div>
                                        <div class="list-description">Report mensile - domani</div>
                                    </div>
                                    <div class="list-time text-xs text-muted">Ieri</div>
                                </li>
                                <li class="list-item">
                                    <div class="list-icon"></div>
                                    <div class="list-content">
                                        <div class="list-title">Nuovo membro aggiunto</div>
                                        <div class="list-description">Andrea Bianchi si è unito</div>
                                    </div>
                                    <div class="list-time text-xs text-muted">2 giorni fa</div>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Projects Progress -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Progetti Attivi</h2>
                        </div>
                        <div class="card-body">
                            <div class="progress-item">
                                <div class="progress-header">
                                    <span class="progress-title">Redesign Sito Web</span>
                                    <span class="badge badge-blue">In Corso</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: 65%;"></div>
                                </div>
                                <div class="text-xs text-muted mt-2">65% Completo</div>
                            </div>
                            <div class="progress-item">
                                <div class="progress-header">
                                    <span class="progress-title">App Mobile</span>
                                    <span class="badge badge-yellow">Pianificazione</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: 20%;"></div>
                                </div>
                                <div class="text-xs text-muted mt-2">20% Completo</div>
                            </div>
                            <div class="progress-item">
                                <div class="progress-header">
                                    <span class="progress-title">Integrazione API</span>
                                    <span class="badge badge-green">Completato</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill bg-success" style="width: 100%;"></div>
                                </div>
                                <div class="text-xs text-muted mt-2">100% Completo</div>
                            </div>
                            <div class="progress-item">
                                <div class="progress-header">
                                    <span class="progress-title">Migrazione Database</span>
                                    <span class="badge badge-blue">In Corso</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: 45%;"></div>
                                </div>
                                <div class="text-xs text-muted mt-2">45% Completo</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden CSRF token -->
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">

    <script>
        class Dashboard {
            constructor() {
                this.config = {
                    apiBase: '/api/',
                    pollInterval: 30000
                };
                this.state = {};
                this.init();
            }

            init() {
                this.bindEvents();
                this.loadInitialData();
            }

            bindEvents() {
                // Mobile sidebar toggle
                const sidebarToggle = document.getElementById('sidebarToggle');
                if (sidebarToggle) {
                    sidebarToggle.addEventListener('click', () => {
                        document.querySelector('.sidebar').classList.toggle('open');
                    });
                }
            }

            async loadInitialData() {
                // Load dashboard data here
                console.log('Dashboard initialized');
            }

            showToast(message, type = 'info') {
                // Toast notification implementation
                console.log(`${type}: ${message}`);
            }
        }

        // Initialize when DOM is ready
        document.addEventListener('DOMContentLoaded', () => {
            window.dashboard = new Dashboard();
        });
    </script>

    <style>
        /* Additional responsive styles */
        .list-item {
            display: flex;
            align-items: flex-start;
            gap: var(--space-3);
            padding: var(--space-3) 0;
            border-bottom: 1px solid var(--color-gray-200);
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .list-icon {
            width: 8px;
            height: 8px;
            background: var(--color-primary);
            border-radius: var(--radius-full);
            margin-top: 6px;
            flex-shrink: 0;
        }

        .list-content {
            flex: 1;
        }

        .list-title {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
            margin-bottom: 2px;
        }

        .list-description {
            font-size: var(--text-xs);
            color: var(--color-gray-600);
        }

        .progress-item {
            padding: var(--space-4) 0;
            border-bottom: 1px solid var(--color-gray-200);
        }

        .progress-item:last-child {
            border-bottom: none;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-2);
        }

        .progress-title {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
        }

        .progress-bar {
            height: 4px;
            background: var(--color-gray-200);
            border-radius: var(--radius-full);
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--color-primary);
            border-radius: var(--radius-full);
            transition: width var(--transition-base);
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: var(--text-xs);
            font-weight: var(--font-medium);
            border-radius: var(--radius-sm);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .badge-blue {
            background: #EFF6FF;
            color: var(--color-primary);
        }

        .badge-green {
            background: #F0FDF4;
            color: var(--color-success);
        }

        .badge-yellow {
            background: #FFFBEB;
            color: var(--color-warning);
        }
    </style>
</body>
</html>