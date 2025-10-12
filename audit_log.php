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
    <title>Audit Log - CollaboraNexio</title>

    <!-- Main CSS -->
    <link rel="stylesheet" href="assets/css/styles.css">
    <!-- Sidebar Responsive Optimization CSS -->
    <link rel="stylesheet" href="assets/css/sidebar-responsive.css">
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

        /* Sidebar navigation styles */
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

        /* Icon base styles */
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

        /* White icon styles using CSS masks */
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

        .icon--download::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'%3E%3Cpath d='M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'/%3E%3Cpolyline points='7 10 12 15 17 10'/%3E%3Cline x1='12' y1='15' x2='12' y2='3'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'%3E%3Cpath d='M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'/%3E%3Cpolyline points='7 10 12 15 17 10'/%3E%3Cline x1='12' y1='15' x2='12' y2='3'/%3E%3C/svg%3E");
        }

        .icon--file::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'%3E%3Cpath d='M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z'/%3E%3Cpolyline points='13 2 13 9 20 9'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'%3E%3Cpath d='M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z'/%3E%3Cpolyline points='13 2 13 9 20 9'/%3E%3C/svg%3E");
        }

        .icon--chevron-left::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'%3E%3Cpolyline points='15 18 9 12 15 6'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'%3E%3Cpolyline points='15 18 9 12 15 6'/%3E%3C/svg%3E");
        }

        .icon--chevron-right::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'%3E%3Cpolyline points='9 18 15 12 9 6'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'%3E%3Cpolyline points='9 18 15 12 9 6'/%3E%3C/svg%3E");
        }

        /* Logo styles */
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

        /* User info styles */
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

        /* Page specific styles */
        .audit-container {
            padding: var(--space-6);
        }

        .audit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-8);
        }

        .audit-filters {
            background: var(--color-white);
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--space-6);
        }

        .filters-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-4);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: var(--space-2);
        }

        .filter-label {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-700);
        }

        .audit-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-6);
        }

        .stat-card {
            background: var(--color-white);
            padding: var(--space-4);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--color-primary);
        }

        .stat-label {
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            color: var(--color-gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: var(--space-1);
        }

        .stat-value {
            font-size: var(--text-2xl);
            font-weight: var(--font-bold);
            color: var(--color-gray-900);
        }

        .audit-table-container {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .table-header {
            padding: var(--space-4) var(--space-6);
            background: var(--color-gray-50);
            border-bottom: 1px solid var(--color-gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: var(--space-4);
            background: var(--color-gray-50);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            color: var(--color-gray-700);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
        }

        td {
            padding: var(--space-4);
            border-top: 1px solid var(--color-gray-200);
            font-size: var(--text-sm);
        }

        tr:hover {
            background: var(--color-gray-50);
        }

        .action-badge {
            display: inline-block;
            padding: var(--space-1) var(--space-2);
            border-radius: var(--radius-sm);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            text-transform: uppercase;
        }

        .action-create { background: var(--color-success-100); color: var(--color-success); }
        .action-update { background: var(--color-primary-100); color: var(--color-primary); }
        .action-delete { background: var(--color-error-100); color: var(--color-error); }
        .action-login { background: var(--color-gray-200); color: var(--color-gray-700); }
        .action-logout { background: var(--color-gray-200); color: var(--color-gray-700); }
        .action-access { background: var(--color-warning-100); color: var(--color-warning); }

        .severity-badge {
            display: inline-block;
            padding: var(--space-1) var(--space-2);
            border-radius: var(--radius-sm);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
        }

        .severity-info { background: var(--color-primary-100); color: var(--color-primary); }
        .severity-warning { background: var(--color-warning-100); color: var(--color-warning); }
        .severity-critical { background: var(--color-error-100); color: var(--color-error); }

        .ip-address {
            font-family: monospace;
            background: var(--color-gray-100);
            padding: var(--space-1) var(--space-2);
            border-radius: var(--radius-sm);
            font-size: var(--text-xs);
        }

        .timestamp {
            color: var(--color-gray-600);
            font-size: var(--text-sm);
        }

        .details-btn {
            padding: var(--space-1) var(--space-2);
            background: transparent;
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-sm);
            color: var(--color-gray-600);
            cursor: pointer;
            font-size: var(--text-xs);
            transition: all var(--transition-fast);
        }

        .details-btn:hover {
            background: var(--color-primary);
            border-color: var(--color-primary);
            color: white;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: var(--space-2);
            padding: var(--space-4);
        }

        .pagination-btn {
            padding: var(--space-2) var(--space-3);
            border: 1px solid var(--color-gray-300);
            background: white;
            border-radius: var(--radius-sm);
            color: var(--color-gray-700);
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .pagination-btn:hover:not(:disabled) {
            background: var(--color-primary);
            border-color: var(--color-primary);
            color: white;
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-btn.active {
            background: var(--color-primary);
            border-color: var(--color-primary);
            color: white;
        }

        .export-menu {
            position: relative;
            display: inline-block;
        }

        .export-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: var(--space-2);
            background: white;
            border: 1px solid var(--color-gray-200);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            display: none;
            min-width: 150px;
            z-index: 100;
        }

        .export-dropdown.active {
            display: block;
        }

        .export-option {
            padding: var(--space-3) var(--space-4);
            cursor: pointer;
            transition: background var(--transition-fast);
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }

        .export-option:hover {
            background: var(--color-gray-50);
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
                    <a href="audit_log.php" class="nav-item active"><i class="icon icon--chart"></i> Audit Log</a>
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
            <!-- Top Bar -->
            <div class="header">
                <h1 class="page-title">Audit Log</h1>
                <div class="flex items-center gap-4">
                    <?php if ($companyFilter->canUseCompanyFilter()): ?>
                        <?php echo $companyFilter->renderDropdown(); ?>
                    <?php endif; ?>
                    <span class="text-sm text-muted">Benvenuto, <?php echo htmlspecialchars($currentUser['name']); ?></span>
                </div>
            </div>

            <!-- Page Content -->
            <div class="page-content">
                <div class="audit-container">
                    <!-- Header -->
                    <div class="audit-header">
                        <h2>Registro Attività Sistema</h2>
                        <div class="export-menu">
                            <button class="btn btn--secondary" onclick="toggleExportMenu()">
                                <i class="icon icon--download"></i> Esporta
                            </button>
                            <div class="export-dropdown" id="export-dropdown">
                                <div class="export-option" onclick="exportData('csv')">
                                    <i class="icon icon--file"></i> CSV
                                </div>
                                <div class="export-option" onclick="exportData('pdf')">
                                    <i class="icon icon--file"></i> PDF
                                </div>
                                <div class="export-option" onclick="exportData('excel')">
                                    <i class="icon icon--file"></i> Excel
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics -->
                    <div class="audit-stats">
                        <div class="stat-card">
                            <div class="stat-label">Eventi Oggi</div>
                            <div class="stat-value">342</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Utenti Attivi</div>
                            <div class="stat-value">28</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Accessi</div>
                            <div class="stat-value">156</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Modifiche</div>
                            <div class="stat-value">89</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Eventi Critici</div>
                            <div class="stat-value" style="color: var(--color-error)">3</div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="audit-filters">
                        <h3 style="margin-bottom: var(--space-4)">Filtri</h3>
                        <div class="filters-row">
                            <div class="filter-group">
                                <label class="filter-label">Data Inizio</label>
                                <input type="datetime-local" class="form-control" value="2024-10-01T00:00">
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Data Fine</label>
                                <input type="datetime-local" class="form-control" value="2024-10-07T23:59">
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Utente</label>
                                <select class="form-control">
                                    <option>Tutti gli utenti</option>
                                    <option>Mario Rossi</option>
                                    <option>Laura Bianchi</option>
                                    <option>Giuseppe Verdi</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Tipo Azione</label>
                                <select class="form-control">
                                    <option>Tutte le azioni</option>
                                    <option>Login/Logout</option>
                                    <option>Creazione</option>
                                    <option>Modifica</option>
                                    <option>Eliminazione</option>
                                    <option>Accesso</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Severità</label>
                                <select class="form-control">
                                    <option>Tutte</option>
                                    <option>Info</option>
                                    <option>Warning</option>
                                    <option>Critico</option>
                                </select>
                            </div>
                        </div>
                        <div style="display: flex; gap: var(--space-3); margin-top: var(--space-4)">
                            <button class="btn btn--primary">Applica Filtri</button>
                            <button class="btn btn--secondary">Reset</button>
                        </div>
                    </div>

                    <!-- Audit Table -->
                    <div class="audit-table-container">
                        <div class="table-header">
                            <h3>Log Eventi</h3>
                            <span style="color: var(--color-gray-600); font-size: var(--text-sm)">Totale: 1,234 eventi</span>
                        </div>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Data/Ora</th>
                                        <th>Utente</th>
                                        <th>Azione</th>
                                        <th>Risorsa</th>
                                        <th>IP Address</th>
                                        <th>Severità</th>
                                        <th>Dettagli</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="timestamp">07/10/2024 14:32:15</td>
                                        <td>Mario Rossi</td>
                                        <td><span class="action-badge action-update">Update</span></td>
                                        <td>Documento #4521</td>
                                        <td><span class="ip-address">192.168.1.105</span></td>
                                        <td><span class="severity-badge severity-info">Info</span></td>
                                        <td><button class="details-btn">Dettagli</button></td>
                                    </tr>
                                    <tr>
                                        <td class="timestamp">07/10/2024 14:28:03</td>
                                        <td>Laura Bianchi</td>
                                        <td><span class="action-badge action-create">Create</span></td>
                                        <td>Task #892</td>
                                        <td><span class="ip-address">192.168.1.112</span></td>
                                        <td><span class="severity-badge severity-info">Info</span></td>
                                        <td><button class="details-btn">Dettagli</button></td>
                                    </tr>
                                    <tr>
                                        <td class="timestamp">07/10/2024 14:15:47</td>
                                        <td>Sistema</td>
                                        <td><span class="action-badge action-delete">Delete</span></td>
                                        <td>File temporanei</td>
                                        <td><span class="ip-address">127.0.0.1</span></td>
                                        <td><span class="severity-badge severity-warning">Warning</span></td>
                                        <td><button class="details-btn">Dettagli</button></td>
                                    </tr>
                                    <tr>
                                        <td class="timestamp">07/10/2024 13:45:22</td>
                                        <td>Giuseppe Verdi</td>
                                        <td><span class="action-badge action-login">Login</span></td>
                                        <td>Sistema</td>
                                        <td><span class="ip-address">82.50.123.45</span></td>
                                        <td><span class="severity-badge severity-info">Info</span></td>
                                        <td><button class="details-btn">Dettagli</button></td>
                                    </tr>
                                    <tr>
                                        <td class="timestamp">07/10/2024 13:12:08</td>
                                        <td>Admin</td>
                                        <td><span class="action-badge action-access">Access</span></td>
                                        <td>Configurazioni Sistema</td>
                                        <td><span class="ip-address">192.168.1.1</span></td>
                                        <td><span class="severity-badge severity-critical">Critico</span></td>
                                        <td><button class="details-btn">Dettagli</button></td>
                                    </tr>
                                    <tr>
                                        <td class="timestamp">07/10/2024 12:55:31</td>
                                        <td>Anna Romano</td>
                                        <td><span class="action-badge action-update">Update</span></td>
                                        <td>Profilo Utente</td>
                                        <td><span class="ip-address">192.168.1.88</span></td>
                                        <td><span class="severity-badge severity-info">Info</span></td>
                                        <td><button class="details-btn">Dettagli</button></td>
                                    </tr>
                                    <tr>
                                        <td class="timestamp">07/10/2024 11:30:19</td>
                                        <td>Sistema</td>
                                        <td><span class="action-badge action-create">Create</span></td>
                                        <td>Backup Automatico</td>
                                        <td><span class="ip-address">127.0.0.1</span></td>
                                        <td><span class="severity-badge severity-info">Info</span></td>
                                        <td><button class="details-btn">Dettagli</button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div class="pagination">
                            <button class="pagination-btn" disabled>
                                <i class="icon icon--chevron-left"></i>
                            </button>
                            <button class="pagination-btn active">1</button>
                            <button class="pagination-btn">2</button>
                            <button class="pagination-btn">3</button>
                            <button class="pagination-btn">4</button>
                            <button class="pagination-btn">5</button>
                            <span style="color: var(--color-gray-500)">...</span>
                            <button class="pagination-btn">124</button>
                            <button class="pagination-btn">
                                <i class="icon icon--chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="assets/js/app.js"></script>
    <script>
        function toggleExportMenu() {
            const dropdown = document.getElementById('export-dropdown');
            dropdown.classList.toggle('active');
        }

        function exportData(format) {
            alert(`Esportazione in formato ${format.toUpperCase()} in corso...`);
            document.getElementById('export-dropdown').classList.remove('active');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const exportMenu = document.querySelector('.export-menu');
            if (!exportMenu.contains(event.target)) {
                document.getElementById('export-dropdown').classList.remove('active');
            }
        });

        // Initialize company filter
        document.addEventListener('DOMContentLoaded', function() {
            const companySelector = document.getElementById('company-filter');
            if (companySelector) {
                companySelector.addEventListener('change', function() {
                    // Handle company filter change
                    console.log('Company changed:', this.value);
                });
            }
        });
    </script>
</body>
</html>