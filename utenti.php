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
    <title>Gestione Utenti - CollaboraNexio</title>

    <?php require_once __DIR__ . '/includes/favicon.php'; ?>

    <!-- Main CSS -->
    <link rel="stylesheet" href="assets/css/styles.css">
    <!-- Page specific CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css">

    <style>
        /* Additional user management specific styles */
        .users-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-6);
        }

        /* Multi-select checkbox styles - Enhanced */
        .tenant-checkbox-list {
            max-height: 250px;
            overflow-y: auto;
            border: 1px solid var(--color-gray-200);
            border-radius: var(--radius-lg);
            padding: var(--space-3);
            background: var(--color-gray-50);
        }

        .tenant-checkbox-list::-webkit-scrollbar {
            width: 8px;
        }

        .tenant-checkbox-list::-webkit-scrollbar-track {
            background: var(--color-gray-100);
            border-radius: var(--radius-full);
        }

        .tenant-checkbox-list::-webkit-scrollbar-thumb {
            background: var(--color-gray-400);
            border-radius: var(--radius-full);
        }

        .tenant-checkbox-list::-webkit-scrollbar-thumb:hover {
            background: var(--color-gray-500);
        }

        .tenant-checkbox-item {
            display: flex;
            align-items: center;
            padding: var(--space-3);
            margin-bottom: var(--space-2);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all var(--transition-fast);
            background: var(--color-white);
            border: 1px solid var(--color-gray-200);
            position: relative;
        }

        .tenant-checkbox-item:last-child {
            margin-bottom: 0;
        }

        .tenant-checkbox-item:hover {
            background: linear-gradient(to right, rgba(59, 130, 246, 0.05), rgba(59, 130, 246, 0.02));
            border-color: var(--color-primary);
            transform: translateX(2px);
        }

        .tenant-checkbox-item.checked {
            background: linear-gradient(to right, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.05));
            border-color: var(--color-primary);
        }

        .tenant-checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: var(--space-3);
            cursor: pointer;
            accent-color: var(--color-primary);
            flex-shrink: 0;
        }

        .tenant-checkbox-item label {
            cursor: pointer;
            flex: 1;
            margin: 0;
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-700);
            user-select: none;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .tenant-checkbox-item:hover label {
            color: var(--color-gray-900);
        }

        .tenant-checkbox-item.checked label {
            color: var(--color-primary);
            font-weight: var(--font-semibold);
        }

        .tenant-info {
            font-size: var(--text-xs);
            color: var(--color-gray-500);
            font-weight: var(--font-normal);
        }

        .tenant-checkbox-item.checked .tenant-info {
            color: var(--color-gray-600);
        }

        .tenant-checkbox-counter {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--color-primary);
            color: white;
            font-size: 11px;
            font-weight: var(--font-bold);
            padding: 2px 6px;
            border-radius: var(--radius-full);
            min-width: 20px;
            text-align: center;
            display: none;
        }

        .tenant-checkbox-list.has-selection .tenant-checkbox-counter {
            display: block;
        }

        .form-group-hidden {
            display: none !important;
        }

        .form-help-text {
            font-size: var(--text-xs);
            color: var(--color-gray-500);
            margin-top: var(--space-1);
        }

        .search-bar {
            position: relative;
            width: 300px;
        }

        .search-bar input {
            width: 100%;
            padding: var(--space-2) var(--space-10) var(--space-2) var(--space-3);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-sm);
            transition: all var(--transition-fast);
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .search-icon {
            position: absolute;
            right: var(--space-3);
            top: 50%;
            transform: translateY(-50%);
            color: var(--color-gray-400);
            pointer-events: none;
        }

        .users-table {
            width: 100%;
            background: var(--color-white);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .users-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th {
            padding: var(--space-3) var(--space-4);
            text-align: left;
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            color: var(--color-gray-600);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: var(--color-gray-50);
            border-bottom: 1px solid var(--color-gray-200);
        }

        .users-table td {
            padding: var(--space-4);
            border-bottom: 1px solid var(--color-gray-200);
        }

        .users-table tbody tr:last-child td {
            border-bottom: none;
        }

        .users-table tbody tr:hover {
            background: var(--color-gray-50);
        }

        .user-info-cell {
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }

        .user-avatar-table {
            width: 36px;
            height: 36px;
            background: var(--color-primary);
            color: var(--color-white);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
            flex-shrink: 0;
        }

        .user-details-table {
            flex: 1;
        }

        .user-name-table {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
        }

        .user-email-table {
            font-size: var(--text-xs);
            color: var(--color-gray-600);
            margin-top: 2px;
        }

        .role-badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: var(--text-xs);
            font-weight: var(--font-medium);
            border-radius: var(--radius-sm);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .role-badge.super-admin {
            background: #FEF3C7;
            color: #92400E;
        }

        .role-badge.admin {
            background: #DBEAFE;
            color: #1E3A8A;
        }

        .role-badge.user {
            background: #E0E7FF;
            color: #3730A3;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            font-size: var(--text-xs);
            font-weight: var(--font-medium);
            border-radius: var(--radius-sm);
        }

        .status-badge.active {
            background: #D1FAE5;
            color: #065F46;
        }

        .status-badge.inactive {
            background: #FEE2E2;
            color: #991B1B;
        }

        .status-indicator {
            width: 6px;
            height: 6px;
            border-radius: var(--radius-full);
            background: currentColor;
        }

        .action-buttons {
            display: flex;
            gap: var(--space-2);
        }

        .btn-icon {
            padding: var(--space-2);
            background: transparent;
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all var(--transition-fast);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-icon:hover {
            background: var(--color-gray-50);
            border-color: var(--color-gray-400);
        }

        .btn-icon.edit {
            color: var(--color-primary);
        }

        .btn-icon.delete {
            color: var(--color-error);
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.2s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: var(--color-white);
            padding: var(--space-6);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease-out;
        }

        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-4);
        }

        .modal-title {
            font-size: var(--text-xl);
            font-weight: var(--font-bold);
            color: var(--color-gray-900);
        }

        .modal-close {
            background: transparent;
            border: none;
            font-size: var(--text-2xl);
            color: var(--color-gray-400);
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
            color: var(--color-gray-600);
        }

        .modal-body {
            margin-bottom: var(--space-4);
        }

        .form-group {
            margin-bottom: var(--space-4);
        }

        .form-group label {
            display: block;
            margin-bottom: var(--space-2);
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-700);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: var(--space-2) var(--space-3);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-sm);
            transition: all var(--transition-fast);
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
        }

        .empty-state {
            text-align: center;
            padding: var(--space-12);
        }

        .empty-state-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto var(--space-4);
            opacity: 0.3;
        }

        .empty-state-text {
            color: var(--color-gray-600);
            font-size: var(--text-sm);
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: var(--space-2);
            margin-top: var(--space-6);
        }

        .pagination button {
            padding: var(--space-2) var(--space-3);
            border: 1px solid var(--color-gray-300);
            background: var(--color-white);
            color: var(--color-gray-700);
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: var(--text-sm);
            transition: all var(--transition-fast);
        }

        .pagination button:hover:not(:disabled) {
            background: var(--color-gray-50);
            border-color: var(--color-gray-400);
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination button.active {
            background: var(--color-primary);
            color: var(--color-white);
            border-color: var(--color-primary);
        }

        /* Additional sidebar styles (matching dashboard.php) */
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

        /* Individual icon masks (matching dashboard.php) */
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

        .logo-img {
            width: 32px;
            height: 32px;
            object-fit: contain;
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

        .toast {
            position: fixed;
            bottom: var(--space-6);
            right: var(--space-6);
            background: var(--color-gray-900);
            color: var(--color-white);
            padding: var(--space-3) var(--space-4);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            z-index: 2000;
            display: none;
            animation: slideInUp 0.3s ease-out;
        }

        .toast.show {
            display: block;
        }

        .toast.success {
            background: var(--color-success);
        }

        .toast.error {
            background: var(--color-error);
        }

        @keyframes slideInUp {
            from {
                transform: translateY(100px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
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
                    <a href="utenti.php" class="nav-item active"><i class="icon icon--users"></i> Utenti</a>
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
                <h1 class="page-title">Gestione Utenti</h1>
                <div class="flex items-center gap-4">
                    <?php if ($companyFilter->canUseCompanyFilter()): ?>
                        <?php echo $companyFilter->renderDropdown(); ?>
                    <?php endif; ?>
                    <span class="text-sm text-muted">Gestisci utenti e permessi</span>
                </div>
            </div>

            <div class="page-content">
                <!-- Header with search and add button -->
                <div class="users-header">
                    <div class="search-bar">
                        <input type="text" id="searchInput" placeholder="Cerca utenti..." />
                        <span class="search-icon">🔍</span>
                    </div>
                    <button class="btn btn-primary" onclick="openAddModal()">
                        + Nuovo Utente
                    </button>
                </div>

                <!-- Users table -->
                <div class="users-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Email</th>
                                <th>Ruolo</th>
                                <th>Azienda</th>
                                <th>Stato</th>
                                <th>Data Creazione</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <!-- Users will be loaded here via JavaScript -->
                        </tbody>
                    </table>
                    <div id="emptyState" class="empty-state" style="display: none;">
                        <div class="empty-state-icon">👥</div>
                        <div class="empty-state-text">Nessun utente trovato</div>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="pagination" id="pagination">
                    <!-- Pagination buttons will be loaded here via JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Aggiungi Nuovo Utente</h2>
                <button class="modal-close" onclick="closeModal('addModal')">×</button>
            </div>
            <form id="addUserForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="addFirstName">Nome</label>
                        <input type="text" id="addFirstName" name="first_name" required />
                    </div>
                    <div class="form-group">
                        <label for="addLastName">Cognome</label>
                        <input type="text" id="addLastName" name="last_name" required />
                    </div>
                    <div class="form-group">
                        <label for="addEmail">Email</label>
                        <input type="email" id="addEmail" name="email" required />
                        <div class="form-help-text">L'utente riceverà un'email con le istruzioni per impostare la password</div>
                    </div>
                    <div class="form-group">
                        <label for="addRole">Ruolo</label>
                        <select id="addRole" name="role" required>
                            <option value="user">Utente</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>
                    <div class="form-group" id="addTenantGroup">
                        <label for="addTenant" id="addTenantLabel">Azienda</label>
                        <div id="addTenantContainer">
                            <select id="addTenant" name="tenant_id">
                                <!-- Tenants will be loaded here for single selection -->
                            </select>
                        </div>
                        <div class="form-help-text" id="addTenantHelp"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Annulla</button>
                    <button type="submit" class="btn btn-primary">Aggiungi Utente</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Modifica Utente</h2>
                <button class="modal-close" onclick="closeModal('editModal')">×</button>
            </div>
            <form id="editUserForm">
                <input type="hidden" id="editUserId" name="user_id" />
                <div class="modal-body">
                    <div class="form-group">
                        <label for="editFirstName">Nome</label>
                        <input type="text" id="editFirstName" name="first_name" required />
                    </div>
                    <div class="form-group">
                        <label for="editLastName">Cognome</label>
                        <input type="text" id="editLastName" name="last_name" required />
                    </div>
                    <div class="form-group">
                        <label for="editEmail">Email</label>
                        <input type="email" id="editEmail" name="email" required />
                    </div>
                    <div class="form-group">
                        <label for="editPassword">Nuova Password (lascia vuoto per non cambiarla)</label>
                        <input type="password" id="editPassword" name="password" />
                    </div>
                    <div class="form-group">
                        <label for="editRole">Ruolo</label>
                        <select id="editRole" name="role" required>
                            <option value="user">Utente</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>
                    <div class="form-group" id="editTenantGroup">
                        <label for="editTenant" id="editTenantLabel">Azienda</label>
                        <div id="editTenantContainer">
                            <select id="editTenant" name="tenant_id">
                                <!-- Tenants will be loaded here for single selection -->
                            </select>
                        </div>
                        <div class="form-help-text" id="editTenantHelp"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Annulla</button>
                    <button type="submit" class="btn btn-primary">Salva Modifiche</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Conferma Eliminazione</h2>
                <button class="modal-close" onclick="closeModal('deleteModal')">×</button>
            </div>
            <div class="modal-body">
                <p>Sei sicuro di voler eliminare questo utente?</p>
                <p class="text-muted text-sm">Questa azione non può essere annullata.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Annulla</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">Elimina</button>
            </div>
        </div>
    </div>

    <!-- Toast notification -->
    <div id="toast" class="toast"></div>

    <!-- Hidden CSRF token -->
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">

    <script>
        class UserManager {
            constructor() {
                this.users = [];
                this.currentPage = 1;
                this.itemsPerPage = 10;
                this.searchQuery = '';
                this.deleteUserId = null;
                this.tenantsList = [];
                this.init();
            }

            init() {
                this.bindEvents();
                this.loadUsers();
                this.loadTenants();
            }

            bindEvents() {
                // Search functionality
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.addEventListener('input', (e) => {
                        this.searchQuery = e.target.value;
                        this.currentPage = 1;
                        this.loadUsers();
                    });
                }

                // Add user form
                document.getElementById('addUserForm').addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.addUser();
                });

                // Edit user form
                document.getElementById('editUserForm').addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.updateUser();
                });

                // Role change listeners for dynamic tenant field
                document.getElementById('addRole').addEventListener('change', (e) => {
                    this.handleRoleChange(e.target.value, 'add');
                });

                document.getElementById('editRole').addEventListener('change', (e) => {
                    this.handleRoleChange(e.target.value, 'edit');
                });
            }

            handleRoleChange(role, formType) {
                const tenantGroup = document.getElementById(`${formType}TenantGroup`);
                const tenantContainer = document.getElementById(`${formType}TenantContainer`);
                const tenantLabel = document.getElementById(`${formType}TenantLabel`);
                const tenantHelp = document.getElementById(`${formType}TenantHelp`);

                // Reset container
                tenantContainer.innerHTML = '';
                tenantHelp.textContent = '';

                switch(role) {
                    case 'super_admin':
                        // Hide tenant field for super admins
                        tenantGroup.classList.add('form-group-hidden');
                        break;

                    case 'admin':
                        // Show multi-select checkboxes for admins
                        tenantGroup.classList.remove('form-group-hidden');
                        tenantLabel.innerHTML = 'Aziende Assegnate <span id="' + formType + 'SelectedCount" style="color: var(--color-primary); font-weight: normal;"></span>';
                        tenantHelp.textContent = 'Gli admin possono gestire più aziende. Seleziona almeno una azienda.';

                        // Create wrapper with counter
                        const wrapperDiv = document.createElement('div');
                        wrapperDiv.style.position = 'relative';

                        // Create checkbox list
                        const checkboxList = document.createElement('div');
                        checkboxList.className = 'tenant-checkbox-list';
                        checkboxList.id = `${formType}TenantCheckboxList`;

                        // Add selection counter badge
                        const counterBadge = document.createElement('div');
                        counterBadge.className = 'tenant-checkbox-counter';
                        counterBadge.id = `${formType}TenantCounter`;
                        counterBadge.textContent = '0';

                        this.tenantsList.forEach((tenant, index) => {
                            const itemDiv = document.createElement('div');
                            itemDiv.className = 'tenant-checkbox-item';

                            const checkbox = document.createElement('input');
                            checkbox.type = 'checkbox';
                            checkbox.id = `${formType}_tenant_${tenant.id}`;
                            checkbox.name = 'tenant_ids[]';
                            checkbox.value = tenant.id;

                            // Add change listener for visual feedback
                            checkbox.addEventListener('change', (e) => {
                                if (e.target.checked) {
                                    itemDiv.classList.add('checked');
                                } else {
                                    itemDiv.classList.remove('checked');
                                }
                                this.updateTenantSelectionCount(formType);
                            });

                            const label = document.createElement('label');
                            label.htmlFor = `${formType}_tenant_${tenant.id}`;

                            // Create label content with company name and additional info
                            const nameSpan = document.createElement('span');
                            nameSpan.textContent = tenant.denominazione || tenant.name;

                            // Add additional info if available
                            const infoSpan = document.createElement('span');
                            infoSpan.className = 'tenant-info';
                            const infoParts = [];
                            if (tenant.code) infoParts.push(`Codice: ${tenant.code}`);
                            if (tenant.numero_dipendenti) infoParts.push(`${tenant.numero_dipendenti} dipendenti`);
                            if (infoParts.length > 0) {
                                infoSpan.textContent = infoParts.join(' • ');
                            }

                            label.appendChild(nameSpan);
                            if (infoParts.length > 0) {
                                label.appendChild(infoSpan);
                            }

                            // Make entire item clickable
                            itemDiv.addEventListener('click', (e) => {
                                if (e.target !== checkbox) {
                                    checkbox.checked = !checkbox.checked;
                                    checkbox.dispatchEvent(new Event('change'));
                                }
                            });

                            itemDiv.appendChild(checkbox);
                            itemDiv.appendChild(label);
                            checkboxList.appendChild(itemDiv);
                        });

                        wrapperDiv.appendChild(checkboxList);
                        wrapperDiv.appendChild(counterBadge);
                        tenantContainer.appendChild(wrapperDiv);

                        // Initialize count
                        this.updateTenantSelectionCount(formType);
                        break;

                    case 'manager':
                    case 'user':
                        // Show single select dropdown for managers and users
                        tenantGroup.classList.remove('form-group-hidden');
                        tenantLabel.textContent = 'Azienda';
                        tenantHelp.textContent = role === 'manager' ?
                            'I manager possono gestire una singola azienda' :
                            'Gli utenti appartengono a una singola azienda';

                        // Create single select dropdown
                        const select = document.createElement('select');
                        select.id = `${formType}Tenant`;
                        select.name = 'tenant_id';
                        select.required = true;

                        // Add empty option
                        const emptyOption = document.createElement('option');
                        emptyOption.value = '';
                        emptyOption.textContent = 'Seleziona un\'azienda';
                        select.appendChild(emptyOption);

                        // Add tenant options
                        this.tenantsList.forEach(tenant => {
                            const option = document.createElement('option');
                            option.value = tenant.id;
                            option.textContent = tenant.name;
                            select.appendChild(option);
                        });

                        tenantContainer.appendChild(select);
                        break;

                    default:
                        // Default to single select
                        tenantGroup.classList.remove('form-group-hidden');
                        tenantLabel.textContent = 'Azienda';

                        const defaultSelect = document.createElement('select');
                        defaultSelect.id = `${formType}Tenant`;
                        defaultSelect.name = 'tenant_id';
                        defaultSelect.required = true;

                        const defaultEmpty = document.createElement('option');
                        defaultEmpty.value = '';
                        defaultEmpty.textContent = 'Seleziona un\'azienda';
                        defaultSelect.appendChild(defaultEmpty);

                        this.tenantsList.forEach(tenant => {
                            const option = document.createElement('option');
                            option.value = tenant.id;
                            option.textContent = tenant.name;
                            defaultSelect.appendChild(option);
                        });

                        tenantContainer.appendChild(defaultSelect);
                        break;
                }
            }

            async loadUsers() {
                try {
                    const response = await fetch(`api/users/list.php?page=${this.currentPage}&search=${encodeURIComponent(this.searchQuery)}`, {
                        headers: {
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        }
                    });

                    const data = await response.json();

                    if (data.success) {
                        // Handle the nested data structure from API
                        this.users = data.data?.users || [];
                        this.renderUsers();
                        this.renderPagination(data.data?.total_pages || 1);
                    } else {
                        this.showToast(data.message || 'Errore nel caricamento utenti', 'error');
                    }
                } catch (error) {
                    console.error('Error loading users:', error);
                    this.showToast('Errore di connessione', 'error');
                    // Initialize empty users array to prevent undefined errors
                    this.users = [];
                    this.renderUsers();
                }
            }

            async loadTenants() {
                try {
                    // First try to get enhanced data from companies API
                    const response = await fetch('api/companies/list.php?page=1&search=', {
                        headers: {
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        }
                    });

                    const data = await response.json();

                    if (data.success && data.data && data.data.companies) {
                        // Use enhanced company data if available
                        this.tenantsList = data.data.companies.map(company => ({
                            id: company.id,
                            name: company.denominazione || company.name || 'Azienda',
                            denominazione: company.denominazione,
                            code: company.code,
                            numero_dipendenti: company.numero_dipendenti,
                            settore_merceologico: company.settore_merceologico,
                            codice_fiscale: company.codice_fiscale
                        }));
                    } else {
                        // Fallback to basic tenants API
                        const fallbackResponse = await fetch('api/users/tenants.php', {
                            headers: {
                                'X-CSRF-Token': document.getElementById('csrfToken').value
                            }
                        });
                        const fallbackData = await fallbackResponse.json();
                        this.tenantsList = fallbackData.data || fallbackData.tenants || [];
                    }

                    // Initialize tenant fields based on default role values
                    this.handleRoleChange(document.getElementById('addRole').value, 'add');
                    this.handleRoleChange(document.getElementById('editRole').value, 'edit');

                } catch (error) {
                    console.error('Error loading tenants:', error);
                    this.tenantsList = [];
                }
            }

            renderUsers() {
                const tbody = document.getElementById('usersTableBody');
                const emptyState = document.getElementById('emptyState');

                // Ensure users is always an array
                if (!this.users || this.users.length === 0) {
                    tbody.innerHTML = '';
                    emptyState.style.display = 'block';
                    return;
                }

                emptyState.style.display = 'none';
                tbody.innerHTML = this.users.map(user => {
                    // Handle both old format (first_name, last_name) and new format (name)
                    const userName = user.name || `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Unknown';
                    const initials = this.getInitialsFromName(userName);
                    const status = user.is_active ? 'active' : 'inactive';

                    return `
                    <tr>
                        <td>
                            <div class="user-info-cell">
                                <div class="user-avatar-table">${initials}</div>
                                <div class="user-details-table">
                                    <div class="user-name-table">${userName}</div>
                                </div>
                            </div>
                        </td>
                        <td>${user.email}</td>
                        <td>
                            <span class="role-badge ${user.role.replace('_', '-')}">${this.getRoleLabel(user.role)}</span>
                        </td>
                        <td>${this.getTenantDisplay(user)}</td>
                        <td>
                            <span class="status-badge ${status}">
                                <span class="status-indicator"></span>
                                ${status === 'active' ? 'Attivo' : 'Inattivo'}
                            </span>
                        </td>
                        <td>${user.created_at ? this.formatDate(user.created_at) : '-'}</td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon edit" onclick="userManager.openEditModal(${user.id})" title="Modifica">
                                    ✏️
                                </button>
                                <button class="btn-icon toggle" onclick="userManager.toggleStatus(${user.id})" title="Cambia stato">
                                    ${status === 'active' ? '⏸️' : '▶️'}
                                </button>
                                <button class="btn-icon delete" onclick="userManager.openDeleteModal(${user.id})" title="Elimina">
                                    🗑️
                                </button>
                            </div>
                        </td>
                    </tr>
                `}).join('');
            }

            renderPagination(totalPages) {
                const pagination = document.getElementById('pagination');

                if (totalPages <= 1) {
                    pagination.innerHTML = '';
                    return;
                }

                let html = '';

                // Previous button
                html += `<button onclick="userManager.goToPage(${this.currentPage - 1})" ${this.currentPage === 1 ? 'disabled' : ''}>←</button>`;

                // Page numbers
                for (let i = 1; i <= totalPages; i++) {
                    if (i === 1 || i === totalPages || (i >= this.currentPage - 2 && i <= this.currentPage + 2)) {
                        html += `<button onclick="userManager.goToPage(${i})" class="${i === this.currentPage ? 'active' : ''}">${i}</button>`;
                    } else if (i === this.currentPage - 3 || i === this.currentPage + 3) {
                        html += `<span>...</span>`;
                    }
                }

                // Next button
                html += `<button onclick="userManager.goToPage(${this.currentPage + 1})" ${this.currentPage === totalPages ? 'disabled' : ''}>→</button>`;

                pagination.innerHTML = html;
            }

            goToPage(page) {
                this.currentPage = page;
                this.loadUsers();
            }

            async addUser() {
                const form = document.getElementById('addUserForm');
                const role = document.getElementById('addRole').value;
                const formData = new FormData();

                // Add basic fields
                formData.append('first_name', form.first_name.value);
                formData.append('last_name', form.last_name.value);
                formData.append('email', form.email.value);
                // Password non più necessaria - verrà inviata email all'utente
                formData.append('role', role);
                formData.append('csrf_token', document.getElementById('csrfToken').value);

                // Handle tenant assignment based on role
                if (role === 'admin') {
                    // Get all checked tenants for admin role
                    const checkedTenants = document.querySelectorAll('#addTenantContainer input[type="checkbox"]:checked');
                    if (checkedTenants.length === 0) {
                        this.showToast('Seleziona almeno un\'azienda per l\'admin', 'error');
                        return;
                    }
                    checkedTenants.forEach(checkbox => {
                        formData.append('tenant_ids[]', checkbox.value);
                    });
                } else if (role !== 'super_admin') {
                    // For manager and user roles, get single tenant
                    const tenantSelect = document.getElementById('addTenant');
                    if (tenantSelect && tenantSelect.value) {
                        formData.append('tenant_id', tenantSelect.value);
                    } else if (role === 'manager' || role === 'user') {
                        this.showToast('Seleziona un\'azienda', 'error');
                        return;
                    }
                }

                try {
                    const response = await fetch('api/users/create_simple.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    // Debug: log della risposta
                    console.log('Create user response:', data);

                    if (data.success) {
                        let message = 'Utente creato con successo';
                        if (data.data && data.data.email_sent) {
                            message += '. Email di benvenuto inviata.';
                        } else if (data.warning) {
                            message += '. ATTENZIONE: ' + data.warning;
                            if (data.reset_link) {
                                console.log('Link manuale per impostare password:', data.reset_link);
                                // Mostra il link in caso di errore email
                                setTimeout(() => {
                                    if (confirm('Email non inviata. Vuoi copiare il link per impostare la password?')) {
                                        navigator.clipboard.writeText(data.reset_link);
                                        this.showToast('Link copiato negli appunti', 'info');
                                    }
                                }, 1000);
                            }
                        }
                        this.showToast(message, 'success');
                        closeModal('addModal');
                        form.reset();
                        this.loadUsers();
                    } else {
                        this.showToast(data.message || 'Errore nella creazione utente', 'error');
                    }
                } catch (error) {
                    console.error('Error adding user:', error);
                    this.showToast('Errore di connessione', 'error');
                }
            }

            async openEditModal(userId) {
                const user = this.users.find(u => u.id === userId);
                if (!user) return;

                // Handle both formats: single 'name' field or 'first_name'/'last_name'
                const nameParts = user.name ? user.name.split(' ') : null;
                const firstName = user.first_name || (nameParts ? nameParts[0] : '');
                const lastName = user.last_name || (nameParts ? nameParts.slice(1).join(' ') : '');

                document.getElementById('editUserId').value = user.id;
                document.getElementById('editFirstName').value = firstName;
                document.getElementById('editLastName').value = lastName;
                document.getElementById('editEmail').value = user.email;
                document.getElementById('editRole').value = user.role;
                document.getElementById('editPassword').value = '';

                // First set the role, which will update the tenant field
                this.handleRoleChange(user.role, 'edit');

                // Then set the tenant value(s) after a short delay to ensure DOM is updated
                setTimeout(() => {
                    if (user.role === 'admin') {
                        // For admins, we need to fetch their assigned companies
                        this.loadUserCompanies(userId, 'edit');
                    } else if (user.role !== 'super_admin') {
                        // For other roles, set single tenant
                        const tenantSelect = document.getElementById('editTenant');
                        if (tenantSelect) {
                            tenantSelect.value = user.tenant_id || '';
                        }
                    }
                }, 100);

                openModal('editModal');
            }

            async loadUserCompanies(userId, formType) {
                try {
                    const response = await fetch(`api/users/get-companies.php?user_id=${userId}`, {
                        headers: {
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        }
                    });

                    const data = await response.json();

                    if (data.success && data.companies) {
                        // Check the corresponding checkboxes and update visual state
                        data.companies.forEach(companyId => {
                            const checkbox = document.getElementById(`${formType}_tenant_${companyId}`);
                            if (checkbox) {
                                checkbox.checked = true;
                                // Update visual state of the item
                                const item = checkbox.closest('.tenant-checkbox-item');
                                if (item) {
                                    item.classList.add('checked');
                                }
                            }
                        });
                        // Update the selection count
                        this.updateTenantSelectionCount(formType);
                    }
                } catch (error) {
                    console.error('Error loading user companies:', error);
                }
            }

            updateTenantSelectionCount(formType) {
                const checkboxes = document.querySelectorAll(`#${formType}TenantContainer input[type="checkbox"]:checked`);
                const count = checkboxes.length;

                // Update counter badge
                const counterBadge = document.getElementById(`${formType}TenantCounter`);
                if (counterBadge) {
                    counterBadge.textContent = count.toString();
                    const list = document.getElementById(`${formType}TenantCheckboxList`);
                    if (list) {
                        if (count > 0) {
                            list.classList.add('has-selection');
                        } else {
                            list.classList.remove('has-selection');
                        }
                    }
                }

                // Update label counter
                const labelCounter = document.getElementById(`${formType}SelectedCount`);
                if (labelCounter) {
                    if (count > 0) {
                        labelCounter.textContent = `(${count} selezionate)`;
                    } else {
                        labelCounter.textContent = '';
                    }
                }
            }

            async updateUser() {
                const form = document.getElementById('editUserForm');
                const role = document.getElementById('editRole').value;
                const formData = new FormData();

                // Add basic fields
                formData.append('user_id', form.user_id.value);
                formData.append('first_name', form.first_name.value);
                formData.append('last_name', form.last_name.value);
                formData.append('email', form.email.value);
                if (form.password.value) {
                    formData.append('password', form.password.value);
                }
                formData.append('role', role);
                formData.append('csrf_token', document.getElementById('csrfToken').value);

                // Handle tenant assignment based on role
                if (role === 'admin') {
                    // Get all checked tenants for admin role
                    const checkedTenants = document.querySelectorAll('#editTenantContainer input[type="checkbox"]:checked');
                    if (checkedTenants.length === 0) {
                        this.showToast('Seleziona almeno un\'azienda per l\'admin', 'error');
                        return;
                    }
                    checkedTenants.forEach(checkbox => {
                        formData.append('tenant_ids[]', checkbox.value);
                    });
                } else if (role !== 'super_admin') {
                    // For manager and user roles, get single tenant
                    const tenantSelect = document.getElementById('editTenant');
                    if (tenantSelect && tenantSelect.value) {
                        formData.append('tenant_id', tenantSelect.value);
                    } else if (role === 'manager' || role === 'user') {
                        this.showToast('Seleziona un\'azienda', 'error');
                        return;
                    }
                }

                try {
                    const response = await fetch('api/users/update_v2.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.showToast('Utente aggiornato con successo', 'success');
                        closeModal('editModal');
                        this.loadUsers();
                    } else {
                        this.showToast(data.message || 'Errore nell\'aggiornamento utente', 'error');
                    }
                } catch (error) {
                    console.error('Error updating user:', error);
                    this.showToast('Errore di connessione', 'error');
                }
            }

            openDeleteModal(userId) {
                this.deleteUserId = userId;
                openModal('deleteModal');
            }

            async confirmDelete() {
                if (!this.deleteUserId) return;

                const formData = new FormData();
                formData.append('user_id', this.deleteUserId);
                formData.append('csrf_token', document.getElementById('csrfToken').value);

                try {
                    const response = await fetch('api/users/delete.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.showToast('Utente eliminato con successo', 'success');
                        closeModal('deleteModal');
                        this.loadUsers();
                    } else {
                        this.showToast(data.message || 'Errore nell\'eliminazione utente', 'error');
                    }
                } catch (error) {
                    console.error('Error deleting user:', error);
                    this.showToast('Errore di connessione', 'error');
                }

                this.deleteUserId = null;
            }

            async toggleStatus(userId) {
                const formData = new FormData();
                formData.append('user_id', userId);
                formData.append('csrf_token', document.getElementById('csrfToken').value);

                try {
                    const response = await fetch('api/users/toggle-status.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.showToast('Stato utente aggiornato', 'success');
                        this.loadUsers();
                    } else {
                        this.showToast(data.message || 'Errore nell\'aggiornamento stato', 'error');
                    }
                } catch (error) {
                    console.error('Error toggling status:', error);
                    this.showToast('Errore di connessione', 'error');
                }
            }

            getInitials(firstName, lastName) {
                return (firstName[0] + lastName[0]).toUpperCase();
            }

            getInitialsFromName(name) {
                const parts = name.split(' ');
                if (parts.length >= 2) {
                    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
                } else if (parts.length === 1 && parts[0].length >= 2) {
                    return parts[0].substring(0, 2).toUpperCase();
                }
                return 'U';
            }

            getRoleLabel(role) {
                const labels = {
                    'super_admin': 'Super Admin',
                    'admin': 'Admin',
                    'tenant_admin': 'Admin',
                    'manager': 'Manager',
                    'user': 'Utente',
                    'guest': 'Ospite'
                };
                return labels[role] || role;
            }

            getTenantDisplay(user) {
                if (user.role === 'super_admin') {
                    return '<em style="color: var(--color-gray-500)">Accesso globale</em>';
                } else if (user.role === 'admin' && user.company_names) {
                    // Display multiple companies for admin
                    const companies = user.company_names.split(',');
                    if (companies.length > 2) {
                        return `${companies[0]} +${companies.length - 1} altri`;
                    }
                    return companies.join(', ');
                }
                return user.tenant_name || '-';
            }

            formatDate(dateString) {
                const date = new Date(dateString);
                return date.toLocaleDateString('it-IT', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric'
                });
            }

            showToast(message, type = 'info') {
                const toast = document.getElementById('toast');
                toast.textContent = message;
                toast.className = `toast show ${type}`;

                setTimeout(() => {
                    toast.classList.remove('show');
                }, 3000);
            }
        }

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        function openAddModal() {
            document.getElementById('addUserForm').reset();
            // Set default role and trigger tenant field update
            document.getElementById('addRole').value = 'user';
            userManager.handleRoleChange('user', 'add');
            openModal('addModal');
        }

        function confirmDelete() {
            userManager.confirmDelete();
        }

        // Initialize when DOM is ready
        let userManager;
        document.addEventListener('DOMContentLoaded', () => {
            userManager = new UserManager();
        });
    </script>
</body>
</html>