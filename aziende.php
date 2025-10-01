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

// Check if user is super admin
$userRole = $currentUser['user_role'] ?? $currentUser['role'] ?? 'user';
$isSuperAdmin = ($userRole === 'super_admin');

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Gestione Aziende - CollaboraNexio</title>

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

        /* Additional company management specific styles */
        .form-section {
            border-top: 1px solid var(--color-gray-200);
            padding-top: var(--space-4);
            margin-top: var(--space-4);
        }

        .form-section:first-child {
            border-top: none;
            padding-top: 0;
            margin-top: 0;
        }

        .form-section-title {
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
            color: var(--color-gray-700);
            margin-bottom: var(--space-3);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: var(--space-3);
        }

        .form-row.single {
            grid-template-columns: 1fr;
        }

        .currency-input {
            position: relative;
        }

        .currency-prefix {
            position: absolute;
            left: var(--space-3);
            top: 50%;
            transform: translateY(-50%);
            color: var(--color-gray-600);
            font-size: var(--text-sm);
            pointer-events: none;
        }

        .currency-input input {
            padding-left: var(--space-8);
        }

        .companies-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-6);
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

        .companies-table {
            width: 100%;
            background: var(--color-white);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .companies-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .companies-table th {
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

        .companies-table td {
            padding: var(--space-4);
            border-bottom: 1px solid var(--color-gray-200);
        }

        .companies-table tbody tr:last-child td {
            border-bottom: none;
        }

        .companies-table tbody tr:hover {
            background: var(--color-gray-50);
        }

        .company-info-cell {
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }

        .company-avatar-table {
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

        .company-details-table {
            flex: 1;
        }

        .company-name-table {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
        }

        .company-code-table {
            font-size: var(--text-xs);
            color: var(--color-gray-600);
            margin-top: 2px;
        }

        .plan-badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: var(--text-xs);
            font-weight: var(--font-medium);
            border-radius: var(--radius-sm);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .plan-badge.enterprise {
            background: #FEF3C7;
            color: #92400E;
        }

        .plan-badge.professional {
            background: #DBEAFE;
            color: #1E3A8A;
        }

        .plan-badge.starter {
            background: #E0E7FF;
            color: #3730A3;
        }

        .plan-badge.trial {
            background: #FEE2E2;
            color: #991B1B;
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

        .status-badge.suspended {
            background: #FEE2E2;
            color: #991B1B;
        }

        .status-badge.pending {
            background: #FEF3C7;
            color: #92400E;
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
            max-width: 700px;
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

        .restricted-message {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            padding: var(--space-8);
            text-align: center;
            box-shadow: var(--shadow-sm);
        }

        .restricted-icon {
            font-size: 48px;
            margin-bottom: var(--space-4);
            opacity: 0.5;
        }

        .restricted-title {
            font-size: var(--text-xl);
            font-weight: var(--font-bold);
            color: var(--color-gray-900);
            margin-bottom: var(--space-2);
        }

        .restricted-text {
            color: var(--color-gray-600);
            font-size: var(--text-sm);
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
                    <a href="aziende.php" class="nav-item active"><i class="icon icon--building"></i> Aziende</a>
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
                        <div class="user-badge"><?php echo strtoupper(str_replace('_', ' ', $userRole)); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1 class="page-title">Gestione Aziende</h1>
                <div class="flex items-center gap-4">
                    <span class="text-sm text-muted">Gestisci aziende e piani</span>
                </div>
            </div>

            <div class="page-content">
                <?php if ($isSuperAdmin): ?>
                    <!-- Header with search and add button -->
                    <div class="companies-header">
                        <div class="search-bar">
                            <input type="text" id="searchInput" placeholder="Cerca aziende..." />
                            <span class="search-icon">🔍</span>
                        </div>
                        <button class="btn btn-primary" onclick="openAddModal()">
                            + Nuova Azienda
                        </button>
                    </div>

                    <!-- Companies table -->
                    <div class="companies-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Denominazione</th>
                                    <th>Codice Fiscale</th>
                                    <th>Partita IVA</th>
                                    <th>Settore</th>
                                    <th>Manager</th>
                                    <th>Dipendenti</th>
                                    <th>Stato</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody id="companiesTableBody">
                                <!-- Companies will be loaded here via JavaScript -->
                            </tbody>
                        </table>
                        <div id="emptyState" class="empty-state" style="display: none;">
                            <div class="empty-state-icon">🏢</div>
                            <div class="empty-state-text">Nessuna azienda trovata</div>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div class="pagination" id="pagination">
                        <!-- Pagination buttons will be loaded here via JavaScript -->
                    </div>
                <?php else: ?>
                    <!-- Restricted access message for non-super admin users -->
                    <div class="restricted-message">
                        <div class="restricted-icon">🔒</div>
                        <h2 class="restricted-title">Accesso Limitato</h2>
                        <p class="restricted-text">Solo i Super Admin possono gestire le aziende.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($isSuperAdmin): ?>
    <!-- Add Company Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Aggiungi Nuova Azienda</h2>
                <button class="modal-close" onclick="closeModal('addModal')">×</button>
            </div>
            <form id="addCompanyForm">
                <div class="modal-body">
                    <!-- Dati Identificativi -->
                    <div class="form-section">
                        <h3 class="form-section-title">Dati Identificativi</h3>
                        <div class="form-group">
                            <label for="addDenominazione">Denominazione *</label>
                            <input type="text" id="addDenominazione" name="denominazione" required />
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="addCodiceFiscale">Codice Fiscale *</label>
                                <input type="text" id="addCodiceFiscale" name="codice_fiscale"
                                       pattern="[A-Z0-9]{16}" maxlength="16"
                                       title="16 caratteri alfanumerici" required
                                       style="text-transform: uppercase;" />
                            </div>
                            <div class="form-group">
                                <label for="addPartitaIva">Partita IVA *</label>
                                <input type="text" id="addPartitaIva" name="partita_iva"
                                       pattern="[0-9]{11}" maxlength="11"
                                       title="11 cifre numeriche" required />
                            </div>
                        </div>
                    </div>

                    <!-- Sedi -->
                    <div class="form-section">
                        <h3 class="form-section-title">Sedi</h3>
                        <div class="form-group">
                            <label for="addSedeLegale">Sede Legale *</label>
                            <input type="text" id="addSedeLegale" name="sede_legale"
                                   placeholder="Via/Piazza, numero civico, CAP, Città (Provincia)" required />
                        </div>
                        <div class="form-group">
                            <label for="addSedeOperativa">Sede Operativa</label>
                            <input type="text" id="addSedeOperativa" name="sede_operativa"
                                   placeholder="Via/Piazza, numero civico, CAP, Città (Provincia)" />
                        </div>
                    </div>

                    <!-- Informazioni Aziendali -->
                    <div class="form-section">
                        <h3 class="form-section-title">Informazioni Aziendali</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="addSettore">Settore Merceologico *</label>
                                <select id="addSettore" name="settore_merceologico" required>
                                    <option value="">Seleziona un settore</option>
                                    <option value="agricoltura">Agricoltura e Allevamento</option>
                                    <option value="alimentare">Alimentare e Bevande</option>
                                    <option value="chimico">Chimico e Farmaceutico</option>
                                    <option value="commercio">Commercio all'ingrosso</option>
                                    <option value="commercio_dettaglio">Commercio al dettaglio</option>
                                    <option value="costruzioni">Costruzioni ed Edilizia</option>
                                    <option value="consulenza">Consulenza e Servizi Professionali</option>
                                    <option value="energia">Energia e Utilities</option>
                                    <option value="finanza">Finanza e Assicurazioni</option>
                                    <option value="immobiliare">Immobiliare</option>
                                    <option value="informatica">Informatica e Tecnologia</option>
                                    <option value="logistica">Logistica e Trasporti</option>
                                    <option value="manifatturiero">Manifatturiero</option>
                                    <option value="meccanico">Meccanico e Metalmeccanico</option>
                                    <option value="media">Media e Comunicazione</option>
                                    <option value="moda">Moda e Tessile</option>
                                    <option value="ristorazione">Ristorazione e Hospitality</option>
                                    <option value="sanita">Sanità e Servizi Sociali</option>
                                    <option value="servizi">Servizi alle Imprese</option>
                                    <option value="turismo">Turismo e Viaggi</option>
                                    <option value="altro">Altro</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="addNumeroDipendenti">Numero Dipendenti *</label>
                                <input type="number" id="addNumeroDipendenti" name="numero_dipendenti"
                                       min="0" required />
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="addDataCostituzione">Data Costituzione *</label>
                                <input type="date" id="addDataCostituzione" name="data_costituzione" required />
                            </div>
                            <div class="form-group">
                                <label for="addCapitaleSociale">Capitale Sociale (EUR)</label>
                                <div class="currency-input">
                                    <span class="currency-prefix">€</span>
                                    <input type="number" id="addCapitaleSociale" name="capitale_sociale"
                                           min="0" step="0.01" placeholder="10.000,00" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contatti -->
                    <div class="form-section">
                        <h3 class="form-section-title">Contatti</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="addTelefono">Telefono *</label>
                                <input type="tel" id="addTelefono" name="telefono"
                                       placeholder="+39 02 1234567" required />
                            </div>
                            <div class="form-group">
                                <label for="addEmailAziendale">Email Aziendale *</label>
                                <input type="email" id="addEmailAziendale" name="email_aziendale" required />
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="addPec">PEC (Posta Elettronica Certificata) *</label>
                            <input type="email" id="addPec" name="pec" required />
                        </div>
                    </div>

                    <!-- Gestione -->
                    <div class="form-section">
                        <h3 class="form-section-title">Gestione</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="addManager">Manager Aziendale *</label>
                                <select id="addManager" name="manager_user_id" required>
                                    <option value="">Seleziona un manager</option>
                                    <!-- Options will be loaded via JavaScript -->
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="addRappresentante">Rappresentante Legale *</label>
                                <input type="text" id="addRappresentante" name="rappresentante_legale" required />
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="addStatus">Stato *</label>
                                <select id="addStatus" name="status" required>
                                    <option value="active">Attivo</option>
                                    <option value="pending">In attesa</option>
                                    <option value="suspended">Sospeso</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="addPlan">Piano *</label>
                                <select id="addPlan" name="plan_type" required>
                                    <option value="trial">Trial</option>
                                    <option value="starter">Starter</option>
                                    <option value="professional">Professional</option>
                                    <option value="enterprise">Enterprise</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Annulla</button>
                    <button type="submit" class="btn btn-primary">Aggiungi Azienda</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Company Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Modifica Azienda</h2>
                <button class="modal-close" onclick="closeModal('editModal')">×</button>
            </div>
            <form id="editCompanyForm">
                <input type="hidden" id="editCompanyId" name="company_id" />
                <div class="modal-body">
                    <!-- Dati Identificativi -->
                    <div class="form-section">
                        <h3 class="form-section-title">Dati Identificativi</h3>
                        <div class="form-group">
                            <label for="editDenominazione">Denominazione *</label>
                            <input type="text" id="editDenominazione" name="denominazione" required />
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="editCodiceFiscale">Codice Fiscale *</label>
                                <input type="text" id="editCodiceFiscale" name="codice_fiscale"
                                       pattern="[A-Z0-9]{16}" maxlength="16"
                                       title="16 caratteri alfanumerici" required
                                       style="text-transform: uppercase;" />
                            </div>
                            <div class="form-group">
                                <label for="editPartitaIva">Partita IVA *</label>
                                <input type="text" id="editPartitaIva" name="partita_iva"
                                       pattern="[0-9]{11}" maxlength="11"
                                       title="11 cifre numeriche" required />
                            </div>
                        </div>
                    </div>

                    <!-- Sedi -->
                    <div class="form-section">
                        <h3 class="form-section-title">Sedi</h3>
                        <div class="form-group">
                            <label for="editSedeLegale">Sede Legale *</label>
                            <input type="text" id="editSedeLegale" name="sede_legale"
                                   placeholder="Via/Piazza, numero civico, CAP, Città (Provincia)" required />
                        </div>
                        <div class="form-group">
                            <label for="editSedeOperativa">Sede Operativa</label>
                            <input type="text" id="editSedeOperativa" name="sede_operativa"
                                   placeholder="Via/Piazza, numero civico, CAP, Città (Provincia)" />
                        </div>
                    </div>

                    <!-- Informazioni Aziendali -->
                    <div class="form-section">
                        <h3 class="form-section-title">Informazioni Aziendali</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="editSettore">Settore Merceologico *</label>
                                <select id="editSettore" name="settore_merceologico" required>
                                    <option value="">Seleziona un settore</option>
                                    <option value="agricoltura">Agricoltura e Allevamento</option>
                                    <option value="alimentare">Alimentare e Bevande</option>
                                    <option value="chimico">Chimico e Farmaceutico</option>
                                    <option value="commercio">Commercio all'ingrosso</option>
                                    <option value="commercio_dettaglio">Commercio al dettaglio</option>
                                    <option value="costruzioni">Costruzioni ed Edilizia</option>
                                    <option value="consulenza">Consulenza e Servizi Professionali</option>
                                    <option value="energia">Energia e Utilities</option>
                                    <option value="finanza">Finanza e Assicurazioni</option>
                                    <option value="immobiliare">Immobiliare</option>
                                    <option value="informatica">Informatica e Tecnologia</option>
                                    <option value="logistica">Logistica e Trasporti</option>
                                    <option value="manifatturiero">Manifatturiero</option>
                                    <option value="meccanico">Meccanico e Metalmeccanico</option>
                                    <option value="media">Media e Comunicazione</option>
                                    <option value="moda">Moda e Tessile</option>
                                    <option value="ristorazione">Ristorazione e Hospitality</option>
                                    <option value="sanita">Sanità e Servizi Sociali</option>
                                    <option value="servizi">Servizi alle Imprese</option>
                                    <option value="turismo">Turismo e Viaggi</option>
                                    <option value="altro">Altro</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="editNumeroDipendenti">Numero Dipendenti *</label>
                                <input type="number" id="editNumeroDipendenti" name="numero_dipendenti"
                                       min="0" required />
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="editDataCostituzione">Data Costituzione *</label>
                                <input type="date" id="editDataCostituzione" name="data_costituzione" required />
                            </div>
                            <div class="form-group">
                                <label for="editCapitaleSociale">Capitale Sociale (EUR)</label>
                                <div class="currency-input">
                                    <span class="currency-prefix">€</span>
                                    <input type="number" id="editCapitaleSociale" name="capitale_sociale"
                                           min="0" step="0.01" placeholder="10.000,00" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contatti -->
                    <div class="form-section">
                        <h3 class="form-section-title">Contatti</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="editTelefono">Telefono *</label>
                                <input type="tel" id="editTelefono" name="telefono"
                                       placeholder="+39 02 1234567" required />
                            </div>
                            <div class="form-group">
                                <label for="editEmailAziendale">Email Aziendale *</label>
                                <input type="email" id="editEmailAziendale" name="email_aziendale" required />
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="editPec">PEC (Posta Elettronica Certificata) *</label>
                            <input type="email" id="editPec" name="pec" required />
                        </div>
                    </div>

                    <!-- Gestione -->
                    <div class="form-section">
                        <h3 class="form-section-title">Gestione</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="editManager">Manager Aziendale *</label>
                                <select id="editManager" name="manager_user_id" required>
                                    <option value="">Seleziona un manager</option>
                                    <!-- Options will be loaded via JavaScript -->
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="editRappresentante">Rappresentante Legale *</label>
                                <input type="text" id="editRappresentante" name="rappresentante_legale" required />
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="editStatus">Stato *</label>
                                <select id="editStatus" name="status" required>
                                    <option value="active">Attivo</option>
                                    <option value="pending">In attesa</option>
                                    <option value="suspended">Sospeso</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="editPlan">Piano *</label>
                                <select id="editPlan" name="plan_type" required>
                                    <option value="trial">Trial</option>
                                    <option value="starter">Starter</option>
                                    <option value="professional">Professional</option>
                                    <option value="enterprise">Enterprise</option>
                                </select>
                            </div>
                        </div>
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
                <p>Sei sicuro di voler eliminare questa azienda?</p>
                <p class="text-muted text-sm">Questa azione eliminerà anche tutti gli utenti e i dati associati. Questa azione non può essere annullata.</p>
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
        class CompanyManager {
            constructor() {
                this.companies = [];
                this.managers = [];
                this.currentPage = 1;
                this.itemsPerPage = 10;
                this.searchQuery = '';
                this.deleteCompanyId = null;
                this.init();
            }

            init() {
                this.bindEvents();
                this.loadManagers();
                this.loadCompanies();
                this.setupValidation();
            }

            bindEvents() {
                // Search functionality
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.addEventListener('input', (e) => {
                        this.searchQuery = e.target.value;
                        this.currentPage = 1;
                        this.loadCompanies();
                    });
                }

                // Add company form
                const addForm = document.getElementById('addCompanyForm');
                if (addForm) {
                    addForm.addEventListener('submit', (e) => {
                        e.preventDefault();
                        this.addCompany();
                    });
                }

                // Edit company form
                const editForm = document.getElementById('editCompanyForm');
                if (editForm) {
                    editForm.addEventListener('submit', (e) => {
                        e.preventDefault();
                        this.updateCompany();
                    });
                }

                // Auto uppercase for Codice Fiscale
                document.querySelectorAll('input[name="codice_fiscale"]').forEach(input => {
                    input.addEventListener('input', (e) => {
                        e.target.value = e.target.value.toUpperCase();
                    });
                });
            }

            setupValidation() {
                // Codice Fiscale validation
                document.querySelectorAll('input[name="codice_fiscale"]').forEach(input => {
                    input.addEventListener('blur', (e) => {
                        const value = e.target.value;
                        if (value && !this.validateCodiceFiscale(value)) {
                            e.target.setCustomValidity('Codice Fiscale non valido (16 caratteri alfanumerici)');
                        } else {
                            e.target.setCustomValidity('');
                        }
                    });
                });

                // Partita IVA validation
                document.querySelectorAll('input[name="partita_iva"]').forEach(input => {
                    input.addEventListener('blur', (e) => {
                        const value = e.target.value;
                        if (value && !this.validatePartitaIVA(value)) {
                            e.target.setCustomValidity('Partita IVA non valida (11 cifre)');
                        } else {
                            e.target.setCustomValidity('');
                        }
                    });
                });
            }

            validateCodiceFiscale(cf) {
                return /^[A-Z0-9]{16}$/.test(cf);
            }

            validatePartitaIVA(piva) {
                if (!/^[0-9]{11}$/.test(piva)) return false;

                // Algoritmo di validazione Partita IVA italiana
                let sum = 0;
                for (let i = 0; i < 11; i++) {
                    const digit = parseInt(piva.charAt(i));
                    if (i % 2 === 0) {
                        sum += digit;
                    } else {
                        const doubled = digit * 2;
                        sum += doubled > 9 ? doubled - 9 : doubled;
                    }
                }
                return sum % 10 === 0;
            }

            async loadManagers() {
                try {
                    const response = await fetch('api/users/list.php?role=manager,admin', {
                        headers: {
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        }
                    });

                    const data = await response.json();
                    if (data.success && data.data) {
                        this.managers = data.data.users || [];
                        this.populateManagerDropdowns();
                    }
                } catch (error) {
                    console.error('Error loading managers:', error);
                }
            }

            populateManagerDropdowns() {
                const addSelect = document.getElementById('addManager');
                const editSelect = document.getElementById('editManager');

                const options = '<option value="">Seleziona un manager</option>' +
                    this.managers.map(manager =>
                        `<option value="${manager.id}">${manager.name} (${manager.email})</option>`
                    ).join('');

                if (addSelect) addSelect.innerHTML = options;
                if (editSelect) editSelect.innerHTML = options;
            }

            async loadCompanies() {
                try {
                    const response = await fetch(`api/companies/list.php?page=${this.currentPage}&search=${encodeURIComponent(this.searchQuery)}`, {
                        headers: {
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        }
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.companies = data.data?.companies || [];
                        this.renderCompanies();
                        this.renderPagination(data.data?.total_pages || 1);
                    } else {
                        this.showToast(data.message || 'Errore nel caricamento aziende', 'error');
                    }
                } catch (error) {
                    console.error('Error loading companies:', error);
                    this.showToast('Errore di connessione', 'error');
                    this.companies = [];
                    this.renderCompanies();
                }
            }

            renderCompanies() {
                const tbody = document.getElementById('companiesTableBody');
                const emptyState = document.getElementById('emptyState');

                if (!this.companies || this.companies.length === 0) {
                    tbody.innerHTML = '';
                    emptyState.style.display = 'block';
                    return;
                }

                emptyState.style.display = 'none';
                tbody.innerHTML = this.companies.map(company => {
                    const initials = this.getInitials(company.denominazione || company.name || 'AZ');
                    const status = company.status || 'active';
                    const managerName = company.manager_name || '-';
                    const settore = this.getSettoreLabel(company.settore_merceologico);

                    return `
                    <tr>
                        <td>
                            <div class="company-info-cell">
                                <div class="company-avatar-table">${initials}</div>
                                <div class="company-details-table">
                                    <div class="company-name-table">${company.denominazione || company.name || '-'}</div>
                                    <div class="company-code-table">${company.sede_legale || ''}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <code style="font-size: 11px;">${company.codice_fiscale || '-'}</code>
                        </td>
                        <td>
                            <code>${company.partita_iva || '-'}</code>
                        </td>
                        <td>
                            <span style="font-size: var(--text-xs);">${settore}</span>
                        </td>
                        <td>
                            <span style="font-size: var(--text-sm);">${managerName}</span>
                        </td>
                        <td style="text-align: center;">
                            <strong>${company.numero_dipendenti || 0}</strong>
                        </td>
                        <td>
                            <span class="status-badge ${status}">
                                <span class="status-indicator"></span>
                                ${this.getStatusLabel(status)}
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon edit" onclick="companyManager.openEditModal(${company.id})" title="Modifica">
                                    ✏️
                                </button>
                                <button class="btn-icon delete" onclick="companyManager.openDeleteModal(${company.id})" title="Elimina">
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
                html += `<button onclick="companyManager.goToPage(${this.currentPage - 1})" ${this.currentPage === 1 ? 'disabled' : ''}>←</button>`;

                // Page numbers
                for (let i = 1; i <= totalPages; i++) {
                    if (i === 1 || i === totalPages || (i >= this.currentPage - 2 && i <= this.currentPage + 2)) {
                        html += `<button onclick="companyManager.goToPage(${i})" class="${i === this.currentPage ? 'active' : ''}">${i}</button>`;
                    } else if (i === this.currentPage - 3 || i === this.currentPage + 3) {
                        html += `<span>...</span>`;
                    }
                }

                // Next button
                html += `<button onclick="companyManager.goToPage(${this.currentPage + 1})" ${this.currentPage === totalPages ? 'disabled' : ''}>→</button>`;

                pagination.innerHTML = html;
            }

            goToPage(page) {
                this.currentPage = page;
                this.loadCompanies();
            }

            async addCompany() {
                const form = document.getElementById('addCompanyForm');
                const formData = new FormData(form);
                formData.append('csrf_token', document.getElementById('csrfToken').value);

                try {
                    const response = await fetch('api/companies/create.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.showToast('Azienda creata con successo', 'success');
                        closeModal('addModal');
                        form.reset();
                        this.loadCompanies();
                    } else {
                        this.showToast(data.message || 'Errore nella creazione azienda', 'error');
                    }
                } catch (error) {
                    console.error('Error adding company:', error);
                    this.showToast('Errore di connessione', 'error');
                }
            }

            openEditModal(companyId) {
                const company = this.companies.find(c => c.id === companyId);
                if (!company) return;

                // Populate all fields
                document.getElementById('editCompanyId').value = company.id;
                document.getElementById('editDenominazione').value = company.denominazione || company.name || '';
                document.getElementById('editCodiceFiscale').value = company.codice_fiscale || '';
                document.getElementById('editPartitaIva').value = company.partita_iva || '';
                document.getElementById('editSedeLegale').value = company.sede_legale || '';
                document.getElementById('editSedeOperativa').value = company.sede_operativa || '';
                document.getElementById('editSettore').value = company.settore_merceologico || '';
                document.getElementById('editNumeroDipendenti').value = company.numero_dipendenti || 0;
                document.getElementById('editDataCostituzione').value = company.data_costituzione || '';
                document.getElementById('editCapitaleSociale').value = company.capitale_sociale || '';
                document.getElementById('editTelefono').value = company.telefono || '';
                document.getElementById('editEmailAziendale').value = company.email_aziendale || '';
                document.getElementById('editPec').value = company.pec || '';
                document.getElementById('editManager').value = company.manager_user_id || '';
                document.getElementById('editRappresentante').value = company.rappresentante_legale || '';
                document.getElementById('editStatus').value = company.status || 'active';
                document.getElementById('editPlan').value = company.plan_type || 'starter';

                openModal('editModal');
            }

            async updateCompany() {
                const form = document.getElementById('editCompanyForm');
                const formData = new FormData(form);
                formData.append('csrf_token', document.getElementById('csrfToken').value);

                try {
                    const response = await fetch('api/companies/update.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.showToast('Azienda aggiornata con successo', 'success');
                        closeModal('editModal');
                        this.loadCompanies();
                    } else {
                        this.showToast(data.message || 'Errore nell\'aggiornamento azienda', 'error');
                    }
                } catch (error) {
                    console.error('Error updating company:', error);
                    this.showToast('Errore di connessione', 'error');
                }
            }

            openDeleteModal(companyId) {
                this.deleteCompanyId = companyId;
                openModal('deleteModal');
            }

            async confirmDelete() {
                if (!this.deleteCompanyId) return;

                const formData = new FormData();
                formData.append('company_id', this.deleteCompanyId);
                formData.append('csrf_token', document.getElementById('csrfToken').value);

                try {
                    const response = await fetch('api/companies/delete.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.showToast('Azienda eliminata con successo', 'success');
                        closeModal('deleteModal');
                        this.loadCompanies();
                    } else {
                        this.showToast(data.message || 'Errore nell\'eliminazione azienda', 'error');
                    }
                } catch (error) {
                    console.error('Error deleting company:', error);
                    this.showToast('Errore di connessione', 'error');
                }

                this.deleteCompanyId = null;
            }

            getInitials(name) {
                if (!name) return 'AZ';
                const words = name.split(' ');
                if (words.length >= 2) {
                    return (words[0][0] + words[1][0]).toUpperCase();
                }
                return name.substring(0, 2).toUpperCase();
            }

            getPlanLabel(plan) {
                const labels = {
                    'trial': 'Trial',
                    'starter': 'Starter',
                    'professional': 'Professional',
                    'enterprise': 'Enterprise'
                };
                return labels[plan] || plan;
            }

            getStatusLabel(status) {
                const labels = {
                    'active': 'Attivo',
                    'suspended': 'Sospeso',
                    'pending': 'In attesa'
                };
                return labels[status] || status;
            }

            getSettoreLabel(settore) {
                const labels = {
                    'agricoltura': 'Agricoltura',
                    'alimentare': 'Alimentare',
                    'chimico': 'Chimico',
                    'commercio': 'Commercio Ingrosso',
                    'commercio_dettaglio': 'Commercio Dettaglio',
                    'costruzioni': 'Costruzioni',
                    'consulenza': 'Consulenza',
                    'energia': 'Energia',
                    'finanza': 'Finanza',
                    'immobiliare': 'Immobiliare',
                    'informatica': 'IT/Tecnologia',
                    'logistica': 'Logistica',
                    'manifatturiero': 'Manifatturiero',
                    'meccanico': 'Meccanico',
                    'media': 'Media',
                    'moda': 'Moda',
                    'ristorazione': 'Ristorazione',
                    'sanita': 'Sanità',
                    'servizi': 'Servizi',
                    'turismo': 'Turismo',
                    'altro': 'Altro'
                };
                return labels[settore] || settore || '-';
            }

            formatCurrency(amount) {
                if (!amount) return '';
                return new Intl.NumberFormat('it-IT', {
                    style: 'currency',
                    currency: 'EUR'
                }).format(amount);
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
            document.getElementById('addCompanyForm').reset();
            // Set default values
            document.getElementById('addStatus').value = 'active';
            document.getElementById('addPlan').value = 'starter';
            openModal('addModal');
        }

        function confirmDelete() {
            companyManager.confirmDelete();
        }

        // Initialize when DOM is ready
        let companyManager;
        document.addEventListener('DOMContentLoaded', () => {
            <?php if ($isSuperAdmin): ?>
            companyManager = new CompanyManager();
            <?php endif; ?>
        });
    </script>
    <?php endif; ?>
</body>
</html>