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

// Track page access for audit logging
require_once __DIR__ . '/includes/audit_page_access.php';
trackPageAccess('tasks');

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

        /* Modal Styles */
        .task-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(2px);
        }

        .modal-dialog {
            position: relative;
            background: var(--color-white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-2xl);
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            z-index: 1001;
            animation: modalSlideIn 0.3s ease-out;
        }

        .modal-dialog.modal-sm {
            max-width: 400px;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            padding: var(--space-6);
            border-bottom: 1px solid var(--color-gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-size: var(--text-xl);
            font-weight: var(--font-semibold);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            line-height: 1;
            color: var(--color-gray-500);
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
        }

        .modal-close:hover {
            background: var(--color-gray-100);
            color: var(--color-gray-900);
        }

        .modal-body {
            padding: var(--space-6);
        }

        .modal-footer {
            padding: var(--space-6);
            border-top: 1px solid var(--color-gray-200);
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
        }

        /* Form Styles */
        #taskForm {
            padding: var(--space-6);
        }

        .form-group {
            margin-bottom: var(--space-4);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-4);
        }

        .form-group label {
            display: block;
            font-weight: var(--font-medium);
            margin-bottom: var(--space-2);
            color: var(--color-gray-700);
        }

        .form-control {
            width: 100%;
            padding: var(--space-2) var(--space-3);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-sm);
            transition: all var(--transition-fast);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-text {
            display: block;
            margin-top: var(--space-1);
            font-size: var(--text-xs);
            color: var(--color-gray-600);
        }

        /* Alert Styles */
        .alert {
            padding: var(--space-4);
            border-radius: var(--radius-md);
            border-left: 4px solid;
        }

        .alert-warning {
            background: #FEF3C7;
            border-left-color: #F59E0B;
            color: #92400E;
        }

        .alert-content {
            display: flex;
            align-items: flex-start;
            gap: var(--space-3);
        }

        .alert-icon {
            width: 24px;
            height: 24px;
            flex-shrink: 0;
        }

        .alert-link {
            color: #92400E;
            text-decoration: underline;
            font-weight: var(--font-medium);
        }

        .alert-link:hover {
            color: #78350F;
        }

        /* Task Card Enhancements */
        .task-card {
            position: relative;
        }

        .task-card-actions {
            position: absolute;
            top: var(--space-2);
            right: var(--space-2);
            display: none;
            gap: var(--space-1);
        }

        .task-card:hover .task-card-actions {
            display: flex;
        }

        .task-card-btn {
            width: 24px;
            height: 24px;
            padding: 0;
            border: none;
            background: rgba(255, 255, 255, 0.9);
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
        }

        .task-card-btn:hover {
            background: var(--color-white);
            box-shadow: var(--shadow-sm);
        }

        .task-card-btn.btn-edit {
            color: var(--color-primary);
        }

        .task-card-btn.btn-delete {
            color: var(--color-error);
        }

        /* Toast Notifications */
        .toast-container {
            position: fixed;
            top: var(--space-6);
            right: var(--space-6);
            z-index: 2000;
            display: flex;
            flex-direction: column;
            gap: var(--space-2);
        }

        .toast {
            min-width: 300px;
            padding: var(--space-4);
            background: var(--color-white);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: var(--space-3);
            animation: toastSlideIn 0.3s ease-out;
        }

        @keyframes toastSlideIn {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .toast.toast-success {
            border-left: 4px solid #10B981;
        }

        .toast.toast-error {
            border-left: 4px solid #EF4444;
        }

        .toast.toast-info {
            border-left: 4px solid #3B82F6;
        }

        /* Drag and Drop Visual Feedback */
        .task-column.drag-over {
            background: var(--color-gray-100);
            border: 2px dashed var(--color-primary);
        }

        .task-card.dragging {
            opacity: 0.5;
            transform: rotate(3deg);
        }

        /* Loading State */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid var(--color-gray-200);
            border-top-color: var(--color-primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: var(--space-8);
            color: var(--color-gray-500);
        }

        .empty-state-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto var(--space-4);
            opacity: 0.5;
        }

        /* Fix for text-muted */
        .text-muted {
            color: var(--color-gray-500);
        }

        /* Fix for toast icon */
        .toast-icon {
            font-size: 20px;
            font-weight: bold;
        }

        /* Fix for text-warning */
        .text-warning {
            color: var(--color-warning);
        }

        /* Fix for btn-secondary and btn-danger */
        .btn-secondary {
            background: var(--color-gray-200);
            color: var(--color-gray-900);
            border: 1px solid var(--color-gray-300);
        }

        .btn-secondary:hover {
            background: var(--color-gray-300);
        }

        .btn-danger {
            background: var(--color-error);
            color: var(--color-white);
            border: 1px solid var(--color-error);
        }

        .btn-danger:hover {
            background: #DC2626;
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
                <button class="btn btn-success" onclick="taskManager.openNewTaskModal()">+ Nuovo Task</button>
            </div>

            <div class="page-content">
                <!-- Orphaned Tasks Warning Banner -->
                <div id="orphanedWarning" class="alert alert-warning" style="display: none; margin-bottom: var(--space-4);">
                    <div class="alert-content">
                        <svg class="alert-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <div class="alert-text">
                            <strong>Attenzione:</strong>
                            <span id="orphanedCount">0</span> task senza utente assegnato valido.
                            <a href="#" onclick="taskManager.showOrphanedTasks(event)" class="alert-link">Visualizza e correggi</a>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="tasks-filters">
                    <button class="filter-btn active">Tutti</button>
                    <button class="filter-btn">I Miei Task</button>
                    <button class="filter-btn">Alta Priorità</button>
                    <button class="filter-btn">Scadenza Oggi</button>
                    <button class="filter-btn">Completati</button>
                </div>

                <!-- Task Board -->
                <div class="tasks-board" id="taskBoard">
                    <!-- To Do Column -->
                    <div class="task-column" data-status="todo">
                        <div class="column-header">
                            <span>Da Fare</span>
                            <span class="task-count" id="count-todo">0</span>
                        </div>
                        <div class="task-list" id="tasks-todo">
                            <!-- Tasks loaded dynamically -->
                        </div>
                    </div>

                    <!-- In Progress Column -->
                    <div class="task-column" data-status="in_progress">
                        <div class="column-header">
                            <span>In Corso</span>
                            <span class="task-count" id="count-in_progress">0</span>
                        </div>
                        <div class="task-list" id="tasks-in_progress">
                            <!-- Tasks loaded dynamically -->
                        </div>
                    </div>

                    <!-- Review Column -->
                    <div class="task-column" data-status="review">
                        <div class="column-header">
                            <span>In Revisione</span>
                            <span class="task-count" id="count-review">0</span>
                        </div>
                        <div class="task-list" id="tasks-review">
                            <!-- Tasks loaded dynamically -->
                        </div>
                    </div>

                    <!-- Done Column -->
                    <div class="task-column" data-status="done">
                        <div class="column-header">
                            <span>Completati</span>
                            <span class="task-count" id="count-done">0</span>
                        </div>
                        <div class="task-list" id="tasks-done">
                            <!-- Tasks loaded dynamically -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden CSRF token -->
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">

    <!-- Task Create/Edit Modal -->
    <div id="taskModal" class="task-modal" style="display: none;">
        <div class="modal-overlay" onclick="taskManager.closeModal()"></div>
        <div class="modal-dialog">
            <div class="modal-header">
                <h2 id="modalTitle">Nuovo Task</h2>
                <button class="modal-close" onclick="taskManager.closeModal()">&times;</button>
            </div>
            <form id="taskForm" onsubmit="taskManager.submitTask(event)">
                <input type="hidden" id="taskId" name="id">

                <div class="form-group">
                    <label for="taskTitle">Titolo*</label>
                    <input type="text" id="taskTitle" name="title" class="form-control" required maxlength="200">
                </div>

                <div class="form-group">
                    <label for="taskDescription">Descrizione</label>
                    <textarea id="taskDescription" name="description" class="form-control" rows="4" maxlength="2000"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="taskStatus">Stato</label>
                        <select id="taskStatus" name="status" class="form-control">
                            <option value="todo">Da Fare</option>
                            <option value="in_progress">In Corso</option>
                            <option value="review">In Revisione</option>
                            <option value="done">Completato</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="taskPriority">Priorità</label>
                        <select id="taskPriority" name="priority" class="form-control">
                            <option value="low">Bassa</option>
                            <option value="medium" selected>Media</option>
                            <option value="high">Alta</option>
                            <option value="critical">Critica</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="taskDueDate">Scadenza</label>
                        <input type="date" id="taskDueDate" name="due_date" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="taskProgress">Progresso (%)</label>
                        <input type="number" id="taskProgress" name="progress" class="form-control" min="0" max="100" value="0">
                    </div>
                </div>

                <div class="form-group">
                    <label for="taskAssignees">Assegna a</label>
                    <select id="taskAssignees" name="assignees[]" class="form-control" multiple size="5">
                        <!-- Populated dynamically -->
                    </select>
                    <small class="form-text">Tieni premuto Ctrl per selezionare più utenti</small>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="taskManager.closeModal()">Annulla</button>
                    <button type="submit" class="btn btn-primary">Salva Task</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="task-modal" style="display: none;">
        <div class="modal-overlay" onclick="taskManager.closeDeleteModal()"></div>
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h2>Conferma Eliminazione</h2>
                <button class="modal-close" onclick="taskManager.closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Sei sicuro di voler eliminare questo task?</p>
                <p class="text-warning"><strong>Questa azione non può essere annullata.</strong></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="taskManager.closeDeleteModal()">Annulla</button>
                <button class="btn btn-danger" onclick="taskManager.confirmDelete()">Elimina</button>
            </div>
        </div>
    </div>

    <!-- Toast Notification Container -->
    <div id="toastContainer" class="toast-container"></div>

    <script src="assets/js/tasks.js?v=<?php echo time(); ?>"></script>
</body>
</html>