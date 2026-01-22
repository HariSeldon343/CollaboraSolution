<?php
/**
 * CollaboraNexio - Work Shifts Management Page (Gestione Turni di Lavoro)
 *
 * MINIMAL DESIGN - Consistent with dashboard.php, calendar.php and files.php
 * NO gradients, NO glassmorphism - Clean enterprise design
 *
 * Features:
 * - Weekly/Monthly calendar view with shift assignments
 * - Shift types management (admin/manager)
 * - Shift assignment creation/modification
 * - Change requests for users with approval workflow
 * - Pending requests sidebar for managers
 *
 * Created: 2025-12-19
 * Pattern: CLAUDE.md 8-step authentication + Minimal UI
 */

// Step 1: Session & Authentication
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/company_filter.php';

// Force no-cache headers
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}

// Step 2: Get current user
$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    header('Location: index.php');
    exit;
}

// Step 3: Tenant access check
require_once __DIR__ . '/includes/tenant_access_check.php';
requireTenantAccess($currentUser['id'], $currentUser['role']);

// Enforce Page Visibility access rules (configurazioni.php -> Visibilità Pagine)
require_once __DIR__ . '/includes/page_access_check.php';
checkPageAccess('turni');

// Step 4: Audit logging
require_once __DIR__ . '/includes/audit_page_access.php';
trackPageAccess('turni');

// Step 5: Company filter
$companyFilter = new CompanyFilter($currentUser);

// Step 6: Generate CSRF token
$csrfToken = $auth->generateCSRFToken();

// User permissions
$userRole = $currentUser['role'] ?? 'user';
$isSuperAdmin = ($userRole === 'super_admin');
$isAdmin = ($userRole === 'admin');
$isManager = ($userRole === 'manager');
$canManageShifts = $isSuperAdmin || $isAdmin || $isManager;
$canApproveRequests = $isSuperAdmin || $isAdmin || $isManager;

// Asset versions for cache busting (mtime alone can collide within the same second on some filesystems)
$shiftsJsVersion = (string)((@filemtime(__DIR__ . '/assets/js/shifts.js') ?: time()) . '-' . (@filesize(__DIR__ . '/assets/js/shifts.js') ?: 0));
$shiftsCssVersion = (string)((@filemtime(__DIR__ . '/assets/css/shifts.css') ?: time()) . '-' . (@filesize(__DIR__ . '/assets/css/shifts.css') ?: 0));

// Build marker for production debugging
$buildId = substr(md5(filemtime(__FILE__) . filemtime(__DIR__ . '/assets/js/shifts.js')), 0, 8);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Gestione Turni - Nexio';
    $pageMeta = [
        '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate, max-age=0">',
        '<meta http-equiv="Pragma" content="no-cache">',
        '<meta http-equiv="Expires" content="0">',
    ];
    $pageCss = ['assets/css/shifts.css?v=' . $shiftsCssVersion];
    require __DIR__ . '/includes/layout_head.php';
?>

    <!-- Build marker for production debugging -->
    <!-- CNX_BUILD_ID: <?php echo $buildId; ?> -->
</head>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
            <!-- Header -->
            <div class="header">
                <h1 class="page-title">Gestione Turni</h1>
                <div class="flex items-center gap-4">
                    <!-- View Selector -->
                    <div class="shifts-view-selector">
                        <button type="button" class="shifts-view-btn active" data-view="week" id="viewWeekBtn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            Settimana
                        </button>
                        <button type="button" class="shifts-view-btn" data-view="month" id="viewMonthBtn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                <rect x="6" y="13" width="3" height="3"></rect>
                                <rect x="10.5" y="13" width="3" height="3"></rect>
                                <rect x="15" y="13" width="3" height="3"></rect>
                            </svg>
                            Mese
                        </button>
                    </div>

                    <?php if ($companyFilter->canUseCompanyFilter()): ?>
                        <?php echo $companyFilter->renderDropdown(); ?>
                    <?php endif; ?>

                    <button type="button" class="btn btn-secondary btn-sm" id="turniShowTourBtn">Guida rapida</button>
                    <button type="button" class="btn btn-outline" id="shiftSummaryBtn" title="Riepilogo turni e ore settimanali">Riepilogo</button>

                    <?php if ($canManageShifts): ?>
                        <button type="button" class="btn btn-outline" id="shiftTypesBtn" title="Gestisci Tipi Turno" onclick="window.shiftsApp && window.shiftsApp.openShiftTypesModal && window.shiftsApp.openShiftTypesModal()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="3"></circle>
                                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                            </svg>
                            Tipi Turno
                        </button>
                        <button type="button" class="btn btn-outline" id="newShiftWizardBtn" title="Wizard guidato per creare turni in blocco (tipo turno → persone → periodo)" onclick="window.shiftsApp && window.shiftsApp.openShiftWizardModal && window.shiftsApp.openShiftWizardModal()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 19h16"></path>
                                <path d="M4 5h16"></path>
                                <path d="M8 5v14"></path>
                                <path d="M16 5v14"></path>
                            </svg>
                            Wizard Turni
                        </button>
                        <button type="button" class="btn btn-outline" id="balanceShiftsBtn" title="Suggerisci bilanciamento turni" onclick="window.shiftsApp && window.shiftsApp.openBalanceModal && window.shiftsApp.openBalanceModal()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 3v18h18"></path>
                                <path d="M7 14l4-4 3 3 7-7"></path>
                            </svg>
                            Bilanciamento
                        </button>
                        <button type="button" class="btn btn-primary" id="newShiftBtn" onclick="window.shiftsApp && window.shiftsApp.openShiftModal && window.shiftsApp.openShiftModal()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            Nuovo Turno
                        </button>
                        <button type="button" class="btn btn-outline" id="toggleSelectShiftsBtn" title="Seleziona più turni per eliminazione rapida">
                            Seleziona
                        </button>
                        <button type="button" class="btn btn-danger" id="bulkDeleteShiftsBtn" title="Elimina i turni selezionati" disabled>
                            Elimina selezionati (0)
                        </button>
                    <?php endif; ?>

                    <span class="text-sm text-muted">Benvenuto, <?php echo htmlspecialchars($currentUser['name']); ?></span>
                </div>
            </div>

            <!-- Page Content -->
            <div class="page-content">
                <div class="shifts-layout <?php echo $canApproveRequests ? 'has-sidebar' : ''; ?>">
                    <!-- Main Calendar Area -->
                    <div class="shifts-main">
                        <!-- Navigation Header -->
                        <div class="shifts-calendar-header">
                            <div class="shifts-nav">
                                <button type="button" class="shifts-nav-btn" id="prevPeriodBtn" title="Periodo precedente">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="15 18 9 12 15 6"></polyline>
                                    </svg>
                                </button>
                                <button type="button" class="shifts-nav-btn" id="todayBtn" title="Oggi">Oggi</button>
                                <button type="button" class="shifts-nav-btn" id="nextPeriodBtn" title="Periodo successivo">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="9 18 15 12 9 6"></polyline>
                                    </svg>
                                </button>
                            </div>
                            <h2 class="shifts-current-period" id="currentPeriodLabel">Dicembre 2025</h2>
                            <button type="button" class="btn btn-outline btn-sm" id="shiftTypesUsedBtn" title="Riepilogo dei tipi di turno utilizzati nel periodo">
                                Tipi turno
                            </button>
                        </div>

                        <!-- Calendar Grid Container -->
                        <div class="shifts-calendar-wrapper">
                            <div class="shifts-calendar" id="shiftsCalendar">
                                <!-- Calendar grid will be rendered here by JavaScript -->
                                <div class="shifts-loading">
                                    <div class="shifts-spinner"></div>
                                    <span>Caricamento turni...</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($canApproveRequests): ?>
                    <!-- Requests Sidebar -->
                    <aside class="shifts-requests-sidebar" id="requestsSidebar">
                        <div class="requests-sidebar-header">
                            <h3>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="12" y1="18" x2="12" y2="12"></line>
                                    <line x1="9" y1="15" x2="15" y2="15"></line>
                                </svg>
                                Richieste
                            </h3>
                            <span class="requests-badge" id="pendingRequestsCount">0</span>
                        </div>
                        <div class="requests-list" id="requestsList">
                            <div class="requests-empty">
                                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                                    <line x1="9" y1="9" x2="9.01" y2="9"></line>
                                    <line x1="15" y1="9" x2="15.01" y2="9"></line>
                                </svg>
                                <p>Nessuna richiesta in sospeso</p>
                            </div>
                        </div>
                    </aside>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Shift Types Management -->
    <div class="modal" id="shiftTypesModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h2 class="modal-title">Gestione Tipi Turno</h2>
                <button type="button" class="modal-close" data-dismiss="modal">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <!-- Shift Types List -->
                <div class="shift-types-list" id="shiftTypesList">
                    <div class="shifts-loading">
                        <div class="shifts-spinner"></div>
                        <span>Caricamento tipi turno...</span>
                    </div>
                </div>

                <!-- Add New Shift Type Form -->
                <div class="shift-type-form" id="shiftTypeForm">
                    <h4 class="shift-type-form-title" id="shiftTypeFormTitle">Nuovo Tipo Turno</h4>
                    <input type="hidden" id="editingShiftTypeId" value="">
                    <div class="form-group">
                        <label for="shiftTypeDescription">Descrizione (opzionale)</label>
                        <textarea id="shiftTypeDescription" class="form-control" rows="2" placeholder="Dettagli / note sul turno..."></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="shiftTypeName">Nome <span class="required">*</span></label>
                            <input type="text" id="shiftTypeName" class="form-control" placeholder="es. Mattina" required>
                        </div>
                        <div class="form-group">
                            <label for="shiftTypeCode">Codice <span class="required">*</span></label>
                            <input type="text" id="shiftTypeCode" class="form-control" placeholder="es. MAT" maxlength="10" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="shiftTypeStartTime">Orario Inizio <span class="required">*</span></label>
                            <input type="time" id="shiftTypeStartTime" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="shiftTypeEndTime">Orario Fine <span class="required">*</span></label>
                            <input type="time" id="shiftTypeEndTime" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="shiftTypeDurationMinutes">Durata (min) (opzionale)</label>
                            <input type="number" id="shiftTypeDurationMinutes" class="form-control" min="0" step="1" placeholder="Vuoto = calcolo automatico">
                        </div>
                        <div class="form-group">
                            <label for="shiftTypeSortOrder">Ordine (opzionale)</label>
                            <input type="number" id="shiftTypeSortOrder" class="form-control" min="0" step="1" placeholder="0">
                        </div>
                        <div class="form-group">
                            <label for="shiftTypeColor">Colore</label>
                            <div class="color-picker-wrapper">
                                <input type="color" id="shiftTypeColor" class="form-control-color" value="#3B82F6">
                                <span class="color-preview" id="colorPreview" style="background-color: #3B82F6;"></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="shiftTypeIcon">Icona (opzionale)</label>
                            <input type="text" id="shiftTypeIcon" class="form-control" placeholder="es. sun / moon / coffee / emoji" list="shiftTypeIconSuggestions">
                            <datalist id="shiftTypeIconSuggestions">
                                <option value="sun"></option>
                                <option value="cloud"></option>
                                <option value="moon"></option>
                                <option value="clock"></option>
                                <option value="coffee"></option>
                            </datalist>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-checkbox-label">
                            <input type="checkbox" id="shiftTypeIsActive" class="form-checkbox" checked>
                            <span>Attivo (disattiva temporaneamente senza eliminare)</span>
                        </label>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" id="cancelShiftTypeBtn">Annulla</button>
                        <button type="button" class="btn btn-primary" id="saveShiftTypeBtn">Salva Tipo Turno</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Shift Summary (counts by type + weekly hours) -->
    <div class="modal" id="shiftSummaryModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content modal-xl">
            <div class="modal-header">
                <h2 class="modal-title" id="shiftSummaryModalTitle">Riepilogo turni</h2>
                <button type="button" class="modal-close" data-dismiss="modal">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="text-sm text-muted" id="shiftSummaryRange">—</div>
                <div id="shiftSummaryAlerts" style="margin-top:10px;"></div>
                <div class="shift-summary-wrap" style="margin-top:12px;">
                    <div class="text-muted" id="shiftSummaryLoading">Caricamento…</div>
                    <div style="overflow:auto;">
                        <table class="shift-summary-table" id="shiftSummaryTable" style="display:none;">
                            <thead id="shiftSummaryThead"></thead>
                            <tbody id="shiftSummaryTbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-dismiss="modal">Chiudi</button>
            </div>
        </div>
    </div>

    <!-- Modal: Shift Types Used (legend summary) -->
    <div class="modal" id="shiftTypesUsedModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h2 class="modal-title" id="shiftTypesUsedModalTitle">Tipi turno utilizzati</h2>
                <button type="button" class="modal-close" data-dismiss="modal">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="text-sm text-muted" id="shiftTypesUsedRange">—</div>
                <div id="shiftTypesUsedBody" style="margin-top:12px;">
                    <div class="text-muted">Caricamento…</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-dismiss="modal">Chiudi</button>
            </div>
        </div>
    </div>

    <!-- Modal: Shift Wizard (guided bulk creation) -->
    <div class="modal" id="shiftWizardModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h2 class="modal-title">Wizard Turni (creazione guidata)</h2>
                <button type="button" class="modal-close" data-dismiss="modal">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="text-muted" id="shiftWizardStepIndicator" style="margin-bottom:10px;"><strong>Step 1/4</strong></div>

                <div class="shift-wizard-step" id="shiftWizardStep1">
                    <div class="form-group">
                        <label>Tipo turno *</label>
                        <select id="shiftWizardType" class="form-control" required>
                            <option value="">Seleziona tipo turno...</option>
                        </select>
                        <small class="form-hint">Il wizard crea turni “a partire dal tipo turno”, poi scegli persone e periodo.</small>
                    </div>
                </div>

                <div class="shift-wizard-step" id="shiftWizardStep2" style="display:none;">
                    <div class="form-group">
                        <label>Persone *</label>
                        <input type="text" id="shiftWizardEmployeeSearch" class="form-control" placeholder="Cerca persona...">
                        <div class="form-actions" style="justify-content:flex-start; gap: 8px; margin-top:10px;">
                            <button type="button" class="btn btn-outline" id="shiftWizardSelectAllBtn">Seleziona tutti</button>
                            <button type="button" class="btn btn-outline" id="shiftWizardSelectOnlyVisibleBtn">Seleziona solo i filtrati</button>
                            <button type="button" class="btn btn-outline" id="shiftWizardClearAllBtn">Deseleziona tutti</button>
                        </div>
                        <div class="bulk-options" style="display:block; margin-top:10px;">
                            <div class="weekdays-selector" id="shiftWizardEmployeesWrap" style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px;">
                                <!-- filled by JS -->
                            </div>
                        </div>
                        <small class="form-hint" id="shiftWizardEmployeesHint">Selezionati: 0</small>
                    </div>
                </div>

                <div class="shift-wizard-step" id="shiftWizardStep3" style="display:none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Data inizio *</label>
                            <input type="date" id="shiftWizardStartDate" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Data fine *</label>
                            <input type="date" id="shiftWizardEndDate" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Giorni della settimana *</label>
                        <div class="weekdays-selector">
                            <label class="weekday-checkbox"><input type="checkbox" value="1" checked> <span>Lun</span></label>
                            <label class="weekday-checkbox"><input type="checkbox" value="2" checked> <span>Mar</span></label>
                            <label class="weekday-checkbox"><input type="checkbox" value="3" checked> <span>Mer</span></label>
                            <label class="weekday-checkbox"><input type="checkbox" value="4" checked> <span>Gio</span></label>
                            <label class="weekday-checkbox"><input type="checkbox" value="5" checked> <span>Ven</span></label>
                            <label class="weekday-checkbox"><input type="checkbox" value="6"> <span>Sab</span></label>
                            <label class="weekday-checkbox"><input type="checkbox" value="0"> <span>Dom</span></label>
                        </div>
                        <small class="form-hint">Massimo 100 turni per operazione (limite API bulk).</small>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Status</label>
                            <select id="shiftWizardStatus" class="form-control">
                                <option value="scheduled" selected>Programmato</option>
                                <option value="confirmed">Confermato</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Note (opzionale)</label>
                            <input type="text" id="shiftWizardNotes" class="form-control" placeholder="Note per tutti i turni creati...">
                        </div>
                    </div>
                </div>

                <div class="shift-wizard-step" id="shiftWizardStep4" style="display:none;">
                    <div class="shift-wizard-preview" id="shiftWizardPreviewBox">
                        <!-- filled by JS -->
                    </div>
                    <div class="form-actions" style="justify-content:flex-start; gap: 8px; margin-top:10px;">
                        <button type="button" class="btn btn-outline" id="shiftWizardExportCsvBtn">Esporta CSV</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="shiftWizardBackBtn">Indietro</button>
                <button type="button" class="btn btn-outline" data-dismiss="modal" id="shiftWizardCancelBtn">Annulla</button>
                <button type="button" class="btn btn-primary" id="shiftWizardNextBtn">Avanti</button>
            </div>
        </div>
    </div>

    <!-- Modal: Create/Edit Shift -->
    <div class="modal" id="shiftModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="shiftModalTitle">Nuovo Turno</h2>
                <button type="button" class="modal-close" data-dismiss="modal">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <form id="shiftForm">
                    <input type="hidden" id="editingShiftId" value="">

                    <div class="form-group">
                        <label for="shiftEmployee">Dipendente <span class="required">*</span></label>
                        <select id="shiftEmployee" class="form-control" required>
                            <option value="">Seleziona dipendente...</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="shiftType">Tipo Turno <span class="required">*</span></label>
                        <select id="shiftType" class="form-control" required>
                            <option value="">Seleziona tipo turno...</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="shiftDateStart">Data Inizio <span class="required">*</span></label>
                            <input type="date" id="shiftDateStart" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="shiftDateEnd">Data Fine (per bulk)</label>
                            <input type="date" id="shiftDateEnd" class="form-control">
                            <small class="form-hint">Lascia vuoto per singolo giorno</small>
                        </div>
                    </div>

                    <!-- Turno "libero": entrata posticipata / uscita anticipata (usa start_time_override/end_time_override) -->
                    <div class="form-group" id="shiftOverrideTimesGroup">
                        <label class="form-checkbox-label" style="justify-content:flex-start;">
                            <input type="checkbox" id="shiftOverrideTimesEnabled" class="form-checkbox">
                            <span>Turno libero (entrata posticipata / uscita anticipata)</span>
                        </label>
                        <small class="form-hint" id="shiftOverrideTimesHint">Opzionale: modifica l’orario solo per questo turno (senza creare un nuovo tipo turno).</small>
                    </div>
                    <div class="form-row" id="shiftOverrideTimesRow" style="display:none;">
                        <div class="form-group">
                            <label class="form-checkbox-label" style="justify-content:flex-start; margin-bottom:6px;">
                                <input type="checkbox" id="shiftStartOverrideEnabled" class="form-checkbox">
                                <span>Entrata posticipata</span>
                            </label>
                            <input type="time" id="shiftStartTimeOverride" class="form-control" step="60" disabled>
                            <small class="form-hint" id="shiftStartTimeOverrideHint">Default: —</small>
                        </div>
                        <div class="form-group">
                            <label class="form-checkbox-label" style="justify-content:flex-start; margin-bottom:6px;">
                                <input type="checkbox" id="shiftEndOverrideEnabled" class="form-checkbox">
                                <span>Uscita anticipata</span>
                            </label>
                            <input type="time" id="shiftEndTimeOverride" class="form-control" step="60" disabled>
                            <small class="form-hint" id="shiftEndTimeOverrideHint">Default: —</small>
                        </div>
                    </div>

                    <!-- Bulk options -->
                    <div class="bulk-options" id="bulkOptions" style="display: none;">
                        <label class="form-label">Giorni della settimana</label>
                        <div class="weekdays-selector">
                            <label class="weekday-checkbox">
                                <input type="checkbox" name="weekdays" value="1" checked>
                                <span>Lun</span>
                            </label>
                            <label class="weekday-checkbox">
                                <input type="checkbox" name="weekdays" value="2" checked>
                                <span>Mar</span>
                            </label>
                            <label class="weekday-checkbox">
                                <input type="checkbox" name="weekdays" value="3" checked>
                                <span>Mer</span>
                            </label>
                            <label class="weekday-checkbox">
                                <input type="checkbox" name="weekdays" value="4" checked>
                                <span>Gio</span>
                            </label>
                            <label class="weekday-checkbox">
                                <input type="checkbox" name="weekdays" value="5" checked>
                                <span>Ven</span>
                            </label>
                            <label class="weekday-checkbox">
                                <input type="checkbox" name="weekdays" value="6">
                                <span>Sab</span>
                            </label>
                            <label class="weekday-checkbox">
                                <input type="checkbox" name="weekdays" value="0">
                                <span>Dom</span>
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="shiftNotes">Note (opzionale)</label>
                        <textarea id="shiftNotes" class="form-control" rows="2" placeholder="Note aggiuntive..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-danger" id="deleteShiftBtn" style="display: none;">Elimina</button>
                <button type="button" class="btn btn-primary" id="saveShiftBtn">Salva Turno</button>
            </div>
        </div>
    </div>

    <!-- Modal: Shift Details / Request Change (for users) -->
    <div class="modal" id="shiftDetailModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Dettagli Turno</h2>
                <button type="button" class="modal-close" data-dismiss="modal">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="shift-detail-card" id="shiftDetailCard">
                    <!-- Populated by JavaScript -->
                </div>

                <?php if (!$canManageShifts): ?>
                <!-- Request Change Form (for regular users) -->
                <div class="request-change-form" id="requestChangeForm" style="display: none;">
                    <h4>Richiedi Modifica</h4>
                    <div class="form-group">
                        <label for="requestType">Tipo Richiesta <span class="required">*</span></label>
                        <select id="requestType" class="form-control" required>
                            <option value="">Seleziona...</option>
                            <option value="change">Cambio turno</option>
                            <option value="swap">Scambio con collega</option>
                            <option value="cancel">Annulla turno</option>
                        </select>
                    </div>

                    <div class="form-group swap-target-group" style="display: none;">
                        <label for="swapTargetUser">Collega per scambio</label>
                        <select id="swapTargetUser" class="form-control">
                            <option value="">Seleziona collega...</option>
                        </select>
                    </div>

                    <div class="form-group new-shift-group" style="display: none;">
                        <label for="requestedShiftType">Nuovo tipo turno</label>
                        <select id="requestedShiftType" class="form-control">
                            <option value="">Seleziona tipo turno...</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="requestReason">Motivo <span class="required">*</span></label>
                        <textarea id="requestReason" class="form-control" rows="3" placeholder="Spiega il motivo della richiesta..." required></textarea>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-dismiss="modal">Chiudi</button>
                <?php if (!$canManageShifts): ?>
                <button type="button" class="btn btn-warning" id="requestChangeBtn">Richiedi Modifica</button>
                <button type="button" class="btn btn-primary" id="submitRequestBtn" style="display: none;">Invia Richiesta</button>
                <?php else: ?>
                <button type="button" class="btn btn-primary" id="editShiftFromDetailBtn">Modifica</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal: Request Detail (for managers) -->
    <div class="modal" id="requestDetailModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Dettagli Richiesta</h2>
                <button type="button" class="modal-close" data-dismiss="modal">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="request-detail-card" id="requestDetailCard">
                    <!-- Populated by JavaScript -->
                </div>

                <div class="form-group rejection-reason-group" id="rejectionReasonGroup" style="display: none;">
                    <label for="rejectionReason">Motivo Rifiuto <span class="required">*</span></label>
                    <textarea id="rejectionReason" class="form-control" rows="2" placeholder="Spiega il motivo del rifiuto..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-dismiss="modal">Chiudi</button>
                <button type="button" class="btn btn-danger" id="rejectRequestBtn">Rifiuta</button>
                <button type="button" class="btn btn-success" id="approveRequestBtn">Approva</button>
            </div>
        </div>
    </div>

    <!-- Toast Notifications Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Hidden Inputs (MANDATORY for ShiftsApp) -->
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" id="currentUserId" value="<?php echo htmlspecialchars($currentUser['id']); ?>">
    <input type="hidden" id="currentUserRole" value="<?php echo htmlspecialchars($currentUser['role']); ?>">
    <input type="hidden" id="turniLoginNonce" value="<?php echo htmlspecialchars((string)($_SESSION['login_nonce'] ?? '')); ?>">
    <?php
        // Expose selected tenant(s) from Company Filter to JS.
        // - For roles allowed to use the filter: can be multiple tenants or "all"
        // - For other roles: single tenant only
        $selectedTenantIds = null; // null => all (for eligible roles)
        if (isset($companyFilter) && $companyFilter instanceof CompanyFilter && $companyFilter->canUseCompanyFilter()) {
            $selectedTenantIds = $companyFilter->getActiveFilterIds(); // null means "Tutte le aziende"
            if ($selectedTenantIds === null) {
                // All companies selected: expose all accessible companies IDs to JS so shifts can load everything.
                $selectedTenantIds = array_values(array_map(static fn($c) => (int)($c['id'] ?? 0), $companyFilter->getAvailableCompanies()));
                $selectedTenantIds = array_values(array_filter($selectedTenantIds, static fn($id) => $id > 0));
            }
        }
        if (!is_array($selectedTenantIds) || empty($selectedTenantIds)) {
            $fallback = (int)($currentUser['tenant_id'] ?? 0);
            $selectedTenantIds = $fallback > 0 ? [$fallback] : [];
        }

        $activeTenantIdForShifts = $selectedTenantIds[0] ?? ($currentUser['tenant_id'] ?? null);
    ?>
    <!-- Backward-compat: single tenant for legacy code paths -->
    <input type="hidden" id="currentTenantId" value="<?php echo htmlspecialchars((string)($activeTenantIdForShifts ?? '')); ?>">
    <!-- Preferred: selected tenant IDs (JSON array) -->
    <input type="hidden" id="currentTenantIds" value="<?php echo htmlspecialchars(json_encode($selectedTenantIds, JSON_UNESCAPED_SLASHES)); ?>">
    <input type="hidden" id="canManageShifts" value="<?php echo $canManageShifts ? '1' : '0'; ?>">
    <input type="hidden" id="canApproveRequests" value="<?php echo $canApproveRequests ? '1' : '0'; ?>">

    <!-- JavaScript Build ID -->
    <script>
        window.CNX_BUILD_ID = '<?php echo $buildId; ?>';
    </script>

    <!-- Shifts JavaScript -->
    <script src="assets/js/shifts.js?v=<?php echo htmlspecialchars($shiftsJsVersion); ?>"></script>

    <script>
        // Initialize ShiftsApp when DOM is ready
        document.addEventListener('DOMContentLoaded', () => {
            try {
                if (typeof window.ShiftsApp !== 'function') {
                    console.error('[Turni Page] ShiftsApp not found (shifts.js not loaded?)');
                    return;
                }
                console.log('[Turni Page] Initializing ShiftsApp...');
                window.shiftsApp = new window.ShiftsApp('shiftsCalendar');
                console.log('[Turni Page] ShiftsApp initialized');

                // Extra-hardening: bind header buttons here too (so clicks work even if
                // - inline onclick is blocked by CSP
                // - ShiftsApp.bindEvents() is skipped for any reason)
                const safeBind = (id, fn) => {
                    const el = document.getElementById(id);
                    if (!el) return;
                    el.addEventListener('click', (ev) => {
                        try { fn(ev); } catch (e) { console.error('[Turni Page] click handler error:', e); }
                    });
                };
                safeBind('shiftTypesBtn', () => window.shiftsApp?.openShiftTypesModal?.());
                safeBind('newShiftWizardBtn', () => window.shiftsApp?.openShiftWizardModal?.());
                safeBind('balanceShiftsBtn', () => window.shiftsApp?.openBalanceModal?.());
                safeBind('newShiftBtn', () => window.shiftsApp?.openShiftModal?.());
            } catch (e) {
                console.error('[Turni Page] ShiftsApp init failed:', e);
            }
        });
    </script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
