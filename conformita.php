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

// Enforce Page Visibility access rules (configurazioni.php -> Visibilità Pagine)
require_once __DIR__ . '/includes/page_access_check.php';
checkPageAccess('conformita');

// Initialize company filter
$companyFilter = new CompanyFilter($currentUser);

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Conformità - Nexio';
    $pageCss = ['assets/css/dashboard.css'];
    require __DIR__ . '/includes/layout_head.php';
?>

    <style>
        /* Page specific styles */
        .conformity-container {
            padding: var(--space-6);
        }

        .conformity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-8);
        }

        .compliance-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: var(--space-6);
            margin-bottom: var(--space-8);
        }

        .compliance-card {
            background: var(--color-white);
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
        }

        .compliance-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--color-primary);
        }

        .compliance-card.warning::before {
            background: var(--color-warning);
        }

        .compliance-card.danger::before {
            background: var(--color-error);
        }

        .compliance-card.success::before {
            background: var(--color-success);
        }

        .compliance-title {
            font-size: var(--text-lg);
            font-weight: var(--font-semibold);
            color: var(--color-gray-900);
            margin-bottom: var(--space-4);
        }

        .compliance-status {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            margin-bottom: var(--space-3);
        }

        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--color-success);
        }

        .status-indicator.warning {
            background: var(--color-warning);
        }

        .status-indicator.danger {
            background: var(--color-error);
        }

        .compliance-details {
            color: var(--color-gray-600);
            font-size: var(--text-sm);
            margin-bottom: var(--space-4);
        }

        .compliance-progress {
            margin-top: var(--space-4);
        }

        .progress-bar {
            background: var(--color-gray-200);
            height: 8px;
            border-radius: var(--radius-sm);
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--color-primary);
            transition: width var(--transition-normal);
        }

        .documents-section {
            background: var(--color-white);
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--space-8);
        }

        .documents-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-6);
        }

        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: var(--space-4);
        }

        .document-card {
            padding: var(--space-4);
            border: 1px solid var(--color-gray-200);
            border-radius: var(--radius-md);
            text-align: center;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .document-card:hover {
            border-color: var(--color-primary);
            background: var(--color-primary-50);
        }

        .document-icon {
            font-size: 48px;
            color: var(--color-primary);
            margin-bottom: var(--space-2);
        }

        .document-name {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
            margin-bottom: var(--space-1);
        }

        .document-date {
            font-size: var(--text-xs);
            color: var(--color-gray-500);
        }

        .requirements-table {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
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
        }

        td {
            padding: var(--space-4);
            border-top: 1px solid var(--color-gray-200);
        }

        tr:hover {
            background: var(--color-gray-50);
        }

        .requirement-status {
            display: inline-block;
            padding: var(--space-1) var(--space-2);
            border-radius: var(--radius-sm);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
        }

        .status-compliant {
            background: var(--color-success-100);
            color: var(--color-success);
        }

        .status-pending {
            background: var(--color-warning-100);
            color: var(--color-warning);
        }

        .status-non-compliant {
            background: var(--color-error-100);
            color: var(--color-error);
        }

        .deadline-badge {
            display: inline-block;
            padding: var(--space-1) var(--space-2);
            border-radius: var(--radius-sm);
            font-size: var(--text-xs);
            background: var(--color-gray-100);
            color: var(--color-gray-700);
        }

        .deadline-badge.urgent {
            background: var(--color-error-100);
            color: var(--color-error);
        }
    </style>
</head>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
            <div class="header">
                <h1 class="page-title">Gestione Conformità</h1>
                <div class="flex items-center gap-4">
                    <?php if ($companyFilter->canUseCompanyFilter()): ?>
                        <?php echo $companyFilter->renderDropdown(); ?>
                    <?php endif; ?>
                    <span class="text-sm text-muted">Benvenuto, <?php echo htmlspecialchars($currentUser['name']); ?></span>
                </div>
            </div>

            <!-- Page Content -->
            <div class="page-content">
                <div class="conformity-container">
                    <!-- Header with actions -->
                    <div class="conformity-header">
                        <h2>Panoramica Conformità</h2>
                        <div>
                            <button class="btn btn--secondary">
                                <i class="icon icon--download"></i> Esporta Report
                            </button>
                            <button class="btn btn--primary">
                                <i class="icon icon--plus"></i> Nuovo Requisito
                            </button>
                        </div>
                    </div>

                    <!-- Compliance Overview Cards -->
                    <div class="compliance-overview">
                        <div class="compliance-card success">
                            <div class="compliance-title">GDPR</div>
                            <div class="compliance-status">
                                <span class="status-indicator"></span>
                                <span>Conforme</span>
                            </div>
                            <div class="compliance-details">
                                Ultimo audit: 15 giorni fa<br>
                                Prossima scadenza: 60 giorni
                            </div>
                            <div class="compliance-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: 95%"></div>
                                </div>
                                <div style="text-align: right; margin-top: var(--space-2); font-size: var(--text-xs); color: var(--color-gray-600)">95% Completo</div>
                            </div>
                        </div>

                        <div class="compliance-card warning">
                            <div class="compliance-title">ISO 27001</div>
                            <div class="compliance-status">
                                <span class="status-indicator warning"></span>
                                <span>Attenzione</span>
                            </div>
                            <div class="compliance-details">
                                3 requisiti in sospeso<br>
                                Scadenza: 30 giorni
                            </div>
                            <div class="compliance-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: 75%; background: var(--color-warning)"></div>
                                </div>
                                <div style="text-align: right; margin-top: var(--space-2); font-size: var(--text-xs); color: var(--color-gray-600)">75% Completo</div>
                            </div>
                        </div>

                        <div class="compliance-card success">
                            <div class="compliance-title">SOC 2</div>
                            <div class="compliance-status">
                                <span class="status-indicator"></span>
                                <span>Conforme</span>
                            </div>
                            <div class="compliance-details">
                                Certificazione valida<br>
                                Rinnovo: 180 giorni
                            </div>
                            <div class="compliance-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: 100%"></div>
                                </div>
                                <div style="text-align: right; margin-top: var(--space-2); font-size: var(--text-xs); color: var(--color-gray-600)">100% Completo</div>
                            </div>
                        </div>

                        <div class="compliance-card">
                            <div class="compliance-title">PCI DSS</div>
                            <div class="compliance-status">
                                <span class="status-indicator"></span>
                                <span>In revisione</span>
                            </div>
                            <div class="compliance-details">
                                Audit in corso<br>
                                Completamento previsto: 7 giorni
                            </div>
                            <div class="compliance-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: 85%"></div>
                                </div>
                                <div style="text-align: right; margin-top: var(--space-2); font-size: var(--text-xs); color: var(--color-gray-600)">85% Completo</div>
                            </div>
                        </div>
                    </div>

                    <!-- Documents Section -->
                    <div class="documents-section">
                        <div class="documents-header">
                            <h3>Documenti di Conformità</h3>
                            <button class="btn btn--secondary btn--sm">
                                <i class="icon icon--upload"></i> Carica Documento
                            </button>
                        </div>
                        <div class="documents-grid">
                            <div class="document-card">
                                <div class="document-icon">📄</div>
                                <div class="document-name">Privacy Policy</div>
                                <div class="document-date">Aggiornato: 01/10/2024</div>
                            </div>
                            <div class="document-card">
                                <div class="document-icon">📋</div>
                                <div class="document-name">Risk Assessment</div>
                                <div class="document-date">Aggiornato: 15/09/2024</div>
                            </div>
                            <div class="document-card">
                                <div class="document-icon">🔒</div>
                                <div class="document-name">Security Policy</div>
                                <div class="document-date">Aggiornato: 20/08/2024</div>
                            </div>
                            <div class="document-card">
                                <div class="document-icon">📊</div>
                                <div class="document-name">Audit Report Q3</div>
                                <div class="document-date">Aggiornato: 30/09/2024</div>
                            </div>
                        </div>
                    </div>

                    <!-- Requirements Table -->
                    <div class="requirements-table">
                        <div style="padding: var(--space-6); border-bottom: 1px solid var(--color-gray-200);">
                            <h3>Requisiti di Conformità</h3>
                        </div>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Requisito</th>
                                        <th>Standard</th>
                                        <th>Categoria</th>
                                        <th>Stato</th>
                                        <th>Responsabile</th>
                                        <th>Scadenza</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Crittografia dati sensibili</td>
                                        <td>GDPR</td>
                                        <td>Sicurezza</td>
                                        <td><span class="requirement-status status-compliant">Conforme</span></td>
                                        <td>Mario Rossi</td>
                                        <td><span class="deadline-badge">30/11/2024</span></td>
                                        <td>
                                            <button class="action-btn"><i class="icon icon--eye"></i></button>
                                            <button class="action-btn"><i class="icon icon--edit"></i></button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>Backup periodici</td>
                                        <td>ISO 27001</td>
                                        <td>Continuità</td>
                                        <td><span class="requirement-status status-pending">In sospeso</span></td>
                                        <td>Laura Bianchi</td>
                                        <td><span class="deadline-badge urgent">15/10/2024</span></td>
                                        <td>
                                            <button class="action-btn"><i class="icon icon--eye"></i></button>
                                            <button class="action-btn"><i class="icon icon--edit"></i></button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>Controllo accessi</td>
                                        <td>SOC 2</td>
                                        <td>Accesso</td>
                                        <td><span class="requirement-status status-compliant">Conforme</span></td>
                                        <td>Giuseppe Verdi</td>
                                        <td><span class="deadline-badge">31/12/2024</span></td>
                                        <td>
                                            <button class="action-btn"><i class="icon icon--eye"></i></button>
                                            <button class="action-btn"><i class="icon icon--edit"></i></button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>Formazione personale</td>
                                        <td>GDPR</td>
                                        <td>Formazione</td>
                                        <td><span class="requirement-status status-non-compliant">Non conforme</span></td>
                                        <td>Anna Romano</td>
                                        <td><span class="deadline-badge urgent">07/10/2024</span></td>
                                        <td>
                                            <button class="action-btn"><i class="icon icon--eye"></i></button>
                                            <button class="action-btn"><i class="icon icon--edit"></i></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

    <!-- Scripts -->
    <script src="assets/js/app.js"></script>
    <script>
        // Initialize company filter if present
        <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'super_admin'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const companySelector = document.getElementById('company-filter');
            if (companySelector) {
                companySelector.addEventListener('change', function() {
                    // Handle company filter change
                    console.log('Company changed:', this.value);
                });
            }
        });
        <?php endif; ?>
    </script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>