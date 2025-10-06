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

    <style>
        /* CSS Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --sidebar-bg: #111827;
            --sidebar-hover: #1F2937;
            --sidebar-text: #F3F4F6;
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 60px;

            --primary: #2563EB;
            --primary-hover: #1D4ED8;
            --success: #10B981;
            --warning: #F59E0B;
            --error: #EF4444;
            --info: #3B82F6;

            --bg-primary: #FFFFFF;
            --bg-secondary: #F9FAFB;
            --border-color: #E5E7EB;
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --text-tertiary: #9CA3AF;

            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);

            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;

            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--text-primary);
            background-color: var(--bg-secondary);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            height: 100vh;
            position: relative;
            overflow: hidden;
        }

        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            color: var(--sidebar-text);
            display: flex;
            flex-direction: column;
            transition: var(--transition);
            position: relative;
            z-index: 100;
        }

        .sidebar-header {
            padding: 1.25rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-icon {
            width: 32px;
            height: 32px;
            color: var(--primary);
            flex-shrink: 0;
        }

        .logo-text {
            font-size: 1.125rem;
            font-weight: 600;
            letter-spacing: -0.025em;
            white-space: nowrap;
        }

        .sidebar-toggle {
            width: 32px;
            height: 32px;
            border: none;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            color: var(--sidebar-text);
        }

        .sidebar-toggle:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .sidebar-toggle svg {
            width: 16px;
            height: 16px;
        }

        /* Navigation */
        .sidebar-nav {
            flex: 1;
            padding: 1rem 0;
            overflow-y: auto;
        }

        .nav-list {
            list-style: none;
        }

        .nav-item {
            margin: 0.25rem 0.75rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            text-decoration: none;
            color: var(--sidebar-text);
            border-radius: var(--radius-md);
            transition: var(--transition);
            position: relative;
        }

        .nav-link:hover {
            background: var(--sidebar-hover);
        }

        .nav-item.active .nav-link {
            background: var(--primary);
        }

        .nav-icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        .nav-text {
            font-size: 0.875rem;
            font-weight: 500;
            white-space: nowrap;
        }

        /* Sidebar Footer */
        .sidebar-footer {
            padding: 1rem 0.75rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .user-avatar.small {
            width: 32px;
            height: 32px;
            font-size: 0.75rem;
        }

        .user-avatar.tiny {
            width: 24px;
            height: 24px;
            font-size: 0.625rem;
        }

        .user-details {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            font-size: 0.875rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 0.75rem;
            color: rgba(243, 244, 246, 0.6);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .btn-logout {
            width: 32px;
            height: 32px;
            border: none;
            background: transparent;
            color: var(--sidebar-text);
            cursor: pointer;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .btn-logout:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .btn-logout svg {
            width: 18px;
            height: 18px;
        }

        /* Main Content Area */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: var(--bg-secondary);
        }

        /* Header */
        .header {
            background: var(--bg-primary);
            border-bottom: 1px solid var(--border-color);
            padding: 0 1.5rem;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-sm);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .mobile-menu-btn {
            display: none;
            width: 36px;
            height: 36px;
            border: none;
            background: transparent;
            cursor: pointer;
            border-radius: var(--radius-sm);
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .mobile-menu-btn:hover {
            background: var(--bg-secondary);
        }

        .mobile-menu-btn svg {
            width: 20px;
            height: 20px;
            color: var(--text-primary);
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
        }

        .breadcrumb-list {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            list-style: none;
        }

        .breadcrumb-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .breadcrumb-link:hover {
            color: var(--primary);
        }

        .breadcrumb-separator {
            color: var(--text-tertiary);
            font-size: 0.875rem;
        }

        .breadcrumb-item.active {
            color: var(--text-primary);
            font-weight: 500;
            font-size: 0.875rem;
        }

        /* Header Right */
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .tenant-display {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
        }

        .tenant-icon {
            width: 16px;
            height: 16px;
            color: var(--text-secondary);
        }

        .tenant-name {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .header-btn {
            width: 36px;
            height: 36px;
            border: none;
            background: transparent;
            cursor: pointer;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            position: relative;
        }

        .header-btn:hover {
            background: var(--bg-secondary);
        }

        .header-btn svg {
            width: 20px;
            height: 20px;
            color: var(--text-secondary);
        }

        .notification-badge {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 18px;
            height: 18px;
            background: var(--error);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.625rem;
            font-weight: 600;
            border: 2px solid var(--bg-primary);
        }

        /* User Dropdown */
        .user-dropdown {
            position: relative;
        }

        .user-dropdown-trigger {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem;
            border: none;
            background: transparent;
            cursor: pointer;
            border-radius: var(--radius-md);
            transition: var(--transition);
        }

        .user-dropdown-trigger:hover {
            background: var(--bg-secondary);
        }

        .user-dropdown-trigger svg {
            width: 16px;
            height: 16px;
            color: var(--text-secondary);
        }

        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 0.5rem;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            min-width: 200px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: var(--transition);
            z-index: 1000;
        }

        .dropdown-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .dropdown-user-name {
            font-weight: 600;
            color: var(--text-primary);
        }

        .dropdown-user-email {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.125rem;
        }

        .dropdown-divider {
            height: 1px;
            background: var(--border-color);
            margin: 0.25rem 0;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 1rem;
            text-decoration: none;
            color: var(--text-primary);
            cursor: pointer;
            transition: var(--transition);
            border: none;
            background: none;
            width: 100%;
            text-align: left;
        }

        .dropdown-item:hover {
            background: var(--bg-secondary);
        }

        .dropdown-item svg {
            width: 16px;
            height: 16px;
            color: var(--text-secondary);
        }

        .dropdown-item.logout-item {
            color: var(--error);
        }

        .dropdown-item.logout-item svg {
            color: var(--error);
        }

        /* Page Content */
        .page-content {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
        }

        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-title {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .page-description {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        /* Stat Cards */
        .stat-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-icon svg {
            width: 24px;
            height: 24px;
        }

        .stat-icon--primary {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary);
        }

        .stat-icon--success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon--warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon--info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .stat-content {
            flex: 1;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }

        .stat-value {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .stat-change {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
        }

        .stat-change.positive {
            color: var(--success);
        }

        .stat-change.negative {
            color: var(--error);
        }

        /* Content Panels */
        .content-panels {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.25rem;
        }

        .panel {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
        }

        .panel-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .panel-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .panel-action {
            padding: 0.375rem 0.75rem;
            background: transparent;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
        }

        .panel-action:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(37, 99, 235, 0.05);
        }

        .panel-body {
            padding: 1.25rem;
        }

        /* Activity List */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary);
            flex-shrink: 0;
        }

        .activity-icon.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .activity-icon.warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .activity-icon svg {
            width: 18px;
            height: 18px;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 0.125rem;
        }

        .activity-description {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .activity-time {
            font-size: 0.75rem;
            color: var(--text-tertiary);
            white-space: nowrap;
        }

        /* Project List */
        .project-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .project-item {
            padding: 1rem;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
        }

        .project-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }

        .project-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .project-badge {
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .project-badge.in-progress {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary);
        }

        .project-badge.planning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .project-badge.completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .project-progress {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .progress-bar {
            flex: 1;
            height: 6px;
            background: var(--border-color);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .progress-fill.completed {
            background: var(--success);
        }

        .progress-text {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        /* Mobile Overlay */
        .mobile-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 90;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .mobile-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Collapsed Sidebar */
        .dashboard-container.sidebar-collapsed .sidebar {
            width: var(--sidebar-collapsed-width);
        }

        .dashboard-container.sidebar-collapsed .logo-text,
        .dashboard-container.sidebar-collapsed .nav-text,
        .dashboard-container.sidebar-collapsed .user-details {
            display: none;
        }

        .dashboard-container.sidebar-collapsed .sidebar-toggle svg {
            transform: rotate(180deg);
        }

        /* Desktop Only */
        @media (max-width: 1024px) {
            .desktop-only {
                display: none;
            }
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: -100%;
                top: 0;
                height: 100vh;
                z-index: 101;
                transition: left 0.3s ease;
            }

            .sidebar.active {
                left: 0;
            }

            .mobile-menu-btn {
                display: flex;
            }

            .mobile-overlay {
                display: block;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .content-panels {
                grid-template-columns: 1fr;
            }

            .tenant-display {
                display: none;
            }

            .page-content {
                padding: 1rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .stat-card {
                padding: 1rem;
            }

            .stat-value {
                font-size: 1.5rem;
            }
        }

        /* Print Styles */
        @media print {
            .sidebar,
            .header,
            .mobile-overlay {
                display: none;
            }

            .main-content {
                margin: 0;
            }

            .page-content {
                padding: 0;
            }

            .panel {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }

        /* Animation for reduced motion */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* Loading State */
        @keyframes skeleton-loading {
            0% {
                background-position: -200% 0;
            }
            100% {
                background-position: 200% 0;
            }
        }

        .skeleton {
            background: linear-gradient(
                90deg,
                var(--bg-secondary) 25%,
                rgba(0, 0, 0, 0.05) 50%,
                var(--bg-secondary) 75%
            );
            background-size: 200% 100%;
            animation: skeleton-loading 1.5s infinite;
        }

        /* Toast Notifications */
        .toast-container {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .toast {
            padding: 1rem;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            min-width: 300px;
            max-width: 400px;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            opacity: 0;
            transform: translateX(100%);
            animation: toast-slide-in 0.3s forwards;
        }

        @keyframes toast-slide-in {
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .toast.success {
            border-left: 4px solid var(--success);
        }

        .toast.error {
            border-left: 4px solid var(--error);
        }

        .toast.warning {
            border-left: 4px solid var(--warning);
        }

        .toast.info {
            border-left: 4px solid var(--info);
        }

        /* Scrollbar Styles */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--bg-secondary);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--text-tertiary);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }
    </style>
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
                                <path d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
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
                                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span class="nav-text">Attività</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <svg class="nav-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
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
                        <span class="tenant-name"><?php echo htmlspecialchars($currentUser['tenant_name'] ?? 'Default Tenant'); ?></span>
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
                    <p class="page-description">Benvenuto nel tuo spazio di lavoro collaborativo</p>
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
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M12 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 0114 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">Nuovo membro aggiunto</div>
                                        <div class="activity-description">Andrea Bianchi si è unito al team</div>
                                    </div>
                                    <div class="activity-time">2 giorni fa</div>
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
                                <div class="project-item">
                                    <div class="project-header">
                                        <div class="project-title">Database Migration</div>
                                        <span class="project-badge in-progress">In corso</span>
                                    </div>
                                    <div class="project-progress">
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: 45%;"></div>
                                        </div>
                                        <span class="progress-text">45%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Toast container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Hidden CSRF token for AJAX requests -->
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">

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

            // Show toast notification function
            function showToast(message, type = 'info') {
                const toastContainer = document.getElementById('toastContainer');
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;

                const icon = document.createElement('div');
                icon.innerHTML = type === 'success' ?
                    '<svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>' :
                    type === 'error' ?
                    '<svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>' :
                    '<svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>';

                const content = document.createElement('div');
                content.textContent = message;

                toast.appendChild(icon);
                toast.appendChild(content);
                toastContainer.appendChild(toast);

                setTimeout(() => {
                    toast.style.opacity = '0';
                    setTimeout(() => toast.remove(), 300);
                }, 3000);
            }

            // Simulate real-time updates
            setInterval(() => {
                const notificationBadge = document.querySelector('.notification-badge');
                if (notificationBadge) {
                    const currentCount = parseInt(notificationBadge.textContent);
                    if (Math.random() > 0.8) {
                        notificationBadge.textContent = currentCount + 1;
                    }
                }
            }, 30000);
        });
    </script>
</body>
</html>