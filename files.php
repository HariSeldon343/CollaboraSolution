<?php
// Initialize session with proper configuration
require_once __DIR__ . '/includes/session_init.php';
// Authentication check - redirect to login if not authenticated
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/company_filter.php';
require_once __DIR__ . '/includes/onlyoffice_config.php';

// Force no-cache headers for files.php page (BUG-008 cache fix)
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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

// BUG-144 FIX: Removed active_tenant_id fallback (column does not exist)
// tenant_id is the only valid source

// Require active tenant access (super_admins bypass this check)
require_once __DIR__ . '/includes/tenant_access_check.php';
requireTenantAccess($currentUser['id'], $currentUser['role']);

// Enforce Page Visibility access rules (configurazioni.php -> Visibilità Pagine)
require_once __DIR__ . '/includes/page_access_check.php';
checkPageAccess('files');

// Track page access for audit logging
require_once __DIR__ . '/includes/audit_page_access.php';
trackPageAccess('files');

// Initialize company filter
$companyFilter = new CompanyFilter($currentUser);

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'File Manager - Nexio';
    $pageMeta = [
        '<meta name="onlyoffice-api-url" content="' . htmlspecialchars(defined('ONLYOFFICE_PUBLIC_API_URL') ? ONLYOFFICE_PUBLIC_API_URL : ONLYOFFICE_API_URL) . '">',
        '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate, max-age=0, post-check=0, pre-check=0">',
        '<meta http-equiv="Pragma" content="no-cache">',
        '<meta http-equiv="Expires" content="0">',
        '<meta http-equiv="Last-Modified" content="' . htmlspecialchars(gmdate('D, d M Y H:i:s') . ' GMT') . '">',
    ];
    $pageCss = [
        'assets/css/filemanager.css',
        'assets/css/filemanager_enhanced.css',
        'assets/css/documentEditor.css',
        'assets/css/pdfViewer.css',
        'assets/css/workflow.css?v=' . (time() . '_v34'),
    ];
    require __DIR__ . '/includes/layout_head.php';
?>
</head>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
    <!-- Hidden fields for JavaScript access -->
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" id="currentUserId" value="<?php echo htmlspecialchars($currentUser['id']); ?>">
    <input type="hidden" id="userRole" value="<?php echo htmlspecialchars($currentUser['role']); ?>">
    <!-- BUG-144 FIX: Changed from active_tenant_id to tenant_id (active_tenant_id does not exist) -->
    <input type="hidden" id="currentTenantId" value="<?php echo htmlspecialchars($currentUser['tenant_id'] ?? ''); ?>">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <button class="sidebar-toggle" id="sidebarToggle">☰</button>
                    <h1 class="header-title">File Manager</h1>
                    <?php if ($companyFilter->canUseCompanyFilter()): ?>
                        <?php echo $companyFilter->renderDropdown(['no_styles' => true]); ?>
                    <?php endif; ?>
                </div>
                <div class="header-right">
                    <!-- Tenant context indicator for Admin/Super Admin -->
                    <?php if (in_array($currentUser['role'], ['admin', 'super_admin'])): ?>
                    <div class="tenant-context-badge" id="tenantContextBadge" style="display: none;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;">
                            <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/>
                            <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/>
                            <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/>
                        </svg>
                        <span id="tenantContextName">-</span>
                    </div>
                    <?php endif; ?>

                    <button class="btn btn-primary" id="uploadBtn" style="display: none;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        <span>Carica</span>
                    </button>

                    <button class="btn btn-primary" id="uploadFolderBtn" style="display: none;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                            <polyline points="12 11 12 17"/>
                            <polyline points="9 14 12 17 15 14"/>
                        </svg>
                        <span>Carica Cartella</span>
                    </button>

                    <button class="btn btn-ghost" id="newFolderBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                            <line x1="12" y1="11" x2="12" y2="17"/>
                            <line x1="9" y1="14" x2="15" y2="14"/>
                        </svg>
                        <span>Nuova Cartella</span>
                    </button>

                    <?php if (in_array($currentUser['role'], ['admin', 'super_admin'])): ?>
                    <button class="btn btn-secondary" id="createRootFolderBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/>
                            <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/>
                            <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/>
                            <line x1="12" y1="11" x2="12" y2="17"/>
                            <line x1="9" y1="14" x2="15" y2="14"/>
                        </svg>
                        <span>Cartella Tenant</span>
                    </button>
                    <?php endif; ?>
                </div>
            </header>

            <!-- Page Content -->
            <div class="page-content">
                <!-- Search and Filter Bar -->
                <div class="file-search-bar">
                    <div class="search-container">
                        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.35-4.35"/>
                        </svg>
                        <input type="text" class="search-input" placeholder="Cerca file e cartelle..." id="fileSearch">
                    </div>
                    <div class="filter-controls">
                        <button class="filter-btn" id="filterBtn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
                            </svg>
                            <span>Filtra</span>
                        </button>
                        <button class="sort-btn" id="sortBtn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 6h18M7 12h10M11 18h2"/>
                            </svg>
                            <span>Ordina</span>
                        </button>
                    </div>
                </div>

                <!-- Breadcrumb Navigation -->
                <div class="breadcrumb-nav">
                    <div class="breadcrumb-items">
                        <a href="#" class="breadcrumb-item" data-path="/">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                            </svg>
                            <span>I Miei File</span>
                        </a>
                        <svg class="breadcrumb-separator" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="9 18 15 12 9 6"/>
                        </svg>
                        <span class="breadcrumb-current">Documenti</span>
                    </div>
                    <div class="view-toggle">
                        <button class="view-btn active" data-view="grid" title="Grid View">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="7" height="7"/>
                                <rect x="14" y="3" width="7" height="7"/>
                                <rect x="14" y="14" width="7" height="7"/>
                                <rect x="3" y="14" width="7" height="7"/>
                            </svg>
                        </button>
                        <button class="view-btn" data-view="list" title="List View">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="8" y1="6" x2="21" y2="6"/>
                                <line x1="8" y1="12" x2="21" y2="12"/>
                                <line x1="8" y1="18" x2="21" y2="18"/>
                                <line x1="3" y1="6" x2="3.01" y2="6"/>
                                <line x1="3" y1="12" x2="3.01" y2="12"/>
                                <line x1="3" y1="18" x2="3.01" y2="18"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Main File Area -->
                <div class="file-main-container">
                    <!-- Files Container -->
                    <div class="files-wrapper" id="filesWrapper">
                        <!-- Drop Zone Overlay -->
                        <div class="drop-zone-overlay" id="dropZone">
                            <div class="drop-zone-content">
                                <svg class="drop-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                    <polyline points="17 8 12 3 7 8"/>
                                    <line x1="12" y1="3" x2="12" y2="15"/>
                                </svg>
                                <h3>Trascina qui i file per caricarli</h3>
                                <p>o clicca per sfogliare</p>
                            </div>
                        </div>

                        <!-- Grid View -->
                        <div class="files-grid view-active" id="filesGrid">
                            <!-- Files will be loaded dynamically from database -->
                        </div>

                        <!-- List View -->
                        <div class="files-list" id="filesList">
                            <table class="file-table">
                                <thead>
                                    <tr>
                                        <th class="checkbox-col">
                                            <input type="checkbox" id="selectAll">
                                        </th>
                                        <th class="name-col">Nome</th>
                                        <th>Proprietario</th>
                                        <th>Assegnato a</th>
                                        <th>Modificato</th>
                                        <th>Dimensione</th>
                                        <th class="actions-col"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Files will be loaded dynamically from database -->
                                </tbody>
                            </table>
                        </div>

                        <!-- Empty State -->
                        <div class="empty-state hidden" id="emptyState">
                            <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M9 13h6m-3-3v6m-9 1V7a2 2 0 0 1 2-2h6l2 2h6a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                            </svg>
                            <h3>Nessun file trovato</h3>
                            <p>Carica il tuo primo file o crea una cartella per iniziare</p>
                            <div class="empty-actions">
                                <button class="btn btn-primary" onclick="fileManager.showUploadDialog()">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                        <polyline points="17 8 12 3 7 8"/>
                                        <line x1="12" y1="3" x2="12" y2="15"/>
                                    </svg>
                                    Carica File
                                </button>
                                <button class="btn btn-secondary" onclick="fileManager.createNewFolder()">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                                        <line x1="12" y1="11" x2="12" y2="17"/>
                                        <line x1="9" y1="14" x2="15" y2="14"/>
                                    </svg>
                                    Nuova Cartella
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- File Details Sidebar -->
                    <div class="file-details-sidebar" id="fileDetailsSidebar">
                        <div class="details-header">
                            <h3>Dettagli File</h3>
                            <button class="close-details" id="closeDetails">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                        </div>
                        <div class="details-content">
                            <div class="details-preview">
                                <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 150'%3E%3Crect fill='%23F3F4F6' width='200' height='150'/%3E%3C/svg%3E" alt="Preview">
                            </div>
                            <div class="details-info">
                                <h4 class="details-filename">Seleziona un file</h4>
                                <div class="details-meta">
                                    <div class="meta-item">
                                        <span class="meta-label">Tipo</span>
                                        <span class="meta-value">—</span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Dimensione</span>
                                        <span class="meta-value">—</span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Modificato</span>
                                        <span class="meta-value">—</span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Proprietario</span>
                                        <span class="meta-value">—</span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Creato</span>
                                        <span class="meta-value">—</span>
                                    </div>
                                </div>
                                <div class="details-actions">
                                    <button class="btn btn-primary btn-block">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                            <polyline points="7 10 12 15 17 10"/>
                                            <line x1="12" y1="15" x2="12" y2="3"/>
                                        </svg>
                                        Scarica
                                    </button>
                                    <button class="btn btn-secondary btn-block" style="display:none;">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/>
                                            <polyline points="16 6 12 2 8 6"/>
                                            <line x1="12" y1="2" x2="12" y2="15"/>
                                        </svg>
                                        <!-- Condivisione disabilitata -->
                                    </button>
                                </div>

                                <!-- Workflow Section (ONLY for files with workflow) -->
                                <div class="workflow-details-section" id="workflowDetailsSection" style="display: none;">
                                    <div class="section-divider"></div>
                                    <h5 class="section-title">Workflow Documento</h5>

                                    <div class="workflow-current-state">
                                        <div class="meta-item">
                                            <span class="meta-label">Stato Attuale</span>
                                            <div id="sidebarWorkflowBadge" class="workflow-badge-container">—</div>
                                        </div>
                                    </div>

                                    <div class="workflow-people" id="workflowPeople" style="display:none;">
                                        <div class="meta-item">
                                            <span class="meta-label">Validatore</span>
                                            <span id="workflowValidator" class="meta-value">—</span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Approvatore</span>
                                            <span id="workflowApprover" class="meta-value">—</span>
                                        </div>
                                    </div>

                                    <div class="workflow-sidebar-actions" id="workflowSidebarActions">
                                        <!-- Pulsanti azioni workflow renderizzati dinamicamente -->
                                    </div>

                                    <div class="workflow-history-link">
                                        <button class="btn btn-link btn-sm" onclick="window.workflowManager?.showHistoryModal(currentFileId, currentFileName)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;">
                                                <circle cx="12" cy="12" r="10"/>
                                                <polyline points="12 6 12 12 16 14"/>
                                            </svg>
                                            Visualizza Cronologia Workflow
                                        </button>
                                    </div>

                                    <!-- Approval Stamp Section (ONLY for approved documents) -->
                                    <div class="approval-stamp-section" id="approvalStampSection" style="display: none;">
                                        <h5 class="section-title">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 18px; height: 18px; vertical-align: middle; margin-right: 6px;">
                                                <path d="M9 11l3 3L22 4"/>
                                                <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
                                            </svg>
                                            Timbro Approvazione
                                        </h5>
                                        <div class="approval-stamp-card">
                                            <div class="stamp-header">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 24px; height: 24px;">
                                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                                    <polyline points="22 4 12 14.01 9 11.01"/>
                                                </svg>
                                                <span>DOCUMENTO APPROVATO</span>
                                            </div>
                                            <div class="stamp-metadata">
                                                <div class="stamp-row">
                                                    <strong>Approvato da:</strong>
                                                    <span id="approverName">—</span>
                                                </div>
                                                <div class="stamp-row">
                                                    <strong>Data approvazione:</strong>
                                                    <span id="approvalDate">—</span>
                                                </div>
                                                <div class="stamp-row" id="approvalCommentRow" style="display:none;">
                                                    <strong>Note:</strong>
                                                    <span id="approvalComment">—</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Upload Progress Toast -->
                <div class="upload-toast hidden" id="uploadToast">
                    <div class="upload-toast-header">
                        <span>Caricamento di 3 file...</span>
                        <button class="close-btn" id="closeUpload">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                    <div class="upload-toast-items" id="uploadItems">
                        <!-- Upload items will be added dynamically -->
                    </div>
                </div>

                <!-- Context Menu -->
                <div class="context-menu" id="contextMenu">
                    <button class="context-item" data-action="rename">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                        <span>Rinomina</span>
                    </button>
                    <button class="context-item" data-action="download">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        <span>Scarica</span>
                    </button>

                    <!-- OnlyOffice: open document in a new browser tab (so user can split-screen) -->
                    <button class="context-item context-file-only" data-action="open-new-tab">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 3h7v7"/>
                            <path d="M10 14L21 3"/>
                            <path d="M21 14v7H3V3h7"/>
                        </svg>
                        <span>Apri in nuova scheda</span>
                    </button>

                    <!-- AI Hub: ask AI about this file (uses tenant knowledge / file context) -->
                    <button class="context-item context-file-only" data-action="ask-ai">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 2a7 7 0 0 0-4 12.74V22l4-2 4 2v-7.26A7 7 0 0 0 12 2z"/>
                            <path d="M9 9h.01M12 9h.01M15 9h.01"/>
                        </svg>
                        <span>Chiedi all'AI</span>
                    </button>

                    <!-- Compliance: restore a visible snapshot version (manager/super_admin only, shown via JS when applicable) -->
                    <button class="context-item context-file-only" data-action="restore-compliance-version" style="display:none;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 12a9 9 0 1 0 3-6.7"/>
                            <path d="M3 3v6h6"/>
                        </svg>
                        <span>Ripristina versione</span>
                    </button>

                    <!-- Workflow Actions (Manager/Admin only) -->
                    <?php if (in_array($currentUser['role'], ['manager', 'admin', 'super_admin'])): ?>
                    <div class="context-separator"></div>
                    <div class="context-section-title">WORKFLOW</div>
                    <button class="context-item" data-action="assign">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                            <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                        <span>Assegna a Utente</span>
                    </button>
                    <button class="context-item" data-action="workflow-roles">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            <polyline points="16 11 12 15 8 11"></polyline>
                        </svg>
                        <span>Gestisci Ruoli Workflow</span>
                    </button>
                    <button class="context-item context-folder-only" data-action="workflow-settings" style="display:none;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z"/>
                            <path d="M12 22V2"/>
                            <path d="M8 10l4 4 4-4"/>
                        </svg>
                        <span>Impostazioni Workflow Cartella</span>
                    </button>
                    <button class="context-item context-file-only" data-action="workflow-status">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                        </svg>
                        <span>Stato Workflow</span>
                    </button>
                    <?php endif; ?>

                    <div class="context-separator"></div>
                    <button class="context-item danger" data-action="delete">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        </svg>
                        <span>Elimina</span>
                    </button>
                </div>

                <!-- File Assignment Modal -->
                <div class="workflow-modal" id="assignmentModal" style="display: none;">
                    <div class="workflow-modal-content">
                        <div class="workflow-modal-header">
                            <h3>Assegna File/Cartella</h3>
                            <button class="workflow-modal-close" onclick="window.fileAssignmentManager?.closeAssignmentModal()">&times;</button>
                        </div>
                        <div class="workflow-modal-body">
                            <div class="form-group">
                                <label>Elemento da Assegnare</label>
                                <div id="assignmentItemName" class="readonly-field"></div>
                            </div>
                            <div class="form-group">
                                <label for="assignmentTargetType">Tipo Assegnazione *</label>
                                <select id="assignmentTargetType" class="form-control">
                                    <option value="user" selected>Utente</option>
                                    <option value="tenant_role">Ruolo Aziendale (gruppo)</option>
                                </select>
                                <small class="form-text">Puoi assegnare a un singolo utente oppure a un gruppo (ruolo aziendale) in modo dinamico.</small>
                            </div>
                            <div class="form-group">
                                <label for="assignToUser">Assegna a Utente *</label>
                                <select id="assignToUser" class="form-control" required>
                                    <option value="">-- Seleziona utente --</option>
                                </select>
                            </div>
                            <div class="form-group" id="assignToTenantRoleGroup" style="display:none;">
                                <label for="assignToTenantRole">Assegna a Ruolo Aziendale *</label>
                                <select id="assignToTenantRole" class="form-control">
                                    <option value="">-- Seleziona ruolo aziendale --</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="assignmentReason">Motivo Assegnazione</label>
                                <textarea id="assignmentReason" class="form-control" rows="3" placeholder="Descrivi il motivo dell'assegnazione..."></textarea>
                            </div>
                            <div class="form-group">
                                <label for="assignmentExpires">Data Scadenza (opzionale)</label>
                                <input type="datetime-local" id="assignmentExpires" class="form-control">
                                <small class="form-text">Lascia vuoto per assegnazione permanente. Invieremo un avviso 7 giorni prima della scadenza.</small>
                            </div>
                        </div>
                        <div class="workflow-modal-footer">
                            <button class="btn btn-secondary" onclick="window.fileAssignmentManager?.closeAssignmentModal()">Annulla</button>
                            <button class="btn btn-primary" onclick="window.fileAssignmentManager?.createAssignment()">Assegna</button>
                        </div>
                    </div>
                </div>

                <!-- Workflow Status Modal -->
                <div class="workflow-modal workflow-modal-large" id="workflowStatusModal" style="display: none;">
                    <div class="workflow-modal-content">
                        <div class="workflow-modal-header">
                            <h3>Stato Workflow Documento</h3>
                            <button class="workflow-modal-close" onclick="window.workflowManager?.closeStatusModal()">&times;</button>
                        </div>
                        <div class="workflow-modal-body">
                            <div id="workflowStatusContent">
                                <!-- Content will be loaded dynamically -->
                                <div class="loading-spinner">
                                    <div class="spinner"></div>
                                    <p>Caricamento...</p>
                                </div>
                            </div>
                        </div>
                        <div class="workflow-modal-footer">
                            <button class="btn btn-secondary" onclick="window.workflowManager?.closeStatusModal()">Chiudi</button>
                            <div id="workflowActions"></div>
                        </div>
                    </div>
                </div>

                <!-- Workflow Submit Modal -->
                <div class="workflow-modal" id="workflowSubmitModal" style="display: none;">
                    <div class="workflow-modal-content">
                        <div class="workflow-modal-header">
                            <h3>Invia Documento per Validazione</h3>
                            <button class="workflow-modal-close" onclick="window.workflowManager?.closeSubmitModal()">&times;</button>
                        </div>
                        <div class="workflow-modal-body">
                            <p>Confermi di voler inviare questo documento per la validazione?</p>
                            <p class="text-muted">Il documento sarà inviato ai validatori configurati per questo tenant.</p>
                        </div>
                        <div class="workflow-modal-footer">
                            <button class="btn btn-secondary" onclick="window.workflowManager?.closeSubmitModal()">Annulla</button>
                            <button class="btn btn-primary" onclick="window.workflowManager?.confirmSubmit()">Invia per Validazione</button>
                        </div>
                    </div>
                </div>

                <!-- Workflow Reject Modal -->
                <div class="workflow-modal" id="workflowRejectModal" style="display: none;">
                    <div class="workflow-modal-content">
                        <div class="workflow-modal-header">
                            <h3>Rifiuta Documento</h3>
                            <button class="workflow-modal-close" onclick="window.workflowManager?.closeRejectModal()">&times;</button>
                        </div>
                        <div class="workflow-modal-body">
                            <div class="form-group">
                                <label for="rejectReason">Motivo Rifiuto *</label>
                                <textarea id="rejectReason" class="form-control" rows="4" placeholder="Descrivi il motivo del rifiuto e cosa deve essere corretto..." required></textarea>
                            </div>
                        </div>
                        <div class="workflow-modal-footer">
                            <button class="btn btn-secondary" onclick="window.workflowManager?.closeRejectModal()">Annulla</button>
                            <button class="btn btn-danger" onclick="window.workflowManager?.confirmReject()">Rifiuta Documento</button>
                        </div>
                    </div>
                </div>

                <!-- Workflow History Modal -->
                <div class="workflow-modal workflow-modal-large" id="workflowHistoryModal" style="display: none;">
                    <div class="workflow-modal-content">
                        <div class="workflow-modal-header">
                            <h3 class="modal-title">Storico Workflow</h3>
                            <button class="workflow-modal-close" onclick="window.workflowManager?.closeHistoryModal()">&times;</button>
                        </div>
                        <div class="workflow-modal-body">
                            <div id="workflowTimeline">
                                <!-- Content will be loaded dynamically -->
                            </div>
                        </div>
                        <div class="workflow-modal-footer">
                            <button class="btn btn-secondary" onclick="window.workflowManager?.closeHistoryModal()">Chiudi</button>
                        </div>
                    </div>
                </div>

                <!-- Workflow Role Configuration Modal -->
                <div class="workflow-modal workflow-modal-large" id="workflowRoleConfigModal" style="display: none;">
                    <div class="workflow-modal-content">
                        <div class="workflow-modal-header">
                            <h3>Configurazione Ruoli Workflow</h3>
                            <button class="workflow-modal-close" onclick="window.workflowManager?.closeRoleConfigModal()">&times;</button>
                        </div>
                        <div class="workflow-modal-body">
                            <div class="row" style="display: flex; gap: 20px;">
                                <div style="flex: 1;">
                                    <h4 style="margin-top: 0;">Validatori</h4>
                                    <div class="form-group">
                                        <label>Seleziona utenti che possono validare documenti:</label>
                                        <select id="validatorUsers" class="form-control" multiple size="8" style="width: 100%; min-height: 200px;">
                                            <!-- Users will be loaded here -->
                                        </select>
                                        <small class="form-text text-muted">
                                            Tieni premuto Ctrl per selezione multipla
                                        </small>
                                    </div>
                                    <button class="btn btn-primary btn-sm" onclick="window.workflowManager?.saveValidators()">
                                        Salva Validatori
                                    </button>
                                </div>

                                <div style="flex: 1;">
                                    <h4 style="margin-top: 0;">Approvatori</h4>
                                    <div class="form-group">
                                        <label>Seleziona utenti che possono approvare documenti:</label>
                                        <select id="approverUsers" class="form-control" multiple size="8" style="width: 100%; min-height: 200px;">
                                            <!-- Users will be loaded here -->
                                        </select>
                                        <small class="form-text text-muted">
                                            Tieni premuto Ctrl per selezione multipla
                                        </small>
                                    </div>
                                    <button class="btn btn-primary btn-sm" onclick="window.workflowManager?.saveApprovers()">
                                        Salva Approvatori
                                    </button>
                                </div>
                            </div>

                            <hr style="margin: 24px 0;">

                            <h4>Ruoli Attuali</h4>
                            <div class="row" style="display: flex; gap: 20px;">
                                <div style="flex: 1;">
                                    <h5>Validatori:</h5>
                                    <ul id="currentValidators" class="list-group" style="list-style: none; padding: 0;">
                                        <!-- Current validators will be listed here -->
                                    </ul>
                                </div>
                                <div style="flex: 1;">
                                    <h5>Approvatori:</h5>
                                    <ul id="currentApprovers" class="list-group" style="list-style: none; padding: 0;">
                                        <!-- Current approvers will be listed here -->
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="workflow-modal-footer">
                            <button class="btn btn-secondary" onclick="window.workflowManager?.closeRoleConfigModal()">Chiudi</button>
                        </div>
                    </div>
                </div>
            </div>
        

    <!-- Create Tenant Folder Modal -->
    <?php if (in_array($currentUser['role'], ['admin', 'super_admin'])): ?>
    <div class="modal-overlay" id="createTenantFolderModal" style="display: none;">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2>Crea Cartella Tenant Root</h2>
                <button class="modal-close" onclick="closeCreateTenantFolderModal()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="tenantSelect">Seleziona Tenant</label>
                    <select id="tenantSelect" class="form-control">
                        <option value="">-- Seleziona un tenant --</option>
                    </select>
                    <small class="form-text text-muted">Seleziona il tenant per cui creare la cartella root</small>
                </div>
                <div class="form-group">
                    <label for="folderName">Nome Cartella</label>
                    <input type="text" id="folderName" class="form-control" placeholder="Es: Documenti Aziendali">
                    <small class="form-text text-muted">Il nome della cartella root per questo tenant</small>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeCreateTenantFolderModal()">Annulla</button>
                <button class="btn btn-primary" onclick="createTenantFolder()">Crea Cartella</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <style>
        /* Tenant context badge */
        .tenant-context-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            margin-right: 16px;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }

        /* Modal styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow: auto;
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 24px;
            border-bottom: 1px solid #E5E7EB;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            color: #1F2937;
        }

        .modal-close {
            background: none;
            border: none;
            padding: 4px;
            cursor: pointer;
            color: #6B7280;
            transition: color 0.2s;
        }

        .modal-close:hover {
            color: #1F2937;
        }

        .modal-close svg {
            width: 20px;
            height: 20px;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #E5E7EB;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
        }

        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #D1D5DB;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: #6366F1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .form-text {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            color: #6B7280;
        }

        /* Tenant name in folder cards */
        .tenant-label {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            background: #F3F4F6;
            color: #6B7280;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            margin-top: 4px;
        }

        .tenant-label svg {
            width: 12px;
            height: 12px;
        }

        /* Adjust button visibility */
        #createRootFolderBtn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }

        #createRootFolderBtn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        /* Hide new folder button in root */
        .at-root #newFolderBtn {
            display: none !important;
        }
    </style>

    <script>
        // Modal functions
        function closeCreateTenantFolderModal() {
            document.getElementById('createTenantFolderModal').style.display = 'none';
        }

        async function createTenantFolder() {
            const tenantId = document.getElementById('tenantSelect').value;
            const folderName = document.getElementById('folderName').value.trim();

            if (!tenantId) {
                window.fileManager.showToast('Seleziona un tenant', 'error');
                return;
            }

            if (!folderName) {
                window.fileManager.showToast('Inserisci il nome della cartella', 'error');
                return;
            }

            try {
                const result = await window.fileManager.createRootFolder(folderName, tenantId);
                if (result.success) {
                    closeCreateTenantFolderModal();
                    document.getElementById('tenantSelect').value = '';
                    document.getElementById('folderName').value = '';
                    window.fileManager.loadFiles();
                }
            } catch (error) {
                console.error('Error creating tenant folder:', error);
            }
        }

        // Pass user role to JavaScript
        window.userRole = '<?php echo $currentUser['role']; ?>';
    </script>

    <!-- Core Application JavaScript -->
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
    <!-- PDF Viewer JavaScript -->
    <script src="assets/js/pdfViewer.js?v=<?php echo time(); ?>"></script>
    <!-- Enhanced File Manager JavaScript with Upload & Document Creation (BUG-086 NUCLEAR - Inline Styles) -->
    <script src="assets/js/filemanager_enhanced.js?v=<?php echo time() . '_v35'; ?>"></script>
    <!-- Document Editor JavaScript -->
    <script src="assets/js/documentEditor.js?v=<?php echo time(); ?>"></script>
    <!-- File Assignment System (BUG-086 NUCLEAR - Inline Styles) -->
    <script src="assets/js/file_assignment.js?v=<?php echo time() . '_v35'; ?>"></script>
    <!-- Document Workflow Management System (BUG-087 FIX - Multi-Tenant Context) -->
    <script src="assets/js/document_workflow_v2.js?v=<?php echo filemtime(__DIR__ . '/assets/js/document_workflow_v2.js'); ?>"></script>

    <!-- BUG-061 CRITICAL FIX: Force close modal IMMEDIATELY (before any other script) -->
    <script>
    (function() {
        console.log('[EMERGENCY] Forcing all modals closed IMMEDIATELY');
        const modals = document.querySelectorAll('.workflow-modal');
        modals.forEach(m => {
            m.style.display = 'none';
            m.style.setProperty('display', 'none', 'important');
        });
    })();
    </script>

    <!-- Workflow System Initialization -->
    <script>
    // Extend workflow managers with files.php specific integrations
    document.addEventListener('DOMContentLoaded', function() {
        // BUG-061 FIX: Force close all modals on page load to prevent auto-open
        const workflowRoleConfigModal = document.getElementById('workflowRoleConfigModal');
        if (workflowRoleConfigModal) {
            workflowRoleConfigModal.style.display = 'none';
            workflowRoleConfigModal.style.setProperty('display', 'none', 'important');
            console.log('[FilesPage] Forced workflowRoleConfigModal to closed state on page load');
        }

        // Wait for workflow managers to be initialized by their respective JS files
        const initWorkflowIntegration = setInterval(() => {
            if (window.fileAssignmentManager && window.workflowManager && window.fileManager) {
                clearInterval(initWorkflowIntegration);

                // Extend context menu handler for workflow actions
                const contextMenu = document.getElementById('contextMenu');
                if (contextMenu) {
                    contextMenu.addEventListener('click', async function(e) {
                        const item = e.target.closest('.context-item');
                        if (!item) return;

                        const action = item.dataset.action;
                        const fileId = contextMenu.dataset.fileId;
                        const folderId = contextMenu.dataset.folderId;
                        const fileName = contextMenu.dataset.fileName;
                        const isFolder = contextMenu.dataset.isFolder === 'true';

                        // Close context menu
                        contextMenu.style.display = 'none';

                        // Handle workflow actions
                        switch(action) {
                            case 'assign':
                                if (window.fileAssignmentManager) {
                                    if (isFolder) {
                                        await window.fileAssignmentManager.showAssignmentModal(null, folderId, fileName);
                                    } else {
                                        await window.fileAssignmentManager.showAssignmentModal(fileId, null, fileName);
                                    }
                                }
                                break;

                            case 'workflow-roles':
                                if (window.workflowManager) {
                                    await window.workflowManager.showRoleConfigModal();
                                }
                                break;

                            case 'workflow-settings':
                                if (window.workflowManager && isFolder) {
                                    const folderName = fileName || 'Cartella';
                                    await window.workflowManager.showWorkflowSettingsModal(folderId, folderName);
                                }
                                break;

                            case 'workflow-status':
                                if (window.workflowManager && !isFolder) {
                                    await window.workflowManager.showStatusModal(fileId);
                                }
                                break;
                        }
                    });
                }

                // BUG-075 FIX: Override ACTUAL methods renderGridItem + renderListItem (NOT renderFileCard which doesn't exist)

                // Override renderGridItem for grid view badges
                if (window.fileManager && window.fileManager.renderGridItem) {
                    const originalRenderGridItem = window.fileManager.renderGridItem.bind(window.fileManager);

                    window.fileManager.renderGridItem = function(item) {
                        // Call original method to create and append the card
                        originalRenderGridItem(item);

                        // BUG-075 FIX: Use lastElementChild instead of querySelector (more reliable)
                        const filesGrid = document.getElementById('filesGrid');
                        if (!filesGrid) return;

                        const card = filesGrid.lastElementChild;
                        if (!card) return;

                        // Add workflow badge if file has workflow_state
                        if (item.workflow_state && window.workflowManager) {
                            const badge = window.workflowManager.renderWorkflowBadge(item.workflow_state);
                            const cardInfo = card.querySelector('.file-card-info');
                            if (cardInfo && !cardInfo.querySelector('.workflow-badge')) {
                                cardInfo.insertAdjacentHTML('beforeend', badge);
                            }
                        }

                        // Add assignment badge if file is assigned
                        if (item.is_assigned && window.fileAssignmentManager) {
                            const assignmentBadge = `<div class="assignment-badge" title="Assegnato">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                                    <circle cx="9" cy="7" r="4"/>
                                </svg>
                            </div>`;
                            const cardInfo = card.querySelector('.file-card-info');
                            if (cardInfo && !cardInfo.querySelector('.assignment-badge')) {
                                cardInfo.insertAdjacentHTML('beforeend', assignmentBadge);
                            }
                        }
                    };
                }

                // Override renderListItem for list view badges
                if (window.fileManager && window.fileManager.renderListItem) {
                    const originalRenderListItem = window.fileManager.renderListItem.bind(window.fileManager);

                    window.fileManager.renderListItem = function(file) {
                        // Call original method to create and append the row
                        originalRenderListItem(file);

                        // BUG-075 FIX: Use lastElementChild instead of querySelector (more reliable)
                        const filesList = document.getElementById('filesList');
                        if (!filesList) return;

                        const tbody = filesList.querySelector('tbody');
                        if (!tbody) return;

                        const row = tbody.lastElementChild;
                        if (!row) return;

                        // Add workflow badge to name cell
                        if (file.workflow_state && window.workflowManager) {
                            const badge = window.workflowManager.renderWorkflowBadge(file.workflow_state);
                            const nameWrapper = row.querySelector('.file-name-wrapper');
                            if (nameWrapper && !nameWrapper.querySelector('.workflow-badge')) {
                                nameWrapper.insertAdjacentHTML('beforeend', badge);
                            }
                        }

                        // Add assignment badge to name cell
                        if (file.is_assigned && window.fileAssignmentManager) {
                            const assignmentBadge = `<span class="assignment-badge-inline" title="Assegnato" style="margin-left: 6px;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px; vertical-align: middle;">
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                                    <circle cx="9" cy="7" r="4"/>
                                </svg>
                            </span>`;
                            const nameWrapper = row.querySelector('.file-name-wrapper');
                            if (nameWrapper && !nameWrapper.querySelector('.assignment-badge-inline')) {
                                nameWrapper.insertAdjacentHTML('beforeend', assignmentBadge);
                            }
                        }
                    };
                }

                // Extend file load to include workflow data
                if (window.fileManager.loadFiles) {
                    const originalLoadFiles = window.fileManager.loadFiles.bind(window.fileManager);

                    window.fileManager.loadFiles = async function(folderId = null) {
                        await originalLoadFiles(folderId);

                        // After files loaded, fetch workflow states for documents
                        if (window.workflowManager && typeof window.workflowManager.getWorkflowStatus === 'function') {
                            const fileCards = document.querySelectorAll('[data-file-id]');
                            for (const card of fileCards) {
                                const fileId = parseInt(card.dataset.fileId);
                                if (fileId && card.dataset.isFolder !== 'true') {
                                    // Fetch workflow status asynchronously
                                    window.workflowManager.getWorkflowStatus(fileId).then(status => {
                                        if (status && status.state) {
                                            if (typeof window.workflowManager.renderWorkflowBadge === 'function') {
                                                const badge = window.workflowManager.renderWorkflowBadge(status.state);
                                                const cardBody = card.querySelector('.file-card-body');
                                                if (cardBody && !cardBody.querySelector('.workflow-badge')) {
                                                    cardBody.insertAdjacentHTML('beforeend', badge);
                                                }
                                            }
                                        }
                                    }).catch(err => {
                                        console.warn(`[Workflow] Could not load status for file ${fileId}:`, err.message || err);
                                    });
                                }
                            }
                        } else {
                            console.warn('[Workflow] WorkflowManager not fully initialized - skipping workflow badges');
                        }
                    };
                }

                console.log('[Workflow] files.php integration complete');
            }
        }, 100);
    });
    </script>

    <style>
    /* Workflow System Styles */
    .workflow-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: none; /* hidden by default, toggle via JS */
        align-items: center;
        justify-content: center;
        z-index: 10000;
        backdrop-filter: blur(4px);
    }

    .workflow-modal.active { display: flex; }

    .workflow-modal-content {
        background: white;
        border-radius: 12px;
        width: 90%;
        max-width: 600px;
        max-height: 90vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        animation: modalSlideIn 0.3s ease-out;
    }

    .workflow-modal-large .workflow-modal-content {
        max-width: 900px;
    }

    .workflow-modal-header {
        padding: 20px 24px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .workflow-modal-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
    }

    .workflow-modal-close {
        background: none;
        border: none;
        font-size: 28px;
        color: #6b7280;
        cursor: pointer;
        line-height: 1;
        padding: 0;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
        transition: all 0.2s;
    }

    .workflow-modal-close:hover {
        background: #f3f4f6;
        color: #1f2937;
    }

    .workflow-modal-body {
        padding: 24px;
        overflow-y: auto;
        flex: 1;
    }

    .workflow-modal-footer {
        padding: 16px 24px;
        border-top: 1px solid #e5e7eb;
        display: flex;
        justify-content: flex-end;
        gap: 12px;
    }

    .readonly-field {
        padding: 10px 12px;
        background: #f3f4f6;
        border-radius: 6px;
        color: #374151;
        font-weight: 500;
    }

    .context-section-title {
        padding: 8px 16px 4px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #6b7280;
    }

    .assignment-badge {
        position: absolute;
        top: 8px;
        right: 8px;
        background: #6366f1;
        color: white;
        padding: 4px;
        border-radius: 4px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .loading-spinner {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 40px;
    }

    .spinner {
        width: 40px;
        height: 40px;
        border: 3px solid #f3f4f6;
        border-top-color: #6366f1;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    .text-muted {
        color: #6b7280;
        font-size: 14px;
    }
    </style>

    <!-- BUG-061 CRITICAL FIX: Force close modal after everything loads -->
    <script>
    (function() {
        // Execute after a short delay to let everything initialize
        setTimeout(function() {
            const modal = document.getElementById('workflowRoleConfigModal');
            if (modal) {
                modal.style.display = 'none';
                modal.style.setProperty('display', 'none', 'important');
                console.log('[BUG-061] Emergency: workflowRoleConfigModal forced closed');
            }

            // Close ANY other workflow modal that might be open
            document.querySelectorAll('.workflow-modal').forEach(function(m) {
                if (m.style.display === 'flex' || m.style.display === 'block') {
                    m.style.display = 'none';
                    console.log('[BUG-061] Emergency: Closed auto-opened modal:', m.id);
                }
            });
        }, 100); // 100ms delay to let DOM settle
    })();
    </script>

    <!-- BUG-076 FIX: WORKFLOW BADGE INJECTION - POST-RENDER APPROACH -->
    <!-- This injects badges AFTER file cards are rendered, bypassing the override timing issue -->
    <script>
    (function() {
        console.log('[WorkflowBadge] Initializing post-render badge injection system...');

        // State color mapping (matches WorkflowManager.workflowStates)
        const stateColors = {
            'bozza': '#3498db',
            'in_validazione': '#f39c12',
            'validato': '#27ae60',
            'in_approvazione': '#e67e22',
            'approvato': '#27ae60',
            'rifiutato': '#e74c3c'
        };

        /**
         * Inject workflow badges into ALL rendered file cards
         * Uses POST-RENDER approach to avoid timing issues with renderGridItem/renderListItem
         */
        function injectWorkflowBadges() {
            console.log('[WorkflowBadge] Scanning DOM for file cards...');

            // Find all file cards (both grid and list view)
            const fileCards = document.querySelectorAll('[data-file-id]');
            console.log('[WorkflowBadge] Found ' + fileCards.length + ' file cards to process');

            if (fileCards.length === 0) {
                console.log('[WorkflowBadge] No file cards found, will retry on next loadFiles call');
                return;
            }

            let badgesAdded = 0;
            let badgesSkippedExisting = 0;
            let foldersIgnored = 0;
            let apiCallsFailed = 0;

            fileCards.forEach(function(card) {
                const fileId = card.dataset.fileId;
                if (!fileId) {
                    console.debug('[WorkflowBadge] Card missing fileId, skipping');
                    return;
                }

                const typeAttr = (card.dataset.type || '').toLowerCase();
                const isFolder = typeAttr === 'folder' || card.classList.contains('folder') || card.dataset.isFolder === 'true';
                if (isFolder) {
                    foldersIgnored++;
                    console.debug('[WorkflowBadge] Skipping folder #' + fileId + ' (workflow non applicabile)');
                    return;
                }

                // Skip if badge already exists (prevent duplicates)
                if (card.querySelector('.workflow-badge-injected')) {
                    badgesSkippedExisting++;
                    return;
                }

                // Get CSRF token for API call
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

                // Call workflow status API
                fetch('/CollaboraNexio/api/documents/workflow/status.php?file_id=' + fileId, {
                    method: 'GET',
                    headers: {
                        'X-CSRF-Token': csrfToken
                    },
                    credentials: 'same-origin'
                })
                .then(function(response) {
                    if (response.status === 404) {
                        console.debug('[WorkflowBadge] Workflow not available for file #' + fileId + ' (404)');
                        return null;
                    }
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (!data) {
                        return;
                    }

                    if (data.success && data.data) {
                        const workflow = data.data.workflow || null;
                        const state = workflow ? workflow.state : null;

                        if (!state) {
                            console.debug('[WorkflowBadge] File #' + fileId + ' has no workflow state');
                            return;
                        }

                        const color = stateColors[state] || '#95a5a6';
                        const label = workflow.state_label || state.toUpperCase();

                        // Create badge element with inline styles (no CSS dependency)
                        const badge = document.createElement('span');
                        badge.className = 'workflow-badge-injected';
                        badge.style.cssText = [
                            'display: inline-block',
                            'padding: 4px 10px',
                            'background: ' + color,
                            'color: white',
                            'border-radius: 4px',
                            'font-size: 11px',
                            'font-weight: 600',
                            'margin-left: 8px',
                            'white-space: nowrap',
                            'vertical-align: middle'
                        ].join('; ');
                        badge.textContent = label;

                        // Find insertion point (try multiple selectors for grid/list views)
                        const nameElement = card.querySelector('.file-name, .file-card-info h4, .file-name-wrapper, td.name-col .file-name-wrapper');

                        if (nameElement && !nameElement.querySelector('.workflow-badge-injected')) {
                            nameElement.appendChild(badge);
                            badgesAdded++;
                            console.log('[WorkflowBadge] ✅ Added badge to file #' + fileId + ': ' + state);
                        } else {
                            console.warn('[WorkflowBadge] ⚠️ Could not find insertion point for file #' + fileId);
                        }
                    }
                })
                .catch(function(error) {
                    apiCallsFailed++;
                    console.debug('[WorkflowBadge] API error for file #' + fileId + ': ' + error.message);
                });
            });

            // Summary log
            setTimeout(function() {
                console.log('[WorkflowBadge] Badge injection complete:');
                console.log('  - Badges added: ' + badgesAdded);
                console.log('  - Badges skipped (già presenti): ' + badgesSkippedExisting);
                console.log('  - Folders ignored: ' + foldersIgnored);
                console.log('  - API errors: ' + apiCallsFailed);
            }, 1000); // Wait for async API calls to complete
        }

        /**
         * Hook into fileManager.loadFiles to inject badges after each folder load
         */
        function hookFileManagerLoadFiles() {
            if (!window.fileManager) {
                console.warn('[WorkflowBadge] fileManager not ready, retrying in 100ms...');
                setTimeout(hookFileManagerLoadFiles, 100);
                return;
            }

            const originalLoadFiles = window.fileManager.loadFiles;

            window.fileManager.loadFiles = async function(folderId) {
                console.log('[WorkflowBadge] fileManager.loadFiles called for folder:', folderId);

                // Call original method
                const result = await originalLoadFiles.call(this, folderId);

                // Inject badges after render completes (give time for DOM to update)
                setTimeout(function() {
                    console.log('[WorkflowBadge] Post-render delay complete, injecting badges...');
                    injectWorkflowBadges();
                }, 600); // Increased to 600ms for safety

                return result;
            };

            console.log('[WorkflowBadge] ✅ Successfully hooked into fileManager.loadFiles');
        }

        /**
         * Initialize on page load
         */
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                console.log('[WorkflowBadge] DOMContentLoaded event fired');
                hookFileManagerLoadFiles();

                // Initial injection for files already rendered
                setTimeout(injectWorkflowBadges, 1500);
            });
        } else {
            console.log('[WorkflowBadge] Document already loaded, initializing immediately');
            hookFileManagerLoadFiles();
            setTimeout(injectWorkflowBadges, 1500);
        }

        console.log('[WorkflowBadge] ✅ Initialization script complete');
    })();
    </script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>