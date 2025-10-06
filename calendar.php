<?php
// Initialize session with proper configuration
require_once __DIR__ . '/includes/session_init.php';
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
    <title>Calendario - CollaboraNexio</title>

    <?php require_once __DIR__ . '/includes/favicon.php'; ?>

    <!-- Main CSS -->
    <link rel="stylesheet" href="assets/css/styles.css">
    <!-- Page specific CSS -->
    <link rel="stylesheet" href="assets/css/calendar.css">

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

        /* Calendar specific styles */
        .calendar-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-6);
        }

        .calendar-nav {
            display: flex;
            gap: var(--space-2);
            align-items: center;
        }

        .calendar-month {
            font-size: var(--text-xl);
            font-weight: var(--font-semibold);
            color: var(--color-gray-900);
            margin: 0 var(--space-4);
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: var(--color-gray-200);
            border: 1px solid var(--color-gray-200);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        .calendar-day-header {
            background: var(--color-gray-50);
            padding: var(--space-3);
            text-align: center;
            font-weight: var(--font-semibold);
            font-size: var(--text-sm);
            color: var(--color-gray-600);
            text-transform: uppercase;
        }

        .calendar-day {
            background: var(--color-white);
            padding: var(--space-3);
            min-height: 120px;
            position: relative;
            cursor: pointer;
            transition: background-color var(--transition-fast);
        }

        .calendar-day:hover {
            background: var(--color-gray-50);
        }

        .calendar-day-number {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-700);
            margin-bottom: var(--space-2);
        }

        .calendar-day.other-month .calendar-day-number {
            color: var(--color-gray-400);
        }

        .calendar-day.today {
            background: #EFF6FF;
        }

        .calendar-day.today .calendar-day-number {
            color: var(--color-primary);
            font-weight: var(--font-bold);
            display: inline-block;
            background: var(--color-white);
            width: 28px;
            height: 28px;
            line-height: 28px;
            text-align: center;
            border-radius: var(--radius-full);
            box-shadow: var(--shadow-sm);
        }

        .calendar-event {
            background: var(--color-primary);
            color: var(--color-white);
            padding: 2px var(--space-2);
            border-radius: var(--radius-sm);
            font-size: var(--text-xs);
            margin: 2px 0;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: all var(--transition-fast);
        }

        .calendar-event:hover {
            background: var(--color-primary-dark);
            transform: translateX(2px);
        }

        .calendar-event.type-meeting {
            background: var(--color-info);
        }

        .calendar-event.type-deadline {
            background: var(--color-error);
        }

        .calendar-event.type-review {
            background: var(--color-warning);
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
                    <a href="dashboard.php" class="nav-item"><i class="icon icon--home"></i> Dashboard</a>
                    <a href="files.php" class="nav-item"><i class="icon icon--folder"></i> File Manager</a>
                    <a href="calendar.php" class="nav-item active"><i class="icon icon--calendar"></i> Calendario</a>
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
                <h1 class="page-title">Calendario</h1>
                <button class="btn btn-primary">+ Nuovo Evento</button>
            </div>

            <div class="page-content">
                <div class="card">
                    <div class="card-body">
                        <!-- Calendar Controls -->
                        <div class="calendar-controls">
                            <div class="calendar-nav">
                                <button class="btn btn-secondary btn-sm" id="prevMonth">
                                    ← Precedente
                                </button>
                                <span class="calendar-month" id="currentMonth">Gennaio 2025</span>
                                <button class="btn btn-secondary btn-sm" id="nextMonth">
                                    Successivo →
                                </button>
                            </div>
                            <div class="flex gap-2">
                                <button class="btn btn-ghost btn-sm" id="todayBtn">Oggi</button>
                                <button class="btn btn-ghost btn-sm" id="viewToggle">Vista Mese</button>
                            </div>
                        </div>

                        <!-- Calendar Grid -->
                        <div class="calendar-grid">
                            <!-- Headers -->
                            <div class="calendar-day-header">Lun</div>
                            <div class="calendar-day-header">Mar</div>
                            <div class="calendar-day-header">Mer</div>
                            <div class="calendar-day-header">Gio</div>
                            <div class="calendar-day-header">Ven</div>
                            <div class="calendar-day-header">Sab</div>
                            <div class="calendar-day-header">Dom</div>

                            <!-- Calendar Days (sample for January 2025) -->
                            <div class="calendar-day other-month">
                                <div class="calendar-day-number">30</div>
                            </div>
                            <div class="calendar-day other-month">
                                <div class="calendar-day-number">31</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">1</div>
                                <div class="calendar-event type-meeting">Meeting Team</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">2</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">3</div>
                                <div class="calendar-event type-review">Review Progetto</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">4</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">5</div>
                            </div>

                            <!-- Week 2 -->
                            <div class="calendar-day">
                                <div class="calendar-day-number">6</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">7</div>
                                <div class="calendar-event">Presentazione</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">8</div>
                            </div>
                            <div class="calendar-day today">
                                <div class="calendar-day-number">9</div>
                                <div class="calendar-event type-deadline">Deadline Report</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">10</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">11</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">12</div>
                            </div>

                            <!-- Week 3 -->
                            <div class="calendar-day">
                                <div class="calendar-day-number">13</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">14</div>
                                <div class="calendar-event type-meeting">Call Cliente</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">15</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">16</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">17</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">18</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">19</div>
                            </div>

                            <!-- Week 4 -->
                            <div class="calendar-day">
                                <div class="calendar-day-number">20</div>
                                <div class="calendar-event">Sprint Review</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">21</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">22</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">23</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">24</div>
                                <div class="calendar-event type-deadline">Consegna Progetto</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">25</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">26</div>
                            </div>

                            <!-- Week 5 -->
                            <div class="calendar-day">
                                <div class="calendar-day-number">27</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">28</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">29</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">30</div>
                            </div>
                            <div class="calendar-day">
                                <div class="calendar-day-number">31</div>
                            </div>
                            <div class="calendar-day other-month">
                                <div class="calendar-day-number">1</div>
                            </div>
                            <div class="calendar-day other-month">
                                <div class="calendar-day-number">2</div>
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
        class Calendar {
            constructor() {
                this.config = {
                    apiBase: '/api/',
                    currentMonth: new Date().getMonth(),
                    currentYear: new Date().getFullYear()
                };
                this.state = {
                    events: [],
                    selectedDate: null
                };
                this.init();
            }

            init() {
                this.bindEvents();
                this.loadCalendarData();
            }

            bindEvents() {
                // Month navigation
                document.getElementById('prevMonth').addEventListener('click', () => {
                    this.navigateMonth(-1);
                });

                document.getElementById('nextMonth').addEventListener('click', () => {
                    this.navigateMonth(1);
                });

                document.getElementById('todayBtn').addEventListener('click', () => {
                    this.goToToday();
                });

                // Event handlers
                document.querySelectorAll('.calendar-event').forEach(event => {
                    event.addEventListener('click', (e) => {
                        e.stopPropagation();
                        this.handleEventClick(e.target);
                    });
                });

                // Day click handlers
                document.querySelectorAll('.calendar-day').forEach(day => {
                    day.addEventListener('click', (e) => {
                        this.handleDayClick(e.currentTarget);
                    });
                });

                // New event button
                document.querySelector('.btn-primary').addEventListener('click', () => {
                    this.createNewEvent();
                });
            }

            navigateMonth(direction) {
                this.config.currentMonth += direction;
                if (this.config.currentMonth > 11) {
                    this.config.currentMonth = 0;
                    this.config.currentYear++;
                } else if (this.config.currentMonth < 0) {
                    this.config.currentMonth = 11;
                    this.config.currentYear--;
                }
                this.updateCalendarView();
            }

            goToToday() {
                const today = new Date();
                this.config.currentMonth = today.getMonth();
                this.config.currentYear = today.getFullYear();
                this.updateCalendarView();
            }

            updateCalendarView() {
                const months = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
                               'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
                document.getElementById('currentMonth').textContent =
                    `${months[this.config.currentMonth]} ${this.config.currentYear}`;
                // Here you would typically regenerate the calendar grid
                console.log('Updating calendar view for', this.config.currentMonth, this.config.currentYear);
            }

            handleEventClick(eventElement) {
                this.showToast(`Evento: ${eventElement.textContent}`, 'info');
            }

            handleDayClick(dayElement) {
                const dayNumber = dayElement.querySelector('.calendar-day-number').textContent;
                this.showToast(`Giorno selezionato: ${dayNumber}`, 'info');
            }

            createNewEvent() {
                this.showToast('Apertura form nuovo evento', 'info');
            }

            async loadCalendarData() {
                // Load calendar events from server
                console.log('Loading calendar data');
            }

            showToast(message, type = 'info') {
                // Toast notification implementation
                console.log(`${type}: ${message}`);
            }
        }

        // Initialize when DOM is ready
        document.addEventListener('DOMContentLoaded', () => {
            window.calendar = new Calendar();
        });
    </script>
</body>
</html>