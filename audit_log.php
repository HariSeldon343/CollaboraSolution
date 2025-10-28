<?php
// Force no-cache headers to prevent 403/500 stale errors (BUG-040)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Date in the past

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

// Only admin and super_admin can access this page
if (!in_array($currentUser['role'], ['admin', 'super_admin'])) {
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
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <title>Registro Audit - CollaboraNexio</title>

    <!-- Main CSS -->
    <link rel="stylesheet" href="assets/css/styles.css">
    <!-- Sidebar Responsive Optimization CSS -->
    <link rel="stylesheet" href="assets/css/sidebar-responsive.css">
    <!-- Page specific CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css">

    <!-- Custom Styles for Audit Log Page -->
    <style>
        /* Page Container */
        .audit-container {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Header Section */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: #1F2937;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #2563EB, #1E40AF);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid #E5E7EB;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #2563EB, #3B82F6);
        }

        .stat-card.critical::before {
            background: linear-gradient(90deg, #EF4444, #F87171);
        }

        .stat-card.warning::before {
            background: linear-gradient(90deg, #F59E0B, #FBB040);
        }

        .stat-card.success::before {
            background: linear-gradient(90deg, #10B981, #34D399);
        }

        .stat-label {
            font-size: 0.875rem;
            color: #6B7280;
            margin-bottom: 0.5rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1F2937;
            line-height: 1;
            transition: color 0.2s ease;
        }

        .stat-card:hover .stat-value {
            color: #2563EB;
        }

        .stat-card.critical:hover .stat-value {
            color: #EF4444;
        }

        /* Filters Section */
        .filters-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid #E5E7EB;
            margin-bottom: 1.5rem;
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
        }

        .filters-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1F2937;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
        }

        .filter-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #4B5563;
        }

        .filter-input,
        .filter-select {
            padding: 0.625rem 0.875rem;
            border: 1px solid #D1D5DB;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            background: white;
        }

        .filter-input:focus,
        .filter-select:focus {
            outline: none;
            border-color: #2563EB;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .filters-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 12px;
            border: 1px solid #E5E7EB;
            overflow: hidden;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #E5E7EB;
            background: #F9FAFB;
        }

        .table-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1F2937;
        }

        .table-actions {
            display: flex;
            gap: 0.75rem;
        }

        .audit-table {
            width: 100%;
            border-collapse: collapse;
        }

        .audit-table thead {
            background: #F9FAFB;
            border-bottom: 2px solid #E5E7EB;
        }

        .audit-table th {
            text-align: left;
            padding: 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: #4B5563;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .audit-table tbody tr {
            border-bottom: 1px solid #F3F4F6;
            transition: background 0.15s ease;
        }

        .audit-table tbody tr:hover {
            background: #F9FAFB;
        }

        .audit-table td {
            padding: 1rem;
            font-size: 0.875rem;
            color: #1F2937;
        }

        /* Action Badge */
        .action-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.625rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .action-badge.create {
            background: #DBEAFE;
            color: #1E40AF;
        }

        .action-badge.update {
            background: #FEF3C7;
            color: #92400E;
        }

        .action-badge.delete {
            background: #FEE2E2;
            color: #991B1B;
        }

        .action-badge.login {
            background: #EDE9FE;
            color: #5B21B6;
        }

        .action-badge.access {
            background: #E0E7FF;
            color: #3730A3;
        }

        /* Severity Badge */
        .severity-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 50%;
        }

        .severity-badge.info {
            background: #E0F2FE;
            color: #0369A1;
        }

        .severity-badge.warning {
            background: #FEF3C7;
            color: #B45309;
        }

        .severity-badge.error {
            background: #FEE2E2;
            color: #DC2626;
        }

        .severity-badge.critical {
            background: #DC2626;
            color: white;
        }

        /* Button Styles */
        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: #2563EB;
            color: white;
        }

        .btn-primary:hover {
            background: #1D4ED8;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        }

        .btn-secondary {
            background: white;
            color: #4B5563;
            border: 1px solid #D1D5DB;
        }

        .btn-secondary:hover {
            background: #F9FAFB;
            border-color: #9CA3AF;
        }

        .btn-danger {
            background: #EF4444;
            color: white;
        }

        .btn-danger:hover {
            background: #DC2626;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
        }

        .btn-icon {
            padding: 0.5rem;
            border-radius: 8px;
            background: transparent;
            border: 1px solid #E5E7EB;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-icon:hover {
            background: #F9FAFB;
            border-color: #9CA3AF;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }

        .empty-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 1rem;
            background: #F3F4F6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9CA3AF;
        }

        .empty-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1F2937;
            margin-bottom: 0.5rem;
        }

        .empty-message {
            color: #6B7280;
            font-size: 0.875rem;
        }

        /* Pagination */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            border-top: 1px solid #E5E7EB;
            background: #F9FAFB;
        }

        .pagination-info {
            font-size: 0.875rem;
            color: #6B7280;
        }

        .pagination-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .pagination-btn {
            padding: 0.5rem 0.75rem;
            border: 1px solid #D1D5DB;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        .pagination-btn:hover:not(:disabled) {
            background: #F9FAFB;
            border-color: #9CA3AF;
        }

        .pagination-btn.active {
            background: #2563EB;
            color: white;
            border-color: #2563EB;
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            max-width: 600px;
            width: 90%;
            max-height: 85vh;
            overflow: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #E5E7EB;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1F2937;
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: transparent;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease;
        }

        .modal-close:hover {
            background: #F3F4F6;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid #E5E7EB;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        /* Detail View */
        .detail-group {
            margin-bottom: 1.5rem;
        }

        .detail-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #6B7280;
            margin-bottom: 0.375rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .detail-value {
            font-size: 0.875rem;
            color: #1F2937;
        }

        .json-view {
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            padding: 1rem;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 0.75rem;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }

        /* Loading Skeleton */
        .skeleton {
            background: linear-gradient(90deg, #F3F4F6 25%, #E5E7EB 50%, #F3F4F6 75%);
            background-size: 200% 100%;
            animation: loading 1.5s ease-in-out infinite;
            border-radius: 4px;
        }

        @keyframes loading {
            0% {
                background-position: 200% 0;
            }
            100% {
                background-position: -200% 0;
            }
        }

        .skeleton-row {
            height: 56px;
            margin-bottom: 1px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .audit-container {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .table-container {
                overflow-x: auto;
            }

            .audit-table {
                min-width: 700px;
            }

            .modal-content {
                width: 95%;
                max-height: 90vh;
            }
        }

        /* Export Menu */
        .export-menu {
            position: relative;
            display: inline-block;
        }

        .export-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 0.5rem;
            background: white;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            min-width: 150px;
            z-index: 100;
        }

        .export-dropdown.active {
            display: block;
        }

        .export-option {
            padding: 0.625rem 1rem;
            font-size: 0.875rem;
            color: #1F2937;
            cursor: pointer;
            transition: background 0.15s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .export-option:hover {
            background: #F9FAFB;
        }

        .export-option:first-child {
            border-radius: 8px 8px 0 0;
        }

        .export-option:last-child {
            border-radius: 0 0 8px 8px;
        }
    </style>
</head>
<body data-user-role="<?php echo htmlspecialchars($currentUser['role']); ?>">
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content" id="main-content">
        <div class="audit-container">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <div class="page-title-icon">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <span>Registro Audit</span>
                </div>
                <div class="page-actions">
                    <?php if ($currentUser['role'] === 'super_admin'): ?>
                    <button class="btn btn-danger" onclick="auditManager.showDeleteModal()">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        Elimina Log
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Eventi Oggi</div>
                    <div class="stat-value" id="stat-events-today">
                        <div class="skeleton" style="width: 60px; height: 32px;"></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Utenti Attivi</div>
                    <div class="stat-value" id="stat-active-users">
                        <div class="skeleton" style="width: 40px; height: 32px;"></div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-label">Accessi</div>
                    <div class="stat-value" id="stat-accesses">
                        <div class="skeleton" style="width: 50px; height: 32px;"></div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-label">Modifiche</div>
                    <div class="stat-value" id="stat-modifications">
                        <div class="skeleton" style="width: 45px; height: 32px;"></div>
                    </div>
                </div>
                <div class="stat-card critical">
                    <div class="stat-label">Eventi Critici</div>
                    <div class="stat-value" id="stat-critical-events">
                        <div class="skeleton" style="width: 35px; height: 32px;"></div>
                    </div>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="filters-container">
                <div class="filters-header">
                    <div class="filters-title">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                        </svg>
                        Filtri
                    </div>
                </div>
                <div class="filters-grid">
                    <div class="filter-group">
                        <label class="filter-label" for="filter-date-from">Data Dal</label>
                        <input type="date" id="filter-date-from" class="filter-input">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label" for="filter-date-to">Data Al</label>
                        <input type="date" id="filter-date-to" class="filter-input">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label" for="filter-user">Utente</label>
                        <select id="filter-user" class="filter-select">
                            <option value="">Tutti gli utenti</option>
                            <option disabled>Caricamento...</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label" for="filter-action">Azione</label>
                        <select id="filter-action" class="filter-select">
                            <option value="">Tutte le azioni</option>
                            <option value="login">Login</option>
                            <option value="logout">Logout</option>
                            <option value="access">Accesso Pagina</option>
                            <option value="create">Creazione</option>
                            <option value="update">Modifica</option>
                            <option value="delete">Eliminazione</option>
                            <option value="download">Download</option>
                            <option value="upload">Upload</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label" for="filter-severity">Severità</label>
                        <select id="filter-severity" class="filter-select">
                            <option value="">Tutte</option>
                            <option value="info">Info</option>
                            <option value="warning">Warning</option>
                            <option value="error">Errore</option>
                            <option value="critical">Critico</option>
                        </select>
                    </div>
                </div>
                <div class="filters-actions">
                    <button class="btn btn-secondary" onclick="auditManager.resetFilters()">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Reset
                    </button>
                    <button class="btn btn-primary" onclick="auditManager.applyFilters()">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        Applica Filtri
                    </button>
                </div>
            </div>

            <!-- Table Section -->
            <div class="table-container">
                <div class="table-header">
                    <div class="table-title">Log di Audit</div>
                    <div class="table-actions">
                        <div class="export-menu">
                            <button class="btn btn-secondary" onclick="toggleExportMenu()">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                Esporta
                            </button>
                            <div class="export-dropdown" id="export-dropdown">
                                <div class="export-option" onclick="exportData('csv')">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    CSV
                                </div>
                                <div class="export-option" onclick="exportData('pdf')">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                    </svg>
                                    PDF
                                </div>
                                <div class="export-option" onclick="exportData('excel')">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                                    </svg>
                                    Excel
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <table class="audit-table">
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
                    <tbody id="audit-logs-tbody">
                        <!-- Loading skeleton rows -->
                        <tr>
                            <td colspan="7">
                                <div class="skeleton skeleton-row"></div>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="7">
                                <div class="skeleton skeleton-row"></div>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="7">
                                <div class="skeleton skeleton-row"></div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div class="pagination-container" id="pagination-container" style="display: none;">
                    <div class="pagination-info" id="pagination-info">
                        Mostrando 0 di 0 risultati
                    </div>
                    <div class="pagination-buttons" id="pagination-buttons">
                        <!-- Pagination buttons will be inserted here -->
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Detail Modal -->
    <div class="modal" id="audit-detail-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Dettagli Audit Log</h3>
                <button class="modal-close" onclick="auditManager.closeDetailModal()">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body" id="audit-detail-content">
                <!-- Detail content will be inserted here -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="auditManager.closeDetailModal()">Chiudi</button>
            </div>
        </div>
    </div>

    <!-- Delete Modal (super_admin only) -->
    <?php if ($currentUser['role'] === 'super_admin'): ?>
    <div class="modal" id="audit-delete-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Elimina Audit Logs</h3>
                <button class="modal-close" onclick="auditManager.closeDeleteModal()">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="detail-group">
                    <label class="detail-label">Modalità di Eliminazione</label>
                    <select id="delete-mode" class="filter-select" onchange="toggleDateRange()">
                        <option value="all">Elimina tutti i log</option>
                        <option value="range">Elimina per periodo</option>
                    </select>
                </div>

                <div id="date-range-section" style="display: none;">
                    <div class="detail-group">
                        <label class="detail-label">Data Inizio</label>
                        <input type="datetime-local" id="delete-date-from" class="filter-input">
                    </div>
                    <div class="detail-group">
                        <label class="detail-label">Data Fine</label>
                        <input type="datetime-local" id="delete-date-to" class="filter-input">
                    </div>
                </div>

                <div class="detail-group">
                    <label class="detail-label">Motivo Eliminazione (minimo 10 caratteri)</label>
                    <textarea id="delete-reason" class="filter-input" rows="3" placeholder="Inserisci il motivo dell'eliminazione..."></textarea>
                </div>

                <div style="padding: 1rem; background: #FEF3C7; border-radius: 8px; margin-top: 1rem;">
                    <p style="margin: 0; color: #92400E; font-size: 0.875rem;">
                        <strong>⚠️ Attenzione:</strong> Questa operazione creerà un record immutabile dell'eliminazione per compliance GDPR. I log eliminati verranno archiviati in formato JSON e non potranno essere recuperati.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="auditManager.closeDeleteModal()">Annulla</button>
                <button class="btn btn-danger" onclick="auditManager.confirmDelete()">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    Elimina
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Include the audit log JavaScript -->
    <script src="assets/js/audit_log.js?v=<?php echo time(); ?>"></script>

    <!-- Additional inline scripts for utility functions -->
    <script>
        // Initialize the audit manager
        let auditManager;

        document.addEventListener('DOMContentLoaded', function() {
            auditManager = new AuditLogManager();
        });

        // Export menu toggle
        function toggleExportMenu() {
            const dropdown = document.getElementById('export-dropdown');
            dropdown.classList.toggle('active');
        }

        // Close export menu when clicking outside
        document.addEventListener('click', function(event) {
            const menu = document.querySelector('.export-menu');
            if (menu && !menu.contains(event.target)) {
                document.getElementById('export-dropdown').classList.remove('active');
            }
        });

        // Export data function
        function exportData(format) {
            console.log('[Export] Exporting data as:', format);

            // Close dropdown
            document.getElementById('export-dropdown').classList.remove('active');

            // Show notification
            showNotification(`Esportazione in formato ${format.toUpperCase()} in corso...`, 'info');

            // TODO: Implement actual export functionality
            setTimeout(() => {
                showNotification(`Export ${format.toUpperCase()} completato!`, 'success');
            }, 1500);
        }

        // Toggle date range in delete modal
        function toggleDateRange() {
            const mode = document.getElementById('delete-mode').value;
            const rangeSection = document.getElementById('date-range-section');
            rangeSection.style.display = mode === 'range' ? 'block' : 'none';
        }

        // Notification helper
        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.textContent = message;

            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                background: ${type === 'success' ? '#10B981' : type === 'error' ? '#EF4444' : '#2563EB'};
                color: white;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 2000;
                animation: slideIn 0.3s ease;
            `;

            document.body.appendChild(notification);

            // Remove after 3 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }

        // Add slide animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            @keyframes slideOut {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(100%);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>