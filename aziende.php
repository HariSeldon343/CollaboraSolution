<?php
// Initialize session with proper configuration
require_once __DIR__ . '/includes/session_init.php';
// Authentication check - redirect to login if not authenticated
require_once __DIR__ . '/includes/auth_simple.php';
// Include Italian provinces data
require_once __DIR__ . '/includes/italian_provinces.php';
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

// Enforce Page Visibility access rules (configurazioni.php -> Visibilità Pagine)
require_once __DIR__ . '/includes/page_access_check.php';
checkPageAccess('aziende');

// Check if user is super admin
$userRole = $currentUser['user_role'] ?? $currentUser['role'] ?? 'user';
$isSuperAdmin = ($userRole === 'super_admin');

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();

// Prevent HTML caching (debug/consistency across tunnel/prod)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Build marker to verify served version in "View Source"
$cnxBuildId = 'aziende.php@' . (string)@filemtime(__FILE__);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Gestione Aziende - Nexio';
    $pageCss = ['assets/css/dashboard.css'];
    require __DIR__ . '/includes/layout_head.php';
?>

    <!-- CNX_BUILD_ID: <?php echo htmlspecialchars($cnxBuildId); ?> -->

    <style>
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

        .form-group.span-2 {
            grid-column: span 2;
        }

        /* Styles for Sedi Operative cards */
        .sede-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            position: relative;
        }

        .sede-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .sede-card-header h4 {
            margin: 0;
            font-size: 14px;
            color: #495057;
            font-weight: 600;
        }

        .btn-remove {
            background: #dc3545;
            color: white;
            border: none;
            padding: 4px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background 0.2s;
        }

        .btn-remove:hover {
            background: #c82333;
        }

        #sediOperativeContainer {
            max-height: 400px;
            overflow-y: auto;
            margin-bottom: 12px;
        }

        .btn-add-sede {
            background: var(--color-primary);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: var(--text-sm);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        }

        .btn-add-sede:hover {
            background: var(--color-primary-dark);
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

        /* Municipality Autocomplete Styles */
        .municipality-autocomplete-wrapper {
            position: relative;
        }

        .municipality-autocomplete-results {
            position: absolute;
            z-index: 1000;
            background: white;
            border: 1px solid #ddd;
            max-height: 300px;
            overflow-y: auto;
            width: 100%;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-radius: var(--radius-md);
            margin-top: 2px;
            display: none;
        }

        .municipality-autocomplete-results.show {
            display: block;
        }

        .autocomplete-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.15s ease;
        }

        .autocomplete-item:last-child {
            border-bottom: none;
        }

        .autocomplete-item:hover,
        .autocomplete-item.selected {
            background-color: #f8f9fa;
        }

        .autocomplete-item.selected {
            background-color: #e7f3ff;
        }

        .mun-name {
            font-weight: 500;
            color: #333;
            display: block;
        }

        .mun-province {
            font-size: 0.85em;
            color: #666;
            margin-left: 5px;
        }

        .autocomplete-item strong {
            color: #007bff;
            font-weight: 600;
        }

        .autocomplete-loading {
            padding: 10px 15px;
            text-align: center;
            color: #666;
            font-size: 0.9em;
        }

        .autocomplete-no-results {
            padding: 10px 15px;
            text-align: center;
            color: #999;
            font-size: 0.9em;
        }

        /* Alternative Tax Code Validation Styles */
        input[data-alt-tax-field].alt-tax-valid {
            border-color: #28a745 !important;
            background-color: #f8fff9;
        }

        input[data-alt-tax-field].alt-tax-invalid {
            border-color: #dc3545 !important;
            background-color: #fff8f8;
        }

        .alt-tax-asterisk {
            color: #dc3545;
            transition: color 0.2s ease;
        }

        .alt-tax-asterisk.valid {
            color: #28a745;
        }

        .alt-tax-validation-message {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            color: #856404;
            padding: 12px 16px;
            margin-bottom: 16px;
            font-size: 14px;
            display: none;
        }

        .alt-tax-validation-message.show {
            display: block;
        }

        .alt-tax-validation-message i {
            margin-right: 8px;
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
<?php require __DIR__ . '/includes/layout_start.php'; ?>
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
                        <div style="display:flex; gap: 10px; align-items:center;">
                            <button class="btn btn-danger" id="bulkDeleteBtn" onclick="companyManager.bulkDeleteSelected()" disabled
                                    title="Seleziona una o più aziende dalla tabella per eliminarle">
                                🗑️ Elimina selezionate <span id="bulkDeleteCount" style="opacity:.9;"></span>
                            </button>
                            <button class="btn btn-primary" onclick="openAddModal()">
                                + Nuova Azienda
                            </button>
                        </div>
                    </div>

                    <!-- Companies table -->
                    <div class="companies-table">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 44px; text-align:center;">
                                        <input type="checkbox" id="companiesSelectAll" title="Seleziona tutti" />
                                    </th>
                                    <th>ID</th>
                                    <th>Denominazione</th>
                                    <th>Codice Fiscale / Partita IVA</th>
                                    <th>Comune (Sede Legale)</th>
                                    <th>Manager</th>
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
                <?php elseif (in_array((string)$userRole, ['admin', 'manager'], true)): ?>
                    <!-- Limited mode: Admin/Manager can manage tenant roles (if enabled) but cannot manage company registry -->
                    <div class="companies-header">
                        <div class="search-bar">
                            <input type="text" id="searchInput" placeholder="Cerca aziende..." />
                            <span class="search-icon">🔍</span>
                        </div>
                        <div class="text-sm text-muted" style="padding: 8px 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
                            Modalità limitata: puoi gestire solo i <strong>Ruoli Aziendali</strong> (se abilitato dal Super Admin).
                        </div>
                    </div>

                    <div class="companies-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Denominazione</th>
                                    <th>Comune (Sede Legale)</th>
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

                    <div class="pagination" id="pagination"></div>
                <?php else: ?>
                    <!-- Restricted access message for other users -->
                    <div class="restricted-message">
                        <div class="restricted-icon">🔒</div>
                        <h2 class="restricted-title">Accesso Limitato</h2>
                        <p class="restricted-text">Non hai i permessi per accedere a questa pagina.</p>
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
                            <label for="addDenominazione">Denominazione</label>
                            <input type="text" id="addDenominazione" name="denominazione" />
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="addCodiceFiscale">Codice Fiscale <span class="alt-tax-asterisk">*</span></label>
                                <input type="text" id="addCodiceFiscale" name="codice_fiscale"
                                       pattern="[A-Z0-9]{16}" maxlength="16"
                                       title="16 caratteri alfanumerici"
                                       data-alt-tax-field="cf"
                                       style="text-transform: uppercase;" />
                            </div>
                            <div class="form-group">
                                <label for="addPartitaIva">Partita IVA <span class="alt-tax-asterisk">*</span></label>
                                <input type="text" id="addPartitaIva" name="partita_iva"
                                       pattern="[0-9]{11}" maxlength="11"
                                       title="11 cifre numeriche"
                                       data-alt-tax-field="piva" />
                            </div>
                        </div>
                    </div>

                    <!-- Sede Legale -->
                    <div class="form-section">
                        <h3 class="form-section-title">Sede Legale *</h3>
                        <div class="form-row">
                            <div class="form-group span-2">
                                <label for="addSedeLegaleIndirizzo">Indirizzo *</label>
                                <input type="text" id="addSedeLegaleIndirizzo" name="sede_legale_indirizzo"
                                       placeholder="Via Roma" required />
                            </div>
                            <div class="form-group">
                                <label for="addSedeLegaleCivico">Civico *</label>
                                <input type="text" id="addSedeLegaleCivico" name="sede_legale_civico"
                                       placeholder="123" required />
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="addSedeLegaleCap">CAP *</label>
                                <input type="text" id="addSedeLegaleCap" name="sede_legale_cap"
                                       pattern="[0-9]{5}" maxlength="5" placeholder="00100" required />
                            </div>
                            <div class="form-group">
                                <label for="addSedeLegaleComune">Comune *</label>
                                <input type="text" id="addSedeLegaleComune" name="sede_legale_comune"
                                       placeholder="Roma" required />
                            </div>
                            <div class="form-group">
                                <label for="addSedeLegaleProvincia">Provincia *</label>
                                <select id="addSedeLegaleProvincia" name="sede_legale_provincia" required>
                                    <?php echo getProvinceOptions(); ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Sedi Operative -->
                    <div class="form-section">
                        <h3 class="form-section-title">Sedi Operative</h3>
                        <div id="addSediOperativeContainer">
                            <!-- Dynamic locations will be added here -->
                        </div>
                        <button type="button" class="btn-add-sede" onclick="addSedeOperativa('add')">
                            + Aggiungi Sede Operativa
                        </button>
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
                                <label for="addNumeroDipendenti">Numero Dipendenti</label>
                                <input type="number" id="addNumeroDipendenti" name="numero_dipendenti"
                                       min="0" />
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="addDataCostituzione">Data Costituzione</label>
                                <input type="date" id="addDataCostituzione" name="data_costituzione" />
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
                                <label for="addTelefono">Telefono</label>
                                <input type="tel" id="addTelefono" name="telefono"
                                       placeholder="+39 02 1234567" />
                            </div>
                            <div class="form-group">
                                <label for="addEmailAziendale">Email Aziendale</label>
                                <input type="email" id="addEmailAziendale" name="email_aziendale" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="addPec">PEC (Posta Elettronica Certificata)</label>
                            <input type="email" id="addPec" name="pec" />
                        </div>
                    </div>

                    <!-- Gestione -->
                    <div class="form-section">
                        <h3 class="form-section-title">Gestione</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="addManager">Manager Aziendale</label>
                                <select id="addManager" name="manager_user_id">
                                    <option value="">Seleziona un manager</option>
                                    <!-- Options will be loaded via JavaScript -->
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="addRappresentante">Rappresentante Legale</label>
                                <input type="text" id="addRappresentante" name="rappresentante_legale" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="addStatus">Stato *</label>
                            <select id="addStatus" name="status" required>
                                <option value="active">Attivo</option>
                                <option value="inactive">Inattivo</option>
                                <option value="suspended">Sospeso</option>
                            </select>
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
                            <label for="editDenominazione">Denominazione</label>
                            <input type="text" id="editDenominazione" name="denominazione" />
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="editCodiceFiscale">Codice Fiscale <span class="alt-tax-asterisk">*</span></label>
                                <input type="text" id="editCodiceFiscale" name="codice_fiscale"
                                       pattern="[A-Z0-9]{16}" maxlength="16"
                                       title="16 caratteri alfanumerici"
                                       data-alt-tax-field="cf"
                                       style="text-transform: uppercase;" />
                            </div>
                            <div class="form-group">
                                <label for="editPartitaIva">Partita IVA <span class="alt-tax-asterisk">*</span></label>
                                <input type="text" id="editPartitaIva" name="partita_iva"
                                       pattern="[0-9]{11}" maxlength="11"
                                       title="11 cifre numeriche"
                                       data-alt-tax-field="piva" />
                            </div>
                        </div>
                    </div>

                    <!-- Logo intestazione documenti (DOCX header) -->
                    <div class="form-section">
                        <h3 class="form-section-title">Logo intestazione documenti</h3>
                        <div class="form-group">
                            <label for="editTenantLogoFile">Logo (PNG/JPG, max 3MB)</label>
                            <input type="file" id="editTenantLogoFile" accept="image/png,image/jpeg" />
                            <div style="margin-top: 8px; font-size: 12px; color: var(--color-gray-600);">
                                Corrente: <span id="editTenantLogoInfo">—</span>
                                <a id="editTenantLogoOpenLink" href="#" target="_blank" rel="noopener" style="display:none; margin-left: 8px;">Apri</a>
                                <button type="button" class="btn btn-secondary" id="editTenantLogoClearBtn" style="display:none; margin-left: 8px; padding: 4px 10px;">Rimuovi</button>
                            </div>
                            <div style="margin-top: 10px;">
                                <button type="button" class="btn btn-primary" id="editTenantLogoUploadBtn">Carica logo</button>
                            </div>
                        </div>
                        <div style="font-size: 12px; color: var(--color-gray-500);">
                            Nota: il logo viene salvato in <code>/IMS/Assets</code> e usato per l’intestazione su tutte le pagine dei DOCX.
                        </div>
                    </div>

                    <!-- Reset modulo documentale -->
                    <div class="form-section">
                        <h3 class="form-section-title">Reset modulo documentale</h3>
                        <div style="font-size: 12px; color: #991B1B; margin-bottom: 10px;">
                            Attenzione: elimina programmi/deliverable wizard, knowledge AI e cartelle <code>/IMS</code> e <code>/Knowledge</code> di questo tenant.
                            Non elimina utenti, ticket, task (restano ma senza link compliance).
                        </div>
                        <button type="button" class="btn btn-danger" id="editTenantDocPurgeBtn">Elimina modulo documentale (irreversibile)</button>
                    </div>

                    <!-- Sede Legale -->
                    <div class="form-section">
                        <h3 class="form-section-title">Sede Legale *</h3>
                        <div class="form-row">
                            <div class="form-group span-2">
                                <label for="editSedeLegaleIndirizzo">Indirizzo *</label>
                                <input type="text" id="editSedeLegaleIndirizzo" name="sede_legale_indirizzo"
                                       placeholder="Via Roma" required />
                            </div>
                            <div class="form-group">
                                <label for="editSedeLegaleCivico">Civico *</label>
                                <input type="text" id="editSedeLegaleCivico" name="sede_legale_civico"
                                       placeholder="123" required />
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="editSedeLegaleCap">CAP *</label>
                                <input type="text" id="editSedeLegaleCap" name="sede_legale_cap"
                                       pattern="[0-9]{5}" maxlength="5" placeholder="00100" required />
                            </div>
                            <div class="form-group">
                                <label for="editSedeLegaleComune">Comune *</label>
                                <input type="text" id="editSedeLegaleComune" name="sede_legale_comune"
                                       placeholder="Roma" required />
                            </div>
                            <div class="form-group">
                                <label for="editSedeLegaleProvincia">Provincia *</label>
                                <select id="editSedeLegaleProvincia" name="sede_legale_provincia" required>
                                    <?php echo getProvinceOptions(); ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Sedi Operative -->
                    <div class="form-section">
                        <h3 class="form-section-title">Sedi Operative</h3>
                        <div id="editSediOperativeContainer">
                            <!-- Dynamic locations will be loaded here -->
                        </div>
                        <button type="button" class="btn-add-sede" onclick="addSedeOperativa('edit')">
                            + Aggiungi Sede Operativa
                        </button>
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
                                <label for="editNumeroDipendenti">Numero Dipendenti</label>
                                <input type="number" id="editNumeroDipendenti" name="numero_dipendenti"
                                       min="0" />
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="editDataCostituzione">Data Costituzione</label>
                                <input type="date" id="editDataCostituzione" name="data_costituzione" />
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
                                <label for="editTelefono">Telefono</label>
                                <input type="tel" id="editTelefono" name="telefono"
                                       placeholder="+39 02 1234567" />
                            </div>
                            <div class="form-group">
                                <label for="editEmailAziendale">Email Aziendale</label>
                                <input type="email" id="editEmailAziendale" name="email_aziendale" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="editPec">PEC (Posta Elettronica Certificata)</label>
                            <input type="email" id="editPec" name="pec" />
                        </div>
                    </div>

                    <!-- Gestione -->
                    <div class="form-section">
                        <h3 class="form-section-title">Gestione</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="editManager">Manager Aziendale</label>
                                <select id="editManager" name="manager_user_id">
                                    <option value="">Seleziona un manager</option>
                                    <!-- Options will be loaded via JavaScript -->
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="editRappresentante">Rappresentante Legale</label>
                                <input type="text" id="editRappresentante" name="rappresentante_legale" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="editStatus">Stato *</label>
                            <select id="editStatus" name="status" required>
                                <option value="active">Attivo</option>
                                <option value="inactive">Inattivo</option>
                                <option value="suspended">Sospeso</option>
                            </select>
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
                <h2 class="modal-title" id="deleteModalTitle">Conferma Eliminazione</h2>
                <button class="modal-close" onclick="closeModal('deleteModal')">×</button>
            </div>
            <div class="modal-body" id="deleteModalBody">
                <p>Sei sicuro di voler eliminare questa azienda?</p>
                <p class="text-muted text-sm">Questa azione eliminerà anche tutti gli utenti e i dati associati.</p>
                <p class="text-danger text-sm" style="margin-top: 12px; padding: 8px; background: #FEE2E2; border-left: 3px solid #DC2626; border-radius: 4px;">
                    <strong>Attenzione:</strong> Questa operazione non può essere annullata. Tutti i dati verranno eliminati definitivamente.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Annulla</button>
                <button type="button" class="btn btn-danger" id="deleteModalConfirmBtn" onclick="confirmDelete()">Elimina</button>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <!-- Tenant Roles Modal (Ruoli Aziendali) -->
    <div id="rolesModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h2 class="modal-title">Gestione Ruoli Aziendali</h2>
                <button class="modal-close" onclick="closeModal('rolesModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="rolesCompanyInfo" style="margin-bottom: 16px; padding: 12px; background: var(--color-gray-50); border-radius: var(--radius-md);">
                    <strong>Azienda:</strong> <span id="rolesCompanyName">-</span>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <div>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" id="hasCustomRolesToggle" onchange="companyManager.toggleCustomRoles()">
                            <span>Abilita ruoli aziendali personalizzati</span>
                        </label>
                    </div>
                    <button class="btn btn-primary" id="addRoleBtn" onclick="companyManager.openAddRoleForm()" style="display: none;">
                        + Nuovo Ruolo
                    </button>
                </div>

                <!-- Permessi assegnazione Ruolo Aziendale (solo Super Admin) -->
                <div id="tenantRoleAssignmentPermissions" style="display:none; margin-bottom: 16px; padding: 12px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-white);">
                    <div style="font-weight: 600; margin-bottom: 8px;">Permessi assegnazione Ruolo Aziendale</div>
                    <div style="font-size: 13px; color: var(--color-gray-600); margin-bottom: 10px;">
                        Di default solo il Super Admin può assegnare Ruoli Aziendali agli utenti. Qui puoi abilitare, per questa azienda, quali tipi utente possono farlo.
                    </div>
                    <div style="display:flex; gap: 14px; flex-wrap: wrap;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" id="permAssignAdmin">
                            <span>Admin</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" id="permAssignManager">
                            <span>Manager</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" id="permAssignUser">
                            <span>Utente</span>
                        </label>
                    </div>
                    <div style="margin-top: 10px; display:flex; justify-content:flex-end; gap: 8px;">
                        <button type="button" class="btn btn-secondary" onclick="companyManager.resetTenantRoleAssignmentPermissions()">Reset</button>
                        <button type="button" class="btn btn-primary" onclick="companyManager.saveTenantRoleAssignmentPermissions()">Salva Permessi</button>
                    </div>
                </div>

                <!-- Permessi gestione Ruoli Aziendali (solo Super Admin) -->
                <div id="tenantRoleManagementPermissions" style="display:none; margin-bottom: 16px; padding: 12px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-white);">
                    <div style="font-weight: 600; margin-bottom: 8px;">Permessi gestione Ruoli Aziendali</div>
                    <div style="font-size: 13px; color: var(--color-gray-600); margin-bottom: 10px;">
                        Di default solo il Super Admin può <strong>creare/modificare/eliminare</strong> Ruoli Aziendali. Qui puoi abilitare, per questa azienda, quali tipi utente possono farlo.
                    </div>
                    <div id="tenantRoleManagementPermStorageNote" style="display:none; margin: 10px 0 0; padding: 10px; border: 1px solid #FDE68A; background: #FFFBEB; color: #92400E; border-radius: 8px; font-size: 12px;">
                        Nota: questa installazione non supporta il salvataggio dei permessi gestione ruoli (colonna <code>tenants.tenant_role_management_roles</code> assente). Verranno usati i valori di default.
                    </div>
                    <div style="display:flex; gap: 14px; flex-wrap: wrap; margin-top: 10px;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" id="permRoleManageAdmin">
                            <span>Admin</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" id="permRoleManageManager">
                            <span>Manager</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" id="permRoleManageUser">
                            <span>Utente</span>
                        </label>
                    </div>
                    <div style="margin-top: 10px; display:flex; justify-content:flex-end; gap: 8px;">
                        <button type="button" class="btn btn-secondary" onclick="companyManager.resetTenantRoleManagementPermissions()">Reset</button>
                        <button type="button" class="btn btn-primary" id="tenantRoleManagementPermSaveBtn" onclick="companyManager.saveTenantRoleManagementPermissions()">Salva Permessi</button>
                    </div>
                </div>

                <!-- Permessi Turni (per-azienda) -->
                <div id="shiftPermissionsPanel" style="display:none; margin-bottom: 16px; padding: 12px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-white);">
                    <div style="font-weight: 600; margin-bottom: 8px;">Permessi Turni</div>
                    <div style="font-size: 13px; color: var(--color-gray-600); margin-bottom: 10px;">
                        Imposta quali tipi utente possono <strong>gestire</strong> i turni e quali possono <strong>approvare</strong> le richieste per questa azienda.
                    </div>
                    <div id="shiftPermStorageNote" style="display:none; margin: 10px 0 0; padding: 10px; border: 1px solid #FDE68A; background: #FFFBEB; color: #92400E; border-radius: 8px; font-size: 12px;">
                        Nota: questa installazione non supporta il salvataggio dei permessi turni (colonna <code>tenants.shift_permissions</code> assente). Verranno usati i valori di default.
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div style="padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
                            <div style="font-weight: 600; margin-bottom: 8px;">Gestione Turni</div>
                            <div style="display:flex; gap: 14px; flex-wrap: wrap;">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" id="permShiftManageAdmin">
                                    <span>Admin</span>
                                </label>
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" id="permShiftManageManager">
                                    <span>Manager</span>
                                </label>
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" id="permShiftManageUser">
                                    <span>Utente</span>
                                </label>
                            </div>
                        </div>

                        <div style="padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
                            <div style="font-weight: 600; margin-bottom: 8px;">Approvazione Richieste</div>
                            <div style="display:flex; gap: 14px; flex-wrap: wrap;">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" id="permShiftApproveAdmin">
                                    <span>Admin</span>
                                </label>
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" id="permShiftApproveManager">
                                    <span>Manager</span>
                                </label>
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" id="permShiftApproveUser">
                                    <span>Utente</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 10px; display:flex; justify-content:flex-end; gap: 8px;">
                        <button type="button" class="btn btn-secondary" onclick="companyManager.resetShiftPermissions()">Reset</button>
                        <button type="button" class="btn btn-primary" id="shiftPermSaveBtn" onclick="companyManager.saveShiftPermissions()">Salva Permessi Turni</button>
                    </div>
                </div>

                <!-- Roles List -->
                <div id="rolesListContainer" style="display: none;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: var(--color-gray-100);">
                                <th style="padding: 8px 12px; text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase;">Colore</th>
                                <th style="padding: 8px 12px; text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase;">Nome</th>
                                <th style="padding: 8px 12px; text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase;">Codice</th>
                                <th style="padding: 8px 12px; text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase;">Utenti</th>
                                <th style="padding: 8px 12px; text-align: center; font-size: 12px; font-weight: 600; text-transform: uppercase;">Azioni</th>
                            </tr>
                        </thead>
                        <tbody id="rolesTableBody">
                            <!-- Roles will be loaded here -->
                        </tbody>
                    </table>
                    <div id="rolesEmptyState" style="display: none; text-align: center; padding: 24px; color: var(--color-gray-500);">
                        Nessun ruolo aziendale definito. Clicca "Nuovo Ruolo" per crearne uno.
                    </div>
                </div>

                <!-- Add/Edit Role Form -->
                <div id="roleFormContainer" style="display: none; margin-top: 16px; padding: 16px; background: var(--color-gray-50); border-radius: var(--radius-md);">
                    <h4 id="roleFormTitle" style="margin-bottom: 12px;">Nuovo Ruolo</h4>
                    <form id="roleForm">
                        <input type="hidden" id="roleFormId" value="">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="roleFormName">Nome Ruolo *</label>
                                <input type="text" id="roleFormName" name="name" required placeholder="es. Responsabile Vendite">
                            </div>
                            <div class="form-group">
                                <label for="roleFormCode">Codice</label>
                                <input type="text" id="roleFormCode" name="code" placeholder="es. RESP_VENDITE" style="text-transform: uppercase;">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="roleFormColor">Colore</label>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <input type="color" id="roleFormColor" name="color" value="#6366f1" style="width: 48px; height: 36px; border: 1px solid var(--color-gray-300); border-radius: var(--radius-md); cursor: pointer;">
                                    <div id="roleColorPreview" style="display: inline-block; padding: 4px 12px; border-radius: var(--radius-full); font-size: 12px; font-weight: 500; background: #6366f1; color: white;">Anteprima</div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="roleFormSortOrder">Ordine</label>
                                <input type="number" id="roleFormSortOrder" name="sort_order" value="0" min="0">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="roleFormDescription">Descrizione</label>
                            <textarea id="roleFormDescription" name="description" rows="2" placeholder="Descrizione del ruolo..."></textarea>
                        </div>
                        <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px;">
                            <button type="button" class="btn btn-secondary" onclick="companyManager.cancelRoleForm()">Annulla</button>
                            <button type="submit" class="btn btn-primary">Salva Ruolo</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('rolesModal')">Chiudi</button>
            </div>
        </div>
    </div>

    <!-- Toast notification -->
    <div id="toast" class="toast"></div>

    <!-- Hidden CSRF token -->
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">

    <script>
        // Build marker to confirm which file version is loaded
        window.CNX_BUILD_ID = <?php echo json_encode($cnxBuildId, JSON_UNESCAPED_UNICODE); ?>;

        // Expose current user role to JS
        window.CNX_CURRENT_USER_ROLE = <?php echo json_encode($currentUser['role'] ?? 'user', JSON_UNESCAPED_UNICODE); ?>;

        class CompanyManager {
            constructor() {
                this.currentUserRole = (window.CNX_CURRENT_USER_ROLE || 'user').toString();
                this.isSuperAdminUi = (this.currentUserRole === 'super_admin');
                this.companies = [];
                this.managers = [];
                this.currentPage = 1;
                this.itemsPerPage = 10;
                this.searchQuery = '';
                this.deleteCompanyId = null;
                this.selectedCompanyIds = new Set();
                this.currentEditCompanyId = null;
                this.init();
            }

            init() {
                this.bindEvents();
                if (this.isSuperAdminUi) {
                    this.loadManagers();
                }
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

                // Tenant logo actions (edit modal)
                document.getElementById('editTenantLogoUploadBtn')?.addEventListener('click', async () => {
                    if (!this.currentEditCompanyId) {
                        this.showToast('Seleziona una azienda prima di caricare il logo', 'error');
                        return;
                    }
                    await this.uploadTenantLogo(this.currentEditCompanyId);
                });
                document.getElementById('editTenantLogoClearBtn')?.addEventListener('click', async () => {
                    if (!this.currentEditCompanyId) return;
                    if (!confirm('Rimuovere il logo intestazione per questa azienda?')) return;
                    await this.clearTenantLogo(this.currentEditCompanyId);
                });

                // Tenant document module purge (edit modal)
                document.getElementById('editTenantDocPurgeBtn')?.addEventListener('click', async () => {
                    if (!this.currentEditCompanyId) return;
                    await this.purgeTenantDocumentModule(this.currentEditCompanyId);
                });

                // Bulk select all
                const selectAll = document.getElementById('companiesSelectAll');
                if (selectAll) {
                    selectAll.addEventListener('change', (e) => {
                        const checked = !!e.target.checked;
                        this.toggleSelectAll(checked);
                    });
                }

                // Auto uppercase for Codice Fiscale
                document.querySelectorAll('input[name="codice_fiscale"]').forEach(input => {
                    input.addEventListener('input', (e) => {
                        e.target.value = e.target.value.toUpperCase();
                    });
                });

                // Comune/Provincia validation for ADD form
                const addProvinciaInput = document.getElementById('addSedeLegaleProvincia');
                const addComuneInput = document.getElementById('addSedeLegaleComune');

                if (addProvinciaInput && addComuneInput) {
                    // Load comuni when provincia changes
                    addProvinciaInput.addEventListener('change', async (e) => {
                        await this.loadComuniByProvincia(e.target.value, addComuneInput);
                    });

                    // Validate comune when user finishes typing
                    addComuneInput.addEventListener('blur', async (e) => {
                        const comune = e.target.value.trim();
                        const provincia = addProvinciaInput.value;
                        if (comune && provincia) {
                            await this.validateComuneProvincia(comune, provincia);
                        }
                    });
                }

                // Comune/Provincia validation for EDIT form
                const editProvinciaInput = document.getElementById('editSedeLegaleProvincia');
                const editComuneInput = document.getElementById('editSedeLegaleComune');

                if (editProvinciaInput && editComuneInput) {
                    // Load comuni when provincia changes
                    editProvinciaInput.addEventListener('change', async (e) => {
                        await this.loadComuniByProvincia(e.target.value, editComuneInput);
                    });

                    // Validate comune when user finishes typing
                    editComuneInput.addEventListener('blur', async (e) => {
                        const comune = e.target.value.trim();
                        const provincia = editProvinciaInput.value;
                        if (comune && provincia) {
                            await this.validateComuneProvincia(comune, provincia);
                        }
                    });
                }
            }

            toggleSelectAll(checked) {
                if (!this.companies || this.companies.length === 0) {
                    this.selectedCompanyIds.clear();
                    this.updateBulkDeleteUI();
                    return;
                }
                if (checked) {
                    this.companies.forEach(c => this.selectedCompanyIds.add(String(c.id)));
                } else {
                    this.selectedCompanyIds.clear();
                }
                this.renderCompanies();
                this.updateBulkDeleteUI();
            }

            toggleSelectCompany(companyId, checked) {
                const key = String(companyId);
                if (checked) this.selectedCompanyIds.add(key);
                else this.selectedCompanyIds.delete(key);
                this.updateBulkDeleteUI();
            }

            updateBulkDeleteUI() {
                const btn = document.getElementById('bulkDeleteBtn');
                const countEl = document.getElementById('bulkDeleteCount');
                const selectAll = document.getElementById('companiesSelectAll');

                const count = this.selectedCompanyIds.size;
                if (countEl) countEl.textContent = count > 0 ? `(${count})` : '';
                if (btn) btn.disabled = count === 0;

                if (selectAll) {
                    const total = (this.companies && this.companies.length) ? this.companies.length : 0;
                    const selected = count;
                    selectAll.indeterminate = (selected > 0 && selected < total);
                    selectAll.checked = (total > 0 && selected === total);
                }
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

            async loadComuniByProvincia(provincia, comuneInput) {
                if (!provincia) {
                    return;
                }

                try {
                    const response = await fetch(`api/locations/list_municipalities.php?province=${provincia}&limit=100`);
                    const data = await response.json();

                    if (data.success && data.data && data.data.municipalities && data.data.municipalities.length > 0) {
                        console.log(`Caricati ${data.data.municipalities.length} comuni per ${provincia}`);
                        // Store municipalities for validation
                        if (!this.municipalitiesByProvince) {
                            this.municipalitiesByProvince = {};
                        }
                        this.municipalitiesByProvince[provincia] = data.data.municipalities;
                    }
                } catch (error) {
                    console.error('Errore caricamento comuni:', error);
                }
            }

            async validateComuneProvincia(comune, provincia) {
                if (!comune || !provincia) return true;

                try {
                    const response = await fetch(
                        `api/locations/validate_municipality.php?municipality=${encodeURIComponent(comune)}&province=${provincia}`
                    );
                    const data = await response.json();

                    if (data.success && data.data) {
                        if (!data.data.valid) {
                            this.showToast(`Il comune "${comune}" non appartiene alla provincia ${provincia}`, 'error');
                            return false;
                        }
                        return true;
                    }
                    return true; // In case of unexpected response structure, don't block
                } catch (error) {
                    console.error('Errore validazione comune:', error);
                    return true; // In caso di errore, non bloccare
                }
            }

            async loadManagers() {
                try {
                    const response = await fetch('api/users/list.php?role=manager,admin', {
                        credentials: 'same-origin',
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
                    const response = await fetch(`api/tenants/list.php?page=${this.currentPage}&search=${encodeURIComponent(this.searchQuery)}`, {
                        credentials: 'same-origin',
                        headers: {
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        }
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.companies = data.data?.tenants || data.data?.companies || [];
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
                if (!tbody || !emptyState) return;

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

                    // Estrai il comune dalla sede legale
                    let comune = '-';
                    if (company.sede_legale) {
                        // Se è un oggetto con proprietà comune
                        if (typeof company.sede_legale === 'object' && company.sede_legale.comune) {
                            comune = company.sede_legale.comune;
                        }
                        // Se è una stringa, prova a estrarre il comune (formato: indirizzo, civico, CAP, Città (Provincia))
                        else if (typeof company.sede_legale === 'string') {
                            const parts = company.sede_legale.split(',');
                            if (parts.length >= 3) {
                                // Il comune è solitamente l'ultima parte o penultima se c'è la provincia
                                comune = parts[parts.length - 1].trim().replace(/\([^)]*\)/g, '').trim();
                            } else {
                                comune = company.sede_legale;
                            }
                        }
                    }

                    // Admin/Manager mode: only show Roles button
                    if (!this.isSuperAdminUi) {
                        return `
                    <tr>
                        <td style="text-align: center;">
                            <strong>${company.id}</strong>
                        </td>
                        <td>
                            <div class="company-info-cell">
                                <div class="company-avatar-table">${initials}</div>
                                <div class="company-details-table">
                                    <div class="company-name-table">${company.denominazione || company.name || '-'}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span style="font-size: var(--text-sm);">${comune}</span>
                        </td>
                        <td>
                            <span class="status-badge ${status}">
                                <span class="status-indicator"></span>
                                ${this.getStatusLabel(status)}
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon" onclick="companyManager.openRolesModal(${company.id})" title="Gestione Ruoli Aziendali" style="color: var(--color-primary);">
                                    👥
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
                    }

                    const isSelected = this.selectedCompanyIds.has(String(company.id));
                    return `
                    <tr>
                        <td style="text-align:center;">
                            <input type="checkbox"
                                   class="company-row-select"
                                   data-company-id="${company.id}"
                                   ${isSelected ? 'checked' : ''} />
                        </td>
                        <td style="text-align: center;">
                            <strong>${company.id}</strong>
                        </td>
                        <td>
                            <div class="company-info-cell">
                                <div class="company-avatar-table">${initials}</div>
                                <div class="company-details-table">
                                    <div class="company-name-table">${company.denominazione || company.name || '-'}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div>
                                <code style="font-size: 11px;">${company.codice_fiscale || '-'}</code><br/>
                                <code style="font-size: 11px;">${company.partita_iva || '-'}</code>
                            </div>
                        </td>
                        <td>
                            <span style="font-size: var(--text-sm);">${comune}</span>
                        </td>
                        <td>
                            <span style="font-size: var(--text-sm);">${managerName}</span>
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
                                <button class="btn-icon" onclick="companyManager.openRolesModal(${company.id})" title="Gestione Ruoli Aziendali" style="color: var(--color-primary);">
                                    👥
                                </button>
                                <button class="btn-icon delete" onclick="companyManager.openDeleteModal(${company.id})" title="Elimina">
                                    🗑️
                                </button>
                            </div>
                        </td>
                    </tr>
                `}).join('');

                if (this.isSuperAdminUi) {
                    // bind checkbox events after rendering
                    tbody.querySelectorAll('.company-row-select').forEach(cb => {
                        cb.addEventListener('change', (e) => {
                            const id = e.target.getAttribute('data-company-id');
                            this.toggleSelectCompany(id, !!e.target.checked);
                        });
                    });

                    this.updateBulkDeleteUI();
                }
            }

            async bulkDeleteSelected() {
                const ids = Array.from(this.selectedCompanyIds)
                    .map(v => parseInt(v, 10))
                    .filter(v => Number.isFinite(v) && v > 0);

                if (!ids.length) {
                    this.showToast('Seleziona almeno una azienda', 'error');
                    return;
                }

                // Special protection for tenant 1
                let confirmSystemTenant = false;
                if (ids.includes(1)) {
                    const typed = prompt('Stai eliminando anche il TENANT DI SISTEMA (ID 1).\nPer confermare digita: ELIMINA SISTEMA');
                    if ((typed || '').trim().toUpperCase() !== 'ELIMINA SISTEMA') {
                        this.showToast('Operazione annullata: conferma tenant di sistema non valida', 'error');
                        return;
                    }
                    confirmSystemTenant = true;
                }

                if (!confirm(`Eliminare ${ids.length} aziende selezionate? Questa operazione è IRREVERSIBILE.`)) {
                    return;
                }

                try {
                    const response = await fetch('api/tenants/bulk_delete.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.getElementById('csrfToken').value,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            csrf_token: document.getElementById('csrfToken').value,
                            tenant_ids: ids,
                            confirm_system_tenant: confirmSystemTenant
                        })
                    });

                    let data = null;
                    const raw = await response.text();
                    try { data = raw ? JSON.parse(raw) : null; } catch (_) { data = null; }

                    if (!data || !data.success) {
                        const msg = (data && (data.error || data.message)) ? (data.error || data.message) : `Errore eliminazione multipla (HTTP ${response.status})`;
                        this.showToast(msg, 'error');
                        if (!data) console.error('bulk_delete non-JSON response:', raw.substring(0, 500));
                        else console.error('bulk_delete error payload:', data);
                        return;
                    }

                    const summary = data.data?.summary || {};
                    const deleted = summary.deleted ?? 0;
                    const failed = summary.failed ?? 0;
                    const skipped = summary.skipped_not_found ?? 0;

                    this.showToast(`Eliminazione completata: ${deleted} OK, ${failed} KO, ${skipped} skipped`, failed ? 'error' : 'success');

                    // Clear selection and reload list
                    this.selectedCompanyIds.clear();
                    const selectAll = document.getElementById('companiesSelectAll');
                    if (selectAll) {
                        selectAll.checked = false;
                        selectAll.indeterminate = false;
                    }
                    await this.loadCompanies();
                } catch (e) {
                    console.error('bulkDeleteSelected error:', e);
                    this.showToast('Errore di connessione', 'error');
                }
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

                // Collect sede legale as object with separate fields
                const sedeLegaleObj = {
                    indirizzo: document.getElementById('addSedeLegaleIndirizzo').value.trim(),
                    civico: document.getElementById('addSedeLegaleCivico').value.trim(),
                    cap: document.getElementById('addSedeLegaleCap').value.trim(),
                    comune: document.getElementById('addSedeLegaleComune').value.trim(),
                    provincia: document.getElementById('addSedeLegaleProvincia').value
                };

                // Validate comune/provincia before submission
                if (sedeLegaleObj.comune && sedeLegaleObj.provincia) {
                    const isValid = await this.validateComuneProvincia(sedeLegaleObj.comune, sedeLegaleObj.provincia);
                    if (!isValid) {
                        this.showToast('Correggi il comune prima di procedere', 'error');
                        return;
                    }
                }

                // Collect sedi operative
                const sediOperative = collectSediOperative('add');

                // Build JSON payload (allinea ai nomi attesi dall'API /api/tenants/create.php)
                const payload = {
                    csrf_token: document.getElementById('csrfToken').value,
                    denominazione: document.getElementById('addDenominazione').value.trim(),
                    codice_fiscale: document.getElementById('addCodiceFiscale').value.trim(),
                    partita_iva: document.getElementById('addPartitaIva').value.trim(),
                    sede_legale: sedeLegaleObj,
                    sedi_operative: sediOperative,

                    // Informazioni aziendali
                    settore_merceologico: document.getElementById('addSettore')?.value || null,
                    numero_dipendenti: parseInt(document.getElementById('addNumeroDipendenti')?.value || '0', 10) || 0,
                    capitale_sociale: parseFloat(document.getElementById('addCapitaleSociale')?.value || '') || null,

                    // Contatti
                    telefono: document.getElementById('addTelefono')?.value?.trim() || null,
                    email: document.getElementById('addEmailAziendale')?.value?.trim() || null,
                    pec: document.getElementById('addPec')?.value?.trim() || null,

                    // Gestione
                    manager_id: document.getElementById('addManager')?.value ? parseInt(document.getElementById('addManager').value, 10) : null,
                    rappresentante_legale: document.getElementById('addRappresentante')?.value?.trim() || null,
                    status: document.getElementById('addStatus')?.value || 'active'
                };

                try {
                    const response = await fetch('api/tenants/create.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        },
                        body: JSON.stringify(payload)
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.showToast('Azienda creata con successo', 'success');
                        closeModal('addModal');
                        form.reset();
                        // Clear sedi operative
                        document.getElementById('addSediOperativeContainer').innerHTML = '';
                        sediOperativeCounters.add = 0;
                        this.loadCompanies();
                    } else {
                        this.showToast(data.error || data.message || 'Errore nella creazione azienda', 'error');
                        console.error('API Error:', data);
                    }
                } catch (error) {
                    console.error('Error adding company:', error);
                    this.showToast('Errore di connessione', 'error');
                }
            }

            openEditModal(companyId) {
                const company = this.companies.find(c => c.id === companyId);
                if (!company) return;
                this.currentEditCompanyId = companyId;

                // Clear previous sedi operative
                document.getElementById('editSediOperativeContainer').innerHTML = '';
                sediOperativeCounters.edit = 0;

                // Populate all fields
                document.getElementById('editCompanyId').value = company.id;
                document.getElementById('editDenominazione').value = company.denominazione || company.name || '';
                document.getElementById('editCodiceFiscale').value = company.codice_fiscale || '';
                document.getElementById('editPartitaIva').value = company.partita_iva || '';

                // Handle sede_legale - può essere string o object
                if (typeof company.sede_legale === 'object' && company.sede_legale) {
                    const sl = company.sede_legale;
                    document.getElementById('editSedeLegaleIndirizzo').value = sl.indirizzo || '';
                    document.getElementById('editSedeLegaleCivico').value = sl.civico || '';
                    document.getElementById('editSedeLegaleCap').value = sl.cap || '';
                    document.getElementById('editSedeLegaleComune').value = sl.comune || '';
                    document.getElementById('editSedeLegaleProvincia').value = sl.provincia || '';
                } else if (typeof company.sede_legale === 'string') {
                    // Try to parse old format string
                    const parts = company.sede_legale.split(',').map(p => p.trim());
                    document.getElementById('editSedeLegaleIndirizzo').value = parts[0] || '';
                    document.getElementById('editSedeLegaleCivico').value = parts[1] || '';
                    document.getElementById('editSedeLegaleCap').value = parts[2] || '';
                    document.getElementById('editSedeLegaleComune').value = parts[3] ? parts[3].replace(/\([^)]*\)/g, '').trim() : '';
                    document.getElementById('editSedeLegaleProvincia').value = parts[3] ? parts[3].match(/\(([^)]+)\)/)?.[1] || '' : '';
                }

                // Handle sedi operative
                if (company.sedi_operative && Array.isArray(company.sedi_operative)) {
                    company.sedi_operative.forEach(sede => {
                        this.addSedeOperativaForEdit(sede);
                    });
                }

                document.getElementById('editSettore').value = company.settore_merceologico || '';
                document.getElementById('editNumeroDipendenti').value = company.numero_dipendenti || 0;
                document.getElementById('editDataCostituzione').value = company.data_costituzione || '';
                document.getElementById('editCapitaleSociale').value = company.capitale_sociale || '';
                document.getElementById('editTelefono').value = company.telefono || '';
                document.getElementById('editEmailAziendale').value = company.email_aziendale || '';
                document.getElementById('editPec').value = company.pec || '';
                document.getElementById('editManager').value = company.manager_user_id || '';
                document.getElementById('editRappresentante').value = company.rappresentante_legale || '';
                // Allinea eventuali stati legacy (es. 'pending') ai valori DB/API
                const normalizedStatus = (company.status === 'pending') ? 'inactive' : (company.status || 'active');
                document.getElementById('editStatus').value = normalizedStatus;

                openModal('editModal');

                // Load current logo info (best-effort)
                try { this.loadTenantLogo(companyId); } catch (e) {}
            }

            setTenantLogoUi(info) {
                const label = document.getElementById('editTenantLogoInfo');
                const openLink = document.getElementById('editTenantLogoOpenLink');
                const clearBtn = document.getElementById('editTenantLogoClearBtn');
                const fid = info?.logo_file_id || 0;
                if (label) label.textContent = fid ? (`File #${fid}`) : '—';
                if (openLink) {
                    const url = info?.open_logo_url || '';
                    if (fid && url) {
                        openLink.href = url;
                        openLink.style.display = 'inline';
                    } else {
                        openLink.href = '#';
                        openLink.style.display = 'none';
                    }
                }
                if (clearBtn) clearBtn.style.display = fid ? 'inline-flex' : 'none';
            }

            async loadTenantLogo(tenantId) {
                try {
                    const csrf = document.getElementById('csrfToken')?.value || '';
                    const res = await fetch(`api/tenants/logo.php?action=get&tenant_id=${encodeURIComponent(String(tenantId))}`, {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: csrf ? { 'X-CSRF-Token': csrf } : {}
                    });
                    const data = await res.json().catch(() => ({}));
                    if (data && data.success && data.data) {
                        if (data.data.storage_available === false) {
                            this.setTenantLogoUi({ logo_file_id: 0 });
                            this.showToast('Logo: storage non disponibile (applica migrazione 49)', 'warning');
                            return;
                        }
                        this.setTenantLogoUi(data.data);
                    }
                } catch (e) {
                    // ignore
                }
            }

            async uploadTenantLogo(tenantId) {
                const input = document.getElementById('editTenantLogoFile');
                const file = input?.files?.[0] || null;
                if (!file) {
                    this.showToast('Seleziona un file PNG/JPG', 'error');
                    return;
                }
                try {
                    const csrf = document.getElementById('csrfToken')?.value || '';
                    const fd = new FormData();
                    fd.append('tenant_id', String(tenantId));
                    fd.append('logo', file);
                    const res = await fetch('api/tenants/logo.php?action=upload', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: csrf ? { 'X-CSRF-Token': csrf } : {},
                        body: fd
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!data || !data.success) {
                        const msg = (data && (data.error || data.message)) ? (data.error || data.message) : `Errore upload logo (HTTP ${res.status})`;
                        this.showToast(msg, 'error');
                        return;
                    }
                    this.showToast('Logo caricato', 'success');
                    this.setTenantLogoUi(data.data || {});
                } catch (e) {
                    this.showToast('Errore di connessione', 'error');
                }
            }

            async clearTenantLogo(tenantId) {
                try {
                    const csrf = document.getElementById('csrfToken')?.value || '';
                    const res = await fetch('api/tenants/logo.php?action=clear', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            ...(csrf ? { 'X-CSRF-Token': csrf } : {})
                        },
                        body: JSON.stringify({ tenant_id: tenantId })
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!data || !data.success) {
                        const msg = (data && (data.error || data.message)) ? (data.error || data.message) : `Errore rimozione logo (HTTP ${res.status})`;
                        this.showToast(msg, 'error');
                        return;
                    }
                    this.showToast('Logo rimosso', 'success');
                    this.setTenantLogoUi({ logo_file_id: 0 });
                } catch (e) {
                    this.showToast('Errore di connessione', 'error');
                }
            }

            async purgeTenantDocumentModule(tenantId) {
                const c1 = prompt('OPERAZIONE IRREVERSIBILE.\nDigita esattamente: ELIMINA MODULO DOCUMENTALE');
                if ((c1 || '').trim().toUpperCase() !== 'ELIMINA MODULO DOCUMENTALE') {
                    this.showToast('Conferma 1 non valida', 'error');
                    return;
                }
                const expected2 = `PURGE-${tenantId}`;
                const c2 = prompt(`Seconda conferma.\nDigita esattamente: ${expected2}`);
                if ((c2 || '').trim() !== expected2) {
                    this.showToast('Conferma 2 non valida', 'error');
                    return;
                }
                if (!confirm('Confermi definitivamente?')) return;

                try {
                    const csrf = document.getElementById('csrfToken')?.value || '';
                    const res = await fetch('api/tenants/purge_document_module.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            ...(csrf ? { 'X-CSRF-Token': csrf } : {})
                        },
                        body: JSON.stringify({
                            tenant_id: tenantId,
                            confirm_tenant_id: tenantId,
                            confirm_phrase_1: 'ELIMINA MODULO DOCUMENTALE',
                            confirm_phrase_2: `PURGE-${tenantId}`
                        })
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!data || !data.success) {
                        const msg = (data && (data.error || data.message)) ? (data.error || data.message) : `Errore purge (HTTP ${res.status})`;
                        this.showToast(msg, 'error');
                        return;
                    }
                    const warns = Array.isArray(data.data?.warnings) ? data.data.warnings : [];
                    this.showToast(`Purge completato${warns.length ? ' (con warning)' : ''}`, warns.length ? 'warning' : 'success');
                } catch (e) {
                    this.showToast('Errore di connessione', 'error');
                }
            }

            addSedeOperativaForEdit(sede) {
                const maxSedi = 20;
                if (sediOperativeCounters.edit >= maxSedi) {
                    return;
                }

                sediOperativeCounters.edit++;
                const counter = sediOperativeCounters.edit;
                const container = document.getElementById('editSediOperativeContainer');

                const html = `
                    <div class="sede-card" id="editSedeOp${counter}">
                        <div class="sede-card-header">
                            <h4>Sede Operativa #${counter}</h4>
                            <button type="button" class="btn-remove" onclick="removeSedeOperativa('edit', ${counter})">
                                Rimuovi
                            </button>
                        </div>
                        <div class="form-row">
                            <div class="form-group span-2">
                                <label>Indirizzo</label>
                                <input type="text" id="edit_so_indirizzo_${counter}"
                                       name="so_indirizzo_${counter}"
                                       class="sede-operativa-field"
                                       value="${sede.indirizzo || ''}"
                                       placeholder="Via Milano">
                            </div>
                            <div class="form-group">
                                <label>Civico</label>
                                <input type="text" id="edit_so_civico_${counter}"
                                       name="so_civico_${counter}"
                                       class="sede-operativa-field"
                                       value="${sede.civico || ''}"
                                       placeholder="45">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>CAP</label>
                                <input type="text" id="edit_so_cap_${counter}"
                                       name="so_cap_${counter}"
                                       pattern="[0-9]{5}"
                                       maxlength="5"
                                       class="sede-operativa-field"
                                       value="${sede.cap || ''}"
                                       placeholder="20100">
                            </div>
                            <div class="form-group">
                                <label>Comune</label>
                                <input type="text" id="edit_so_comune_${counter}"
                                       name="so_comune_${counter}"
                                       class="sede-operativa-field"
                                       value="${sede.comune || ''}"
                                       placeholder="Milano">
                            </div>
                            <div class="form-group">
                                <label>Provincia</label>
                                <select id="edit_so_provincia_${counter}"
                                        name="so_provincia_${counter}"
                                        class="sede-operativa-field">
                                    ${getProvinceOptions(sede.provincia || '')}
                                </select>
                            </div>
                        </div>
                    </div>
                `;
                container.insertAdjacentHTML('beforeend', html);
            }

            async updateCompany() {
                const form = document.getElementById('editCompanyForm');

                // Collect sede legale as object with separate fields
                const sedeLegaleObj = {
                    indirizzo: document.getElementById('editSedeLegaleIndirizzo').value.trim(),
                    civico: document.getElementById('editSedeLegaleCivico').value.trim(),
                    cap: document.getElementById('editSedeLegaleCap').value.trim(),
                    comune: document.getElementById('editSedeLegaleComune').value.trim(),
                    provincia: document.getElementById('editSedeLegaleProvincia').value
                };

                // Validate comune/provincia before submission
                if (sedeLegaleObj.comune && sedeLegaleObj.provincia) {
                    const isValid = await this.validateComuneProvincia(sedeLegaleObj.comune, sedeLegaleObj.provincia);
                    if (!isValid) {
                        this.showToast('Correggi il comune prima di procedere', 'error');
                        return;
                    }
                }

                // Collect sedi operative
                const sediOperative = collectSediOperative('edit');

                // Build JSON payload (allinea ai nomi attesi dall'API /api/tenants/update.php)
                const payload = {
                    csrf_token: document.getElementById('csrfToken').value,
                    tenant_id: parseInt(document.getElementById('editCompanyId').value),
                    denominazione: document.getElementById('editDenominazione').value.trim(),
                    codice_fiscale: document.getElementById('editCodiceFiscale').value.trim(),
                    partita_iva: document.getElementById('editPartitaIva').value.trim(),
                    sede_legale: sedeLegaleObj,
                    sedi_operative: sediOperative,

                    // Informazioni aziendali
                    settore_merceologico: document.getElementById('editSettore')?.value || null,
                    numero_dipendenti: parseInt(document.getElementById('editNumeroDipendenti')?.value || '0', 10) || 0,
                    capitale_sociale: parseFloat(document.getElementById('editCapitaleSociale')?.value || '') || null,

                    // Contatti
                    telefono: document.getElementById('editTelefono')?.value?.trim() || null,
                    email: document.getElementById('editEmailAziendale')?.value?.trim() || null,
                    pec: document.getElementById('editPec')?.value?.trim() || null,

                    // Gestione
                    manager_id: document.getElementById('editManager')?.value ? parseInt(document.getElementById('editManager').value, 10) : null,
                    rappresentante_legale: document.getElementById('editRappresentante')?.value?.trim() || null,
                    status: document.getElementById('editStatus')?.value || 'active'
                };

                try {
                    const response = await fetch('api/tenants/update.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        },
                        body: JSON.stringify(payload)
                    });

                    let data = null;
                    let rawText = '';
                    try {
                        rawText = await response.text();
                        data = rawText ? JSON.parse(rawText) : null;
                    } catch (e) {
                        data = null;
                    }

                    if (data && data.success) {
                        this.showToast('Azienda aggiornata con successo', 'success');
                        closeModal('editModal');
                        this.loadCompanies();
                    } else {
                        const msg = (data && (data.error || data.message)) ? (data.error || data.message) : ('Errore aggiornamento (HTTP ' + response.status + ')');
                        this.showToast(msg, 'error');
                        if (!data) {
                            console.error('updateCompany non-JSON response:', rawText.substring(0, 500));
                        } else {
                            console.error('updateCompany error payload:', data);
                        }
                    }
                } catch (error) {
                    console.error('Error updating company:', error);
                    this.showToast('Errore di connessione', 'error');
                }
            }

            openDeleteModal(companyId) {
                this.deleteCompanyId = companyId;
                this.systemTenantConfirmed = false; // Reset confirmation state

                // Customize modal for system tenant (ID 1)
                const modalTitle = document.getElementById('deleteModalTitle');
                const modalBody = document.getElementById('deleteModalBody');
                const confirmBtn = document.getElementById('deleteModalConfirmBtn');

                if (companyId === 1) {
                    modalTitle.innerHTML = '⚠️ ATTENZIONE: Eliminazione Tenant di Sistema';
                    modalBody.innerHTML = `
                        <p style="font-size: 16px; font-weight: 600; color: #DC2626; margin-bottom: 16px;">
                            Stai per eliminare il tenant di sistema (ID 1).
                        </p>
                        <p style="margin-bottom: 12px;">
                            Questa operazione eliminerà <strong>TUTTI i dati associati</strong> al tenant di sistema, inclusi:
                        </p>
                        <ul style="margin: 12px 0; padding-left: 24px; color: #4B5563;">
                            <li>Tutti gli utenti del tenant</li>
                            <li>Tutti i progetti e i relativi dati</li>
                            <li>Tutti i file e documenti</li>
                            <li>Tutte le attività e comunicazioni</li>
                        </ul>
                        <p class="text-danger text-sm" style="margin-top: 16px; padding: 12px; background: #FEE2E2; border-left: 4px solid #DC2626; border-radius: 4px;">
                            <strong>⚠️ ATTENZIONE:</strong> Questa operazione è <strong>IRREVERSIBILE</strong> e può compromettere il funzionamento del sistema. Sei assolutamente sicuro di voler procedere?
                        </p>
                    `;
                    confirmBtn.textContent = 'Conferma Eliminazione Sistema';
                    confirmBtn.className = 'btn btn-danger';
                    confirmBtn.style.backgroundColor = '#7F1D1D';
                } else {
                    modalTitle.textContent = 'Conferma Eliminazione';
                    modalBody.innerHTML = `
                        <p>Sei sicuro di voler eliminare questa azienda?</p>
                        <p class="text-muted text-sm">Questa azione eliminerà anche tutti gli utenti e i dati associati.</p>
                        <p class="text-danger text-sm" style="margin-top: 12px; padding: 8px; background: #FEE2E2; border-left: 3px solid #DC2626; border-radius: 4px;">
                            <strong>Attenzione:</strong> Questa operazione non può essere annullata. Tutti i dati verranno eliminati definitivamente.
                        </p>
                    `;
                    confirmBtn.textContent = 'Elimina';
                    confirmBtn.className = 'btn btn-danger';
                    confirmBtn.style.backgroundColor = '';
                }

                openModal('deleteModal');
            }

            async confirmDelete() {
                if (!this.deleteCompanyId) return;

                const formData = new FormData();
                formData.append('tenant_id', this.deleteCompanyId);
                formData.append('csrf_token', document.getElementById('csrfToken').value);

                // Add confirmation parameter for system tenant (ID 1)
                if (this.deleteCompanyId === 1) {
                    formData.append('confirm_system_tenant', 'true');
                }

                try {
                    const response = await fetch('api/tenants/delete.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: formData
                    });

                    // Verifica Content-Type prima di parsare JSON
                    const contentType = response.headers.get('content-type');
                    let data;

                    if (contentType && contentType.includes('application/json')) {
                        try {
                            data = await response.json();
                        } catch (jsonError) {
                            console.error('JSON parse error:', jsonError);
                            this.showToast('Errore nel formato della risposta del server', 'error');
                            closeModal('deleteModal');
                            this.deleteCompanyId = null;
                            return;
                        }
                    } else {
                        // Response non è JSON (probabilmente errore 500 con HTML)
                        const text = await response.text();
                        console.error('Non-JSON response:', text);
                        this.showToast('Errore server - risposta non JSON (HTTP ' + response.status + ')', 'error');
                        closeModal('deleteModal');
                        this.deleteCompanyId = null;
                        return;
                    }

                    // Chiudi sempre il modal
                    closeModal('deleteModal');

                    if (data.success) {
                        // Mostra dettagli cascata se disponibili
                        let message = 'Azienda eliminata con successo';

                        // Special warning for system tenant deletion
                        if (this.deleteCompanyId === 1) {
                            message = '⚠️ TENANT DI SISTEMA ELIMINATO - Azienda eliminata con successo';
                        }

                        if (data.data && data.data.cascade_info) {
                            const totalDeleted = Object.values(data.data.cascade_info).reduce((sum, val) => sum + (parseInt(val) || 0), 0);
                            message += ` (${totalDeleted} record eliminati)`;
                        }
                        this.showToast(message, 'success');
                        this.loadCompanies();
                    } else {
                        // Gestione errori specifici basati su status code e messaggio
                        const errorMessage = data.message || data.error || 'Errore nell\'eliminazione azienda';

                        // Messaggi user-friendly per errori comuni
                        let userMessage = errorMessage;

                        if (response.status === 500 && errorMessage.includes('system tenant')) {
                            userMessage = 'Eliminazione del tenant di sistema richiede conferma esplicita';
                        } else if (response.status === 403) {
                            if (errorMessage.includes('explicit confirmation')) {
                                userMessage = '⚠️ Eliminazione tenant di sistema richiede conferma esplicita';
                            } else {
                                userMessage = 'Non hai i permessi per eliminare questa azienda';
                            }
                        } else if (response.status === 400) {
                            userMessage = 'Richiesta non valida - verificare i dati';
                        } else if (response.status === 404) {
                            userMessage = 'Azienda non trovata';
                        }

                        this.showToast(userMessage, 'error');

                        // Log dettagliato solo in console per debugging
                        if (response.status !== 200) {
                            console.warn(`Delete failed - HTTP ${response.status}:`, data);
                        }
                    }
                } catch (error) {
                    console.error('Error deleting company:', error);
                    this.showToast('Errore di connessione al server', 'error');
                    closeModal('deleteModal');
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

            // ===== TENANT ROLES MANAGEMENT (Ruoli Aziendali) =====

            async openRolesModal(companyId) {
                const company = this.companies.find(c => c.id === companyId);
                if (!company) return;

                this.currentRolesCompanyId = companyId;
                this.tenantRoles = [];

                // Set company name in modal
                document.getElementById('rolesCompanyName').textContent = company.denominazione || company.name;

                // Reset form state
                this.cancelRoleForm();

                // Load roles and update UI
                await this.loadTenantRoles(companyId);

                openModal('rolesModal');
            }

            async loadTenantRoles(tenantId) {
                try {
                    const response = await fetch(`api/tenant-roles/list.php?tenant_id=${tenantId}&include_inactive=true`, {
                        credentials: 'same-origin',
                        headers: {
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        }
                    });

                    const data = await response.json();

                    if (data.success && data.data) {
                        this.tenantRoles = data.data.roles || [];
                        const hasCustomRoles = data.data.tenant_has_custom_roles || false;
                        this.canAssignCustomRoles = !!data.data.can_assign_custom_roles;
                        this.canManageCustomRoles = !!data.data.can_manage_custom_roles;
                        this.roleManagementPermStorageAvailable = !(data.data.storage_available_management_roles === false);
                        this.tenantRoleManagementRolesRaw = data.data.tenant_role_management_roles ?? null;

                        // Update toggle state
                        const hasCustomRolesToggle = document.getElementById('hasCustomRolesToggle');
                        if (hasCustomRolesToggle) {
                            hasCustomRolesToggle.checked = hasCustomRoles;
                            // Only Admin/Super Admin can toggle (API tenants/update requires admin+)
                            const canToggle = (this.currentUserRole === 'super_admin' || this.currentUserRole === 'admin');
                            hasCustomRolesToggle.disabled = !canToggle;
                            hasCustomRolesToggle.title = !canToggle ? 'Solo Admin/Super Admin possono abilitare/disabilitare i ruoli aziendali' : '';
                        }

                        // Super Admin only: show and prefill permissions panel
                        this.initTenantRoleAssignmentPermissionsUI(tenantId, hasCustomRoles);
                        this.initTenantRoleManagementPermissionsUI(tenantId, hasCustomRoles);

                        // Per-tenant shift permissions (super_admin/admin/manager)
                        await this.initShiftPermissionsUI(tenantId);

                        // Update UI visibility
                        this.updateRolesUIState(hasCustomRoles);

                        // Render roles table
                        this.renderRolesTable();
                    } else {
                        this.showToast(data.message || 'Errore caricamento ruoli', 'error');
                    }
                } catch (error) {
                    console.error('Error loading tenant roles:', error);
                    this.showToast('Errore di connessione', 'error');
                }
            }

            initTenantRoleAssignmentPermissionsUI(tenantId, hasCustomRoles) {
                const panel = document.getElementById('tenantRoleAssignmentPermissions');
                if (!panel) return;

                // Only super_admin can configure. We infer from session role via PHP-rendered badge in sidebar:
                // Companies page is already admin-only, but we need super_admin specifically.
                const currentRole = (window.CNX_CURRENT_USER_ROLE || '').toString();
                const isSuperAdmin = (currentRole === 'super_admin');

                if (!isSuperAdmin || !hasCustomRoles) {
                    panel.style.display = 'none';
                    return;
                }

                panel.style.display = 'block';

                // Read allowed roles from the loaded company object (best-effort)
                const company = this.companies.find(c => c.id === tenantId);
                let allowed = [];
                try {
                    const raw = company ? (company.tenant_role_assignment_roles || null) : null;
                    if (raw) {
                        const decoded = JSON.parse(raw);
                        if (Array.isArray(decoded)) allowed = decoded.map(String);
                    }
                } catch (e) {
                    allowed = [];
                }

                document.getElementById('permAssignAdmin').checked = allowed.includes('admin');
                document.getElementById('permAssignManager').checked = allowed.includes('manager');
                document.getElementById('permAssignUser').checked = allowed.includes('user');
            }

            initTenantRoleManagementPermissionsUI(tenantId, hasCustomRoles) {
                const panel = document.getElementById('tenantRoleManagementPermissions');
                if (!panel) return;

                const currentRole = (window.CNX_CURRENT_USER_ROLE || '').toString();
                const isSuperAdmin = (currentRole === 'super_admin');
                if (!isSuperAdmin || !hasCustomRoles) {
                    panel.style.display = 'none';
                    return;
                }
                panel.style.display = 'block';

                const note = document.getElementById('tenantRoleManagementPermStorageNote');
                const saveBtn = document.getElementById('tenantRoleManagementPermSaveBtn');
                if (note) note.style.display = 'none';
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.removeAttribute('title');
                }

                if (this.roleManagementPermStorageAvailable === false) {
                    if (note) note.style.display = 'block';
                    if (saveBtn) {
                        saveBtn.disabled = true;
                        saveBtn.title = 'Permessi non salvabili: migrazione non applicata';
                    }
                }

                let allowed = [];
                try {
                    const raw = this.tenantRoleManagementRolesRaw;
                    if (typeof raw === 'string' && raw) {
                        const decoded = JSON.parse(raw);
                        if (Array.isArray(decoded)) allowed = decoded.map(String);
                    }
                } catch (e) {
                    allowed = [];
                }

                document.getElementById('permRoleManageAdmin').checked = allowed.includes('admin');
                document.getElementById('permRoleManageManager').checked = allowed.includes('manager');
                document.getElementById('permRoleManageUser').checked = allowed.includes('user');
            }

            async initShiftPermissionsUI(tenantId) {
                const panel = document.getElementById('shiftPermissionsPanel');
                if (!panel) return;

                const currentRole = (window.CNX_CURRENT_USER_ROLE || '').toString();
                const canConfigure = (currentRole === 'super_admin' || currentRole === 'admin' || currentRole === 'manager');
                if (!canConfigure) {
                    panel.style.display = 'none';
                    return;
                }

                panel.style.display = 'block';
                const note = document.getElementById('shiftPermStorageNote');
                const saveBtn = document.getElementById('shiftPermSaveBtn');
                if (note) note.style.display = 'none';
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.removeAttribute('title');
                }
                this.shiftPermStorageAvailable = true;

                // Load current permissions from API (best effort)
                try {
                    const url = `api/shifts/permissions.php?action=get&tenant_id=${encodeURIComponent(String(tenantId))}&_ts=${Date.now()}`;
                    const response = await fetch(url, {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'X-CSRF-Token': document.getElementById('csrfToken').value,
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await response.json().catch(() => ({}));
                    if (data && data.success && data.data && data.data.permissions) {
                        this.shiftPermStorageAvailable = !(data.data.storage_available === false);
                        const perms = data.data.permissions;
                        const manage = Array.isArray(perms.can_manage_shifts_roles) ? perms.can_manage_shifts_roles.map(String) : [];
                        const approve = Array.isArray(perms.can_approve_shift_requests_roles) ? perms.can_approve_shift_requests_roles.map(String) : [];

                        document.getElementById('permShiftManageAdmin').checked = manage.includes('admin');
                        document.getElementById('permShiftManageManager').checked = manage.includes('manager');
                        document.getElementById('permShiftManageUser').checked = manage.includes('user');

                        document.getElementById('permShiftApproveAdmin').checked = approve.includes('admin');
                        document.getElementById('permShiftApproveManager').checked = approve.includes('manager');
                        document.getElementById('permShiftApproveUser').checked = approve.includes('user');

                        if (data.data.storage_available === false) {
                            if (note) note.style.display = 'block';
                            if (saveBtn) {
                                saveBtn.disabled = true;
                                saveBtn.title = 'Permessi non salvabili: migrazione non applicata';
                            }
                        }
                    } else {
                        // Defaults: admin+manager enabled
                        document.getElementById('permShiftManageAdmin').checked = true;
                        document.getElementById('permShiftManageManager').checked = true;
                        document.getElementById('permShiftManageUser').checked = false;

                        document.getElementById('permShiftApproveAdmin').checked = true;
                        document.getElementById('permShiftApproveManager').checked = true;
                        document.getElementById('permShiftApproveUser').checked = false;
                    }
                } catch (e) {
                    console.error('initShiftPermissionsUI error:', e);
                }
            }

            resetShiftPermissions() {
                const panel = document.getElementById('shiftPermissionsPanel');
                if (!panel || panel.style.display === 'none') return;

                // Defaults: admin+manager
                document.getElementById('permShiftManageAdmin').checked = true;
                document.getElementById('permShiftManageManager').checked = true;
                document.getElementById('permShiftManageUser').checked = false;

                document.getElementById('permShiftApproveAdmin').checked = true;
                document.getElementById('permShiftApproveManager').checked = true;
                document.getElementById('permShiftApproveUser').checked = false;
            }

            async saveShiftPermissions() {
                const panel = document.getElementById('shiftPermissionsPanel');
                if (!panel || panel.style.display === 'none') return;
                if (this.shiftPermStorageAvailable === false) {
                    this.showToast('Permessi turni non salvabili: migrazione non applicata', 'warning');
                    return;
                }

                const manage = [];
                if (document.getElementById('permShiftManageAdmin').checked) manage.push('admin');
                if (document.getElementById('permShiftManageManager').checked) manage.push('manager');
                if (document.getElementById('permShiftManageUser').checked) manage.push('user');

                const approve = [];
                if (document.getElementById('permShiftApproveAdmin').checked) approve.push('admin');
                if (document.getElementById('permShiftApproveManager').checked) approve.push('manager');
                if (document.getElementById('permShiftApproveUser').checked) approve.push('user');

                try {
                    const response = await fetch('api/shifts/permissions.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.getElementById('csrfToken').value,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            action: 'save',
                            csrf_token: document.getElementById('csrfToken').value,
                            tenant_id: this.currentRolesCompanyId,
                            can_manage_shifts_roles: manage,
                            can_approve_shift_requests_roles: approve
                        })
                    });

                    const data = await response.json().catch(() => ({}));
                    if (data && data.success) {
                        this.showToast('Permessi turni aggiornati', 'success');
                    } else {
                        this.showToast((data && (data.error || data.message)) ? (data.error || data.message) : 'Errore aggiornamento permessi turni', 'error');
                    }
                } catch (e) {
                    console.error('saveShiftPermissions error:', e);
                    this.showToast('Errore di connessione', 'error');
                }
            }

            resetTenantRoleAssignmentPermissions() {
                const panel = document.getElementById('tenantRoleAssignmentPermissions');
                if (!panel || panel.style.display === 'none') return;
                document.getElementById('permAssignAdmin').checked = false;
                document.getElementById('permAssignManager').checked = false;
                document.getElementById('permAssignUser').checked = false;
            }

            async saveTenantRoleAssignmentPermissions() {
                const panel = document.getElementById('tenantRoleAssignmentPermissions');
                if (!panel || panel.style.display === 'none') return;

                const roles = [];
                if (document.getElementById('permAssignAdmin').checked) roles.push('admin');
                if (document.getElementById('permAssignManager').checked) roles.push('manager');
                if (document.getElementById('permAssignUser').checked) roles.push('user');

                try {
                    const response = await fetch('api/tenants/update.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        },
                        body: JSON.stringify({
                            csrf_token: document.getElementById('csrfToken').value,
                            tenant_id: this.currentRolesCompanyId,
                            tenant_role_assignment_roles: roles
                        })
                    });

                    const data = await response.json();
                    if (data && data.success) {
                        // Update local cache so UI remains consistent without reload
                        const company = this.companies.find(c => c.id === this.currentRolesCompanyId);
                        if (company) {
                            company.tenant_role_assignment_roles = JSON.stringify(roles);
                        }
                        this.showToast('Permessi aggiornati', 'success');
                    } else {
                        this.showToast((data && (data.error || data.message)) ? (data.error || data.message) : 'Errore aggiornamento permessi', 'error');
                    }
                } catch (e) {
                    console.error('saveTenantRoleAssignmentPermissions error:', e);
                    this.showToast('Errore di connessione', 'error');
                }
            }

            resetTenantRoleManagementPermissions() {
                const panel = document.getElementById('tenantRoleManagementPermissions');
                if (!panel || panel.style.display === 'none') return;
                document.getElementById('permRoleManageAdmin').checked = false;
                document.getElementById('permRoleManageManager').checked = false;
                document.getElementById('permRoleManageUser').checked = false;
            }

            async saveTenantRoleManagementPermissions() {
                const panel = document.getElementById('tenantRoleManagementPermissions');
                if (!panel || panel.style.display === 'none') return;
                if (this.roleManagementPermStorageAvailable === false) {
                    this.showToast('Permessi gestione ruoli non salvabili: migrazione non applicata', 'warning');
                    return;
                }

                const roles = [];
                if (document.getElementById('permRoleManageAdmin').checked) roles.push('admin');
                if (document.getElementById('permRoleManageManager').checked) roles.push('manager');
                if (document.getElementById('permRoleManageUser').checked) roles.push('user');

                try {
                    const response = await fetch('api/tenants/update.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        },
                        body: JSON.stringify({
                            csrf_token: document.getElementById('csrfToken').value,
                            tenant_id: this.currentRolesCompanyId,
                            tenant_role_management_roles: roles
                        })
                    });

                    const data = await response.json().catch(() => ({}));
                    if (data && data.success) {
                        // Update local cache so UI remains consistent without reload
                        const company = this.companies.find(c => c.id === this.currentRolesCompanyId);
                        if (company) {
                            company.tenant_role_management_roles = JSON.stringify(roles);
                        }
                        this.tenantRoleManagementRolesRaw = JSON.stringify(roles);
                        this.showToast('Permessi gestione ruoli aggiornati', 'success');
                        // Keep UX consistent: canManage doesn't change for super_admin, but refresh flags anyway
                        this.canManageCustomRoles = true;
                        this.updateRolesUIState(!!document.getElementById('hasCustomRolesToggle')?.checked);
                        this.renderRolesTable();
                    } else {
                        this.showToast((data && (data.error || data.message)) ? (data.error || data.message) : 'Errore aggiornamento permessi gestione ruoli', 'error');
                    }
                } catch (e) {
                    console.error('saveTenantRoleManagementPermissions error:', e);
                    this.showToast('Errore di connessione', 'error');
                }
            }

            updateRolesUIState(hasCustomRoles) {
                const rolesListContainer = document.getElementById('rolesListContainer');
                const addRoleBtn = document.getElementById('addRoleBtn');

                if (hasCustomRoles) {
                    rolesListContainer.style.display = 'block';
                    // Show "+ Nuovo Ruolo" only if user can manage tenant roles
                    const canManage = !!this.canManageCustomRoles;
                    addRoleBtn.style.display = canManage ? 'inline-flex' : 'none';
                } else {
                    rolesListContainer.style.display = 'none';
                    addRoleBtn.style.display = 'none';
                }
            }

            renderRolesTable() {
                const tbody = document.getElementById('rolesTableBody');
                const emptyState = document.getElementById('rolesEmptyState');
                const canManage = !!this.canManageCustomRoles;

                if (!this.tenantRoles || this.tenantRoles.length === 0) {
                    tbody.innerHTML = '';
                    emptyState.style.display = 'block';
                    return;
                }

                emptyState.style.display = 'none';
                tbody.innerHTML = this.tenantRoles.map(role => {
                    const textColor = this.getContrastingColor(role.color || '#6366f1');
                    return `
                        <tr style="border-bottom: 1px solid var(--color-gray-200);">
                            <td style="padding: 12px;">
                                <div style="width: 24px; height: 24px; border-radius: var(--radius-full); background: ${role.color || '#6366f1'};"></div>
                            </td>
                            <td style="padding: 12px;">
                                <span style="display: inline-block; padding: 4px 12px; border-radius: var(--radius-full); background: ${role.color || '#6366f1'}; color: ${textColor}; font-weight: 500;">${role.name}</span>
                                ${role.description ? `<div style="font-size: 12px; color: var(--color-gray-500); margin-top: 4px;">${role.description}</div>` : ''}
                            </td>
                            <td style="padding: 12px; font-family: monospace; font-size: 12px;">${role.code || '-'}</td>
                            <td style="padding: 12px;">
                                <span style="display: inline-flex; align-items: center; justify-content: center; min-width: 24px; height: 24px; background: var(--color-gray-100); border-radius: var(--radius-full); font-size: 12px; font-weight: 600;">${role.user_count || 0}</span>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <div class="action-buttons" style="justify-content: center;">
                                    <button class="btn-icon edit" onclick="companyManager.openEditRoleForm(${role.id})" title="Modifica" ${!canManage ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''}>
                                        ✏️
                                    </button>
                                    <button class="btn-icon delete" onclick="companyManager.deleteRole(${role.id})" title="Elimina" ${(!canManage || role.user_count > 0) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''}>
                                        🗑️
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('');
            }

            getContrastingColor(hexColor) {
                const hex = (hexColor || '#6366f1').replace('#', '');
                const r = parseInt(hex.substr(0, 2), 16);
                const g = parseInt(hex.substr(2, 2), 16);
                const b = parseInt(hex.substr(4, 2), 16);
                const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
                return luminance > 0.5 ? '#000000' : '#ffffff';
            }

            async toggleCustomRoles() {
                const hasCustomRoles = document.getElementById('hasCustomRolesToggle').checked;

                try {
                    const response = await fetch('api/tenants/update.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        },
                        body: JSON.stringify({
                            csrf_token: document.getElementById('csrfToken').value,
                            tenant_id: this.currentRolesCompanyId,
                            has_custom_roles: hasCustomRoles
                        })
                    });

                    let data = null;
                    let rawText = '';
                    try {
                        rawText = await response.text();
                        data = rawText ? JSON.parse(rawText) : null;
                    } catch (e) {
                        data = null;
                    }

                    if (data && data.success) {
                        this.updateRolesUIState(hasCustomRoles);
                        this.showToast(hasCustomRoles ? 'Ruoli aziendali abilitati' : 'Ruoli aziendali disabilitati', 'success');

                        // After enabling, reload roles list immediately
                        if (hasCustomRoles) {
                            try {
                                await this.loadTenantRoles(this.currentRolesCompanyId);
                            } catch (e) {
                                console.warn('Failed to reload tenant roles after enabling:', e);
                            }
                        } else {
                            // Clear roles list when disabling
                            this.tenantRoles = [];
                            this.renderRolesTable();
                        }
                    } else {
                        // Revert toggle
                        document.getElementById('hasCustomRolesToggle').checked = !hasCustomRoles;
                        const msg = (data && (data.error || data.message)) ? (data.error || data.message) : ('Errore aggiornamento (HTTP ' + response.status + ')');
                        this.showToast(msg, 'error');
                        if (!data) {
                            console.error('toggleCustomRoles non-JSON response:', rawText.substring(0, 500));
                        } else {
                            console.error('toggleCustomRoles error payload:', data);
                        }
                    }
                } catch (error) {
                    console.error('Error toggling custom roles:', error);
                    document.getElementById('hasCustomRolesToggle').checked = !hasCustomRoles;
                    this.showToast('Errore di connessione', 'error');
                }
            }

            openAddRoleForm() {
                if (!this.canManageCustomRoles) {
                    this.showToast('Permessi insufficienti per creare ruoli aziendali', 'error');
                    return;
                }
                document.getElementById('roleFormTitle').textContent = 'Nuovo Ruolo';
                document.getElementById('roleFormId').value = '';
                document.getElementById('roleFormName').value = '';
                document.getElementById('roleFormCode').value = '';
                document.getElementById('roleFormColor').value = '#6366f1';
                document.getElementById('roleFormSortOrder').value = this.tenantRoles.length;
                document.getElementById('roleFormDescription').value = '';
                this.updateColorPreview('#6366f1');
                document.getElementById('roleFormContainer').style.display = 'block';
            }

            openEditRoleForm(roleId) {
                if (!this.canManageCustomRoles) {
                    this.showToast('Permessi insufficienti per modificare ruoli aziendali', 'error');
                    return;
                }
                const role = this.tenantRoles.find(r => r.id === roleId);
                if (!role) return;

                document.getElementById('roleFormTitle').textContent = 'Modifica Ruolo';
                document.getElementById('roleFormId').value = role.id;
                document.getElementById('roleFormName').value = role.name;
                document.getElementById('roleFormCode').value = role.code || '';
                document.getElementById('roleFormColor').value = role.color || '#6366f1';
                document.getElementById('roleFormSortOrder').value = role.sort_order || 0;
                document.getElementById('roleFormDescription').value = role.description || '';
                this.updateColorPreview(role.color || '#6366f1');
                document.getElementById('roleFormContainer').style.display = 'block';
            }

            cancelRoleForm() {
                document.getElementById('roleFormContainer').style.display = 'none';
                document.getElementById('roleForm').reset();
            }

            updateColorPreview(color) {
                const preview = document.getElementById('roleColorPreview');
                const textColor = this.getContrastingColor(color);
                preview.style.background = color;
                preview.style.color = textColor;
            }

            async saveRole() {
                if (!this.canManageCustomRoles) {
                    this.showToast('Permessi insufficienti per salvare ruoli aziendali', 'error');
                    return;
                }
                const roleId = document.getElementById('roleFormId').value;
                const name = document.getElementById('roleFormName').value.trim();
                const code = document.getElementById('roleFormCode').value.trim().toUpperCase();
                const color = document.getElementById('roleFormColor').value;
                const sortOrder = parseInt(document.getElementById('roleFormSortOrder').value) || 0;
                const description = document.getElementById('roleFormDescription').value.trim();

                if (!name) {
                    this.showToast('Inserisci il nome del ruolo', 'error');
                    return;
                }

                const payload = {
                    csrf_token: document.getElementById('csrfToken').value,
                    tenant_id: this.currentRolesCompanyId,
                    name: name,
                    code: code || null,
                    color: color,
                    sort_order: sortOrder,
                    description: description || null
                };

                const isEdit = roleId !== '';
                if (isEdit) {
                    payload.role_id = parseInt(roleId);
                }

                try {
                    const endpoint = isEdit ? 'api/tenant-roles/update.php' : 'api/tenant-roles/create.php';
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        },
                        body: JSON.stringify(payload)
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.showToast(isEdit ? 'Ruolo aggiornato' : 'Ruolo creato', 'success');
                        this.cancelRoleForm();
                        await this.loadTenantRoles(this.currentRolesCompanyId);
                    } else {
                        this.showToast(data.message || 'Errore salvataggio', 'error');
                    }
                } catch (error) {
                    console.error('Error saving role:', error);
                    this.showToast('Errore di connessione', 'error');
                }
            }

            async deleteRole(roleId) {
                if (!this.canManageCustomRoles) {
                    this.showToast('Permessi insufficienti per eliminare ruoli aziendali', 'error');
                    return;
                }
                const role = this.tenantRoles.find(r => r.id === roleId);
                if (!role) return;

                if (role.user_count > 0) {
                    this.showToast('Impossibile eliminare un ruolo assegnato a utenti', 'error');
                    return;
                }

                if (!confirm(`Sei sicuro di voler eliminare il ruolo "${role.name}"?`)) {
                    return;
                }

                try {
                    const response = await fetch('api/tenant-roles/delete.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        },
                        body: JSON.stringify({
                            csrf_token: document.getElementById('csrfToken').value,
                            role_id: roleId,
                            tenant_id: this.currentRolesCompanyId
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.showToast('Ruolo eliminato', 'success');
                        await this.loadTenantRoles(this.currentRolesCompanyId);
                    } else {
                        this.showToast(data.message || 'Errore eliminazione', 'error');
                    }
                } catch (error) {
                    console.error('Error deleting role:', error);
                    this.showToast('Errore di connessione', 'error');
                }
            }

            initRoleFormListeners() {
                // Color picker live preview
                const colorInput = document.getElementById('roleFormColor');
                if (colorInput) {
                    colorInput.addEventListener('input', (e) => {
                        this.updateColorPreview(e.target.value);
                    });
                }

                // Form submission
                const roleForm = document.getElementById('roleForm');
                if (roleForm) {
                    roleForm.addEventListener('submit', (e) => {
                        e.preventDefault();
                        this.saveRole();
                    });
                }

                // Auto-generate code from name
                const nameInput = document.getElementById('roleFormName');
                const codeInput = document.getElementById('roleFormCode');
                if (nameInput && codeInput) {
                    nameInput.addEventListener('input', (e) => {
                        if (!codeInput.value || codeInput.dataset.autoGenerated === 'true') {
                            const code = e.target.value
                                .toUpperCase()
                                .replace(/[^A-Z0-9\s]/g, '')
                                .replace(/\s+/g, '_')
                                .substring(0, 20);
                            codeInput.value = code;
                            codeInput.dataset.autoGenerated = 'true';
                        }
                    });

                    codeInput.addEventListener('input', () => {
                        codeInput.dataset.autoGenerated = 'false';
                    });
                }
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
            // Clear sedi operative
            document.getElementById('addSediOperativeContainer').innerHTML = '';
            sediOperativeCounters.add = 0;
            // Set default values
            document.getElementById('addStatus').value = 'active';
            openModal('addModal');
        }

        function confirmDelete() {
            companyManager.confirmDelete();
        }

        // Sedi Operative Management
        let sediOperativeCounters = { add: 0, edit: 0 };
        const provinces = <?php echo getProvincesJS(); ?>;

        function getProvinceOptions(selected = '') {
            let html = '<option value="">Seleziona provincia</option>';
            for (const [code, name] of Object.entries(provinces)) {
                const isSelected = code === selected ? 'selected' : '';
                html += `<option value="${code}" ${isSelected}>${name} (${code})</option>`;
            }
            return html;
        }

        function addSedeOperativa(modalType = 'add') {
            const maxSedi = 20;
            if (sediOperativeCounters[modalType] >= maxSedi) {
                companyManager.showToast(`Massimo ${maxSedi} sedi operative`, 'warning');
                return;
            }

            sediOperativeCounters[modalType]++;
            const counter = sediOperativeCounters[modalType];
            const container = document.getElementById(`${modalType}SediOperativeContainer`);
            const prefix = modalType === 'add' ? 'add' : 'edit';

            const html = `
                <div class="sede-card" id="${prefix}SedeOp${counter}">
                    <div class="sede-card-header">
                        <h4>Sede Operativa #${counter}</h4>
                        <button type="button" class="btn-remove" onclick="removeSedeOperativa('${modalType}', ${counter})">
                            Rimuovi
                        </button>
                    </div>
                    <div class="form-row">
                        <div class="form-group span-2">
                            <label>Indirizzo</label>
                            <input type="text" id="${prefix}_so_indirizzo_${counter}"
                                   name="so_indirizzo_${counter}"
                                   class="sede-operativa-field"
                                   placeholder="Via Milano">
                        </div>
                        <div class="form-group">
                            <label>Civico</label>
                            <input type="text" id="${prefix}_so_civico_${counter}"
                                   name="so_civico_${counter}"
                                   class="sede-operativa-field"
                                   placeholder="45">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>CAP</label>
                            <input type="text" id="${prefix}_so_cap_${counter}"
                                   name="so_cap_${counter}"
                                   pattern="[0-9]{5}"
                                   maxlength="5"
                                   class="sede-operativa-field"
                                   placeholder="20100">
                        </div>
                        <div class="form-group">
                            <label>Comune</label>
                            <input type="text" id="${prefix}_so_comune_${counter}"
                                   name="so_comune_${counter}"
                                   class="sede-operativa-field"
                                   placeholder="Milano">
                        </div>
                        <div class="form-group">
                            <label>Provincia</label>
                            <select id="${prefix}_so_provincia_${counter}"
                                    name="so_provincia_${counter}"
                                    class="sede-operativa-field">
                                ${getProvinceOptions()}
                            </select>
                        </div>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', html);
        }

        function removeSedeOperativa(modalType, index) {
            const prefix = modalType === 'add' ? 'add' : 'edit';
            const element = document.getElementById(`${prefix}SedeOp${index}`);
            if (element) {
                element.remove();
            }
        }

        function collectSediOperative(modalType = 'add') {
            const prefix = modalType === 'add' ? 'add' : 'edit';
            const container = document.getElementById(`${modalType}SediOperativeContainer`);
            const sedi = [];

            container.querySelectorAll('.sede-card').forEach((card) => {
                const indirizzo = card.querySelector('[name^="so_indirizzo"]').value.trim();
                const civico = card.querySelector('[name^="so_civico"]').value.trim();
                const cap = card.querySelector('[name^="so_cap"]').value.trim();
                const comune = card.querySelector('[name^="so_comune"]').value.trim();
                const provincia = card.querySelector('[name^="so_provincia"]').value.trim();

                // Only add if at minimum indirizzo and comune are provided
                if (indirizzo && comune) {
                    sedi.push({
                        indirizzo,
                        civico,
                        cap,
                        comune,
                        provincia
                    });
                }
            });

            return sedi;
        }

        // Municipality Autocomplete Component
        class MunicipalityAutocomplete {
            constructor(inputElement, provinceElement) {
                this.input = inputElement;
                this.provinceInput = provinceElement;
                this.resultsContainer = null;
                this.municipalities = [];
                this.selectedIndex = -1;
                this.debounceTimer = null;
                this.isLoading = false;
                this.blurTimer = null;
                this.init();
            }

            init() {
                this.createResultsContainer();
                this.bindEvents();
            }

            createResultsContainer() {
                // Wrap input in wrapper div if not already wrapped
                if (!this.input.parentElement.classList.contains('municipality-autocomplete-wrapper')) {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'municipality-autocomplete-wrapper';
                    this.input.parentNode.insertBefore(wrapper, this.input);
                    wrapper.appendChild(this.input);
                }

                // Create results container
                this.resultsContainer = document.createElement('div');
                this.resultsContainer.className = 'municipality-autocomplete-results';
                this.resultsContainer.setAttribute('role', 'listbox');
                this.input.parentElement.appendChild(this.resultsContainer);
            }

            bindEvents() {
                // Input event with debounce
                this.input.addEventListener('input', (e) => {
                    clearTimeout(this.debounceTimer);
                    const query = e.target.value.trim();

                    if (query.length < 2) {
                        this.hideResults();
                        return;
                    }

                    this.debounceTimer = setTimeout(() => {
                        this.search(query);
                    }, 300);
                });

                // Keyboard navigation
                this.input.addEventListener('keydown', (e) => {
                    if (!this.resultsContainer.classList.contains('show')) return;

                    switch (e.key) {
                        case 'ArrowDown':
                            e.preventDefault();
                            this.selectedIndex = Math.min(this.selectedIndex + 1, this.municipalities.length - 1);
                            this.updateSelection();
                            break;
                        case 'ArrowUp':
                            e.preventDefault();
                            this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
                            this.updateSelection();
                            break;
                        case 'Enter':
                            e.preventDefault();
                            if (this.selectedIndex >= 0) {
                                this.selectMunicipality(this.municipalities[this.selectedIndex]);
                            }
                            break;
                        case 'Escape':
                            this.hideResults();
                            break;
                    }
                });

                // Blur event with delay to allow click on results
                this.input.addEventListener('blur', () => {
                    this.blurTimer = setTimeout(() => {
                        this.hideResults();
                    }, 200);
                });

                // Focus event - clear blur timer
                this.input.addEventListener('focus', () => {
                    clearTimeout(this.blurTimer);
                });

                // Click on results
                this.resultsContainer.addEventListener('mousedown', (e) => {
                    e.preventDefault(); // Prevent blur
                });

                this.resultsContainer.addEventListener('click', (e) => {
                    const item = e.target.closest('.autocomplete-item');
                    if (item) {
                        const index = parseInt(item.dataset.index);
                        this.selectMunicipality(this.municipalities[index]);
                    }
                });

                // Mouse hover
                this.resultsContainer.addEventListener('mouseover', (e) => {
                    const item = e.target.closest('.autocomplete-item');
                    if (item) {
                        this.selectedIndex = parseInt(item.dataset.index);
                        this.updateSelection();
                    }
                });
            }

            async search(query) {
                this.isLoading = true;
                this.showLoading();

                try {
                    const province = this.provinceInput.value;
                    const url = new URL('/CollaboraNexio/api/locations/search_municipalities.php', window.location.origin);
                    url.searchParams.append('q', query);
                    if (province) {
                        url.searchParams.append('province', province);
                    }

                    const response = await fetch(url.toString(), {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json'
                        },
                        credentials: 'same-origin'
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const data = await response.json();

                    if (data.success && data.data && data.data.results) {
                        // Map API response to expected format
                        this.municipalities = data.data.results.map(item => ({
                            name: item.name,
                            province: item.province_code
                        }));
                        this.renderResults(query);
                    } else {
                        this.showNoResults();
                    }
                } catch (error) {
                    console.error('Municipality search error:', error);
                    this.showError();
                } finally {
                    this.isLoading = false;
                }
            }

            showLoading() {
                this.resultsContainer.innerHTML = '<div class="autocomplete-loading">Ricerca in corso...</div>';
                this.resultsContainer.classList.add('show');
            }

            showNoResults() {
                this.resultsContainer.innerHTML = '<div class="autocomplete-no-results">Nessun comune trovato</div>';
                this.resultsContainer.classList.add('show');
            }

            showError() {
                this.resultsContainer.innerHTML = '<div class="autocomplete-no-results">Errore durante la ricerca</div>';
                this.resultsContainer.classList.add('show');
            }

            renderResults(query) {
                if (this.municipalities.length === 0) {
                    this.showNoResults();
                    return;
                }

                const queryLower = query.toLowerCase();
                const html = this.municipalities.map((mun, index) => {
                    const nameLower = mun.name.toLowerCase();
                    const nameHighlighted = this.highlightMatch(mun.name, query);

                    return `
                        <div class="autocomplete-item" data-index="${index}" role="option">
                            <span class="mun-name">${nameHighlighted}</span>
                            <span class="mun-province">(${mun.province})</span>
                        </div>
                    `;
                }).join('');

                this.resultsContainer.innerHTML = html;
                this.resultsContainer.classList.add('show');
                this.selectedIndex = -1;
            }

            highlightMatch(text, query) {
                const index = text.toLowerCase().indexOf(query.toLowerCase());
                if (index === -1) return text;

                const before = text.substring(0, index);
                const match = text.substring(index, index + query.length);
                const after = text.substring(index + query.length);

                return `${before}<strong>${match}</strong>${after}`;
            }

            updateSelection() {
                const items = this.resultsContainer.querySelectorAll('.autocomplete-item');
                items.forEach((item, index) => {
                    if (index === this.selectedIndex) {
                        item.classList.add('selected');
                        item.scrollIntoView({ block: 'nearest' });
                    } else {
                        item.classList.remove('selected');
                    }
                });
            }

            selectMunicipality(mun) {
                if (!mun) return;

                this.input.value = mun.name;
                this.provinceInput.value = mun.province;

                // Trigger change event
                this.input.dispatchEvent(new Event('change', { bubbles: true }));
                this.provinceInput.dispatchEvent(new Event('change', { bubbles: true }));

                this.hideResults();
            }

            hideResults() {
                this.resultsContainer.classList.remove('show');
                this.resultsContainer.innerHTML = '';
                this.municipalities = [];
                this.selectedIndex = -1;
            }
        }

        /**
         * AlternativeTaxCodeValidator
         * Validates that at least one of Codice Fiscale or Partita IVA is filled
         * Provides dynamic visual feedback and form submission validation
         */
        class AlternativeTaxCodeValidator {
            constructor(cfInput, pivaInput, formElement) {
                this.cfInput = cfInput;
                this.pivaInput = pivaInput;
                this.formElement = formElement;
                this.cfLabel = cfInput.closest('.form-group').querySelector('.alt-tax-asterisk');
                this.pivaLabel = pivaInput.closest('.form-group').querySelector('.alt-tax-asterisk');
                this.validationMessage = null;

                this.init();
            }

            init() {
                // Create validation message element
                this.createValidationMessage();

                // Add event listeners
                this.cfInput.addEventListener('input', () => this.validate());
                this.pivaInput.addEventListener('input', () => this.validate());
                this.cfInput.addEventListener('blur', () => this.validate());
                this.pivaInput.addEventListener('blur', () => this.validate());

                // Intercept form submission
                this.formElement.addEventListener('submit', (e) => {
                    if (!this.validateBeforeSubmit()) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                });

                // Initial validation
                this.validate();
            }

            createValidationMessage() {
                this.validationMessage = document.createElement('div');
                this.validationMessage.className = 'alt-tax-validation-message';
                this.validationMessage.innerHTML = '<i class="fas fa-exclamation-triangle"></i>È richiesto almeno uno tra Codice Fiscale e Partita IVA';

                // Insert before the form row containing the fields
                const formRow = this.cfInput.closest('.form-row');
                formRow.parentNode.insertBefore(this.validationMessage, formRow);
            }

            validate() {
                const cfValue = this.cfInput.value.trim();
                const pivaValue = this.pivaInput.value.trim();

                // Check if at least one is filled
                const isValid = cfValue !== '' || pivaValue !== '';

                // Update visual feedback
                this.updateVisualFeedback(isValid, cfValue, pivaValue);

                return isValid;
            }

            updateVisualFeedback(isValid, cfValue, pivaValue) {
                // Remove all validation classes first
                this.cfInput.classList.remove('alt-tax-valid', 'alt-tax-invalid');
                this.pivaInput.classList.remove('alt-tax-valid', 'alt-tax-invalid');
                this.cfLabel.classList.remove('valid');
                this.pivaLabel.classList.remove('valid');

                if (isValid) {
                    // At least one is filled - show green for filled fields
                    if (cfValue !== '') {
                        this.cfInput.classList.add('alt-tax-valid');
                        this.cfLabel.classList.add('valid');
                    }
                    if (pivaValue !== '') {
                        this.pivaInput.classList.add('alt-tax-valid');
                        this.pivaLabel.classList.add('valid');
                    }

                    // Hide validation message
                    this.validationMessage.classList.remove('show');
                } else {
                    // Both empty - show red for both
                    this.cfInput.classList.add('alt-tax-invalid');
                    this.pivaInput.classList.add('alt-tax-invalid');

                    // Don't show message immediately, only on submit attempt
                }
            }

            validateBeforeSubmit() {
                const isValid = this.validate();

                if (!isValid) {
                    // Show validation message
                    this.validationMessage.classList.add('show');

                    // Focus on first empty field
                    if (this.cfInput.value.trim() === '') {
                        this.cfInput.focus();
                    } else if (this.pivaInput.value.trim() === '') {
                        this.pivaInput.focus();
                    }

                    // Scroll to message
                    this.validationMessage.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }

                return isValid;
            }

            reset() {
                // Clear validation state
                this.cfInput.classList.remove('alt-tax-valid', 'alt-tax-invalid');
                this.pivaInput.classList.remove('alt-tax-valid', 'alt-tax-invalid');
                this.cfLabel.classList.remove('valid');
                this.pivaLabel.classList.remove('valid');
                this.validationMessage.classList.remove('show');
            }
        }

        // Initialize when DOM is ready
        let companyManager;
        let addMunicipalityAutocomplete;
        let editMunicipalityAutocomplete;
        let addTaxCodeValidator;
        let editTaxCodeValidator;

        document.addEventListener('DOMContentLoaded', () => {
            const currentRole = (window.CNX_CURRENT_USER_ROLE || '').toString();
            const canUseCompaniesUi = (currentRole === 'super_admin' || currentRole === 'admin' || currentRole === 'manager');
            if (!canUseCompaniesUi) return;

            companyManager = new CompanyManager();

            // Initialize tenant roles form listeners (needed also for Admin/Manager)
            companyManager.initRoleFormListeners();

            // Super Admin only: init tools used by add/edit company modals
            if (currentRole === 'super_admin') {
                // Initialize municipality autocomplete for Add modal
                const addComuneInput = document.getElementById('addSedeLegaleComune');
                const addProvinciaInput = document.getElementById('addSedeLegaleProvincia');
                if (addComuneInput && addProvinciaInput) {
                    addMunicipalityAutocomplete = new MunicipalityAutocomplete(addComuneInput, addProvinciaInput);
                }

                // Initialize municipality autocomplete for Edit modal
                const editComuneInput = document.getElementById('editSedeLegaleComune');
                const editProvinciaInput = document.getElementById('editSedeLegaleProvincia');
                if (editComuneInput && editProvinciaInput) {
                    editMunicipalityAutocomplete = new MunicipalityAutocomplete(editComuneInput, editProvinciaInput);
                }

                // Initialize alternative tax code validators
                const addCFInput = document.getElementById('addCodiceFiscale');
                const addPIVAInput = document.getElementById('addPartitaIva');
                const addForm = document.getElementById('addCompanyForm');
                if (addCFInput && addPIVAInput && addForm) {
                    addTaxCodeValidator = new AlternativeTaxCodeValidator(addCFInput, addPIVAInput, addForm);
                }

                const editCFInput = document.getElementById('editCodiceFiscale');
                const editPIVAInput = document.getElementById('editPartitaIva');
                const editForm = document.getElementById('editCompanyForm');
                if (editCFInput && editPIVAInput && editForm) {
                    editTaxCodeValidator = new AlternativeTaxCodeValidator(editCFInput, editPIVAInput, editForm);
                }
            }
        });
    </script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>