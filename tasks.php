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

// Require active tenant access (super_admins bypass this check)
require_once __DIR__ . '/includes/tenant_access_check.php';
requireTenantAccess($currentUser['id'], $currentUser['role']);

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Task - CollaboraNexio</title>

    <?php require_once __DIR__ . '/includes/favicon.php'; ?>

    <!-- Main CSS -->
    <link rel="stylesheet" href="assets/css/styles.css">
    <!-- Sidebar Responsive Optimization CSS -->
    <link rel="stylesheet" href="assets/css/sidebar-responsive.css">

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

        /* Task specific styles */
        .tasks-filters {
            display: flex;
            gap: var(--space-2);
            margin-bottom: var(--space-6);
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: var(--space-2) var(--space-4);
            border: 1px solid var(--color-gray-300);
            background: var(--color-white);
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: var(--text-sm);
            transition: all var(--transition-fast);
        }

        .filter-btn:hover {
            background: var(--color-gray-50);
            border-color: var(--color-gray-400);
        }

        .filter-btn.active {
            background: var(--color-primary);
            color: var(--color-white);
            border-color: var(--color-primary);
        }

        .tasks-board {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--space-6);
        }

        .task-column {
            background: var(--color-gray-50);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
        }

        .column-header {
            font-weight: var(--font-semibold);
            color: var(--color-gray-900);
            margin-bottom: var(--space-4);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .task-count {
            background: var(--color-gray-200);
            color: var(--color-gray-600);
            padding: 2px var(--space-2);
            border-radius: var(--radius-full);
            font-size: var(--text-xs);
            font-weight: var(--font-medium);
        }

        .task-card {
            background: var(--color-white);
            border: 1px solid var(--color-gray-200);
            border-radius: var(--radius-md);
            padding: var(--space-4);
            margin-bottom: var(--space-3);
            cursor: move;
            transition: all var(--transition-fast);
        }

        .task-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .task-title {
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
            margin-bottom: var(--space-2);
            font-size: var(--text-sm);
        }

        .task-description {
            font-size: var(--text-xs);
            color: var(--color-gray-600);
            margin-bottom: var(--space-3);
            line-height: var(--leading-relaxed);
        }

        .task-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: var(--text-xs);
        }

        .task-priority {
            padding: 2px var(--space-2);
            border-radius: var(--radius-sm);
            font-weight: var(--font-medium);
        }

        .priority-high {
            background: #FEE2E2;
            color: var(--color-error);
        }

        .priority-medium {
            background: #FEF3C7;
            color: var(--color-warning);
        }

        .priority-low {
            background: #DBEAFE;
            color: var(--color-info);
        }

        .task-assignee {
            display: flex;
            align-items: center;
            gap: var(--space-1);
        }

        .assignee-avatar {
            width: 24px;
            height: 24px;
            border-radius: var(--radius-full);
            background: var(--color-primary);
            color: var(--color-white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: var(--font-semibold);
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
                    <a href="calendar.php" class="nav-item"><i class="icon icon--calendar"></i> Calendario</a>
                    <a href="tasks.php" class="nav-item active"><i class="icon icon--check"></i> Task</a>
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
                <h1 class="page-title">Gestione Task</h1>
                <button class="btn btn-success">+ Nuovo Task</button>
            </div>

            <div class="page-content">
                <!-- Filters -->
                <div class="tasks-filters">
                    <button class="filter-btn active">Tutti</button>
                    <button class="filter-btn">I Miei Task</button>
                    <button class="filter-btn">Alta Priorità</button>
                    <button class="filter-btn">Scadenza Oggi</button>
                    <button class="filter-btn">Completati</button>
                </div>

                <!-- Task Board -->
                <div class="tasks-board">
                    <!-- To Do Column -->
                    <div class="task-column">
                        <div class="column-header">
                            <span>Da Fare</span>
                            <span class="task-count">3</span>
                        </div>
                        <div class="task-card">
                            <div class="task-title">Preparare presentazione Q1</div>
                            <div class="task-description">Creare slides per la review trimestrale con i dati di vendita</div>
                            <div class="task-meta">
                                <span class="task-priority priority-high">Alta</span>
                                <div class="task-assignee">
                                    <span class="assignee-avatar">MR</span>
                                    <span>Mario Rossi</span>
                                </div>
                            </div>
                        </div>
                        <div class="task-card">
                            <div class="task-title">Aggiornare documentazione API</div>
                            <div class="task-description">Documentare i nuovi endpoint aggiunti nella versione 2.0</div>
                            <div class="task-meta">
                                <span class="task-priority priority-medium">Media</span>
                                <div class="task-assignee">
                                    <span class="assignee-avatar">LB</span>
                                    <span>Laura Bianchi</span>
                                </div>
                            </div>
                        </div>
                        <div class="task-card">
                            <div class="task-title">Fix bug login mobile</div>
                            <div class="task-description">Risolvere problema di autenticazione su dispositivi iOS</div>
                            <div class="task-meta">
                                <span class="task-priority priority-high">Alta</span>
                                <div class="task-assignee">
                                    <span class="assignee-avatar">GV</span>
                                    <span>Giuseppe Verdi</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- In Progress Column -->
                    <div class="task-column">
                        <div class="column-header">
                            <span>In Corso</span>
                            <span class="task-count">2</span>
                        </div>
                        <div class="task-card">
                            <div class="task-title">Implementare dashboard analytics</div>
                            <div class="task-description">Sviluppo dei grafici per il monitoraggio KPI</div>
                            <div class="task-meta">
                                <span class="task-priority priority-medium">Media</span>
                                <div class="task-assignee">
                                    <span class="assignee-avatar">AB</span>
                                    <span>Anna Bello</span>
                                </div>
                            </div>
                        </div>
                        <div class="task-card">
                            <div class="task-title">Test integrazione pagamenti</div>
                            <div class="task-description">Verificare il flusso completo con Stripe API</div>
                            <div class="task-meta">
                                <span class="task-priority priority-high">Alta</span>
                                <div class="task-assignee">
                                    <span class="assignee-avatar">MR</span>
                                    <span>Mario Rossi</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Review Column -->
                    <div class="task-column">
                        <div class="column-header">
                            <span>In Revisione</span>
                            <span class="task-count">1</span>
                        </div>
                        <div class="task-card">
                            <div class="task-title">Ottimizzazione query database</div>
                            <div class="task-description">Miglioramento performance delle query principali</div>
                            <div class="task-meta">
                                <span class="task-priority priority-low">Bassa</span>
                                <div class="task-assignee">
                                    <span class="assignee-avatar">RS</span>
                                    <span>Roberto Stella</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Done Column -->
                    <div class="task-column">
                        <div class="column-header">
                            <span>Completati</span>
                            <span class="task-count">4</span>
                        </div>
                        <div class="task-card" style="opacity: 0.7;">
                            <div class="task-title">Setup ambiente di staging</div>
                            <div class="task-description">Configurazione server di test</div>
                            <div class="task-meta">
                                <span class="task-priority priority-medium">Media</span>
                                <div class="task-assignee">
                                    <span class="assignee-avatar">LB</span>
                                    <span>Laura Bianchi</span>
                                </div>
                            </div>
                        </div>
                        <div class="task-card" style="opacity: 0.7;">
                            <div class="task-title">Migrazione dati utenti</div>
                            <div class="task-description">Trasferimento database al nuovo sistema</div>
                            <div class="task-meta">
                                <span class="task-priority priority-high">Alta</span>
                                <div class="task-assignee">
                                    <span class="assignee-avatar">GV</span>
                                    <span>Giuseppe Verdi</span>
                                </div>
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
        class TaskManager {
            constructor() {
                this.config = {
                    apiBase: '/api/',
                    draggedElement: null
                };
                this.state = {
                    tasks: [],
                    filter: 'all'
                };
                this.init();
            }

            init() {
                this.bindEvents();
                this.loadTasks();
                this.initDragAndDrop();
            }

            bindEvents() {
                // Filter buttons
                document.querySelectorAll('.filter-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        this.handleFilterClick(e.target);
                    });
                });

                // New task button
                document.querySelector('.btn-success').addEventListener('click', () => {
                    this.createNewTask();
                });

                // Task card clicks
                document.querySelectorAll('.task-card').forEach(card => {
                    card.addEventListener('click', (e) => {
                        this.handleTaskClick(e.currentTarget);
                    });
                });
            }

            initDragAndDrop() {
                const cards = document.querySelectorAll('.task-card');
                const columns = document.querySelectorAll('.task-column');

                cards.forEach(card => {
                    card.draggable = true;

                    card.addEventListener('dragstart', (e) => {
                        this.config.draggedElement = e.target;
                        e.target.style.opacity = '0.5';
                    });

                    card.addEventListener('dragend', (e) => {
                        e.target.style.opacity = '';
                        this.config.draggedElement = null;
                    });
                });

                columns.forEach(column => {
                    column.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        column.style.background = 'var(--color-gray-100)';
                    });

                    column.addEventListener('dragleave', (e) => {
                        column.style.background = '';
                    });

                    column.addEventListener('drop', (e) => {
                        e.preventDefault();
                        column.style.background = '';
                        if (this.config.draggedElement) {
                            const cardsContainer = column.querySelector('.task-card')?.parentElement || column;
                            cardsContainer.appendChild(this.config.draggedElement);
                            this.updateTaskCount(column);
                        }
                    });
                });
            }

            handleFilterClick(filterBtn) {
                document.querySelectorAll('.filter-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                filterBtn.classList.add('active');
                this.showToast(`Filtro: ${filterBtn.textContent}`, 'info');
            }

            handleTaskClick(taskCard) {
                const title = taskCard.querySelector('.task-title').textContent;
                this.showToast(`Dettagli task: ${title}`, 'info');
            }

            createNewTask() {
                this.showToast('Apertura form nuovo task', 'info');
            }

            updateTaskCount(column) {
                const count = column.querySelectorAll('.task-card').length;
                const countElement = column.querySelector('.task-count');
                if (countElement) {
                    countElement.textContent = count;
                }
            }

            async loadTasks() {
                // Load tasks from server
                console.log('Loading tasks');
            }

            showToast(message, type = 'info') {
                // Toast notification implementation
                console.log(`${type}: ${message}`);
            }
        }

        // Initialize when DOM is ready
        document.addEventListener('DOMContentLoaded', () => {
            window.taskManager = new TaskManager();
        });
    </script>
</body>
</html>